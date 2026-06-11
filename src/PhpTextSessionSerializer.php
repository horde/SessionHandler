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
 * Serializer for PHP's default `php` session-data format.
 *
 * Layout: a flat concatenation of `<key>|<serialize($value)>` records,
 * one per top-level session key. No header, no separator, no footer.
 * The serialised value is self-delimiting; the parser advances by the
 * exact byte length of each `serialize()` output.
 *
 * This is the default of PHP's `session.serialize_handler`. Reading
 * and writing this format does NOT require an active PHP session
 * (unlike `session_encode()` / `session_decode()` which only operate
 * on `$_SESSION` while the session module is open). Modern PSR-15
 * middleware and CLI consumers use this serializer to interoperate
 * with sessions written by the legacy stack.
 *
 * Limitations match the platform: keys cannot contain `|`, `!`, or
 * `\0`. Keys with those bytes are rejected during serialize and
 * serialised payloads with those bytes embedded structurally cause
 * deserialise failures the same way PHP's native handler would
 * silently truncate at the offending byte.
 */
final class PhpTextSessionSerializer implements SessionSerializer
{
    /**
     * Bytes PHP forbids in session keys. The session module aborts the
     * write with a notice; this serializer rejects up front.
     */
    private const FORBIDDEN_KEY_BYTES = ['|', '!', "\0"];

    public function serialize(Session $session): SerializedSessionPayload
    {
        $out = '';
        foreach ($session->toPayload() as $key => $value) {
            $stringKey = (string) $key;
            $this->guardKey($stringKey);
            $out .= $stringKey . '|' . serialize($value);
        }
        return new SerializedSessionPayload($out);
    }

    /** @return array<string, mixed> */
    public function deserialize(SerializedSessionPayload $payload): array
    {
        if ($payload->isEmpty()) {
            return [];
        }

        $data = $payload->getData();
        $length = strlen($data);
        $pos = 0;
        $result = [];

        while ($pos < $length) {
            $pipe = strpos($data, '|', $pos);
            if ($pipe === false) {
                throw new SerializationException(sprintf(
                    'Malformed session payload: expected key|value record at offset %d',
                    $pos,
                ));
            }

            $key = substr($data, $pos, $pipe - $pos);
            $valueStart = $pipe + 1;
            if ($valueStart >= $length) {
                throw new SerializationException(sprintf(
                    'Malformed session payload: missing value for key "%s" at offset %d',
                    $key,
                    $pipe,
                ));
            }

            // unserialize() does not report how many bytes it consumed.
            // Re-serialise the result and use its byte length to advance
            // the cursor. serialize() is canonical: an unserialise/
            // reserialise round-trip yields the same bytes.
            $value = @unserialize(substr($data, $valueStart), ['allowed_classes' => true]);
            $remaining = substr($data, $valueStart);

            if ($value === false && !$this->looksLikeSerializedFalse($remaining)) {
                throw new SerializationException(sprintf(
                    'Malformed session payload: cannot unserialise value for key "%s" at offset %d',
                    $key,
                    $valueStart,
                ));
            }

            $consumed = strlen(serialize($value));
            $result[$key] = $value;
            $pos = $valueStart + $consumed;
        }

        return $result;
    }

    private function guardKey(string $key): void
    {
        foreach (self::FORBIDDEN_KEY_BYTES as $byte) {
            if (str_contains($key, $byte)) {
                throw new SerializationException(sprintf(
                    'Invalid session key "%s": contains byte 0x%s which the PHP '
                    . 'session-data format does not permit.',
                    $key,
                    bin2hex($byte),
                ));
            }
        }
    }

    /**
     * Distinguish a genuine `b:0;` (serialised false) from an
     * unserialise() failure that also returns false.
     */
    private function looksLikeSerializedFalse(string $bytes): bool
    {
        return str_starts_with($bytes, 'b:0;');
    }
}
