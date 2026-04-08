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

use Horde_SessionHandler_Exception;
use Horde_SessionHandler_Storage_External;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(Horde_SessionHandler_Storage_External::class)]
class ExternalUnitTest extends TestCase
{
    private function createExternal(array $overrides = []): Horde_SessionHandler_Storage_External
    {
        $defaults = [
            'open' => fn() => true,
            'close' => fn() => true,
            'read' => fn() => '',
            'write' => fn() => true,
            'destroy' => fn() => true,
            'gc' => fn() => true,
        ];
        return new Horde_SessionHandler_Storage_External(array_merge($defaults, $overrides));
    }

    public function testOpenDelegatesToCallback(): void
    {
        $called = false;
        $storage = $this->createExternal([
            'open' => function ($path, $name) use (&$called) {
                $called = true;
                $this->assertEquals('/tmp', $path);
                $this->assertEquals('sess', $name);
                return true;
            },
        ]);
        $this->assertTrue($storage->open('/tmp', 'sess'));
        $this->assertTrue($called);
    }

    public function testCloseDelegatesToCallback(): void
    {
        $called = false;
        $storage = $this->createExternal([
            'close' => function () use (&$called) {
                $called = true;
                return true;
            },
        ]);
        $storage->close();
        $this->assertTrue($called);
    }

    public function testReadDelegatesToCallback(): void
    {
        $storage = $this->createExternal([
            'read' => fn($id) => 'data-for-' . $id,
        ]);
        $this->assertEquals('data-for-abc', $storage->read('abc'));
    }

    public function testWriteDelegatesToCallback(): void
    {
        $receivedArgs = [];
        $storage = $this->createExternal([
            'write' => function ($id, $data) use (&$receivedArgs) {
                $receivedArgs = [$id, $data];
                return true;
            },
        ]);
        $this->assertTrue($storage->write('sid', 'session-data'));
        $this->assertEquals(['sid', 'session-data'], $receivedArgs);
    }

    public function testDestroyDelegatesToCallback(): void
    {
        $destroyedId = null;
        $storage = $this->createExternal([
            'destroy' => function ($id) use (&$destroyedId) {
                $destroyedId = $id;
                return true;
            },
        ]);
        $this->assertTrue($storage->destroy('del-me'));
        $this->assertEquals('del-me', $destroyedId);
    }

    public function testGcDelegatesToCallback(): void
    {
        $receivedLifetime = null;
        $storage = $this->createExternal([
            'gc' => function ($maxlifetime) use (&$receivedLifetime) {
                $receivedLifetime = $maxlifetime;
                return true;
            },
        ]);
        $storage->gc(600);
        $this->assertEquals(600, $receivedLifetime);
    }

    public function testGcUsesDefaultMaxlifetime(): void
    {
        $receivedLifetime = null;
        $storage = $this->createExternal([
            'gc' => function ($maxlifetime) use (&$receivedLifetime) {
                $receivedLifetime = $maxlifetime;
                return true;
            },
        ]);
        $storage->gc();
        $this->assertEquals(300, $receivedLifetime);
    }

    public function testGetSessionIDsThrowsException(): void
    {
        $storage = $this->createExternal();
        $this->expectException(Horde_SessionHandler_Exception::class);
        $this->expectExceptionMessage('Driver does not support listing session IDs');
        $storage->getSessionIDs();
    }

    public function testCallbackReturnValuesArePropagated(): void
    {
        $storage = $this->createExternal([
            'open' => fn() => false,
            'write' => fn() => false,
            'destroy' => fn() => false,
        ]);
        $this->assertFalse($storage->open('/tmp', 'sess'));
        $this->assertFalse($storage->write('id', 'data'));
        $this->assertFalse($storage->destroy('id'));
    }
}
