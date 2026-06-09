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

use Generator;
use Horde\SessionHandler\Exception\CapabilityException;

/**
 * Cross-session inspection and administration service.
 *
 * Decorates {@see SessionHandler} with operations relevant to admin tools
 * and security flows: list, load, force-destroy, look up by user, sign-out
 * everywhere. Callers include `base/admin/sessions.php`, password-change
 * handlers ("invalidate other sessions for this user"), and ops jobs.
 *
 * Design rule: code that reaches for SessionAdministrator should never
 * also need to reach for {@see SessionHandler}. Every operation that an
 * admin caller might need is exposed here either as a pass-through to
 * SessionHandler (for the lifecycle ops that apply to any session by id)
 * or as a user-aware method composed over those primitives. SessionHandler
 * itself stays focused on the active-session lifecycle.
 *
 * Active-session-only operations on SessionHandler — `create()`, `save()`,
 * `regenerate()` and the SessionHandlerInterface plumbing — are
 * deliberately NOT proxied. They don't make sense for arbitrary stored
 * sessions; admin tools should not be able to mint new ones, mutate
 * others' state, or rotate IDs of foreign sessions.
 *
 * Capability requirements
 * -----------------------
 * The capability checks belong to the underlying SessionHandler — we just
 * forward calls. listAll() throws {@see CapabilityException} when the
 * backend is not iterable; getMetadata() throws when the backend lacks
 * metadata support; expire() throws when the backend lacks administrative
 * expiration support. Mandatory ops (load, destroy) work on any backend.
 *
 * User-aware methods
 * ------------------
 * findByUser/destroyAllForUser inspect the payload of each enumerated
 * session via the protected {@see extractUserId()} hook. The default
 * implementation reads "horde/auth/userId" — the wire layout used by
 * {@see \Horde\Core\Session\HordeSession::getAuthenticatedUser()}. Other
 * Session implementations can override extractUserId() in a subclass.
 *
 * Performance note
 * ----------------
 * findByUser/destroyAllForUser iterate all sessions and load each one.
 * Acceptable for admin tools; not for hot paths. A backend-specific
 * user-indexed implementation can replace this service in deployments
 * where session counts are large.
 */
class SessionAdministrator
{
    public function __construct(
        protected readonly SessionHandler $handler,
    ) {}

    /**
     * Enumerate all known session IDs.
     *
     * @return Generator<SessionId>
     *
     * @throws CapabilityException If the backend cannot enumerate sessions.
     */
    public function listAll(): Generator
    {
        foreach ($this->handler->listSessions() as $id) {
            yield $id;
        }
    }

    /**
     * Load a stored session by ID.
     *
     * Pass-through to {@see SessionHandler::load()}. Returns null if the
     * session does not exist, has expired, or its payload is corrupt.
     */
    public function load(SessionId $id): ?Session
    {
        return $this->handler->load($id);
    }

    /**
     * Forcibly delete a session.
     *
     * Pass-through to {@see SessionHandler::destroySession()}. Idempotent.
     */
    public function destroy(SessionId $id): void
    {
        $this->handler->destroySession($id);
    }

    /**
     * Mark a session expired without deleting it.
     *
     * Pass-through to {@see SessionHandler::expire()}. Useful for "soft
     * logout" flows where a record of the session should remain for audit
     * but the session is no longer valid for authentication.
     *
     * @throws CapabilityException If the backend does not support
     *                             administrative expiration.
     */
    public function expire(SessionId $id): void
    {
        $this->handler->expire($id);
    }

    /**
     * Retrieve session metadata (creation time, last modification, expiry).
     *
     * Pass-through to {@see SessionHandler::getMetadata()}. Returns null
     * when the backend supports metadata but no record matches the id.
     *
     * @throws CapabilityException If the backend does not support metadata.
     */
    public function getMetadata(SessionId $id): ?SessionMetadata
    {
        return $this->handler->getMetadata($id);
    }

    /**
     * Locate every session whose authenticated user matches $userId.
     *
     * Iterates and inspects each payload. Sessions that fail to load are
     * silently skipped — they're invalid, GC will clean them.
     *
     * @return list<SessionId>
     *
     * @throws CapabilityException If the backend cannot enumerate sessions.
     */
    public function findByUser(string $userId): array
    {
        $matches = [];

        foreach ($this->listAll() as $sessionId) {
            $session = $this->load($sessionId);
            if ($session === null) {
                continue;
            }

            if ($this->extractUserId($session) === $userId) {
                $matches[] = $sessionId;
            }
        }

        return $matches;
    }

    /**
     * Forcibly destroy all of $userId's sessions, optionally keeping one.
     *
     * Used by "sign out everywhere" flows and by password-change handlers
     * that invalidate other live sessions while leaving the caller's
     * current session intact.
     *
     * @param SessionId|null $exceptId  Session to keep (typically the
     *                                  caller's current session ID).
     *
     * @throws CapabilityException If the backend cannot enumerate sessions.
     */
    public function destroyAllForUser(string $userId, ?SessionId $exceptId = null): void
    {
        foreach ($this->findByUser($userId) as $id) {
            if ($exceptId !== null && $id->equals($exceptId)) {
                continue;
            }
            $this->destroy($id);
        }
    }

    /**
     * Extract the authenticated user ID from a session payload.
     *
     * Default implementation reads "horde/auth/userId" — the wire layout
     * used by HordeSession. Override in a subclass for non-Horde session
     * shapes.
     */
    protected function extractUserId(Session $session): ?string
    {
        $payload = $session->toPayload();
        $value = $payload['horde']['auth/userId'] ?? null;

        return is_string($value) ? $value : null;
    }
}
