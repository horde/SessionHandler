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

use InvalidArgumentException;
use Stringable;

/**
 * Immutable value object representing a validated session identifier.
 */
final readonly class SessionId implements Stringable
{
    private const PATTERN = '/^[a-zA-Z0-9,\-]{1,256}$/';

    public function __construct(
        public string $id,
    ) {
        if (preg_match(self::PATTERN, $id) !== 1) {
            throw new InvalidArgumentException(
                sprintf('Invalid session ID "%s": must match %s', $id, self::PATTERN),
            );
        }
    }

    public function __toString(): string
    {
        return $this->id;
    }

    public function equals(self $other): bool
    {
        return $this->id === $other->id;
    }
}
