<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\SessionHandler\Test\Unit;

use Horde\SessionHandler\DefaultSession;
use Horde\SessionHandler\SessionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultSession::class)]
class DefaultSessionTest extends TestCase
{
    private function createSession(array $data = []): DefaultSession
    {
        return new DefaultSession(new SessionId('test-session'), $data);
    }

    #[Test]
    public function testNewSessionHasEmptyKeys(): void
    {
        $session = $this->createSession();
        self::assertSame([], $session->keys());
    }

    #[Test]
    public function testNewSessionIsNotDirty(): void
    {
        $session = $this->createSession();
        self::assertFalse($session->isDirty());
    }

    #[Test]
    public function testSetStoresValueAndMarksDirty(): void
    {
        $session = $this->createSession();
        $session->set('user', 'alice');
        self::assertSame('alice', $session->get('user'));
        self::assertTrue($session->isDirty());
    }

    #[Test]
    public function testGetReturnsStoredValue(): void
    {
        $session = $this->createSession();
        $session->set('count', 42);
        self::assertSame(42, $session->get('count'));
    }

    #[Test]
    public function testGetReturnsNullForMissingKey(): void
    {
        $session = $this->createSession();
        self::assertNull($session->get('nonexistent'));
    }

    #[Test]
    public function testHasReturnsTrueForExistingKey(): void
    {
        $session = $this->createSession();
        $session->set('key', 'value');
        self::assertTrue($session->has('key'));
    }

    #[Test]
    public function testHasReturnsFalseForMissingKey(): void
    {
        $session = $this->createSession();
        self::assertFalse($session->has('missing'));
    }

    #[Test]
    public function testRemoveDeletesKeyAndMarksDirty(): void
    {
        $session = $this->createSession(['existing' => 'value']);
        $session->remove('existing');
        self::assertFalse($session->has('existing'));
        self::assertTrue($session->isDirty());
    }

    #[Test]
    public function testRemoveMissingKeyDoesNotMarkDirty(): void
    {
        $session = $this->createSession();
        $session->remove('nonexistent');
        self::assertFalse($session->isDirty());
    }

    #[Test]
    public function testKeysReturnsAllKeyNames(): void
    {
        $session = $this->createSession();
        $session->set('alpha', 1);
        $session->set('beta', 2);
        $keys = $session->keys();
        sort($keys);
        self::assertSame(['alpha', 'beta'], $keys);
    }

    #[Test]
    public function testToPayloadReturnsDataArray(): void
    {
        $session = $this->createSession();
        $session->set('foo', 'bar');
        $session->set('num', 7);
        self::assertSame(['foo' => 'bar', 'num' => 7], $session->toPayload());
    }

    #[Test]
    public function testGetIdReturnsSessionId(): void
    {
        $id = new SessionId('my-session');
        $session = new DefaultSession($id);
        self::assertTrue($id->equals($session->getId()));
    }

    #[Test]
    public function testRestoredSessionHasDataAndIsNotDirty(): void
    {
        $session = $this->createSession(['name' => 'Bob', 'role' => 'admin']);

        self::assertSame(['name', 'role'], $session->keys());
        self::assertSame('Bob', $session->get('name'));
        self::assertTrue($session->has('role'));
        self::assertFalse($session->isDirty());
    }
}
