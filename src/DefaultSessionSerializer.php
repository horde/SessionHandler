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
 * Dispatching session serializer that picks an inner implementation
 * matching PHP's `session.serialize_handler` ini setting.
 *
 * Two formats are supported, matching what stock PHP and typical
 * distributions ship:
 *
 * - `php` (the default): see {@see PhpTextSessionSerializer}.
 * - `php_serialize`: see {@see PhpSessionSerializer}.
 *
 * Other handlers (`igbinary`, `wddx`, custom registrations) are
 * rejected at construction time. If the modern stack ever needs them,
 * write a serializer for the format and extend the dispatcher; until
 * then, fail loudly so operators see a clear error rather than
 * silently-corrupted session data.
 *
 * The chosen handler is captured at construction and frozen; changes
 * to the ini at runtime do not retroactively switch the serializer.
 * That matches PHP's own behaviour: the session module reads the
 * setting once when the session opens.
 */
final class DefaultSessionSerializer implements SessionSerializer
{
    private SessionSerializer $inner;

    /**
     * @param string|null $handler Override the auto-detected handler.
     *                             When null, reads from
     *                             `ini_get('session.serialize_handler')`
     *                             with a fallback to `php` if that
     *                             returns empty (CLI sessions disabled,
     *                             etc.).
     */
    public function __construct(?string $handler = null)
    {
        $resolved = $handler ?? $this->detectHandler();
        $this->inner = match ($resolved) {
            'php' => new PhpTextSessionSerializer(),
            'php_serialize' => new PhpSessionSerializer(),
            default => throw new SerializationException(sprintf(
                'Unsupported session.serialize_handler "%s". '
                . 'Supported handlers are "php" and "php_serialize". '
                . 'For other formats (igbinary, custom) a dedicated '
                . 'serializer must be written.',
                $resolved,
            )),
        };
    }

    public function serialize(Session $session): SerializedSessionPayload
    {
        return $this->inner->serialize($session);
    }

    /** @return array<string, mixed> */
    public function deserialize(SerializedSessionPayload $payload): array
    {
        return $this->inner->deserialize($payload);
    }

    /** Internal: which inner serializer is in use. */
    public function inner(): SessionSerializer
    {
        return $this->inner;
    }

    private function detectHandler(): string
    {
        $value = ini_get('session.serialize_handler');
        if (is_string($value) && $value !== '') {
            return $value;
        }
        return 'php';
    }
}
