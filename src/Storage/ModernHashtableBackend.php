<?php

declare(strict_types=1);

/**
 * Copyright 2013-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author Michael Slusarz <slusarz@horde.org>
 */

namespace Horde\SessionHandler\Storage;

use DateTimeImmutable;
use Generator;
use Horde\HashTable\HashTableException;
use Horde\HashTable\LockableHashTable;
use Horde\SessionHandler\AdministrativeSessionBackend;
use Horde\SessionHandler\Exception\CapabilityException;
use Horde\SessionHandler\Exception\SessionException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionStorageBackend;

/**
 * HashTable-based session storage backend (Memcache/Redis).
 *
 * Uses the PSR-4 Horde\HashTable\LockableHashTable interface. Sessions are
 * stored as opaque blobs with a TTL derived from the expiration timestamp.
 * An optional tracking set allows enumeration of active sessions.
 *
 * This is the modern counterpart to {@see HashtableBackend}, which accepts
 * the legacy Horde_HashTable_Base & Horde_HashTable_Lock intersection. New
 * deployments select this backend via the SessionHandlerFactory; the legacy
 * backend remains for the deprecation period.
 */
final class ModernHashtableBackend implements
    SessionStorageBackend,
    IterableSessionBackend,
    AdministrativeSessionBackend
{
    /**
     * @param LockableHashTable $hashTable  HashTable instance with cross-process locking
     * @param bool              $track      Whether to track active session IDs
     * @param string            $trackKey   Key used to store the tracking set
     */
    public function __construct(
        private readonly LockableHashTable $hashTable,
        private readonly bool $track = false,
        private readonly string $trackKey = 'horde_sessions_track_ht',
    ) {}

    public function load(SessionId $id): ?SerializedSessionPayload
    {
        $result = $this->hashTable->get($id->id);

        if ($result === null) {
            return null;
        }

        return new SerializedSessionPayload($result);
    }

    public function save(SessionId $id, SerializedSessionPayload $payload, DateTimeImmutable $expiresAt): void
    {
        $ttl = max(0, $expiresAt->getTimestamp() - time());

        try {
            $this->hashTable->set($id->id, $payload->getData(), $ttl > 0 ? $ttl : null);

            if ($this->track) {
                $this->hashTable->lock($this->trackKey);

                try {
                    $ids = $this->getTrackIds();
                    $ids[$id->id] = 1;
                    $this->hashTable->set($this->trackKey, json_encode($ids));
                } finally {
                    $this->hashTable->unlock($this->trackKey);
                }
            }
        } catch (HashTableException $e) {
            throw new SessionException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function delete(SessionId $id): void
    {
        try {
            $this->hashTable->delete($id->id);

            if ($this->track) {
                $this->hashTable->lock($this->trackKey);

                try {
                    $ids = $this->getTrackIds();
                    unset($ids[$id->id]);
                    $this->hashTable->set($this->trackKey, json_encode($ids));
                } finally {
                    $this->hashTable->unlock($this->trackKey);
                }
            }
        } catch (HashTableException $e) {
            throw new SessionException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /** @return Generator<SessionId> */
    public function listSessions(): Generator
    {
        if (!$this->track) {
            throw new CapabilityException('Session tracking is not enabled; cannot list sessions');
        }

        try {
            $this->trackGC();

            $ids = $this->getTrackIds();

            foreach (array_keys($ids) as $id) {
                yield new SessionId($id);
            }
        } catch (HashTableException $e) {
            throw new SessionException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function expire(SessionId $id): void
    {
        $this->delete($id);
    }

    /**
     * Garbage-collect the tracking set by removing IDs whose
     * sessions have already expired from the HashTable.
     */
    public function trackGC(): void
    {
        try {
            $this->hashTable->lock($this->trackKey);

            try {
                $ids = $this->getTrackIds();
                $altered = false;

                foreach (array_keys($ids) as $key) {
                    if (!$this->hashTable->exists($key)) {
                        unset($ids[$key]);
                        $altered = true;
                    }
                }

                if ($altered) {
                    $this->hashTable->set($this->trackKey, json_encode($ids));
                }
            } finally {
                $this->hashTable->unlock($this->trackKey);
            }
        } catch (HashTableException) {
            // Best effort -- silently ignore tracking GC failures
        }
    }

    /**
     * Retrieve the current set of tracked session IDs.
     *
     * @return array<string, int>
     */
    private function getTrackIds(): array
    {
        $raw = $this->hashTable->get($this->trackKey);

        if ($raw === null) {
            return [];
        }

        if (!is_string($raw)) {
            return [];
        }

        $ids = json_decode($raw, true);

        if (!is_array($ids)) {
            return [];
        }

        return $ids;
    }
}
