<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @category   Horde
 * @package    Horde_SessionHandler
 * @subpackage UnitTests
 * @license    http://www.horde.org/licenses/lgpl21 LGPL 2.1
 */

namespace Horde\SessionHandler\Test\Unit;

use Horde_HashTable_Exception;
use Horde_HashTable_Memory;
use Horde_SessionHandler_Exception;
use Horde_SessionHandler_Storage_Hashtable;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use Horde_HashTable_Base;

#[CoversClass(Horde_SessionHandler_Storage_Hashtable::class)]
class HashtableUnitTest extends TestCase
{
    private function createHashMock(): Horde_HashTable_Memory
    {
        return $this->getMockBuilder(Horde_HashTable_Memory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['get', 'set', 'delete', 'exists', 'lock', 'unlock'])
            ->getMock();
    }

    private function createStorage(
        Horde_HashTable_Memory $hash,
        bool $track = false
    ): Horde_SessionHandler_Storage_Hashtable {
        return new Horde_SessionHandler_Storage_Hashtable([
            'hashtable' => $hash,
            'track' => $track,
        ]);
    }

    public function testConstructorRequiresHashtable(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Horde_SessionHandler_Storage_Hashtable([]);
    }

    public function testConstructorRequiresLockingSupport(): void
    {
        $hash = $this->createStub(Horde_HashTable_Base::class);
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('locking');
        new Horde_SessionHandler_Storage_Hashtable(['hashtable' => $hash]);
    }

    public function testReadLocksAndReturnsData(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('lock')->with('sid');
        $hash->expects($this->once())->method('get')->with('sid')->willReturn('session-data');
        $hash->expects($this->never())->method('unlock');

        $storage = $this->createStorage($hash);
        $this->assertEquals('session-data', $storage->read('sid'));
    }

    public function testReadUnlocksOnMiss(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('lock')->with('sid');
        $hash->expects($this->once())->method('get')->with('sid')->willReturn(false);
        $hash->expects($this->once())->method('unlock')->with('sid');

        $storage = $this->createStorage($hash);
        $this->assertEquals('', $storage->read('sid'));
    }

    public function testReadOnlySkipsLocking(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->never())->method('lock');
        $hash->expects($this->once())->method('get')->with('sid')->willReturn('data');

