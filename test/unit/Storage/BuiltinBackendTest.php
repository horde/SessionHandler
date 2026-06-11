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
use Horde\SessionHandler\Storage\BuiltinBackend;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(BuiltinBackend::class)]
class BuiltinBackendTest extends TestCase
{
    private string $tempDir;
    private DateTimeImmutable $expiresAt;

    protected function setUp(): void
    {
        $this->tempDir = sys_get_temp_dir() . '/horde_builtin_' . bin2hex(random_bytes(4));
        mkdir($this->tempDir, 0o777, true);
        $this->expiresAt = new DateTimeImmutable('+1 hour');
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tempDir);
    }

    private function removeTree(string $path): void
    {
        if (!file_exists($path)) {
            return;
        }
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $this->removeTree($path . '/' . $entry);
        }
        @rmdir($path);
    }

    private function payload(string $bytes): SerializedSessionPayload
    {
        return new SerializedSessionPayload($bytes);
    }

    // ---------------------------------------------------------------
    // Flat layout (depth 0)
    // ---------------------------------------------------------------

    #[Test]
    public function testSaveWritesSessFileAtFlatPath(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        $id = new SessionId('abc123');

        $backend->save($id, $this->payload('hello'), $this->expiresAt);

        $expected = $this->tempDir . '/sess_abc123';
        self::assertFileExists($expected);
        self::assertSame('hello', file_get_contents($expected));
    }

    #[Test]
    public function testSavedFileHasMode0600(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        $id = new SessionId('modetest');

        $backend->save($id, $this->payload('x'), $this->expiresAt);

        $file = $this->tempDir . '/sess_modetest';
        $perms = fileperms($file) & 0o777;
        self::assertSame(0o600, $perms);
    }

    #[Test]
    public function testLoadReadsBackWhatSaveWrote(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        $id = new SessionId('roundtrip');

        $backend->save($id, $this->payload('payload-bytes'), $this->expiresAt);
        $loaded = $backend->load($id);

        self::assertNotNull($loaded);
        self::assertSame('payload-bytes', $loaded->getData());
    }

    #[Test]
    public function testLoadReturnsNullForMissingFile(): void
    {
        $backend = new BuiltinBackend($this->tempDir);

        self::assertNull($backend->load(new SessionId('notthere')));
    }

    #[Test]
    public function testLoadReturnsNullForEmptyFile(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        touch($this->tempDir . '/sess_empty');

        self::assertNull($backend->load(new SessionId('empty')));
    }

    #[Test]
    public function testDeleteUnlinksFile(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        $id = new SessionId('todelete');
        $backend->save($id, $this->payload('x'), $this->expiresAt);

        $backend->delete($id);

        self::assertFileDoesNotExist($this->tempDir . '/sess_todelete');
    }

    #[Test]
    public function testDeleteOfMissingFileIsSilent(): void
    {
        $backend = new BuiltinBackend($this->tempDir);

        // Must not raise.
        $backend->delete(new SessionId('neverexisted'));
        self::assertTrue(true);
    }

    #[Test]
    public function testSaveOverwritesExistingFile(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        $id = new SessionId('overwrite');

        $backend->save($id, $this->payload('first'), $this->expiresAt);
        $backend->save($id, $this->payload('second'), $this->expiresAt);

        $loaded = $backend->load($id);
        self::assertNotNull($loaded);
        self::assertSame('second', $loaded->getData());
    }

    #[Test]
    public function testSaveDoesNotLeaveTempfileBehind(): void
    {
        $backend = new BuiltinBackend($this->tempDir);

        $backend->save(new SessionId('cleanup'), $this->payload('x'), $this->expiresAt);

        $stragglers = glob($this->tempDir . '/.sess_tmp_*') ?: [];
        self::assertSame([], $stragglers);
    }

    // ---------------------------------------------------------------
    // Hashed-tree layout (depth > 0)
    // ---------------------------------------------------------------

    #[Test]
    public function testSaveUsesHashedSubdirectoryForDepth2(): void
    {
        // Depth 2: id "abcdef" → tempDir/a/b/sess_abcdef
        mkdir($this->tempDir . '/a/b', 0o755, true);
        $backend = new BuiltinBackend('2;' . $this->tempDir);
        $id = new SessionId('abcdef');

        $backend->save($id, $this->payload('hashed'), $this->expiresAt);

        $expected = $this->tempDir . '/a/b/sess_abcdef';
        self::assertFileExists($expected);
        self::assertSame('hashed', file_get_contents($expected));
    }

    #[Test]
    public function testLoadFromHashedSubdirectoryRoundtrips(): void
    {
        mkdir($this->tempDir . '/3/a', 0o755, true);
        $backend = new BuiltinBackend('2;' . $this->tempDir);
        $id = new SessionId('3a99ff');

        $backend->save($id, $this->payload('rt'), $this->expiresAt);
        $loaded = $backend->load($id);

        self::assertNotNull($loaded);
        self::assertSame('rt', $loaded->getData());
    }

    #[Test]
    public function testSaveThrowsWhenSubdirectoryMissing(): void
    {
        // depth=2 but we never create the a/b tree.
        $backend = new BuiltinBackend('2;' . $this->tempDir);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/does not exist/');
        $this->expectExceptionMessageMatches('/does not auto-create/');

        $backend->save(new SessionId('abcdef'), $this->payload('x'), $this->expiresAt);
    }

    #[Test]
    public function testDepthModeAndPathFormParses(): void
    {
        // Just confirm the ctor accepts the three-field form. Mode is
        // recorded but not consulted today.
        $backend = new BuiltinBackend('1;0700;' . $this->tempDir);
        mkdir($this->tempDir . '/a', 0o755, true);

        $backend->save(new SessionId('abc'), $this->payload('x'), $this->expiresAt);

        self::assertFileExists($this->tempDir . '/a/sess_abc');
    }
}
