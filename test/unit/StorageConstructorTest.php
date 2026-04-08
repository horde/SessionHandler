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
use Horde_SessionHandler_Storage_External;
use Horde_SessionHandler_Storage_File;
use Horde_SessionHandler_Storage_Stack;
use InvalidArgumentException;
use LogicException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Horde_SessionHandler_Exception;

#[CoversClass(Horde_SessionHandler_Storage::class)]
#[CoversClass(Horde_SessionHandler_Storage_File::class)]
#[CoversClass(Horde_SessionHandler_Storage_External::class)]
#[CoversClass(Horde_SessionHandler_Storage_Stack::class)]
class StorageConstructorTest extends TestCase
{
    public function testFileStorageRequiresPath(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing path parameter');
        new Horde_SessionHandler_Storage_File();
    }

    public function testFileStorageTrimsTrailingSlash(): void
    {
        $storage = new Horde_SessionHandler_Storage_File(['path' => '/tmp/test/']);
        // The storage was created successfully - path was accepted and trimmed
        $this->assertInstanceOf(Horde_SessionHandler_Storage_File::class, $storage);
    }

    public function testExternalStorageRequiresAllCallbacks(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing parameter: open');
        new Horde_SessionHandler_Storage_External([
            'close' => fn() => true,
            'read' => fn() => '',
            'write' => fn() => true,
            'destroy' => fn() => true,
            'gc' => fn() => true,
        ]);
    }

    #[DataProvider('missingExternalCallbackProvider')]
    public function testExternalStorageRejectsMissingCallback(string $missing): void
    {
        $callbacks = [
            'open' => fn() => true,
            'close' => fn() => true,
            'read' => fn() => '',
            'write' => fn() => true,
            'destroy' => fn() => true,
            'gc' => fn() => true,
        ];
        unset($callbacks[$missing]);

        $this->expectException(InvalidArgumentException::class);
        new Horde_SessionHandler_Storage_External($callbacks);
    }

    public static function missingExternalCallbackProvider(): array
    {
        return [
            'missing open' => ['open'],
            'missing close' => ['close'],
            'missing read' => ['read'],
            'missing write' => ['write'],
            'missing destroy' => ['destroy'],
            'missing gc' => ['gc'],
        ];
    }

    public function testStackStorageRequiresStack(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Missing stack parameter');
        new Horde_SessionHandler_Storage_Stack();
    }

    public function testStorageSleepThrowsLogicException(): void
    {
        $storage = new Horde_SessionHandler_Storage_File(['path' => '/tmp']);
        $this->expectException(LogicException::class);
        $this->expectExceptionMessage('do not support serialization');
        $storage->__sleep();
    }

    public function testStorageReadonlyProperty(): void
    {
        $storage = new Horde_SessionHandler_Storage_File(['path' => '/tmp']);
        $this->assertFalse($storage->readonly);
        $storage->readonly = true;
        $this->assertTrue($storage->readonly);
    }

    public function testExternalGetSessionIDsThrowsException(): void
    {
        $storage = new Horde_SessionHandler_Storage_External([
            'open' => fn() => true,
            'close' => fn() => true,
            'read' => fn() => '',
            'write' => fn() => true,
            'destroy' => fn() => true,
            'gc' => fn() => true,
        ]);
        $this->expectException(Horde_SessionHandler_Exception::class);
        $storage->getSessionIDs();
    }
}
