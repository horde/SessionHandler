<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 */

namespace Horde\SessionHandler\Storage;

use InvalidArgumentException;

/**
 * Parsed representation of PHP's session.save_path syntax.
 *
 * PHP's `mod_files` accepts up to three semicolon-separated fields:
 *
 *     path
 *     N;path
 *     N;MODE;path
 *
 * where:
 *
 * - `N` is the number of single-hex-character subdirectory levels to use
 *   when storing files. With N=0 (the default) all files live in `path`
 *   itself; with N=2 a file `sess_3ab...` lives at `path/3/a/sess_3ab...`.
 *   PHP imposes no upper bound on N, but values above 32 do not improve
 *   anything (a session id is at most 32 hex chars under PHP's default
 *   sid configuration) and balloon directory counts. We cap at 32.
 *
 * - `MODE` is the octal directory mode PHP would use when *creating*
 *   subdirectories. We never auto-create directories (matching PHP), so
 *   the parsed mode is recorded for completeness but otherwise unused
 *   today. Default 0600.
 *
 * - `path` is the base directory.
 *
 * Whitespace inside fields is preserved; `mod_files` itself does not trim.
 * Empty inputs and inputs whose final field is an empty string are
 * rejected as user errors.
 */
final class PhpFilesSavePathSpec
{
    /** Hard cap on subdirectory depth. PHP itself imposes none; we do. */
    public const MAX_DEPTH = 32;

    /** Default directory creation mode when MODE is omitted. */
    public const DEFAULT_MODE = 0600;

    /**
     * @param int    $depth    Number of single-hex-character subdirectory
     *                         levels. 0 means "flat directory".
     * @param int    $mode     Directory creation mode (octal). Recorded for
     *                         completeness; not currently consulted because
     *                         BuiltinBackend does not auto-create.
     * @param string $basePath Absolute or relative base directory. No
     *                         trailing slash normalisation.
     */
    public function __construct(
        public readonly int $depth,
        public readonly int $mode,
        public readonly string $basePath,
    ) {}

    /**
     * Parse a `session.save_path`-style string.
     *
     * Accepts: `path`, `N;path`, `N;MODE;path`. Rejects empty input,
     * malformed N or MODE, depth > {@see MAX_DEPTH}, and an empty path
     * field after splitting.
     *
     * @throws InvalidArgumentException on any malformed input.
     */
    public static function parse(string $input): self
    {
        if ($input === '') {
            throw new InvalidArgumentException(
                'session.save_path is empty',
            );
        }

        $parts = explode(';', $input);
        $count = count($parts);

        if ($count > 3) {
            throw new InvalidArgumentException(sprintf(
                'session.save_path "%s" has more than three semicolon-'
                . 'separated fields; expected at most "N;MODE;path"',
                $input,
            ));
        }

        // Last field is always the path.
        $basePath = (string) array_pop($parts);
        if ($basePath === '') {
            throw new InvalidArgumentException(sprintf(
                'session.save_path "%s" has no path component',
                $input,
            ));
        }

        $depth = 0;
        $mode = self::DEFAULT_MODE;

        if ($parts !== []) {
            $depth = self::parseDepth(array_shift($parts), $input);
        }

        if ($parts !== []) {
            $mode = self::parseMode(array_shift($parts), $input);
        }

        return new self($depth, $mode, $basePath);
    }

    /**
     * Compute the directory path for a session id, applying the configured
     * subdirectory depth.
     *
     * For depth 0 returns {@see basePath}. For depth N returns
     * `basePath/<id[0]>/<id[1]>/.../<id[N-1]>`. The session id must contain
     * at least `depth` characters (PHP enforces this implicitly because
     * its session ids are always longer than any reasonable depth).
     */
    public function directoryFor(string $sessionId): string
    {
        if ($this->depth === 0) {
            return $this->basePath;
        }

        if (strlen($sessionId) < $this->depth) {
            throw new InvalidArgumentException(sprintf(
                'Session id "%s" is shorter than the configured save_path '
                . 'depth %d; cannot derive subdirectory path',
                $sessionId,
                $this->depth,
            ));
        }

        $dir = $this->basePath;
        for ($i = 0; $i < $this->depth; $i++) {
            $dir .= '/' . $sessionId[$i];
        }

        return $dir;
    }

    private static function parseDepth(string $raw, string $input): int
    {
        if ($raw === '' || !ctype_digit($raw)) {
            throw new InvalidArgumentException(sprintf(
                'session.save_path "%s" has a non-numeric depth "%s"',
                $input,
                $raw,
            ));
        }

        $depth = (int) $raw;
        if ($depth < 0 || $depth > self::MAX_DEPTH) {
            throw new InvalidArgumentException(sprintf(
                'session.save_path "%s" depth %d is outside the supported '
                . 'range 0..%d',
                $input,
                $depth,
                self::MAX_DEPTH,
            ));
        }

        return $depth;
    }

    private static function parseMode(string $raw, string $input): int
    {
        if ($raw === '') {
            throw new InvalidArgumentException(sprintf(
                'session.save_path "%s" has an empty MODE field',
                $input,
            ));
        }

        // PHP parses MODE as octal regardless of leading-zero prefix.
        if (!preg_match('/^[0-7]+$/', $raw)) {
            throw new InvalidArgumentException(sprintf(
                'session.save_path "%s" has a non-octal MODE "%s"',
                $input,
                $raw,
            ));
        }

        $mode = octdec($raw);
        if ($mode < 0 || $mode > 07777) {
            throw new InvalidArgumentException(sprintf(
                'session.save_path "%s" MODE %s is outside the valid '
                . 'range 0..07777',
                $input,
                $raw,
            ));
        }

        return (int) $mode;
    }
}
