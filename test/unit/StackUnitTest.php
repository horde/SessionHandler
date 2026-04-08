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

use Horde_SessionHandler_Storage;
use Horde_SessionHandler_Storage_Stack;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Horde_SessionHandler_Storage_Stack::class)]
class StackUnitTest extends TestCase
{
    private function createStorageStub(array $methods = []): Horde_SessionHandler_Storage
    {
        $stub = $this->createStub(Horde_SessionHandler_Storage::class);
        foreach ($methods as $method => $return) {
            $stub->method($method)->willReturn($return);
        }
        return $stub;
    }

    public function testOpenCallsAllBackends(): void
    {
        $s1 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s2 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s1->expects($this->once())->method('open')->with('/tmp', 'sess')->willReturn(true);
        $s2->expects($this->once())->method('open')->with('/tmp', 'sess')->willReturn(true);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertTrue($stack->open('/tmp', 'sess'));
    }

    public function testOpenReturnsFalseIfAnyBackendFails(): void
    {
        $s1 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s1->method('open')->willReturn(false);
        $s2 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s2->method('open')->willReturn(true);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertFalse($stack->open('/tmp', 'sess'));
    }

    public function testCloseCallsAllBackends(): void
    {
        $s1 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s2 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s1->expects($this->once())->method('close')->willReturn(true);
        $s2->expects($this->once())->method('close')->willReturn(true);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertTrue($stack->close());
    }

    public function testCloseReturnsFalseIfAnyBackendFails(): void
    {
        $s1 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s1->method('close')->willReturn(true);
        $s2 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s2->method('close')->willReturn(false);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertFalse($stack->close());
    }

    public function testReadReturnsDataFromLastBackendInStack(): void
    {
        $s1 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s1->method('read')->willReturn('data-from-cache');
        $s2 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s2->method('read')->willReturn('data-from-master');

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        // Stack reads in order; last result wins
        $this->assertEquals('data-from-master', $stack->read('id'));
    }

    public function testReadStopsOnFalse(): void
    {
        $s1 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s1->expects($this->once())->method('read')->with('id')->willReturn(false);
        $s2 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s2->expects($this->never())->method('read');

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertFalse($stack->read('id'));
    }

    public function testWriteWritesInReverseOrder(): void
    {
        // Master is last in the stack array, so written first (reverse order)
        $callOrder = [];
        $s1 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s1->expects($this->once())->method('write')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'cache';
                return true;
            });
        $s2 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s2->expects($this->once())->method('write')
            ->willReturnCallback(function () use (&$callOrder) {
                $callOrder[] = 'master';
                return true;
            });

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertTrue($stack->write('id', 'data'));
        $this->assertEquals(['master', 'cache'], $callOrder);
    }

    public function testWriteReturnsFalseIfMasterFails(): void
    {
        $s1 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s1->method('write')->willReturn(true);
        // Master (last in stack) fails
        $s2 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s2->method('write')->willReturn(false);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertFalse($stack->write('id', 'data'));
    }

    public function testWriteInvalidatesCacheOnNonMasterFailure(): void
    {
        // s1 (cache) fails write, s2 (master) succeeds
        $s1 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s1->expects($this->once())->method('write')->willReturn(false);
        // When cache write fails, destroy is called to invalidate
        $s1->expects($this->once())->method('destroy')->with('id');

        $s2 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s2->method('write')->willReturn(true);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        // Returns true because master succeeded
        $this->assertTrue($stack->write('id', 'data'));
    }

    public function testDestroyReportsOnlyMasterResult(): void
    {
        // Master succeeds, cache fails — overall success
        $s1 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s1->method('destroy')->willReturn(false);
        $s2 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s2->method('destroy')->willReturn(true);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertTrue($stack->destroy('id'));
    }

    public function testDestroyReturnsFalseIfMasterFails(): void
    {
        $s1 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s1->method('destroy')->willReturn(true);
        $s2 = $this->createStub(Horde_SessionHandler_Storage::class);
        $s2->method('destroy')->willReturn(false);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertFalse($stack->destroy('id'));
    }

    public function testGcReturnsResultFromMasterOnly(): void
    {
        $s1 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s1->expects($this->once())->method('gc')->with(300)->willReturn(false);
        // Master is last — its result is returned
        $s2 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s2->expects($this->once())->method('gc')->with(300)->willReturn(true);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertTrue($stack->gc(300));
    }

    public function testGetSessionIDsDelegatestoMaster(): void
    {
        $s1 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s1->expects($this->never())->method('getSessionIDs');
        $s2 = $this->createMock(Horde_SessionHandler_Storage::class);
        $s2->expects($this->once())->method('getSessionIDs')->willReturn(['a', 'b']);

        $stack = new Horde_SessionHandler_Storage_Stack(['stack' => [$s1, $s2]]);
        $this->assertEquals(['a', 'b'], $stack->getSessionIDs());
    }
}
