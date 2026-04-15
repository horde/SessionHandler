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

namespace Horde\SessionHandler\Storage;

use Horde\SessionHandler\SessionLock;

/**
 * File-based session lock backed by flock().
 *
 * Holds an exclusive lock on a session file until release() is called.
 * Calling release() multiple times is safe and idempotent.
 */
final class FileLock implements SessionLock
{
    /** @var resource|null */
    private $handle;

    /** @param resource $handle */
    public function __construct($handle)
    {
        $this->handle = $handle;
    }

    public function release(): void
    {
        if ($this->handle !== null) {
            flock($this->handle, LOCK_UN);
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
