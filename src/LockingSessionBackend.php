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

use Horde\SessionHandler\Exception\SessionLockException;

/**
 * Capability interface for backends that support session locking.
 */
interface LockingSessionBackend
{
    /** @throws SessionLockException */
    public function acquireLock(SessionId $id): SessionLock;
}
