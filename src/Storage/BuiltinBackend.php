<?php

declare(strict_types=1);

/**
 * Copyright 2005-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author Matt Selsky <selsky@columbia.edu>
 */

namespace Horde\SessionHandler\Storage;

use DateTimeImmutable;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionStorageBackend;
use RuntimeException;

/**
 * Storage backend that reads and writes PHP's standard `sess_<id>` session
 * files, including support for the `N;[MODE;]/path` form of
 * `session.save_path`.
 *
 * # Interop scope
 *
 * The on-disk *layout* matches what PHP's native `mod_files` handler would
 * produce: `sess_<id>` files under `session.save_path`, optionally split
 * across N levels of single-hex-character subdirectories derived from the
 * session id. A site can therefore inspect, archive, or migrate sessions
 * with the same tooling it would use for `session.save_handler = files`.
 *
 * # Divergence from PHP's mod_files
 *
 * Two deliberate differences:
 *
 * 1. **No per-request `flock(LOCK_EX)`.** PHP's `mod_files` opens the
 *    session file once per request, holds an exclusive lock from `read()`
 *    through `write()`, and only releases it when the session closes.
 *    That serialises concurrent requests for the same session id at the
 *    cost of carrying a file descriptor across the request. The modern
 *    `SessionHandler` PHP-callback shape does not thread a lock between
 *    `read()` and `write()`; doing so cleanly is a separate cross-cutting
 *    change. This backend therefore drops the per-request lock and
 *    accepts the same concurrency model the rest of the modern stack
 *    already lives with: two simultaneous requests for the same session
 *    id race, last writer wins.
 *
 * 2. **Atomic writes via tempfile-and-rename, not in-place truncate-and-
 *    write.** PHP's `mod_files` writes to the same fd it reads through, so
 *    a concurrent reader without a shared lock can observe a torn or
 *    zero-byte file. This backend writes to a sibling tempfile, applies
 *    mode 0600, then `rename()`s into place. Concurrent readers see
 *    either the old inode or the new one, never a partial state. This is
 *    a strictly stronger atomicity guarantee than PHP's, at the cost of
 *    not coordinating with a hypothetical second writer that uses
 *    `flock(LOCK_EX)`. We accept that trade for the same reason as (1).
 *
 * # Auto-creation of subdirectories
 *
 * Mirrors PHP: the backend does **not** create subdirectories on demand.
 * Operators using `session.save_path = "N;..."` are expected to provision
 * the tree at install time (typically via `find ... -type d` or a distro
 * postinst script). A missing intermediate directory raises a
 * {@see RuntimeException} with the exact path so the misconfiguration is
 * obvious.
 *
 * # Garbage collection
 *
 * Not implemented. This backend does not declare
 * {@see \Horde\SessionHandler\IterableSessionBackend} or
 * {@see \Horde\SessionHandler\SessionMetadataBackend}, so
 * {@see \Horde\SessionHandler\SessionHandler::gc()} returns false and PHP's
 * probabilistic GC is a no-op. Operators relying on `Builtin` must run
 * external cleanup (cron, `systemd-tmpfiles`, the OS `/tmp` cleaner, or
 * similar). Adding GC that walks the hashed tree is a future capability
 * tracked separately.
 */
final class BuiltinBackend implements SessionStorageBackend
{
    private const FILE_PREFIX = 'sess_';
    private const FILE_MODE = 0o600;
    private const TEMP_PREFIX = '.sess_tmp_';

    private readonly PhpFilesSavePathSpec $spec;

    /**
     * @param string $path A `session.save_path`-style string. Accepts any
     *                     of the three forms `path`, `N;path`,
     *                     `N;MODE;path`. Empty input falls back to
     *                     {@see session_save_path()} or
     *                     {@see sys_get_temp_dir()}.
     */
    public function __construct(string $path = '')
    {
        $this->spec = PhpFilesSavePathSpec::parse(self::resolvePath($path));
    }

    public function load(SessionId $id): ?SerializedSessionPayload
    {
        $file = $this->filePath($id);

        if (!is_file($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        if ($contents === false || $contents === '') {
            return null;
        }

        return new SerializedSessionPayload($contents);
    }

    public function save(SessionId $id, SerializedSessionPayload $payload, DateTimeImmutable $expiresAt): void
    {
        $directory = $this->spec->directoryFor($id->id);

        if (!is_dir($directory)) {
            throw new RuntimeException(sprintf(
                'Session save directory "%s" does not exist. Provision the '
                . 'configured save_path tree before starting sessions; this '
                . 'backend does not auto-create subdirectories (matching '
                . 'PHP\'s mod_files).',
                $directory,
            ));
        }

        $finalPath = $directory . '/' . self::FILE_PREFIX . $id->id;
        $tempPath = @tempnam($directory, self::TEMP_PREFIX);

        if ($tempPath === false) {
            throw new RuntimeException(sprintf(
                'Failed to create temporary session file in "%s"',
                $directory,
            ));
        }

        $written = @file_put_contents($tempPath, $payload->getData());
        if ($written === false) {
            @unlink($tempPath);
            throw new RuntimeException(sprintf(
                'Failed to write session data to temporary file "%s"',
                $tempPath,
            ));
        }

        // Apply mode before rename so the file is never visible at its
        // final path with looser permissions.
        if (!@chmod($tempPath, self::FILE_MODE)) {
            @unlink($tempPath);
            throw new RuntimeException(sprintf(
                'Failed to set mode 0600 on temporary session file "%s"',
                $tempPath,
            ));
        }

        if (!@rename($tempPath, $finalPath)) {
            @unlink($tempPath);
            throw new RuntimeException(sprintf(
                'Failed to rename temporary session file "%s" to "%s"',
                $tempPath,
                $finalPath,
            ));
        }
    }

    public function delete(SessionId $id): void
    {
        @unlink($this->filePath($id));
    }

    private function filePath(SessionId $id): string
    {
        return $this->spec->directoryFor($id->id)
            . '/' . self::FILE_PREFIX . $id->id;
    }

    /**
     * Resolve the configured path argument, honouring PHP's fallback
     * chain: explicit value, then {@see session_save_path()}, then
     * {@see sys_get_temp_dir()}. Returned as-is for the spec parser to
     * interpret (so an `N;MODE;path` value passed in via DI configuration
     * still goes through the parser).
     */
    private static function resolvePath(string $path): string
    {
        if ($path !== '') {
            return $path;
        }

        $sessionPath = session_save_path();
        if ($sessionPath !== '' && $sessionPath !== false) {
            return $sessionPath;
        }

        return sys_get_temp_dir();
    }
}
