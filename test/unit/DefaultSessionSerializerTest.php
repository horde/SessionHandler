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
use Horde\SessionHandler\DefaultSessionSerializer;
use Horde\SessionHandler\Exception\SerializationException;
use Horde\SessionHandler\PhpSessionSerializer;
use Horde\SessionHandler\PhpTextSessionSerializer;
use Horde\SessionHandler\SessionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(DefaultSessionSerializer::class)]
class DefaultSessionSerializerTest extends TestCase
{
    #[Test]
    public function testPickPhpForPhpHandler(): void
    {
        $serializer = new DefaultSessionSerializer('php');
        self::assertInstanceOf(PhpTextSessionSerializer::class, $serializer->inner());
    }

    #[Test]
    public function testPickPhpSerializeForPhpSerializeHandler(): void
    {
        $serializer = new DefaultSessionSerializer('php_serialize');
        self::assertInstanceOf(PhpSessionSerializer::class, $serializer->inner());
    }

    #[Test]
    public function testRejectsUnsupportedHandler(): void
    {
        $this->expectException(SerializationException::class);
        new DefaultSessionSerializer('igbinary');
    }

    #[Test]
    public function testRejectsCustomHandler(): void
    {
        $this->expectException(SerializationException::class);
        new DefaultSessionSerializer('user');
    }

    #[Test]
    public function testRoundTripDelegatesToInner(): void
    {
        $serializer = new DefaultSessionSerializer('php');
        $session = new DefaultSession(new SessionId('rt'), ['k' => 'v']);

        $payload = $serializer->serialize($session);
        $result = $serializer->deserialize($payload);

        self::assertSame(['k' => 'v'], $result);
    }

    #[Test]
    public function testNullHandlerFallsBackToIniValueOrPhp(): void
    {
        // Null means auto-detect. The detected value is whatever the
        // running PHP says; under both common configurations (php or
        // php_serialize) the construction succeeds. Just assert that
        // construction does NOT throw and produces a known inner type.
        $serializer = new DefaultSessionSerializer();
        self::assertTrue(
            $serializer->inner() instanceof PhpTextSessionSerializer
            || $serializer->inner() instanceof PhpSessionSerializer,
        );
    }
}
