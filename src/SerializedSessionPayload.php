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
 * Immutable wrapper around an opaque serialized session blob.
 */
final readonly class SerializedSessionPayload
{
    public function __construct(
        private string $data,
    ) {}

    public function getData(): string
    {
        return $this->data;
    }

    public function isEmpty(): bool
    {
        return $this->data === '';
    }
}
