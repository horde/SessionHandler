<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\SessionHandler\Test\unit;

use Horde\SessionHandler\DefaultSessionFactory;
use Horde\SessionHandler\Exception\CapabilityException;
use Horde\SessionHandler\PhpSessionSerializer;
use Horde\SessionHandler\SessionHandler;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\Storage\ExternalBackend;
use Horde\SessionHandler\Storage\FileBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(SessionHandler::class)]
class SessionHandlerTest extends TestCase
{
    private string $tempDir;
    private FileBackend $backend;
    private SessionHandler $handler;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/horde_sh_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o777, true);
        $this->backend = new FileBackend($this->tempDir);
        $this->handler = new SessionHandler(
            backend: $this->backend,
            serializer: new PhpSessionSerializer(),
            sessionFactory: new DefaultSessionFactory(),
        );
    }

    protected function tearDown(): void
    {
        $files = glob($this->tempDir . '/*');
        if ($files !== false) {
            foreach ($files as $file) {
                @unlink($file);
            }
        }
        @rmdir($this->tempDir);
    }

    #[Test]
    public function testCreate(): void
    {
        $session = $this->handler->create();

        self::assertNotEmpty($session->getId()->id);
        self::assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $session->getId()->id);
    }

    #[Test]
    public function testCreateAndSave(): void
    {
        $session = $this->handler->create();
        $session->set('username', 'alice');
        $session->set('role', 'admin');

        $this->handler->save($session);

        $loaded = $this->handler->load($session->getId());

        self::assertNotNull($loaded);
        self::assertSame('alice', $loaded->get('username'));
        self::assertSame('admin', $loaded->get('role'));
    }

    #[Test]
    public function testLoadMissing(): void
    {
        $id = new SessionId('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa1');

        self::assertNull($this->handler->load($id));
    }

    #[Test]
    public function testSaveSkipsCleanSession(): void
    {
        $session = $this->handler->create();

        // Don't set anything, session is clean (not dirty)
        $this->handler->save($session);

        $loaded = $this->handler->load($session->getId());
        self::assertNull($loaded);
    }

    #[Test]
    public function testDestroySession(): void
    {
        $session = $this->handler->create();
        $session->set('key', 'value');
        $this->handler->save($session);

        self::assertNotNull($this->handler->load($session->getId()));

        $this->handler->destroySession($session->getId());

        self::assertNull($this->handler->load($session->getId()));
    }

    #[Test]
    public function testRegenerate(): void
    {
        $session = $this->handler->create();
        $session->set('token', 'secret');
        $this->handler->save($session);

        $oldId = $session->getId();
        $newSession = $this->handler->regenerate($session);

        // New session has different ID
        self::assertFalse($oldId->equals($newSession->getId()));

        // New session carries the same data
        self::assertSame('secret', $newSession->get('token'));

        // Old ID is gone from the backend
        self::assertNull($this->handler->load($oldId));

        // Mark new session dirty so save() persists it
        $newSession->set('token', $newSession->get('token'));
        $this->handler->save($newSession);
        $reloaded = $this->handler->load($newSession->getId());
        self::assertNotNull($reloaded);
        self::assertSame('secret', $reloaded->get('token'));
    }

    #[Test]
    public function testListSessions(): void
    {
        $session1 = $this->handler->create();
        $session1->set('a', 1);
        $this->handler->save($session1);

        $session2 = $this->handler->create();
        $session2->set('b', 2);
        $this->handler->save($session2);

        $ids = [];
        foreach ($this->handler->listSessions() as $sessionId) {
            $ids[] = $sessionId->id;
        }

        sort($ids);
        $expected = [$session1->getId()->id, $session2->getId()->id];
        sort($expected);

        self::assertSame($expected, $ids);
    }

    #[Test]
    public function testExpire(): void
    {
        $session = $this->handler->create();
        $session->set('data', 'value');
        $this->handler->save($session);

        self::assertNotNull($this->handler->load($session->getId()));

        $this->handler->expire($session->getId());

        self::assertNull($this->handler->load($session->getId()));
    }

    #[Test]
    public function testCapabilityExceptionForListSessions(): void
    {
        /* ExternalBackend wraps arbitrary PHP SessionHandlerInterface
         * callbacks and cannot enumerate; a good fit for exercising
         * the CapabilityException path. BuiltinBackend used to be
         * non-iterable and was the fixture here, but now implements
         * IterableSessionBackend by walking the save-path tree. */
        $nonIterable = new ExternalBackend(
            \Closure::fromCallable(function (): ?string { return null; }),
            \Closure::fromCallable(function (): void {}),
            \Closure::fromCallable(function (): void {}),
        );
        $handler = new SessionHandler(
            backend: $nonIterable,
            serializer: new PhpSessionSerializer(),
            sessionFactory: new DefaultSessionFactory(),
        );

        $this->expectException(CapabilityException::class);
        $handler->listSessions();
    }

    #[Test]
    public function testPhpNativeReadWrite(): void
    {
        $sessionId = bin2hex(random_bytes(16));
        $data = 'username|s:5:"alice";';

        $writeResult = $this->handler->write($sessionId, $data);
        self::assertTrue($writeResult);

        $readResult = $this->handler->read($sessionId);
        self::assertSame($data, $readResult);
    }

    #[Test]
    public function testPhpNativeDestroy(): void
    {
        $sessionId = bin2hex(random_bytes(16));
        $this->handler->write($sessionId, 'some-data');

        $result = $this->handler->destroy($sessionId);
        self::assertTrue($result);

        $readResult = $this->handler->read($sessionId);
        self::assertSame('', $readResult);
    }

    #[Test]
    public function testValidateIdExistingSession(): void
    {
        $sessionId = bin2hex(random_bytes(16));
        $this->handler->write($sessionId, 'session-data');

        self::assertTrue($this->handler->validateId($sessionId));
    }

    #[Test]
    public function testValidateIdMissingSession(): void
    {
        $sessionId = bin2hex(random_bytes(16));

        self::assertFalse($this->handler->validateId($sessionId));
    }

    #[Test]
    public function testValidateIdInvalidString(): void
    {
        // SessionId pattern: /^[a-zA-Z0-9,\-]{1,256}$/
        // Characters like spaces and special chars are invalid
        self::assertFalse($this->handler->validateId('invalid session id!@#'));
    }
}
