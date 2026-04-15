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
 * Default in-memory implementation of the Session interface.
 */
class DefaultSession implements Session
{
    protected bool $dirty = false;

    /**
     * @param array<string, mixed> $data
     */
    public function __construct(
        private readonly SessionId $id,
        protected array $data = [],
    ) {}

    public function getId(): SessionId
    {
        return $this->id;
    }

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    public function set(string $key, mixed $value): void
    {
        $this->data[$key] = $value;
        $this->dirty = true;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->data);
    }

    public function remove(string $key): void
    {
        if (array_key_exists($key, $this->data)) {
            unset($this->data[$key]);
            $this->dirty = true;
        }
    }

    /** @return array<string> */
    public function keys(): array
    {
        return array_keys($this->data);
    }

    public function isDirty(): bool
    {
        return $this->dirty;
    }

    /** @return array<string, mixed> */
    public function toPayload(): array
    {
        return $this->data;
    }
}