        $storage = $this->createStorage($hash);
        $storage->readonly = true;
        $this->assertEquals('data', $storage->read('sid'));
    }

    public function testReadOnlyUnlocksNothingOnMiss(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->never())->method('lock');
        $hash->expects($this->never())->method('unlock');
        $hash->expects($this->once())->method('get')->with('sid')->willReturn(false);

        $storage = $this->createStorage($hash);
        $storage->readonly = true;
        $this->assertEquals('', $storage->read('sid'));
    }

    public function testCloseUnlocksCurrentSession(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('get')->willReturn('data');
        $hash->expects($this->once())->method('lock')->with('sid');
        $hash->expects($this->once())->method('unlock')->with('sid');

        $storage = $this->createStorage($hash);
        $storage->read('sid');
        $storage->close();
    }

    public function testCloseDoesNothingWithoutSession(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->never())->method('unlock');

        $storage = $this->createStorage($hash);
        $storage->close();
    }

    public function testWriteWithoutTracking(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('set')
            ->with('sid', 'data', $this->anything())
            ->willReturn(true);

        $storage = $this->createStorage($hash, track: false);
        $this->assertTrue($storage->write('sid', 'data'));
    }

    public function testWriteWithTrackingReplaceSucceeds(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('set')
            ->with('sid', 'data', $this->callback(function ($opts) {
                return !empty($opts['replace']);
            }))
            ->willReturn(true);
        $hash->expects($this->never())->method('lock');

        $storage = $this->createStorage($hash, track: true);
        $this->assertTrue($storage->write('sid', 'data'));
    }

    public function testWriteWithTrackingNewSession(): void
    {
        $hash = $this->createHashMock();
        $callCount = 0;
        $hash->expects($this->exactly(3))->method('set')
            ->willReturnCallback(function ($key, $val, $opts = []) use (&$callCount) {
                $callCount++;
                if ($callCount === 1) {
                    return false;
                }
                if ($callCount === 2) {
                    $this->assertEquals('sid', $key);
                    return true;
                }
                $this->assertEquals('horde_sessions_track_ht', $key);
                return true;
            });
        $hash->expects($this->once())->method('lock')->with('horde_sessions_track_ht');
        $hash->expects($this->once())->method('unlock')->with('horde_sessions_track_ht');
        $hash->expects($this->once())->method('get')->willReturn(false);

        $storage = $this->createStorage($hash, track: true);
        $this->assertTrue($storage->write('sid', 'data'));
    }

    public function testWriteReturnsFalseOnFailure(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('set')->willReturn(false);

        $storage = $this->createStorage($hash, track: false);
        $this->assertFalse($storage->write('sid', 'data'));
    }

    public function testDestroyDeletesAndUnlocks(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('delete')->with('sid')->willReturn(true);
        $hash->expects($this->once())->method('unlock')->with('sid');

        $storage = $this->createStorage($hash);
        $this->assertTrue($storage->destroy('sid'));
    }

    public function testDestroyReturnsFalseOnDeleteFailure(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('delete')->with('sid')->willReturn(false);
        $hash->expects($this->once())->method('unlock')->with('sid');

        $storage = $this->createStorage($hash);
        $this->assertFalse($storage->destroy('sid'));
    }

    public function testDestroyWithTrackingRemovesFromTrackList(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('delete')->willReturn(true);
        $hash->expects($this->atLeastOnce())->method('unlock');
        $hash->expects($this->once())->method('lock')->with('horde_sessions_track_ht');
        $hash->expects($this->once())->method('get')->with('horde_sessions_track_ht')
            ->willReturn(json_encode(['sid' => 1, 'other' => 1]));
        $hash->expects($this->once())->method('set')
            ->with(
                'horde_sessions_track_ht',
                $this->callback(function ($val) {
                    $decoded = json_decode($val, true);
                    return isset($decoded['other']) && !isset($decoded['sid']);
                })
            )
            ->willReturn(true);

        $storage = $this->createStorage($hash, track: true);
        $this->assertTrue($storage->destroy('sid'));
    }

    public function testGcAlwaysReturnsTrue(): void
    {
        $hash = new Horde_HashTable_Memory();
        $storage = new Horde_SessionHandler_Storage_Hashtable(['hashtable' => $hash]);
        $this->assertTrue($storage->gc(300));
        $this->assertTrue($storage->gc(-1));
    }

    public function testGetSessionIDsThrowsWithoutTracking(): void
    {
        $hash = new Horde_HashTable_Memory();
        $storage = new Horde_SessionHandler_Storage_Hashtable(['hashtable' => $hash]);
        $this->expectException(Horde_SessionHandler_Exception::class);
        $storage->getSessionIDs();
    }

    public function testGetSessionIDsReturnsTrackedIds(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->atLeastOnce())->method('lock');
        $hash->expects($this->atLeastOnce())->method('unlock');
        $hash->expects($this->atLeastOnce())->method('get')->willReturn(json_encode(['id1' => 1, 'id2' => 1]));
        $hash->expects($this->atLeastOnce())->method('exists')->willReturn(true);

        $storage = $this->createStorage($hash, track: true);
        $ids = $storage->getSessionIDs();
        sort($ids);
        $this->assertEquals(['id1', 'id2'], $ids);
    }

    public function testTrackGCRemovesExpiredSessions(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->atLeastOnce())->method('lock');
        $hash->expects($this->atLeastOnce())->method('unlock');
        $hash->expects($this->atLeastOnce())->method('get')->with('horde_sessions_track_ht')
            ->willReturn(json_encode(['alive' => 1, 'dead' => 1]));
        $hash->expects($this->atLeastOnce())->method('exists')->willReturnCallback(function ($key) {
            return $key === 'alive';
        });
        $hash->expects($this->once())->method('set')
            ->with(
                'horde_sessions_track_ht',
                $this->callback(function ($val) {
                    $decoded = json_decode($val, true);
                    return isset($decoded['alive']) && !isset($decoded['dead']);
                })
            )
            ->willReturn(true);

        $storage = $this->createStorage($hash, track: true);
        $storage->trackGC();
    }

    public function testTrackGCHandlesExceptionGracefully(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('lock')
            ->willThrowException(new Horde_HashTable_Exception('fail'));

        $storage = $this->createStorage($hash, track: true);
        $storage->trackGC();
        $this->assertTrue(true);
    }

    public function testTrackGCNoOpWhenNoTrackedIds(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('lock');
        $hash->expects($this->once())->method('unlock');
        $hash->expects($this->once())->method('get')->willReturn(false);
        $hash->expects($this->never())->method('set');

        $storage = $this->createStorage($hash, track: true);
        $storage->trackGC();
    }

    public function testTrackGCNoOpWhenAllSessionsAlive(): void
    {
        $hash = $this->createHashMock();
        $hash->expects($this->once())->method('lock');
        $hash->expects($this->once())->method('unlock');
        $hash->expects($this->once())->method('get')->willReturn(json_encode(['a' => 1, 'b' => 1]));
        $hash->expects($this->atLeastOnce())->method('exists')->willReturn(true);
        $hash->expects($this->never())->method('set');

        $storage = $this->createStorage($hash, track: true);
        $storage->trackGC();
    }
}
