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
use Horde\SessionHandler\Exception\SerializationException;
use Horde\SessionHandler\PhpSessionSerializer;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpSessionSerializer::class)]
class PhpSessionSerializerTest extends TestCase
{
    private PhpSessionSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PhpSessionSerializer();
    }

    #[Test]
    public function testRoundTripPreservesPayload(): void
    {
        $session = new DefaultSession(new SessionId('round-trip'), [
            'user' => 'alice',
            'count' => 5,
        ]);

        $payload = $this->serializer->serialize($session);
        $result = $this->serializer->deserialize($payload);

        self::assertSame(['user' => 'alice', 'count' => 5], $result);
    }

    #[Test]
    public function testEmptyPayloadDeserializesToEmptyArray(): void
    {
        $payload = new SerializedSessionPayload('');
        $result = $this->serializer->deserialize($payload);
        self::assertSame([], $result);
    }

    #[Test]
    public function testInvalidPayloadThrowsSerializationException(): void
    {
        $payload = new SerializedSessionPayload('not-valid-serialized-data');
        $this->expectException(SerializationException::class);
        $this->serializer->deserialize($payload);
    }

    #[Test]
    public function testNonArrayPayloadThrowsSerializationException(): void
    {
        $payload = new SerializedSessionPayload(serialize('just a string'));
        $this->expectException(SerializationException::class);
        $this->serializer->deserialize($payload);
    }
}
