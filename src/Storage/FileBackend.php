<?php

declare(strict_types=1);

/**
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author Michael Slusarz <slusarz@horde.org>
 */

namespace Horde\SessionHandler\Storage;

use DateTimeImmutable;
use DirectoryIterator;
use Generator;
use Horde\SessionHandler\AdministrativeSessionBackend;
use Horde\SessionHandler\Exception\SessionException;
use Horde\SessionHandler\Exception\SessionLockException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\LockingSessionBackend;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionLock;
use Horde\SessionHandler\SessionMetadata;
use Horde\SessionHandler\SessionMetadataBackend;
use Horde\SessionHandler\SessionStorageBackend;
use InvalidArgumentException;

/**
 * Filesystem-based session storage backend.
 *
 * Files are named `horde_sh_<SESSION_ID>` in a flat directory.
 * Raw session data is stored as file content with no additional wrapping,
 * maintaining data compatibility with the legacy Horde_SessionHandler_Storage_File.
 */
final class FileBackend implements
    SessionStorageBackend,
    IterableSessionBackend,
    SessionMetadataBackend,
    AdministrativeSessionBackend,
    LockingSessionBackend
{
    private const FILE_PREFIX = 'horde_sh_';

    /**
     * @param string $path Directory where session files are stored
     *
     * @throws SessionException If the path is not a writable directory
     */
    public function __construct(
        private readonly string $path,
    ) {
        if (!is_dir($this->path)) {
            throw new SessionException(
                sprintf('Session storage path "%s" is not a directory', $this->path),
            );
        }

        if (!is_writable($this->path)) {
            throw new SessionException(
                sprintf('Session storage path "%s" is not writable', $this->path),
            );
        }
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
        $finalPath = $this->filePath($id);

        $tempFile = @tempnam($this->path, 'sess_');

        if ($tempFile === false) {
            throw new SessionException(
                sprintf('Failed to create temporary file in "%s"', $this->path),
            );
        }

        $written = @file_put_contents($tempFile, $payload->getData());

        if ($written === false) {
            @unlink($tempFile);
            throw new SessionException(
                sprintf('Failed to write session data to temporary file "%s"', $tempFile),
            );
        }

        if (!@rename($tempFile, $finalPath)) {
            @unlink($tempFile);
            throw new SessionException(
                sprintf('Failed to rename temporary file to "%s"', $finalPath),
            );
        }
    }

    public function delete(SessionId $id): void
    {
        @unlink($this->filePath($id));
    }

    /** @return Generator<SessionId> */
    public function listSessions(): Generator
    {
        $prefixLength = strlen(self::FILE_PREFIX);

        foreach (new DirectoryIterator($this->path) as $entry) {
            if ($entry->isDot() || !$entry->isFile()) {
                continue;
            }

            $filename = $entry->getFilename();

            if (!str_starts_with($filename, self::FILE_PREFIX)) {
                continue;
            }

            $idString = substr($filename, $prefixLength);

            try {
                yield new SessionId($idString);
            } catch (InvalidArgumentException) {
                // Skip files whose suffix is not a valid session ID
                continue;
            }
        }
    }

    public function getMetadata(SessionId $id): ?SessionMetadata
    {
        $file = $this->filePath($id);

        if (!is_file($file)) {
            return null;
        }

        $mtime = @filemtime($file);
        $ctime = @filectime($file);

        if ($mtime === false || $ctime === false) {
            return null;
        }

        $maxLifetime = (int) ini_get('session.gc_maxlifetime');

        if ($maxLifetime <= 0) {
            $maxLifetime = 1440;
        }

        $createdAt = (new DateTimeImmutable())->setTimestamp($ctime);
        $lastModifiedAt = (new DateTimeImmutable())->setTimestamp($mtime);
        $expiresAt = (new DateTimeImmutable())->setTimestamp($mtime + $maxLifetime);

        return new SessionMetadata($createdAt, $lastModifiedAt, $expiresAt);
    }

    public function expire(SessionId $id): void
    {
        $this->delete($id);
    }

    /** @throws SessionLockException */
    public function acquireLock(SessionId $id): SessionLock
    {
        $file = $this->filePath($id);

        $handle = @fopen($file, 'c');

        if ($handle === false) {
            throw new SessionLockException(
                sprintf('Failed to open session file "%s" for locking', $file),
            );
        }

        if (!flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new SessionLockException(
                sprintf('Failed to acquire exclusive lock on session "%s"', $id->id),
            );
        }

        return new FileLock($handle);
    }

    private function filePath(SessionId $id): string
    {
        return $this->path . '/' . self::FILE_PREFIX . $id->id;
    }
}
