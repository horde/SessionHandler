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

/**
 * Minimal compatibility backend that reads from PHP's native session file
 * storage. Implements only SessionStorageBackend -- no iteration, metadata,
 * locking, or admin operations.
 */
final class BuiltinBackend implements SessionStorageBackend
{
    private const FILE_PREFIX = 'sess_';

    public function __construct(
        private readonly string $path = '',
    ) {}

    public function load(SessionId $id): ?SerializedSessionPayload
    {
        $file = $this->filePath($id);

        if (!file_exists($file)) {
            return null;
        }

        $contents = @file_get_contents($file);

        if ($contents === false || $contents === '') {
            return null;
        }

        return new SerializedSessionPayload($contents);
    }

    /**
     * Persist session data to PHP's default sess_ file layout.
     *
     * When Horde registers {@see \Horde\SessionHandler\SessionHandler} as
     * PHP's save handler, this backend must write the blob itself — PHP no
     * longer invokes the native handler directly.
     */
    public function save(SessionId $id, SerializedSessionPayload $payload, DateTimeImmutable $expiresAt): void
    {
        $file = $this->filePath($id);
        $written = @file_put_contents($file, $payload->getData(), LOCK_EX);

        if ($written === false) {
            throw new \RuntimeException(
                sprintf('Failed to write session file "%s"', $file)
            );
        }
    }

    public function delete(SessionId $id): void
    {
        @unlink($this->filePath($id));
    }

    private function filePath(SessionId $id): string
    {
        return $this->getPath() . '/' . self::FILE_PREFIX . $id->id;
    }

    private function getPath(): string
    {
        if ($this->path !== '') {
            return $this->path;
        }

        $path = session_save_path();

        if ($path === '' || $path === false) {
            return sys_get_temp_dir();
        }

        return $path;
    }
}
