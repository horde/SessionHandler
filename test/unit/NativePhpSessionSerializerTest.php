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
use Horde\SessionHandler\NativePhpSessionSerializer;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * Tests for NativePhpSessionSerializer.
 *
 * These tests require an active PHP session (session_decode/session_encode
 * only work when a session is active). The test manages its own session
 * lifecycle.
 */
#[CoversClass(NativePhpSessionSerializer::class)]
class NativePhpSessionSerializerTest extends TestCase
{
    private ?string $originalSessionId = null;
    private array $originalSession = [];
    private bool $sessionStartedByTest = false;

    protected function setUp(): void
    {
        // Save original session state
        $this->originalSession = $_SESSION ?? [];

        if (session_status() !== PHP_SESSION_ACTIVE) {
            // No active session — start one for the test
            ini_set('session.use_cookies', '0');
            session_start();
            $this->sessionStartedByTest = true;
        }

        $this->originalSessionId = session_id();
    }

    protected function tearDown(): void
    {
        // Restore original session state
        $_SESSION = $this->originalSession;

        if ($this->sessionStartedByTest) {
            session_write_close();
        }
    }

    #[Test]
    public function testDeserializeEmptyPayload(): void
    {
        $serializer = new NativePhpSessionSerializer();
        $payload = new SerializedSessionPayload('');
        self::assertSame([], $serializer->deserialize($payload));
    }

    #[Test]
    public function testDeserializeSimplePayload(): void
    {
        $serializer = new NativePhpSessionSerializer();

        // Build a native PHP session payload
        $old = $_SESSION;
        $_SESSION = ['name' => 'alice', 'count' => 42];
        $encoded = session_encode();
        $_SESSION = $old;

        $payload = new SerializedSessionPayload($encoded);
        $result = $serializer->deserialize($payload);

        self::assertSame('alice', $result['name']);
        self::assertSame(42, $result['count']);
    }

    #[Test]
    public function testDeserializeTwoLevelPayload(): void
    {
        $serializer = new NativePhpSessionSerializer();

        // Simulate Horde's $_SESSION structure
        $old = $_SESSION;
        $_SESSION = [
            '_b' => 1700000000,
            'horde' => [
                'auth/userId' => 'alice',
                'auth/browser' => 'Firefox',
                'auth/remoteAddr' => '10.0.0.1',
                'auth/timestamp' => 1700000000,
            ],
            'imp' => [
                'mailbox' => 'INBOX',
            ],
        ];
        $encoded = session_encode();
        $_SESSION = $old;

        $payload = new SerializedSessionPayload($encoded);
        $result = $serializer->deserialize($payload);

        self::assertSame(1700000000, $result['_b']);
        self::assertIsArray($result['horde']);
        self::assertSame('alice', $result['horde']['auth/userId']);
        self::assertSame('Firefox', $result['horde']['auth/browser']);
        self::assertIsArray($result['imp']);
        self::assertSame('INBOX', $result['imp']['mailbox']);
    }

    #[Test]
    public function testSerializeProducesDecodableOutput(): void
    {
        $serializer = new NativePhpSessionSerializer();

        $session = new DefaultSession(new SessionId('test-id'), [
            'user' => 'bob',
            'role' => 'admin',
        ]);

        $payload = $serializer->serialize($session);
        self::assertFalse($payload->isEmpty());

        // Verify it round-trips
        $result = $serializer->deserialize($payload);
        self::assertSame('bob', $result['user']);
        self::assertSame('admin', $result['role']);
    }

    #[Test]
    public function testDeserializePreservesOriginalSession(): void
    {
        $_SESSION['preserve_me'] = 'original';
        $serializer = new NativePhpSessionSerializer();

        // Build a different payload
        $old = $_SESSION;
        $_SESSION = ['different' => 'data'];
        $encoded = session_encode();
        $_SESSION = $old;

        $payload = new SerializedSessionPayload($encoded);
        $serializer->deserialize($payload);

        // $_SESSION should be restored
        self::assertSame('original', $_SESSION['preserve_me']);
    }

    #[Test]
    public function testSerializePreservesOriginalSession(): void
    {
        $_SESSION['preserve_me'] = 'original';
        $serializer = new NativePhpSessionSerializer();

        $session = new DefaultSession(new SessionId('test-id'), ['other' => 'data']);
        $serializer->serialize($session);

        // $_SESSION should be restored
        self::assertSame('original', $_SESSION['preserve_me']);
    }
}
