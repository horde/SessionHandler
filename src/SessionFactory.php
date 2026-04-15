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

/**
 * Factory for creating new and restoring existing session instances.
 */
interface SessionFactory
{
    public function createNew(SessionId $id): Session;

    /** @param array<string, mixed> $payload */
    public function restore(SessionId $id, array $payload): Session;
}
