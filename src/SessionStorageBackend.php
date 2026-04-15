<?php

declare(strict_types=1);

/**
 * Copyright 2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author Ralf Lang <ralf.lang@ralf-lang.de>
 */

namespace Horde\SessionHandler;

use DateTimeImmutable;

/**
 * Mandatory interface for session storage backends.
 */
interface SessionStorageBackend
{
    public function load(SessionId $id): ?SerializedSessionPayload;

    public function save(SessionId $id, SerializedSessionPayload $payload, DateTimeImmutable $expiresAt): void;

    public function delete(SessionId $id): void;
}
