<?php

declare(strict_types=1);

/**
 * Copyright 2010-2026 Horde LLC (http://www.horde.org/)
 *
 * See the enclosed file LICENSE for license information (LGPL). If you
 * did not receive this file, see http://www.horde.org/licenses/lgpl21.
 *
 * @author Michael Slusarz <slusarz@horde.org>
 */

namespace Horde\SessionHandler\Storage;

use DateTimeImmutable;
use Horde\SessionHandler\Exception\SessionException;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionStorageBackend;
use InvalidArgumentException;

/**
 * Composite backend that stacks multiple storage backends.
 *
 * Reads iterate forward through the stack and return the first hit.
 * Writes iterate in reverse — the last backend is the "master" and
 * its failure is fatal. Failures on non-master backends during write
 * trigger a delete to invalidate a stale cache entry.
 *
 * This backend implements only SessionStorageBackend. Capability
 * interfaces (iteration, metadata, admin, locking) are not proxied.
 * Code that needs those features should query the master backend
 * directly.
 */
final class StackBackend implements SessionStorageBackend
{
    /** @var SessionStorageBackend[] */
    private readonly array $backends;

    /**
     * @param SessionStorageBackend ...$backends  At least one backend;
     *                                            the last is the master.
     *
     * @throws InvalidArgumentException If no backends are provided
     */
    public function __construct(SessionStorageBackend ...$backends)
    {
        if ($backends === []) {
            throw new InvalidArgumentException('At least one backend is required.');
        }

        $this->backends = $backends;
    }

    public function load(SessionId $id): ?SerializedSessionPayload
    {
        foreach ($this->backends as $backend) {
            $result = $backend->load($id);

            if ($result !== null) {
                return $result;
            }
        }

        return null;
    }

    public function save(SessionId $id, SerializedSessionPayload $payload, DateTimeImmutable $expiresAt): void
    {
        $reversed = array_reverse($this->backends);
        $master = true;

        foreach ($reversed as $backend) {
            try {
                $backend->save($id, $payload, $expiresAt);
            } catch (SessionException $e) {
                if ($master) {
                    throw $e;
                }

                // Non-master write failed — invalidate the stale entry
                try {
                    $backend->delete($id);
                } catch (SessionException) {
                    // Best effort
                }
            }

            $master = false;
        }
    }

    public function delete(SessionId $id): void
    {
        $reversed = array_reverse($this->backends);
        $master = true;
        $masterException = null;

        foreach ($reversed as $backend) {
            try {
                $backend->delete($id);
            } catch (SessionException $e) {
                if ($master) {
                    $masterException = $e;
                }
            }

            $master = false;
        }

        if ($masterException !== null) {
            throw $masterException;
        }
    }
}
