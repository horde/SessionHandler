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

use Horde_SessionHandler;
use Horde_SessionHandler_Exception;
use Horde_SessionHandler_Storage;
use Horde_SessionHandler_Storage_File;
use Horde_Util;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Horde_SessionHandler::class)]
class HandlerTest extends TestCase
{
    private static string $dir;

    public static function setUpBeforeClass(): void
    {
        self::$dir = Horde_Util::createTempDir();
    }

    private function createHandler(
        ?Horde_SessionHandler_Storage $storage = null,
        array $params = []
    ): Horde_SessionHandler {
        $storage ??= new Horde_SessionHandler_Storage_File(['path' => self::$dir]);
        return new Horde_SessionHandler($storage, array_merge(['noset' => true], $params));
    }

    public function testOpenDelegatesToStorage(): void
    {
        $handler = $this->createHandler();
        $this->assertTrue($handler->open(self::$dir, 'test'));
    }

    public function testOpenOnlyConnectsOnce(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->expects($this->once())->method('open');
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $handler->open(self::$dir, 'test');
        $handler->open(self::$dir, 'test');
    }

    public function testOpenReturnsFalseOnException(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->expects($this->once())
            ->method('open')
            ->willThrowException(new Horde_SessionHandler_Exception('fail'));
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $this->assertFalse($handler->open(self::$dir, 'test'));
    }

    public function testCloseResetsConnection(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->expects($this->exactly(2))->method('open');
        $storage->expects($this->once())->method('close');
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);

        $handler->open(self::$dir, 'test');
        $handler->close();
        // After close, open should call storage->open again
        $handler->open(self::$dir, 'test');
    }

    public function testCloseReturnsTrueEvenOnException(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->expects($this->once())
            ->method('close')
            ->willThrowException(new Horde_SessionHandler_Exception('fail'));
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $this->assertTrue($handler->close());
    }

    public function testReadReturnsDataFromStorage(): void
    {
        $handler = $this->createHandler();
        $handler->open(self::$dir, 'test');
        $handler->write('read-test', 'hello');
        // Close and reopen to flush write
        $handler->close();
        $handler->open(self::$dir, 'test');
        // Need a fresh handler to avoid md5 signature caching
        $handler2 = $this->createHandler();
        $handler2->open(self::$dir, 'test');
        $this->assertEquals('hello', $handler2->read('read-test'));
    }

    public function testWriteSkipsWhenDataUnchanged(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->method('read')->willReturn('same-data');
        // write should NOT be called because md5 signature matches
        $storage->expects($this->never())->method('write');
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $handler->read('test-id');
        $handler->write('test-id', 'same-data');
    }

    public function testWriteProceedsWhenDataChanged(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->method('read')->willReturn('old-data');
        $storage->expects($this->once())
            ->method('write')
            ->with('test-id', 'new-data')
            ->willReturn(true);
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $handler->read('test-id');
        $this->assertTrue($handler->write('test-id', 'new-data'));
    }

    public function testWriteProceedsWhenChangedFlagSet(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->method('read')->willReturn('same-data');
        $storage->expects($this->once())
            ->method('write')
            ->with('test-id', 'same-data')
            ->willReturn(true);
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $handler->read('test-id');
        $handler->changed = true;
        $this->assertTrue($handler->write('test-id', 'same-data'));
    }

    public function testWriteReturnsFalseOnStorageFailure(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->method('read')->willReturn('old-data');
        $storage->expects($this->once())
            ->method('write')
            ->willReturn(false);
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $handler->read('test-id');
        $this->assertFalse($handler->write('test-id', 'new-data'));
    }

    public function testWriteSkipsMd5WhenNoMd5ParamSet(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->method('read')->willReturn('same-data');
        // With no_md5, write should be skipped unless changed flag is set
        $storage->expects($this->never())->method('write');
        $handler = new Horde_SessionHandler($storage, ['noset' => true, 'no_md5' => true]);
        $handler->read('test-id');
        $handler->write('test-id', 'same-data');
    }

    public function testWriteWithNoMd5AndChangedFlag(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->method('read')->willReturn('same-data');
        $storage->expects($this->once())
            ->method('write')
            ->willReturn(true);
        $handler = new Horde_SessionHandler($storage, ['noset' => true, 'no_md5' => true]);
        $handler->read('test-id');
        $handler->changed = true;
        $this->assertTrue($handler->write('test-id', 'same-data'));
    }

    public function testDestroyReturnsTrueOnSuccess(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->expects($this->once())
            ->method('destroy')
            ->with('session-123')
            ->willReturn(true);
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $this->assertTrue($handler->destroy('session-123'));
    }

    public function testDestroyReturnsFalseOnFailure(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->expects($this->once())
            ->method('destroy')
            ->willReturn(false);
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $this->assertFalse($handler->destroy('session-123'));
    }

    public function testGcDelegatesToStorage(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->expects($this->once())
            ->method('gc')
            ->with(600)
            ->willReturn(true);
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $handler->gc(600);
    }

    public function testGetSessionIDsDelegatesToStorage(): void
    {
        $storage = $this->createMock(Horde_SessionHandler_Storage::class);
        $storage->expects($this->once())
            ->method('getSessionIDs')
            ->willReturn(['id1', 'id2']);
        $handler = new Horde_SessionHandler($storage, ['noset' => true]);
        $this->assertEquals(['id1', 'id2'], $handler->getSessionIDs());
    }

    public function testGetSessionsInfoReturnsEmptyWithoutParser(): void
    {
        $handler = $this->createHandler();
        $this->assertEquals([], $handler->getSessionsInfo());
    }

    public function testGetSessionsInfoReturnsEmptyWithNonCallableParser(): void
    {
        $handler = $this->createHandler(params: ['parse' => 'not_a_function_xyz']);
        $this->assertEquals([], $handler->getSessionsInfo());
    }

    public function testGetSessionsInfoParsesSessionData(): void
    {
        $storage = new Horde_SessionHandler_Storage_File(['path' => self::$dir]);
        $handler = new Horde_SessionHandler($storage, [
            'noset' => true,
            'parse' => function (string $data) {
                if ($data === '') {
                    return false;
                }
                return ['raw' => $data];
            },
        ]);

        // Write some session data
        $storage->open(self::$dir, 'test');
        $storage->write('info-test-1', 'user=alice');
        $storage->close();

        $info = $handler->getSessionsInfo();
        $this->assertArrayHasKey('info-test-1', $info);
        $this->assertEquals(['raw' => 'user=alice'], $info['info-test-1']);
    }

    public function testGetSessionsInfoSkipsUnparsableSessions(): void
    {
        $dir = Horde_Util::createTempDir();
        $storage = new Horde_SessionHandler_Storage_File(['path' => $dir]);
        $handler = new Horde_SessionHandler($storage, [
            'noset' => true,
            'parse' => function (string $data) {
                if ($data === 'skip-me') {
                    return false;
                }
                return ['data' => $data];
            },
        ]);

        $storage->open($dir, 'test');
        $storage->write('parseable', 'good-data');
        $storage->close();
        $storage->open($dir, 'test');
        $storage->write('unparseable', 'skip-me');
        $storage->close();

        $info = $handler->getSessionsInfo();
        $this->assertArrayHasKey('parseable', $info);
        $this->assertArrayNotHasKey('unparseable', $info);
    }

    public function testPublicProperties(): void
    {
        $handler = $this->createHandler();
        $this->assertSame('', $handler->data);
        $this->assertSame('', $handler->id);
        $this->assertSame('', $handler->name);
        $this->assertFalse($handler->changed);
    }
}
