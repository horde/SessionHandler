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
use Horde\SessionHandler\Exception\SessionException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionStorageBackend;
use Horde\SessionHandler\Storage\StackBackend;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(StackBackend::class)]
class StackBackendTest extends TestCase
{
    private function createMockBackend(): SessionStorageBackend
    {
        return $this->createMock(SessionStorageBackend::class);
    }

    private function createStubBackend(): SessionStorageBackend
    {
        return $this->createStub(SessionStorageBackend::class);
    }

    #[Test]
    public function testConstructorThrowsWhenEmpty(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new StackBackend();
    }

    #[Test]
    public function testLoadReturnsFirstNonNullResult(): void
    {
        $payload = new SerializedSessionPayload('cached-data');
        $id = new SessionId('sess1');

        $cache = $this->createMockBackend();
        $cache->expects(self::once())
            ->method('load')
            ->with($id)
            ->willReturn($payload);

        $master = $this->createMockBackend();
        $master->expects(self::never())
            ->method('load');

        $stack = new StackBackend($cache, $master);
        $result = $stack->load($id);

        self::assertNotNull($result);
        self::assertSame('cached-data', $result->getData());
    }

    #[Test]
    public function testLoadFallsThroughToLaterBackend(): void
    {
        $payload = new SerializedSessionPayload('master-data');
        $id = new SessionId('sess2');

        $cache = $this->createStubBackend();
        $cache->method('load')->willReturn(null);

        $master = $this->createMockBackend();
        $master->expects(self::once())
            ->method('load')
            ->with($id)
            ->willReturn($payload);

        $stack = new StackBackend($cache, $master);
        $result = $stack->load($id);

        self::assertNotNull($result);
        self::assertSame('master-data', $result->getData());
    }

    #[Test]
    public function testLoadReturnsNullWhenAllReturnNull(): void
    {
        $id = new SessionId('missing');

        $backendA = $this->createStubBackend();
        $backendA->method('load')->willReturn(null);

        $backendB = $this->createStubBackend();
        $backendB->method('load')->willReturn(null);

        $stack = new StackBackend($backendA, $backendB);

        self::assertNull($stack->load($id));
    }

    #[Test]
    public function testSaveWritesToAllBackendsInReverse(): void
    {
        $id = new SessionId('save-test');
        $payload = new SerializedSessionPayload('data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $callOrder = [];

        $cache = $this->createMockBackend();
        $cache->expects(self::once())
            ->method('save')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'cache';
            });

        $master = $this->createMockBackend();
        $master->expects(self::once())
            ->method('save')
            ->willReturnCallback(function () use (&$callOrder): void {
                $callOrder[] = 'master';
            });

        // Stack: [cache, master] -- master is last, so it's the "master"
        $stack = new StackBackend($cache, $master);
        $stack->save($id, $payload, $expiresAt);

        // Master (last) should be written first (reverse iteration)
        self::assertSame(['master', 'cache'], $callOrder);
    }

    #[Test]
    public function testSaveThrowsOnMasterFailure(): void
    {
        $id = new SessionId('fail-master');
        $payload = new SerializedSessionPayload('data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $cache = $this->createMockBackend();
        $cache->expects(self::never())
            ->method('save');

        $master = $this->createStubBackend();
        $master->method('save')
            ->willThrowException(new SessionException('Master write failed'));

        $stack = new StackBackend($cache, $master);

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Master write failed');

        $stack->save($id, $payload, $expiresAt);
    }

    #[Test]
    public function testSaveInvalidatesCacheOnNonMasterFailure(): void
    {
        $id = new SessionId('cache-fail');
        $payload = new SerializedSessionPayload('data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $cache = $this->createMockBackend();
        $cache->method('save')
            ->willThrowException(new SessionException('Cache write failed'));

        // When non-master fails, delete() is called on it to invalidate
        $cache->expects(self::once())
            ->method('delete')
            ->with($id);

        $master = $this->createMockBackend();
        $master->expects(self::once())
            ->method('save');

        $stack = new StackBackend($cache, $master);

        // Should NOT throw -- only master failure is fatal
        $stack->save($id, $payload, $expiresAt);
    }

    #[Test]
    public function testDeleteRemovesFromAllBackends(): void
    {
        $id = new SessionId('del-all');

        $cache = $this->createMockBackend();
        $cache->expects(self::once())
            ->method('delete')
            ->with($id);

        $master = $this->createMockBackend();
        $master->expects(self::once())
            ->method('delete')
            ->with($id);

        $stack = new StackBackend($cache, $master);
        $stack->delete($id);
    }

    #[Test]
    public function testDeleteThrowsOnMasterFailure(): void
    {
        $id = new SessionId('del-fail');

        $cache = $this->createMockBackend();
        $cache->expects(self::once())
            ->method('delete');

        $master = $this->createStubBackend();
        $master->method('delete')
            ->willThrowException(new SessionException('Master delete failed'));

        $stack = new StackBackend($cache, $master);

        $this->expectException(SessionException::class);
        $this->expectExceptionMessage('Master delete failed');

        $stack->delete($id);
    }

    #[Test]
    public function testImplementsOnlyStorageBackend(): void
    {
        $backend = $this->createStubBackend();
        $stack = new StackBackend($backend);

        self::assertInstanceOf(SessionStorageBackend::class, $stack);
        self::assertNotInstanceOf(IterableSessionBackend::class, $stack);
    }
}
