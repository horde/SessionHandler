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

use Generator;
use Horde\SessionHandler\Exception\CapabilityException;
use Horde\SessionHandler\Exception\SessionException;

/**
 * Cross-session inspection and administration service.
 *
 * Operates on stored sessions other than the one currently active in this
 * PHP process. Callers include admin tools (list active sessions, force
 * logout one), security flows (password change → invalidate all OTHER
 * sessions for the user), and ops jobs (background cleanup).
 *
 * This is deliberately separate from {@see SessionHandler}, which manages
 * the lifecycle of the SINGLE active session in the current request.
 *
 * Capability requirements
 * -----------------------
 * The injected backend must implement {@see SessionStorageBackend} (mandatory)
 * for delete/load. Methods that enumerate sessions additionally require
 * {@see IterableSessionBackend}; calling them against a non-iterable backend
 * throws {@see CapabilityException}.
 *
 * Methods that filter by user (findByUser, destroyAllForUser) inspect the
 * payload of each enumerated session. The user identifier is read via the
 * "horde/auth/userId" path used by HordeSession, matching the wire layout
 * of {@see \Horde\Core\Session\HordeSession::getAuthenticatedUser()}. Other
 * Session implementations can extract via the same path or override by
 * subclassing.
 *
 * Performance note
 * ----------------
 * findByUser/destroyAllForUser iterate all sessions and load each one.
 * Acceptable for admin tools; not for hot paths. A backend-specific
 * user-indexed implementation can replace this service in deployments
 * where session counts are large.
 */
class SessionAdministrator
{
    public function __construct(
        protected readonly SessionStorageBackend $backend,
        protected readonly SessionFactory $factory,
        protected readonly SessionSerializer $serializer,
    ) {}

    /**
     * Enumerate all known session IDs in the backend.
     *
     * @return Generator<SessionId>
     *
     * @throws CapabilityException If the backend cannot enumerate sessions.
     * @throws SessionException    On backend failure during enumeration.
     */
    public function listAll(): Generator
    {
        if (!$this->backend instanceof IterableSessionBackend) {
            throw new CapabilityException(
                'Backend ' . $this->backend::class . ' does not support session enumeration; '
                . 'implement IterableSessionBackend to enable listing.'
            );
        }

        foreach ($this->backend->listSessions() as $id) {
            yield $id;
        }
    }

    /**
     * Load a stored session by ID.
     *
     * Returns null if the session does not exist, has expired, or its
     * payload is corrupt and cannot be deserialized.
     */
    public function load(SessionId $id): ?Session
    {
        $payload = $this->backend->load($id);
        if ($payload === null) {
            return null;
        }

        try {
            $data = $this->serializer->deserialize($payload);
        } catch (SessionException) {
            return null;
        }

        return $this->factory->restore($id, $data);
    }

    /**
     * Forcibly delete a session.
     *
     * Idempotent: removing a non-existent session is not an error.
     */
    public function destroy(SessionId $id): void
    {
        $this->backend->delete($id);
    }

    /**
     * Locate every session whose authenticated user matches $userId.
     *
     * Iterates the backend and inspects each payload. Sessions that fail
     * to deserialize are silently skipped — they're invalid and should be
     * cleaned up by GC, not reported here.
     *
     * @return list<SessionId>
     *
     * @throws CapabilityException If the backend cannot enumerate sessions.
     */
    public function findByUser(string $userId): array
    {
        $matches = [];

        foreach ($this->listAll() as $sessionId) {
            $session = $this->load($sessionId);
            if ($session === null) {
                continue;
            }

            if ($this->extractUserId($session) === $userId) {
                $matches[] = $sessionId;
            }
        }

        return $matches;
    }

    /**
     * Forcibly destroy all of $userId's sessions, optionally keeping one.
     *
     * Used by "sign out everywhere" flows and by password-change handlers
     * that want to invalidate other live sessions while leaving the
     * current one intact.
     *
     * @param SessionId|null $exceptId  Session to keep (typically the
     *                                  caller's current session ID).
     *
     * @throws CapabilityException If the backend cannot enumerate sessions.
     */
    public function destroyAllForUser(string $userId, ?SessionId $exceptId = null): void
    {
        foreach ($this->findByUser($userId) as $id) {
            if ($exceptId !== null && $id->equals($exceptId)) {
                continue;
            }
            $this->destroy($id);
        }
    }

    /**
     * Extract the authenticated user ID from a session payload.
     *
     * Default implementation reads "horde/auth/userId" — the wire layout
     * used by HordeSession. Override in a subclass for non-Horde session
     * shapes.
     */
    protected function extractUserId(Session $session): ?string
    {
        $payload = $session->toPayload();
        $value = $payload['horde']['auth/userId'] ?? null;

        return is_string($value) ? $value : null;
    }
}
