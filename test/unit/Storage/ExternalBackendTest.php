<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\SessionHandler\Test\unit\Storage;

use Closure;
use DateTimeImmutable;
use Horde\SessionHandler\AdministrativeSessionBackend;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\LockingSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionMetadataBackend;
use Horde\SessionHandler\SessionStorageBackend;
use Horde\SessionHandler\Storage\ExternalBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(ExternalBackend::class)]
class ExternalBackendTest extends TestCase
{
    #[Test]
    public function testLoadDelegatesToCallback(): void
    {
        $receivedId = null;
        $expectedPayload = new SerializedSessionPayload('callback-data');

        $readCallback = function (SessionId $id) use (&$receivedId, $expectedPayload): ?SerializedSessionPayload {
            $receivedId = $id;
            return $expectedPayload;
        };

        $backend = new ExternalBackend(
            Closure::fromCallable($readCallback),
            Closure::fromCallable(function (): void {}),
            Closure::fromCallable(function (): void {}),
        );

        $sessionId = new SessionId('readsess');
        $result = $backend->load($sessionId);

        self::assertSame($expectedPayload, $result);
        self::assertNotNull($receivedId);
        self::assertSame('readsess', $receivedId->id);
    }

    #[Test]
    public function testSaveDelegatesToCallback(): void
    {
        $receivedId = null;
        $receivedPayload = null;
        $receivedExpiry = null;

        $writeCallback = function (
            SessionId $id,
            SerializedSessionPayload $payload,
            DateTimeImmutable $expiresAt,
        ) use (&$receivedId, &$receivedPayload, &$receivedExpiry): void {
            $receivedId = $id;
            $receivedPayload = $payload;
            $receivedExpiry = $expiresAt;
        };

        $backend = new ExternalBackend(
            Closure::fromCallable(function (): ?SerializedSessionPayload {
                return null;
            }),
            Closure::fromCallable($writeCallback),
            Closure::fromCallable(function (): void {}),
        );

        $sessionId = new SessionId('writesess');
        $payload = new SerializedSessionPayload('write-data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $backend->save($sessionId, $payload, $expiresAt);

        self::assertNotNull($receivedId);
        self::assertSame('writesess', $receivedId->id);
        self::assertSame($payload, $receivedPayload);
        self::assertSame($expiresAt, $receivedExpiry);
    }

    #[Test]
    public function testDeleteDelegatesToCallback(): void
    {
        $receivedId = null;

        $deleteCallback = function (SessionId $id) use (&$receivedId): void {
            $receivedId = $id;
        };

        $backend = new ExternalBackend(
            Closure::fromCallable(function (): ?SerializedSessionPayload {
                return null;
            }),
            Closure::fromCallable(function (): void {}),
            Closure::fromCallable($deleteCallback),
        );

        $sessionId = new SessionId('delsess');
        $backend->delete($sessionId);

        self::assertNotNull($receivedId);
        self::assertSame('delsess', $receivedId->id);
    }

    #[Test]
    public function testImplementsOnlyStorageBackend(): void
    {
        $backend = new ExternalBackend(
            Closure::fromCallable(function (): ?SerializedSessionPayload {
                return null;
            }),
            Closure::fromCallable(function (): void {}),
            Closure::fromCallable(function (): void {}),
        );

        self::assertInstanceOf(SessionStorageBackend::class, $backend);
        self::assertNotInstanceOf(IterableSessionBackend::class, $backend);
        self::assertNotInstanceOf(SessionMetadataBackend::class, $backend);
        self::assertNotInstanceOf(AdministrativeSessionBackend::class, $backend);
        self::assertNotInstanceOf(LockingSessionBackend::class, $backend);
    }
}
