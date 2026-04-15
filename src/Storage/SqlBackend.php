<?php

declare(strict_types=1);

/**
 * Copyright 2002-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author Mike Cochrane <mike@graftonhall.co.nz>
 */

namespace Horde\SessionHandler\Storage;

use DateTimeImmutable;
use Generator;
use Horde\Db\Adapter;
use Horde\Db\DbException;
use Horde\Db\Value\Binary;
use Horde\SessionHandler\AdministrativeSessionBackend;
use Horde\SessionHandler\Exception\SessionException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionMetadata;
use Horde\SessionHandler\SessionMetadataBackend;
use Horde\SessionHandler\SessionStorageBackend;

/**
 * SQL database session storage backend.
 *
 * Stores sessions in a relational database table via the Horde\Db adapter.
 * Compatible with the legacy horde_sessionhandler table schema.
 */
final class SqlBackend implements
    SessionStorageBackend,
    IterableSessionBackend,
    SessionMetadataBackend,
    AdministrativeSessionBackend
{
    public function __construct(
        private readonly Adapter $db,
        private readonly string $table = 'horde_sessionhandler',
    ) {}

    public function load(SessionId $id): ?SerializedSessionPayload
    {
        try {
            $query = sprintf(
                'SELECT session_data FROM %s WHERE session_id = ?',
                $this->table,
            );
            $columns = $this->db->columns($this->table); // @phpstan-ignore method.notFound (columns() is dispatched via __call on the adapter's schema layer)
            $data = $this->db->selectValue($query, [$id->id]);

            if ($data === null || $data === false) {
                return null;
            }

            $data = $columns['session_data']->binaryToString($data);

            return new SerializedSessionPayload($data);
        } catch (DbException) {
            return null;
        }
    }

    public function save(SessionId $id, SerializedSessionPayload $payload, DateTimeImmutable $expiresAt): void
    {
        $values = [
            'session_data' => new Binary($payload->getData()),
            'session_lastmodified' => time(),
        ];

        try {
            $query = sprintf(
                'SELECT 1 FROM %s WHERE session_id = ?',
                $this->table,
            );
            $exists = $this->db->selectValue($query, [$id->id]);

            if ($exists) {
                $this->db->updateBlob(
                    $this->table,
                    $values,
                    ['session_id = ?', [$id->id]],
                );
            } else {
                $this->db->insertBlob(
                    $this->table,
                    array_merge(['session_id' => $id->id], $values),
                    null,
                    $id->id,
                );
            }
        } catch (DbException $e) {
            throw new SessionException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function delete(SessionId $id): void
    {
        try {
            $query = sprintf(
                'DELETE FROM %s WHERE session_id = ?',
                $this->table,
            );
            $this->db->delete($query, [$id->id]);
        } catch (DbException $e) {
            throw new SessionException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    /** @return Generator<SessionId> */
    public function listSessions(): Generator
    {
        $maxLifetime = (int) ini_get('session.gc_maxlifetime');

        if ($maxLifetime <= 0) {
            $maxLifetime = 1440;
        }

        $cutoff = time() - $maxLifetime;

        try {
            $query = sprintf(
                'SELECT session_id FROM %s WHERE session_lastmodified >= ?',
                $this->table,
            );
            $ids = $this->db->selectValues($query, [$cutoff]);

            foreach ($ids as $id) {
                yield new SessionId($id);
            }
        } catch (DbException $e) {
            throw new SessionException($e->getMessage(), (int) $e->getCode(), $e);
        }
    }

    public function getMetadata(SessionId $id): ?SessionMetadata
    {
        try {
            $query = sprintf(
                'SELECT session_lastmodified FROM %s WHERE session_id = ?',
                $this->table,
            );
            $timestamp = $this->db->selectValue($query, [$id->id]);

            if ($timestamp === null || $timestamp === false) {
                return null;
            }

            $maxLifetime = (int) ini_get('session.gc_maxlifetime');

            if ($maxLifetime <= 0) {
                $maxLifetime = 1440;
            }

            $lastModifiedAt = (new DateTimeImmutable())->setTimestamp((int) $timestamp);
            $createdAt = $lastModifiedAt;
            $expiresAt = (new DateTimeImmutable())->setTimestamp((int) $timestamp + $maxLifetime);

            return new SessionMetadata($createdAt, $lastModifiedAt, $expiresAt);
        } catch (DbException) {
            return null;
        }
    }

    public function expire(SessionId $id): void
    {
        $this->delete($id);
    }
}
