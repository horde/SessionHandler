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
use Horde_HashTable_Base;
use Horde_HashTable_Lock;
use Horde\SessionHandler\AdministrativeSessionBackend;
use Horde\SessionHandler\Exception\CapabilityException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\LockingSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionMetadataBackend;
use Horde\SessionHandler\SessionStorageBackend;
use Horde\SessionHandler\Storage\HashtableBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Concrete test double that extends Horde_HashTable_Base and implements
 * Horde_HashTable_Lock so that the magic __get('locking') returns true.
 */
class LockingHashTableStub extends Horde_HashTable_Base implements Horde_HashTable_Lock
{
    public function __construct()
    {
        // Skip parent to avoid side effects
    }

    protected function _delete($keys)
    {
        return true;
    }

    protected function _exists($keys)
    {
        return [];
    }

    protected function _get($keys)
    {
        return [];
    }

    protected function _set($key, $val, $opts)
    {
        return true;
    }

    public function clear() {}

    public function lock($key) {}

    public function unlock($key) {}

    public function serialize()
    {
        return '';
    }

    public function unserialize($data) {}

    public function __serialize(): array
    {
        return [];
    }

    public function __unserialize(array $data): void {}
}

/**
 * Concrete test double without Horde_HashTable_Lock so __get('locking') returns false.
 */
class NonLockingHashTableStub extends Horde_HashTable_Base
{
    public function __construct() {}

    protected function _delete($keys)
    {
        return true;
    }

    protected function _exists($keys)
    {
        return [];
    }

    protected function _get($keys)
    {
        return [];
    }

    protected function _set($key, $val, $opts)
    {
        return true;
    }

    public function clear() {}

    public function serialize()
    {
        return '';
    }

    public function unserialize($data) {}

    public function __serialize(): array
    {
        return [];
    }

    public function __unserialize(array $data): void {}
}

