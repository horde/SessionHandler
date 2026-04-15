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

use Closure;
use DateTimeImmutable;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionStorageBackend;

/**
 * Session storage backend that delegates to user-provided Closure callbacks.
 *
 * Only the three core data operations (read/write/delete) are needed;
 * open/close/gc are handled at the SessionHandler level.
 */
final class ExternalBackend implements SessionStorageBackend
{
    public function __construct(
        private readonly Closure $readCallback,
        private readonly Closure $writeCallback,
        private readonly Closure $deleteCallback,
    ) {}

    public function load(SessionId $id): ?SerializedSessionPayload
    {
        return ($this->readCallback)($id);
    }

    public function save(SessionId $id, SerializedSessionPayload $payload, DateTimeImmutable $expiresAt): void
    {
        ($this->writeCallback)($id, $payload, $expiresAt);
    }

    public function delete(SessionId $id): void
    {
        ($this->deleteCallback)($id);
    }
}
