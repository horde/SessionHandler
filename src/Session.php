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
 * Core session interface for reading and writing session data.
 */
interface Session
{
    public function getId(): SessionId;

    public function get(string $key): mixed;

    public function set(string $key, mixed $value): void;

    public function has(string $key): bool;

    public function remove(string $key): void;

    /** @return array<string> */
    public function keys(): array;

    public function isDirty(): bool;

    /** @return array<string, mixed> The full payload as an associative array */
    public function toPayload(): array;
}