#[CoversClass(HashtableBackend::class)]
class HashtableBackendTest extends TestCase
{
    /**
     * Build a locking hash table mock.
     *
     * Uses LockingHashTableStub which implements Horde_HashTable_Lock,
     * so __get('locking') returns true via the real __get() method.
     *
     * @return LockingHashTableStub&\PHPUnit\Framework\MockObject\MockObject
     */
    private function createMockHashTable(): Horde_HashTable_Base
    {
        return $this->getMockBuilder(LockingHashTableStub::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'set', 'delete', 'exists', 'lock', 'unlock', 'clear'])
            ->getMock();
    }

    #[Test]
    public function testConstructorThrowsWhenLockingDisabled(): void
    {
        $hashTable = new NonLockingHashTableStub();

        $this->expectException(\TypeError::class);

        new HashtableBackend($hashTable);
    }

    #[Test]
    public function testLoadReturnsNullWhenNotFound(): void
    {
        $hashTable = $this->createMockHashTable();
        $hashTable->expects(self::once())->method('get')->with('sess123')->willReturn(false);

        $backend = new HashtableBackend($hashTable);
        $result = $backend->load(new SessionId('sess123'));

        self::assertNull($result);
    }

    #[Test]
    public function testLoadReturnsPayloadWhenFound(): void
    {
        $hashTable = $this->createMockHashTable();
        $hashTable->expects(self::once())->method('get')->with('sess123')->willReturn('serialized-data');

        $backend = new HashtableBackend($hashTable);
        $result = $backend->load(new SessionId('sess123'));

        self::assertNotNull($result);
        self::assertInstanceOf(SerializedSessionPayload::class, $result);
        self::assertSame('serialized-data', $result->getData());
    }

    #[Test]
    public function testSaveSetsWithTimeout(): void
    {
        $hashTable = $this->createMockHashTable();

        $hashTable->expects(self::once())
            ->method('set')
            ->with(
                'sess-save',
                'payload-data',
                self::callback(function (array $opts): bool {
                    return isset($opts['timeout']) && $opts['timeout'] > 0;
                }),
            );

        $backend = new HashtableBackend($hashTable);
        $backend->save(
            new SessionId('sess-save'),
            new SerializedSessionPayload('payload-data'),
            new DateTimeImmutable('+1 hour'),
        );
    }

    #[Test]
    public function testSaveUpdatesTrackingWhenEnabled(): void
    {
        $hashTable = $this->createMockHashTable();

        // First set call: session data. Second set call: tracking key.
        $hashTable->expects(self::exactly(2))
            ->method('set');

        $hashTable->expects(self::once())
            ->method('lock')
            ->with('horde_sessions_track_ht');

        $hashTable->expects(self::once())
            ->method('unlock')
            ->with('horde_sessions_track_ht');

        // get() for tracking key returns empty set initially
        $hashTable->method('get')
            ->willReturn(false);

        $backend = new HashtableBackend($hashTable, track: true);
        $backend->save(
            new SessionId('tracked-sess'),
            new SerializedSessionPayload('data'),
            new DateTimeImmutable('+1 hour'),
        );
    }

    #[Test]
    public function testDeleteRemovesSession(): void
    {
        $hashTable = $this->createMockHashTable();

        $hashTable->expects(self::once())
            ->method('delete')
            ->with('del-sess');

        $backend = new HashtableBackend($hashTable);
        $backend->delete(new SessionId('del-sess'));
    }

    #[Test]
    public function testDeleteUpdatesTrackingWhenEnabled(): void
    {
        $hashTable = $this->createMockHashTable();

        $hashTable->expects(self::once())
            ->method('delete')
            ->with('del-tracked');

        $hashTable->expects(self::once())
            ->method('lock')
            ->with('horde_sessions_track_ht');

        $hashTable->expects(self::once())
            ->method('unlock')
            ->with('horde_sessions_track_ht');

        // Tracking set contains the key to delete
        $hashTable->method('get')
            ->willReturn(json_encode(['del-tracked' => 1, 'other-sess' => 1]));

        // set() called to store updated tracking set
        $hashTable->expects(self::once())
            ->method('set')
            ->with(
                'horde_sessions_track_ht',
                self::callback(function (string $val): bool {
                    $decoded = json_decode($val, true);
                    // del-tracked should be removed, other-sess should remain
                    return !isset($decoded['del-tracked']) && isset($decoded['other-sess']);
                }),
            );

        $backend = new HashtableBackend($hashTable, track: true);
        $backend->delete(new SessionId('del-tracked'));
    }

    #[Test]
    public function testListSessionsThrowsWhenTrackingDisabled(): void
    {
        $hashTable = $this->createMockHashTable();
        $hashTable->expects(self::never())->method('get');

        $backend = new HashtableBackend($hashTable, track: false);

        $this->expectException(CapabilityException::class);

        // Generator won't execute until iterated
        iterator_to_array($backend->listSessions());
    }

    #[Test]
    public function testListSessionsYieldsTrackedIds(): void
    {
        $hashTable = $this->createMockHashTable();

        $trackingData = json_encode(['sess-a' => 1, 'sess-b' => 1, 'sess-c' => 1]);

        // get() called during trackGC and getTrackIds
        $hashTable->expects(self::atLeastOnce())
            ->method('get')
            ->willReturn($trackingData);

        // All sessions exist (not expired)
        $hashTable->expects(self::exactly(3))
            ->method('exists')
            ->willReturn(true);

        $backend = new HashtableBackend($hashTable, track: true);

        $ids = [];
        foreach ($backend->listSessions() as $sessionId) {
            self::assertInstanceOf(SessionId::class, $sessionId);
            $ids[] = $sessionId->id;
        }

        sort($ids);
        self::assertSame(['sess-a', 'sess-b', 'sess-c'], $ids);
    }

    #[Test]
    public function testExpireDelegatesToDelete(): void
    {
        $hashTable = $this->createMockHashTable();

        $hashTable->expects(self::once())
            ->method('delete')
            ->with('expire-me');

        $backend = new HashtableBackend($hashTable);
        $backend->expire(new SessionId('expire-me'));
    }

    #[Test]
    public function testTrackGCPrunesExpiredIds(): void
    {
        $hashTable = $this->createMockHashTable();

        $trackingData = json_encode(['alive' => 1, 'dead-a' => 1, 'dead-b' => 1]);

        // get() returns the tracking set
        $hashTable->method('get')
            ->willReturn($trackingData);

        // exists(): alive => true, dead-a => false, dead-b => false
        $hashTable->method('exists')
            ->willReturnCallback(function (string $key): bool {
                return $key === 'alive';
            });

        // The pruned set should only contain 'alive'
        $hashTable->expects(self::once())
            ->method('set')
            ->with(
                'horde_sessions_track_ht',
                self::callback(function (string $val): bool {
                    $decoded = json_decode($val, true);
                    return isset($decoded['alive'])
                        && !isset($decoded['dead-a'])
                        && !isset($decoded['dead-b']);
                }),
            );

        $backend = new HashtableBackend($hashTable, track: true);
        $backend->trackGC();
    }

    #[Test]
    public function testImplementsCorrectInterfaces(): void
    {
        $hashTable = $this->createMockHashTable();
        $hashTable->expects(self::never())->method('get');

        $backend = new HashtableBackend($hashTable);

        self::assertInstanceOf(SessionStorageBackend::class, $backend);
        self::assertInstanceOf(IterableSessionBackend::class, $backend);
        self::assertInstanceOf(AdministrativeSessionBackend::class, $backend);
        self::assertNotInstanceOf(SessionMetadataBackend::class, $backend);
        self::assertNotInstanceOf(LockingSessionBackend::class, $backend);
    }
}
