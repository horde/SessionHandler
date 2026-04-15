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
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionLock;
use Horde\SessionHandler\SessionMetadata;
use Horde\SessionHandler\Storage\FileBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

#[CoversClass(FileBackend::class)]
class FileBackendTest extends TestCase
{
    private string $tempDir;
    private FileBackend $backend;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/horde_sh_test_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o777, true);
        $this->backend = new FileBackend($this->tempDir);
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
    public function testSaveAndLoad(): void
    {
        $id = new SessionId('abc123');
        $payload = new SerializedSessionPayload('some-session-data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $this->backend->save($id, $payload, $expiresAt);
        $loaded = $this->backend->load($id);

        self::assertNotNull($loaded);
        self::assertSame('some-session-data', $loaded->getData());
    }

    #[Test]
    public function testLoadMissing(): void
    {
        $id = new SessionId('nonexistent');
        $loaded = $this->backend->load($id);

        self::assertNull($loaded);
    }

    #[Test]
    public function testDelete(): void
    {
        $id = new SessionId('deleteme');
        $payload = new SerializedSessionPayload('data-to-delete');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $this->backend->save($id, $payload, $expiresAt);
        self::assertNotNull($this->backend->load($id));

        $this->backend->delete($id);
        self::assertNull($this->backend->load($id));
    }

    #[Test]
    public function testDeleteIdempotent(): void
    {
        $id = new SessionId('neverexisted');

        // Should not throw
        $this->backend->delete($id);
        self::assertNull($this->backend->load($id));
    }

    #[Test]
    public function testListSessions(): void
    {
        $ids = ['session1', 'session2', 'session3'];
        $expiresAt = new DateTimeImmutable('+1 hour');

        foreach ($ids as $idStr) {
            $this->backend->save(
                new SessionId($idStr),
                new SerializedSessionPayload('data-' . $idStr),
                $expiresAt,
            );
        }

        $listed = [];
        foreach ($this->backend->listSessions() as $sessionId) {
            $listed[] = $sessionId->id;
        }

        sort($listed);
        sort($ids);
        self::assertSame($ids, $listed);
    }

    #[Test]
    public function testListSessionsEmpty(): void
    {
        $listed = [];
        foreach ($this->backend->listSessions() as $sessionId) {
            $listed[] = $sessionId->id;
        }

        self::assertSame([], $listed);
    }

    #[Test]
    public function testGetMetadata(): void
    {
        $id = new SessionId('metacheck');
        $payload = new SerializedSessionPayload('metadata-test');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $before = new DateTimeImmutable();
        $this->backend->save($id, $payload, $expiresAt);
        $after = new DateTimeImmutable();

        $metadata = $this->backend->getMetadata($id);

        self::assertNotNull($metadata);
        self::assertInstanceOf(SessionMetadata::class, $metadata);

        // Timestamps should be within a reasonable window
        self::assertGreaterThanOrEqual(
            $before->getTimestamp(),
            $metadata->lastModifiedAt->getTimestamp(),
        );
        self::assertLessThanOrEqual(
            $after->getTimestamp() + 1,
            $metadata->lastModifiedAt->getTimestamp(),
        );
        self::assertGreaterThanOrEqual(
            $before->getTimestamp(),
            $metadata->createdAt->getTimestamp(),
        );
    }

    #[Test]
    public function testGetMetadataMissing(): void
    {
        $id = new SessionId('nometa');

        self::assertNull($this->backend->getMetadata($id));
    }

    #[Test]
    public function testExpire(): void
    {
        $id = new SessionId('expirable');
        $payload = new SerializedSessionPayload('expire-me');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $this->backend->save($id, $payload, $expiresAt);
        self::assertNotNull($this->backend->load($id));

        $this->backend->expire($id);
        self::assertNull($this->backend->load($id));
    }

    #[Test]
    public function testAcquireLockAndRelease(): void
    {
        $id = new SessionId('locktest');
        $payload = new SerializedSessionPayload('lock-data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $this->backend->save($id, $payload, $expiresAt);

        $lock = $this->backend->acquireLock($id);

        self::assertInstanceOf(SessionLock::class, $lock);

        // Release should not throw
        $lock->release();

        // Data should still be loadable after lock release
        self::assertNotNull($this->backend->load($id));
    }

    #[Test]
    public function testDataCompatibilityFileNaming(): void
    {
        $id = new SessionId('compat123');
        $rawData = 'raw-session-content';
        $payload = new SerializedSessionPayload($rawData);
        $expiresAt = new DateTimeImmutable('+1 hour');

        $this->backend->save($id, $payload, $expiresAt);

        $expectedFile = $this->tempDir . '/horde_sh_compat123';
        self::assertFileExists($expectedFile);
        self::assertSame($rawData, file_get_contents($expectedFile));
    }

    #[Test]
    public function testAtomicWriteCreatesFile(): void
    {
        $id = new SessionId('atomictest');
        $payload = new SerializedSessionPayload('atomic-data');
        $expiresAt = new DateTimeImmutable('+1 hour');

        $expectedFile = $this->tempDir . '/horde_sh_atomictest';
        self::assertFileDoesNotExist($expectedFile);

        $this->backend->save($id, $payload, $expiresAt);

        self::assertFileExists($expectedFile);
    }
}
