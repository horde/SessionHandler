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
use Horde_HashTable_Base;
use Horde_HashTable_Exception;
use Horde_HashTable_Lock;
use Horde\SessionHandler\AdministrativeSessionBackend;
use Horde\SessionHandler\Exception\CapabilityException;
use Horde\SessionHandler\Exception\SessionException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionStorageBackend;

/**
 * HashTable-based session storage backend (Memcache/Redis/Memory).
 *
 * Ported from the legacy Horde_SessionHandler_Storage_Hashtable driver.
 * Sessions are stored as opaque blobs with a TTL derived from the
 * expiration timestamp. An optional tracking set allows enumeration
 * of active sessions.
 */
final class HashtableBackend implements
    SessionStorageBackend,
    IterableSessionBackend,
    AdministrativeSessionBackend
{
    /**
     * @param Horde_HashTable_Base&Horde_HashTable_Lock $hashTable  A HashTable instance with locking support
     * @param bool                                      $track      Whether to track active session IDs
     * @param string                                    $trackKey   Key used to store the tracking set
     */
    public function __construct(
        private readonly Horde_HashTable_Base&Horde_HashTable_Lock $hashTable,
        private readonly bool $track = false,
        private readonly string $trackKey = 'horde_sessions_track_ht',
    ) {}

    public function load(SessionId $id): ?SerializedSessionPayload
    {
        $result = $this->hashTable->get($id->id);

        if ($result === false) {
            return null;
        }

        return new SerializedSessionPayload($result);
    }

    public function save(SessionId $id, SerializedSessionPayload $payload, DateTimeImmutable $expiresAt): void
    {
        $timeout = max(0, $expiresAt->getTimestamp() - time());

        try {
            $this->hashTable->set($id->id, $payload->getData(), ['timeout' => $timeout]);

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
        } catch (Horde_HashTable_Exception $e) {
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
        } catch (Horde_HashTable_Exception $e) {
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
        } catch (Horde_HashTable_Exception $e) {
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
        } catch (Horde_HashTable_Exception) {
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

        if ($raw === false) {
            return [];
        }

        $ids = json_decode($raw, true);

        if (!is_array($ids)) {
            return [];
        }

        return $ids;
    }
}
