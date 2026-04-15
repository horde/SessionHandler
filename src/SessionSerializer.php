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
 * Serializes and deserializes session data to/from opaque blobs.
 */
interface SessionSerializer
{
    public function serialize(Session $session): SerializedSessionPayload;

    /** @return array<string, mixed> */
    public function deserialize(SerializedSessionPayload $payload): array;
}
