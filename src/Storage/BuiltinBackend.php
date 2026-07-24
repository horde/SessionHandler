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
use DirectoryIterator;
use Generator;
use Horde\SessionHandler\Exception\SessionException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionStorageBackend;
use InvalidArgumentException;
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
 * # Iteration and garbage collection
 *
 * Implements {@see \Horde\SessionHandler\IterableSessionBackend} by
 * walking the configured save-path tree and yielding a {@see SessionId}
 * per `sess_*` file found. Traversal is lazy (a Generator); admin pages
 * can process large session pools without materialising the full list.
 * Does not declare {@see \Horde\SessionHandler\SessionMetadataBackend},
 * so {@see \Horde\SessionHandler\SessionHandler::gc()} still relies on
 * external cleanup (cron, `systemd-tmpfiles`, the OS `/tmp` cleaner, or
 * similar). GC that walks the tree during iteration is a future
 * capability tracked separately.
 */
final class BuiltinBackend implements SessionStorageBackend, IterableSessionBackend
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

        if (!is_readable($file)) {
            throw new SessionException(sprintf(
                'Session file "%s" exists but is not readable by the PHP '
                . 'user (check filesystem permissions and umask on the '
                . 'save_path).',
                $file,
            ));
        }

        $contents = @file_get_contents($file);

        if ($contents === false) {
            /* file_get_contents on a file that passed is_file() and
             * is_readable() moments ago most often means a concurrent
             * unlink (session GC) or a filesystem-level I/O error.
             * Surface the path so the caller can act; do not silently
             * skip. */
            $err = error_get_last();
            throw new SessionException(sprintf(
                'Failed to read session file "%s"%s',
                $file,
                isset($err['message']) ? ': ' . $err['message'] : '.',
            ));
        }

        if ($contents === '') {
            /* Freshly-created but never-written session file. Treated
             * as "no data" rather than an error; the session will be
             * populated on first write. */
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

    /**
     * Enumerate all session ids currently present on disk.
     *
     * Walks the configured save-path tree; for depth 0 that is a flat
     * scan of {@see PhpFilesSavePathSpec::basePath}, for depth N a
     * recursive walk of the N-level single-hex-char subdirectory tree.
     *
     * Errors are surfaced with the exact path involved so that an
     * admin who sees the failure can act on it directly:
     *
     *   - Base path missing or not a directory: throw immediately,
     *     before yielding anything.
     *   - Base or subdirectory unreadable (permissions): throw naming
     *     that specific directory.
     *   - Subdirectory expected under the hashed layout but absent on
     *     disk: skipped silently. PHP's mod_files also tolerates this
     *     because it lazily creates directories on write.
     *   - Filename that does not parse as a SessionId (e.g., a stray
     *     `sess_` prefix on something unrelated): skipped silently.
     *     Matches FileBackend's tolerance.
     *
     * @return Generator<SessionId>
     * @throws SessionException on unreadable directories.
     */
    public function listSessions(): Generator
    {
        $base = $this->spec->basePath;

        if (!is_dir($base)) {
            throw new SessionException(sprintf(
                'Session save_path "%s" is not a directory. Cannot list '
                . 'sessions.',
                $base,
            ));
        }
        if (!is_readable($base)) {
            throw new SessionException(sprintf(
                'Session save_path "%s" is not readable by the PHP user '
                . '(check filesystem permissions). Cannot list sessions.',
                $base,
            ));
        }

        yield from $this->listSessionsIn($base, $this->spec->depth);
    }

    /**
     * Recursive walk helper for {@see listSessions}. At depth 0 yields
     * SessionId per `sess_*` file in $dir; otherwise recurses into each
     * of the 16 single-hex-char subdirectories that PHP's mod_files
     * would use for this level.
     *
     * @return Generator<SessionId>
     */
    private function listSessionsIn(string $dir, int $remainingDepth): Generator
    {
        if ($remainingDepth > 0) {
            for ($i = 0; $i < 16; $i++) {
                $sub = $dir . '/' . dechex($i);
                if (!file_exists($sub)) {
                    /* PHP mod_files does not pre-create these; a missing
                     * subdirectory just means no sessions have hashed
                     * into that bucket yet. */
                    continue;
                }
                if (!is_dir($sub)) {
                    throw new SessionException(sprintf(
                        'Session save_path subdirectory "%s" exists but '
                        . 'is not a directory; the save_path tree is '
                        . 'inconsistent.',
                        $sub,
                    ));
                }
                if (!is_readable($sub)) {
                    throw new SessionException(sprintf(
                        'Session save_path subdirectory "%s" is not '
                        . 'readable by the PHP user (check filesystem '
                        . 'permissions).',
                        $sub,
                    ));
                }
                yield from $this->listSessionsIn($sub, $remainingDepth - 1);
            }
            return;
        }

        try {
            $it = new DirectoryIterator($dir);
        } catch (RuntimeException $e) {
            throw new SessionException(sprintf(
                'Failed to open session save_path directory "%s": %s',
                $dir,
                $e->getMessage(),
            ), 0, $e);
        }

        $prefixLength = strlen(self::FILE_PREFIX);

        foreach ($it as $entry) {
            if ($entry->isDot() || !$entry->isFile()) {
                continue;
            }
            $name = $entry->getFilename();
            if (!str_starts_with($name, self::FILE_PREFIX)) {
                continue;
            }
            $idString = substr($name, $prefixLength);
            try {
                yield new SessionId($idString);
            } catch (InvalidArgumentException) {
                /* Stray file matching sess_* whose suffix is not a
                 * valid session id. Skip; matches FileBackend. */
                continue;
            }
        }
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
