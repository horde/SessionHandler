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
use Horde\SessionHandler\PhpTextSessionSerializer;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(PhpTextSessionSerializer::class)]
class PhpTextSessionSerializerTest extends TestCase
{
    private PhpTextSessionSerializer $serializer;

    protected function setUp(): void
    {
        $this->serializer = new PhpTextSessionSerializer();
    }

    #[Test]
    public function testRoundTripPreservesScalarPayload(): void
    {
        $session = new DefaultSession(new SessionId('rt-scalars'), [
            'user' => 'alice',
            'count' => 42,
            'flag' => true,
            'rate' => 1.5,
            'absent' => null,
        ]);

        $payload = $this->serializer->serialize($session);
        $result = $this->serializer->deserialize($payload);

        self::assertSame(
            ['user' => 'alice', 'count' => 42, 'flag' => true, 'rate' => 1.5, 'absent' => null],
            $result,
        );
    }

    #[Test]
    public function testRoundTripPreservesNestedArrays(): void
    {
        $session = new DefaultSession(new SessionId('rt-nested'), [
            'horde' => [
                'auth/userId' => 'alice',
                'auth_app/imp' => ['user' => 'a', 'pass' => 'b'],
            ],
            '_b' => 1700000000,
        ]);

        $payload = $this->serializer->serialize($session);
        $result = $this->serializer->deserialize($payload);

        self::assertSame(
            [
                'horde' => [
                    'auth/userId' => 'alice',
                    'auth_app/imp' => ['user' => 'a', 'pass' => 'b'],
                ],
                '_b' => 1700000000,
            ],
            $result,
        );
    }

    #[Test]
    public function testEmptyPayloadDeserializesToEmptyArray(): void
    {
        $result = $this->serializer->deserialize(new SerializedSessionPayload(''));
        self::assertSame([], $result);
    }

    #[Test]
    public function testEmptySessionSerializesToEmptyPayload(): void
    {
        $session = new DefaultSession(new SessionId('empty'), []);
        $payload = $this->serializer->serialize($session);
        self::assertTrue($payload->isEmpty());
    }

    #[Test]
    public function testWireFormatMatchesPhpDefault(): void
    {
        // Spec lock: the text format is "<key>|<serialize($value)>"
        // concatenated. Asserting the byte sequence guards against
        // accidental layout drift in either direction.
        $session = new DefaultSession(new SessionId('wire'), [
            'a' => 'x',
            'n' => 5,
        ]);
        $payload = $this->serializer->serialize($session);
        self::assertSame('a|s:1:"x";n|i:5;', $payload->getData());
    }

    #[Test]
    public function testReadsPayloadProducedByPhpSessionEncode(): void
    {
        // Output of `session_encode()` for ['user' => 'bob', 'n' => 7]
        // under session.serialize_handler = php.
        $payload = new SerializedSessionPayload('user|s:3:"bob";n|i:7;');
        $result = $this->serializer->deserialize($payload);
        self::assertSame(['user' => 'bob', 'n' => 7], $result);
    }

    #[Test]
    public function testRejectsKeyContainingPipe(): void
    {
        $session = new DefaultSession(new SessionId('bad-key'), ['a|b' => 'x']);
        $this->expectException(SerializationException::class);
        $this->serializer->serialize($session);
    }

    #[Test]
    public function testRejectsKeyContainingNullByte(): void
    {
        $session = new DefaultSession(new SessionId('bad-key2'), ["a\0b" => 'x']);
        $this->expectException(SerializationException::class);
        $this->serializer->serialize($session);
    }

    #[Test]
    public function testRejectsKeyContainingExclamation(): void
    {
        $session = new DefaultSession(new SessionId('bad-key3'), ['a!b' => 'x']);
        $this->expectException(SerializationException::class);
        $this->serializer->serialize($session);
    }

    #[Test]
    public function testRejectsMalformedPayloadWithoutPipe(): void
    {
        $payload = new SerializedSessionPayload('no-pipe-here');
        $this->expectException(SerializationException::class);
        $this->serializer->deserialize($payload);
    }

    #[Test]
    public function testRejectsMalformedSerializedValue(): void
    {
        $payload = new SerializedSessionPayload('user|not-a-serialize-payload');
        $this->expectException(SerializationException::class);
        $this->serializer->deserialize($payload);
    }

    #[Test]
    public function testPreservesGenuineSerializedFalse(): void
    {
        $session = new DefaultSession(new SessionId('false-val'), ['flag' => false]);
        $payload = $this->serializer->serialize($session);
        $result = $this->serializer->deserialize($payload);
        self::assertSame(['flag' => false], $result);
    }

    #[Test]
    public function testRoundTripBinaryPayload(): void
    {
        // Horde_Pack-style binary payloads get stored as the raw bytes
        // of a string value. Ensures the serializer doesn't choke on
        // null bytes inside string values (only inside keys is forbidden).
        $binary = "\x01\x02\x03\xff\x00data";
        $session = new DefaultSession(new SessionId('binary'), ['blob' => $binary]);

        $payload = $this->serializer->serialize($session);
        $result = $this->serializer->deserialize($payload);

        self::assertSame(['blob' => $binary], $result);
    }
}
