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
 * Immutable value object holding session timing metadata.
 */
final readonly class SessionMetadata
{
    public function __construct(
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $lastModifiedAt,
        public DateTimeImmutable $expiresAt,
    ) {}
}
