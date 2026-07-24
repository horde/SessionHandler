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

    // ---------------------------------------------------------------
    // Iteration
    // ---------------------------------------------------------------

    #[Test]
    public function testImplementsIterableSessionBackend(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        self::assertInstanceOf(IterableSessionBackend::class, $backend);
    }

    #[Test]
    public function testListSessionsFlatYieldsIds(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        $backend->save(new SessionId('aaa'), $this->payload('1'), $this->expiresAt);
        $backend->save(new SessionId('bbb'), $this->payload('2'), $this->expiresAt);

        $ids = [];
        foreach ($backend->listSessions() as $id) {
            $ids[] = $id->id;
        }
        sort($ids);

        self::assertSame(['aaa', 'bbb'], $ids);
    }

    #[Test]
    public function testListSessionsSkipsNonSessFiles(): void
    {
        $backend = new BuiltinBackend($this->tempDir);
        $backend->save(new SessionId('good'), $this->payload('x'), $this->expiresAt);
        // Foreign files that must not surface as session ids.
        file_put_contents($this->tempDir . '/README', 'not a session');
        file_put_contents($this->tempDir . '/.hidden', 'not a session');

        $ids = [];
        foreach ($backend->listSessions() as $id) {
            $ids[] = $id->id;
        }

        self::assertSame(['good'], $ids);
    }

    #[Test]
    public function testListSessionsRecursesIntoHashedTree(): void
    {
        // Depth 2, ids "0ab..." and "1cd..." — mkdir the two subdirs
        // they hash into, then save.
        $backend = new BuiltinBackend('2;' . $this->tempDir);
        mkdir($this->tempDir . '/0/a', 0o755, true);
        mkdir($this->tempDir . '/1/c', 0o755, true);
        $backend->save(new SessionId('0abc'), $this->payload('x'), $this->expiresAt);
        $backend->save(new SessionId('1cde'), $this->payload('y'), $this->expiresAt);

        $ids = [];
        foreach ($backend->listSessions() as $id) {
            $ids[] = $id->id;
        }
        sort($ids);

        self::assertSame(['0abc', '1cde'], $ids);
    }

    #[Test]
    public function testListSessionsSkipsMissingHashSubdirectories(): void
    {
        // Depth 2 but no subdirectories yet — mod_files-compatible:
        // never-created buckets mean no sessions, not an error.
        $backend = new BuiltinBackend('2;' . $this->tempDir);

        $ids = iterator_to_array($backend->listSessions());

        self::assertSame([], $ids);
    }

    #[Test]
    public function testListSessionsThrowsWhenBaseIsNotADirectory(): void
    {
        $file = $this->tempDir . '/not_a_dir';
        file_put_contents($file, 'x');

        $backend = new BuiltinBackend($file);

        $this->expectException(SessionException::class);
        $this->expectExceptionMessageMatches('/is not a directory/');
        $this->expectExceptionMessageMatches('/' . preg_quote($file, '/') . '/');

        iterator_to_array($backend->listSessions());
    }

    #[Test]
    public function testListSessionsThrowsWhenBaseIsUnreadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('permission tests do not apply to root');
        }

        chmod($this->tempDir, 0o000);
        // Verify chmod actually stripped the read bit (some filesystems
        // ignore Unix mode bits).
        if (is_readable($this->tempDir)) {
            chmod($this->tempDir, 0o755);
            self::markTestSkipped('filesystem does not honour chmod(0)');
        }

        try {
            $backend = new BuiltinBackend($this->tempDir);
            $this->expectException(SessionException::class);
            $this->expectExceptionMessageMatches('/not readable/');
            $this->expectExceptionMessageMatches('/' . preg_quote($this->tempDir, '/') . '/');
            iterator_to_array($backend->listSessions());
        } finally {
            /* Restore mode so tearDown()'s recursive remove works. */
            chmod($this->tempDir, 0o755);
        }
    }

    #[Test]
    public function testListSessionsThrowsWhenHashSubdirectoryIsUnreadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('permission tests do not apply to root');
        }

        $backend = new BuiltinBackend('1;' . $this->tempDir);
        mkdir($this->tempDir . '/a', 0o755, true);
        $backend->save(new SessionId('abc'), $this->payload('x'), $this->expiresAt);
        chmod($this->tempDir . '/a', 0o000);
        if (is_readable($this->tempDir . '/a')) {
            chmod($this->tempDir . '/a', 0o755);
            self::markTestSkipped('filesystem does not honour chmod(0)');
        }

        try {
            $this->expectException(SessionException::class);
            $this->expectExceptionMessageMatches('/subdirectory/');
            $this->expectExceptionMessageMatches('/not readable/');
            iterator_to_array($backend->listSessions());
        } finally {
            chmod($this->tempDir . '/a', 0o755);
        }
    }

    // ---------------------------------------------------------------
    // load() error surfacing
    // ---------------------------------------------------------------

    #[Test]
    public function testLoadThrowsWhenSessionFileIsUnreadable(): void
    {
        if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
            self::markTestSkipped('permission tests do not apply to root');
        }

        $backend = new BuiltinBackend($this->tempDir);
        $id = new SessionId('locked');
        $backend->save($id, $this->payload('x'), $this->expiresAt);
        $file = $this->tempDir . '/sess_locked';
        chmod($file, 0o000);
        if (is_readable($file)) {
            chmod($file, 0o600);
            self::markTestSkipped('filesystem does not honour chmod(0)');
        }

        try {
            $this->expectException(SessionException::class);
            $this->expectExceptionMessageMatches('/not readable/');
            $this->expectExceptionMessageMatches('/' . preg_quote($file, '/') . '/');
            $backend->load($id);
        } finally {
            chmod($file, 0o600);
        }
    }
}
