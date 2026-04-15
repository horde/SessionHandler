<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\SessionHandler\Test\unit\Storage;

use DateTimeImmutable;
use Horde\Db\Adapter;
use Horde\Db\DbException;
use Horde\SessionHandler\AdministrativeSessionBackend;
use Horde\SessionHandler\Exception\SessionException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\LockingSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionMetadata;
use Horde\SessionHandler\SessionMetadataBackend;
use Horde\SessionHandler\SessionStorageBackend;
use Horde\SessionHandler\Storage\SqlBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;

/**
 * Test-local interface that extends Adapter with the columns() method.
 *
 * In the real Horde\Db stack, columns() lives on the Schema layer and
 * is exposed by concrete adapters via __call delegation. Since it is
 * not part of the Adapter interface, PHPUnit cannot stub it on a plain
 * Adapter mock. This narrow sub-interface makes it mockable.
 */
interface TestableDbAdapter extends Adapter
{
    /** @return array<string, object> */
    public function columns(string $tableName, ?string $name = null): array;
}

#[CoversClass(SqlBackend::class)]
class SqlBackendTest extends TestCase
{
    private TestableDbAdapter&Stub $db;
    private SqlBackend $backend;

    protected function setUp(): void
    {
        $this->db = $this->createStub(TestableDbAdapter::class);
        $this->backend = new SqlBackend($this->db);
    }

    #[Test]
    public function testLoadReturnsNullWhenNotFound(): void
    {
        $column = new class {
            public function binaryToString(mixed $value): string
            {
                return (string) $value;
            }
        };

        $this->db->method('columns')
            ->willReturn(['session_data' => $column]);

        $this->db->method('selectValue')
            ->willReturn(null);

        $result = $this->backend->load(new SessionId('sess123'));

        self::assertNull($result);
    }

    #[Test]
    public function testLoadReturnsPayloadWhenFound(): void
    {
        $column = new class {
            public function binaryToString(mixed $value): string
            {
                return (string) $value;
            }
        };

        $this->db->method('columns')
            ->willReturn(['session_data' => $column]);

        $this->db->method('selectValue')
            ->willReturn('serialized-session-data');

        $result = $this->backend->load(new SessionId('sess123'));

        self::assertNotNull($result);
        self::assertInstanceOf(SerializedSessionPayload::class, $result);
        self::assertSame('serialized-session-data', $result->getData());
    }

    #[Test]
    public function testLoadReturnsNullOnDbException(): void
    {
        $this->db->method('columns')
            ->willThrowException(new DbException('Connection lost'));

        $result = $this->backend->load(new SessionId('sess123'));

        self::assertNull($result);
    }

    #[Test]
    public function testSaveInsertsNewSession(): void
    {
        $db = $this->createMock(TestableDbAdapter::class);
        $backend = new SqlBackend($db);

        $id = new SessionId('newsess');
        $payload = new SerializedSessionPayload('new-data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        // selectValue returns null -> session does not exist
        $db->method('selectValue')
            ->willReturn(null);

        $db->expects(self::once())
            ->method('insertBlob')
            ->with(
                'horde_sessionhandler',
                self::callback(function (array $fields) {
                    return $fields['session_id'] === 'newsess'
                        && isset($fields['session_lastmodified']);
                }),
                null,
                'newsess',
            );

        $backend->save($id, $payload, $expiresAt);
    }

    #[Test]
    public function testSaveUpdatesExistingSession(): void
    {
        $db = $this->createMock(TestableDbAdapter::class);
        $backend = new SqlBackend($db);

        $id = new SessionId('existing');
        $payload = new SerializedSessionPayload('updated-data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        // selectValue returns 1 -> session exists
        $db->method('selectValue')
            ->willReturn(1);

        $db->expects(self::once())
            ->method('updateBlob')
            ->with(
                'horde_sessionhandler',
                self::callback(function (array $fields) {
                    return isset($fields['session_data'])
                        && isset($fields['session_lastmodified']);
                }),
                ['session_id = ?', ['existing']],
            );

        $backend->save($id, $payload, $expiresAt);
    }

    #[Test]
    public function testSaveWrapsDbException(): void
    {
        $id = new SessionId('failsess');
        $payload = new SerializedSessionPayload('fail-data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $this->db->method('selectValue')
            ->willThrowException(new DbException('DB error'));

        $this->expectException(SessionException::class);

        $this->backend->save($id, $payload, $expiresAt);
    }

    #[Test]
    public function testDeleteRemovesSession(): void
    {
        $db = $this->createMock(TestableDbAdapter::class);
        $backend = new SqlBackend($db);

        $id = new SessionId('deleteme');

        $db->expects(self::once())
            ->method('delete')
            ->with(
                'DELETE FROM horde_sessionhandler WHERE session_id = ?',
                ['deleteme'],
            );

        $backend->delete($id);
    }

    #[Test]
    public function testListSessionsYieldsSessionIds(): void
    {
        $this->db->method('selectValues')
            ->willReturn(['sess1', 'sess2', 'sess3']);

        $ids = [];
        foreach ($this->backend->listSessions() as $sessionId) {
            self::assertInstanceOf(SessionId::class, $sessionId);
            $ids[] = $sessionId->id;
        }

        self::assertSame(['sess1', 'sess2', 'sess3'], $ids);
    }

    #[Test]
    public function testGetMetadataReturnsMetadata(): void
    {
        $timestamp = time() - 60;

        $this->db->method('selectValue')
            ->willReturn($timestamp);

        $result = $this->backend->getMetadata(new SessionId('metasess'));

        self::assertNotNull($result);
        self::assertInstanceOf(SessionMetadata::class, $result);
        self::assertSame($timestamp, $result->lastModifiedAt->getTimestamp());
        self::assertSame($timestamp, $result->createdAt->getTimestamp());
        self::assertGreaterThan($timestamp, $result->expiresAt->getTimestamp());
    }

    #[Test]
    public function testGetMetadataReturnsNullWhenNotFound(): void
    {
        $this->db->method('selectValue')
            ->willReturn(null);

        $result = $this->backend->getMetadata(new SessionId('nosess'));

        self::assertNull($result);
    }

    #[Test]
    public function testExpireDelegatesToDelete(): void
    {
        $db = $this->createMock(TestableDbAdapter::class);
        $backend = new SqlBackend($db);

        $id = new SessionId('expireme');

        $db->expects(self::once())
            ->method('delete')
            ->with(
                'DELETE FROM horde_sessionhandler WHERE session_id = ?',
                ['expireme'],
            );

        $backend->expire($id);
    }

    #[Test]
    public function testImplementsCorrectInterfaces(): void
    {
        self::assertInstanceOf(SessionStorageBackend::class, $this->backend);
        self::assertInstanceOf(IterableSessionBackend::class, $this->backend);
        self::assertInstanceOf(SessionMetadataBackend::class, $this->backend);
        self::assertInstanceOf(AdministrativeSessionBackend::class, $this->backend);
        self::assertNotInstanceOf(LockingSessionBackend::class, $this->backend);
    }
}
