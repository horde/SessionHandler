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
 * Serializer for sessions stored by PHP's native session handler.
 *
 * PHP's native session machinery (session_set_save_handler + session_start)
 * uses session_encode()/session_decode(), which produces a format different
 * from serialize()/unserialize(). This serializer bridges that gap so the
 * modern object API can read sessions written through $_SESSION.
 */
final class NativePhpSessionSerializer implements SessionSerializer
{
    public function serialize(Session $session): SerializedSessionPayload
    {
        $old = $_SESSION ?? [];

        try {
            $_SESSION = $session->toPayload();
            $encoded = session_encode();
        } finally {
            $_SESSION = $old;
        }

        if ($encoded === false) {
            throw new SerializationException('session_encode() failed');
        }

        return new SerializedSessionPayload($encoded);
    }

    /** @return array<string, mixed> */
    public function deserialize(SerializedSessionPayload $payload): array
    {
        if ($payload->isEmpty()) {
            return [];
        }

        $old = $_SESSION ?? [];

        try {
            $_SESSION = [];
            $result = session_decode($payload->getData());

            if ($result === false) {
                throw new SerializationException('session_decode() failed');
            }

            $data = $_SESSION;
        } finally {
            $_SESSION = $old;
        }

        return $data;
    }
}
