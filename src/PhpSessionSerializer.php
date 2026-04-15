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

use Horde\SessionHandler\Exception\SerializationException;

/**
 * Serializer that uses PHP's native serialize/unserialize functions.
 */
final class PhpSessionSerializer implements SessionSerializer
{
    public function serialize(Session $session): SerializedSessionPayload
    {
        return new SerializedSessionPayload(
            serialize($session->toPayload()),
        );
    }

    /** @return array<string, mixed> */
    public function deserialize(SerializedSessionPayload $payload): array
    {
        if ($payload->isEmpty()) {
            return [];
        }

        $result = @unserialize($payload->getData(), ['allowed_classes' => true]);

        if ($result === false && $payload->getData() !== serialize(false)) {
            throw new SerializationException(
                'Failed to deserialize session payload',
            );
        }

        if (!is_array($result)) {
            throw new SerializationException(
                sprintf(
                    'Expected array from deserialized session payload, got %s',
                    get_debug_type($result),
                ),
            );
        }

        return $result;
    }
}
