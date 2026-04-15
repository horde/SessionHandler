<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author Ralf Lang <ralf.lang@ralf-lang.de>
 */

namespace Horde\SessionHandler;

use DateInterval;
use DateTimeImmutable;
use Horde\SessionHandler\Event\SessionCreated;
use Horde\SessionHandler\Event\SessionDestroyed;
use Horde\SessionHandler\Event\SessionExpired;
use Horde\SessionHandler\Event\SessionIdRegenerated;
use Horde\SessionHandler\Event\SessionLoaded;
use Horde\SessionHandler\Event\SessionSaved;
use Horde\SessionHandler\Exception\CapabilityException;
use InvalidArgumentException;
use Psr\Clock\ClockInterface;
use Psr\EventDispatcher\EventDispatcherInterface;
use SessionHandlerInterface;
use SessionIdInterface;
use SessionUpdateTimestampHandlerInterface;
use Throwable;

/**
 * Modern session handler orchestrator.
 *
 * Provides both a high-level object API (create/load/save/destroy/regenerate)
 * and implements PHP's native session interfaces as adapter plumbing so it can
 * be registered with session_set_save_handler().
 */
final class SessionHandler implements
    SessionHandlerInterface,
    SessionUpdateTimestampHandlerInterface,
    SessionIdInterface
{
    public function __construct(
        private readonly SessionStorageBackend $backend,
        private readonly SessionSerializer $serializer = new PhpSessionSerializer(),
        private readonly SessionFactory $sessionFactory = new DefaultSessionFactory(),
        private readonly ?EventDispatcherInterface $events = null,
        private readonly ?ClockInterface $clock = null,
    ) {}

    // ---------------------------------------------------------------
    // Private helpers
    // ---------------------------------------------------------------

    private function now(): DateTimeImmutable
    {
        return $this->clock?->now() ?? new DateTimeImmutable();
    }

    private function dispatch(object $event): void
    {
        $this->events?->dispatch($event);
    }

    /**
     * Calculate the session expiry timestamp based on session.gc_maxlifetime.
     */
    private function expiresAt(): DateTimeImmutable
    {
        $lifetime = (int) ini_get('session.gc_maxlifetime') ?: 1440;

        return $this->now()->add(new DateInterval('PT' . $lifetime . 'S'));
    }

    // ---------------------------------------------------------------
    // Core lifecycle API — exceptions propagate to application code
    // ---------------------------------------------------------------

    public function create(): Session
    {
        $id = new SessionId($this->create_sid());
        $session = $this->sessionFactory->createNew($id);
        $this->dispatch(new SessionCreated($id));

        return $session;
    }

    public function load(SessionId $id): ?Session
    {
        $payload = $this->backend->load($id);

        if ($payload === null) {
            return null;
        }

        $data = $this->serializer->deserialize($payload);
        $session = $this->sessionFactory->restore($id, $data);
        $this->dispatch(new SessionLoaded($id));

        return $session;
    }

    public function save(Session $session): void
    {
        if (!$session->isDirty()) {
            return;
        }

        $payload = $this->serializer->serialize($session);
        $this->backend->save($session->getId(), $payload, $this->expiresAt());
        $this->dispatch(new SessionSaved($session->getId()));
    }

    /**
     * Destroy a session by its ID.
     *
     * This is the high-level API method (SessionId parameter).
     * The native PHP interface method is destroy(string): bool below.
     */
    public function destroySession(SessionId $id): void
    {
        $this->backend->delete($id);
        $this->dispatch(new SessionDestroyed($id));
    }

    public function regenerate(Session $session): Session
    {
        $newId = new SessionId($this->create_sid());
        $newSession = $this->sessionFactory->restore($newId, $session->toPayload());
        $this->backend->delete($session->getId());
        $this->dispatch(new SessionIdRegenerated($session->getId(), $newId));

        return $newSession;
    }

    // ---------------------------------------------------------------
    // Capability-gated admin operations
    // ---------------------------------------------------------------

    /** @return iterable<SessionId> */
    public function listSessions(): iterable
    {
        if (!$this->backend instanceof IterableSessionBackend) {
            throw new CapabilityException('Backend does not support session iteration');
        }

        return $this->backend->listSessions();
    }

    public function getMetadata(SessionId $id): ?SessionMetadata
    {
        if (!$this->backend instanceof SessionMetadataBackend) {
            throw new CapabilityException('Backend does not support session metadata');
        }

        return $this->backend->getMetadata($id);
    }

    public function expire(SessionId $id): void
    {
        if (!$this->backend instanceof AdministrativeSessionBackend) {
            throw new CapabilityException('Backend does not support administrative session expiration');
        }

        $this->backend->expire($id);
        $this->dispatch(new SessionExpired($id));
    }

    // ---------------------------------------------------------------
    // PHP native session interface — SessionHandlerInterface
    // ---------------------------------------------------------------

    public function open(string $path, string $name): bool
    {
        return true;
    }

    public function close(): bool
    {
        return true;
    }

    public function read(string $id): string|false
    {
        try {
            $sessionId = new SessionId($id);
            $payload = $this->backend->load($sessionId);

            if ($payload === null) {
                return '';
            }

            return $payload->getData();
        } catch (Throwable) {
            return false;
        }
    }

    public function write(string $id, string $data): bool
    {
        try {
            $sessionId = new SessionId($id);
            $payload = new SerializedSessionPayload($data);
            $this->backend->save($sessionId, $payload, $this->expiresAt());

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Destroy a session — native PHP interface (string parameter).
     */
    public function destroy(string $id): bool
    {
        try {
            $sessionId = new SessionId($id);
            $this->backend->delete($sessionId);
            $this->dispatch(new SessionDestroyed($sessionId));

            return true;
        } catch (Throwable) {
            return false;
        }
    }

    public function gc(int $max_lifetime): int|false
    {
        if (
            !$this->backend instanceof IterableSessionBackend
            || !$this->backend instanceof SessionMetadataBackend
        ) {
            return false;
        }

        try {
            $now = $this->now();
            $deleted = 0;

            foreach ($this->backend->listSessions() as $sessionId) {
                $metadata = $this->backend->getMetadata($sessionId);

                if ($metadata === null) {
                    continue;
                }

                if ($metadata->expiresAt <= $now) {
                    $this->backend->delete($sessionId);
                    $deleted++;
                }
            }

            return $deleted;
        } catch (Throwable) {
            return false;
        }
    }

    // ---------------------------------------------------------------
    // PHP native session interface — SessionIdInterface
    // ---------------------------------------------------------------

    public function create_sid(): string
    {
        return bin2hex(random_bytes(16));
    }

    // ---------------------------------------------------------------
    // PHP native session interface — SessionUpdateTimestampHandlerInterface
    // ---------------------------------------------------------------

    public function validateId(string $id): bool
    {
        try {
            $sessionId = new SessionId($id);
            $payload = $this->backend->load($sessionId);

            return $payload !== null;
        } catch (InvalidArgumentException) {
            return false;
        } catch (Throwable) {
            return false;
        }
    }

    public function updateTimestamp(string $id, string $data): bool
    {
        try {
            $sessionId = new SessionId($id);
            $payload = new SerializedSessionPayload($data);
            $this->backend->save($sessionId, $payload, $this->expiresAt());

            return true;
        } catch (Throwable) {
            return false;
        }
    }
}
