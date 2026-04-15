# Extending Horde\SessionHandler

This library is designed to be extended at four points: storage backends,
session serializers, session objects, and session factories. Each point is
an interface. You implement what you need and pass it to the `SessionHandler`
constructor.

## Custom storage backends

### The required interface

Every backend must implement `SessionStorageBackend`:

```php
use DateTimeImmutable;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\SessionStorageBackend;

final class RedisBackend implements SessionStorageBackend
{
    public function __construct(
        private readonly \Redis $redis,
    ) {}

    public function load(SessionId $id): ?SerializedSessionPayload
    {
        $data = $this->redis->get('session:' . $id->id);

        if ($data === false) {
            return null;
        }

        return new SerializedSessionPayload($data);
    }

    public function save(
        SessionId $id,
        SerializedSessionPayload $payload,
        DateTimeImmutable $expiresAt,
    ): void {
        $ttl = max(0, $expiresAt->getTimestamp() - time());
        $this->redis->setex('session:' . $id->id, $ttl, $payload->getData());
    }

    public function delete(SessionId $id): void
    {
        $this->redis->del('session:' . $id->id);
    }
}
```

That is a complete, usable backend. It stores, retrieves, and deletes
sessions. The `SessionHandler` can use it immediately:

```php
$handler = new SessionHandler(backend: new RedisBackend($redis));
```

### Capability interfaces

The library defines four optional interfaces. A backend implements only the
ones it can genuinely support. Do not implement an interface if the
underlying store cannot efficiently perform the operation -- the handler
will throw `CapabilityException` when application code asks for a
capability the backend does not have, which is the correct behavior.

#### IterableSessionBackend

Return an iterable of active session IDs. Used by `SessionHandler::gc()`
and `SessionHandler::listSessions()`.

```php
use Generator;
use Horde\SessionHandler\IterableSessionBackend;

final class RedisBackend implements SessionStorageBackend, IterableSessionBackend
{
    /** @return Generator<SessionId> */
    public function listSessions(): Generator
    {
        $keys = $this->redis->keys('session:*');

        foreach ($keys as $key) {
            yield new SessionId(substr($key, strlen('session:')));
        }
    }
}
```

#### SessionMetadataBackend

Return timing metadata for a session. Used by `SessionHandler::gc()` to
decide which sessions are expired and by `SessionHandler::getMetadata()`.

```php
use Horde\SessionHandler\SessionMetadata;
use Horde\SessionHandler\SessionMetadataBackend;

final class RedisBackend implements SessionStorageBackend, SessionMetadataBackend
{
    public function getMetadata(SessionId $id): ?SessionMetadata
    {
        $ttl = $this->redis->ttl('session:' . $id->id);

        if ($ttl < 0) {
            return null;
        }

        $expiresAt = new DateTimeImmutable('+' . $ttl . ' seconds');
        // Redis does not track creation or modification times natively.
        // Use expiresAt as a best-effort approximation.
        return new SessionMetadata(
            createdAt: $expiresAt,
            lastModifiedAt: $expiresAt,
            expiresAt: $expiresAt,
        );
    }
}
```

If your store does not have creation or modification timestamps, it is
better to return honest approximations than to fake precision. The
`SessionMetadata` fields are informational -- the handler will not break
if `createdAt` equals `lastModifiedAt`.

#### AdministrativeSessionBackend

Force-expire a session on demand. Used by `SessionHandler::expire()`.

```php
use Horde\SessionHandler\AdministrativeSessionBackend;

final class RedisBackend implements SessionStorageBackend, AdministrativeSessionBackend
{
    public function expire(SessionId $id): void
    {
        $this->redis->del('session:' . $id->id);
    }
}
```

In many backends the `expire()` method is identical to `delete()`. Totally valid.
They are separate because the handler dispatches `SessionExpired` vs
`SessionDestroyed` events depending on which method was called, and the
semantic distinction matters to listeners.

#### LockingSessionBackend

Acquire an exclusive lock on a session. The handler does not call this
automatically. It exists for applications that need concurrent-request
safety. The returned `SessionLock` must be released by the caller.

```php
use Horde\SessionHandler\LockingSessionBackend;
use Horde\SessionHandler\SessionLock;
use Horde\SessionHandler\Exception\SessionLockException;

final class RedisBackend implements SessionStorageBackend, LockingSessionBackend
{
    public function acquireLock(SessionId $id): SessionLock
    {
        $lockKey = 'lock:session:' . $id->id;
        $token = bin2hex(random_bytes(16));

        $acquired = $this->redis->set($lockKey, $token, ['NX', 'EX' => 30]);

        if (!$acquired) {
            throw new SessionLockException('Could not acquire lock for session ' . $id->id);
        }

        return new class($this->redis, $lockKey, $token) implements SessionLock {
            public function __construct(
                private readonly \Redis $redis,
                private readonly string $key,
                private readonly string $token,
            ) {}

            public function release(): void
            {
                // Atomic check-and-delete via Lua to avoid releasing someone else's lock
                $script = "if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) else return 0 end";
                $this->redis->eval($script, [$this->key, $this->token], 1);
            }
        };
    }
}
```

Only implement `LockingSessionBackend` if your store provides a real
locking primitive. Internal read/write isolation (database transactions,
HashTable lock-on-get) does not qualify. Those are implementation details
of the backend, not locks the caller can hold.

### When to throw

- `SessionException` for storage-level failures (connection lost, write
  error, permission denied).
- `CapabilityException` if a capability method is called on a backend that
  conditionally supports it (see `HashtableBackend::listSessions()` when
  tracking is disabled).
- Let the handler catch and wrap -- do not catch storage exceptions inside
  the backend to return `null` or `false`. The handler distinguishes
  "not found" (returns `null`) from "error" (exception propagates).

## Custom serializers

The `SessionSerializer` interface has two methods:

```php
use Horde\SessionHandler\Session;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionSerializer;

interface SessionSerializer
{
    public function serialize(Session $session): SerializedSessionPayload;

    /** @return array<string, mixed> */
    public function deserialize(SerializedSessionPayload $payload): array;
}
```

`serialize()` takes a `Session` and returns an opaque blob.
`deserialize()` takes a blob and returns the associative array that will be
passed to `SessionFactory::restore()`.

### Example: JSON serializer

```php
use Horde\SessionHandler\Exception\SerializationException;

final class JsonSessionSerializer implements SessionSerializer
{
    public function serialize(Session $session): SerializedSessionPayload
    {
        $json = json_encode($session->toPayload(), JSON_THROW_ON_ERROR);

        return new SerializedSessionPayload($json);
    }

    public function deserialize(SerializedSessionPayload $payload): array
    {
        if ($payload->isEmpty()) {
            return [];
        }

        try {
            $data = json_decode($payload->getData(), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new SerializationException('Invalid JSON in session payload', 0, $e);
        }

        if (!is_array($data)) {
            throw new SerializationException('Expected JSON object, got ' . get_debug_type($data));
        }

        return $data;
    }
}
```

Throw `SerializationException` when the payload is corrupt or unparseable.

### Choosing the right serializer

The built-in `PhpSessionSerializer` uses `serialize()` / `unserialize()`.
This is the right choice when your session data contains PHP objects that
need identity preservation.

A JSON serializer is appropriate when sessions cross language boundaries or
when you want human-readable storage.

**Do not confuse this with PHP's session serialization format.**
When using `session_set_save_handler()` + `session_start()`, PHP calls
`session_encode()` / `session_decode()` internally. The `SessionSerializer`
is only used by the explicit object API (`$handler->create()`,
`$handler->load()`, `$handler->save()`).

## Custom session objects

The `Session` interface represents an active session's data:

```php
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
    /** @return array<string, mixed> */
    public function toPayload(): array;
}
```

The default `DefaultSession` is a flat key-value store. You might want a
richer session object that provides typed accessors, namespace prefixing, or
domain-specific methods.

### Example: typed accessors

```php
use Horde\SessionHandler\Session;
use Horde\SessionHandler\SessionId;

final class AppSession implements Session
{
    private bool $dirty = false;

    public function __construct(
        private readonly SessionId $id,
        private array $data = [],
    ) {}

    // ... implement all Session interface methods (delegate to $data) ...

    // Typed domain accessors:

    public function getUserId(): ?string
    {
        return $this->get('auth/userId');
    }

    public function setUserId(string $userId): void
    {
        $this->set('auth/userId', $userId);
    }

    public function getLocale(): string
    {
        return $this->get('locale') ?? 'en_US';
    }

    public function setLocale(string $locale): void
    {
        $this->set('locale', $locale);
    }

    public function getFlashMessages(): array
    {
        $messages = $this->get('_flash') ?? [];
        $this->remove('_flash');

        return $messages;
    }

    public function addFlash(string $type, string $message): void
    {
        $flash = $this->get('_flash') ?? [];
        $flash[] = ['type' => $type, 'message' => $message];
        $this->set('_flash', $flash);
    }
}
```

The key rules:

- `isDirty()` must return `true` after any `set()` or `remove()`.
- `toPayload()` must return the full data as an associative array.
- `keys()` must return all top-level keys.

The `SessionHandler` calls `isDirty()` before saving and `toPayload()`
when serializing. Everything else is between your application and your
session object.

## Custom session factories

A `SessionFactory` creates session instances:

```php
interface SessionFactory
{
    public function createNew(SessionId $id): Session;

    /** @param array<string, mixed> $payload */
    public function restore(SessionId $id, array $payload): Session;
}
```

`createNew()` is called by `SessionHandler::create()`. `restore()` is
called by `SessionHandler::load()` after the serializer has deserialized
the payload.

### Example: factory for the typed session

```php
use Horde\SessionHandler\SessionFactory;

final class AppSessionFactory implements SessionFactory
{
    public function createNew(SessionId $id): Session
    {
        return new AppSession($id);
    }

    public function restore(SessionId $id, array $payload): Session
    {
        return new AppSession($id, $payload);
    }
}
```

Wire it up:

```php
$handler = new SessionHandler(
    backend:        new SqlBackend($db),
    serializer:     new JsonSessionSerializer(),
    sessionFactory: new AppSessionFactory(),
);

$session = $handler->create();
assert($session instanceof AppSession);
$session->setUserId('alice');
$session->setLocale('de_DE');
$handler->save($session);
```

## Wiring it all together

The `SessionHandler` constructor is the composition root:

```php
$handler = new SessionHandler(
    backend:        $backend,        // SessionStorageBackend (required)
    serializer:     $serializer,     // SessionSerializer (default: PhpSessionSerializer)
    sessionFactory: $factory,        // SessionFactory (default: DefaultSessionFactory)
    events:         $dispatcher,     // PSR-14 EventDispatcherInterface (optional)
    clock:          $clock,          // PSR-20 ClockInterface (optional)
);
```

All five parameters are interfaces. You can replace any of them. The
handler does not care about concrete types.

If you use a PSR-11 container, bind each interface to your implementation
and let the container resolve the constructor. The handler has no hidden
state, service locators, or singletons.

## The Horde integration layer

The library described above is deliberately framework-agnostic. It knows
nothing about Horde's registry, preferences, authentication, or encryption.

The Horde application framework provides an integration layer in
`horde/core` (`Horde\Core\Session` namespace) that bridges the generic
library with Horde-specific concerns: scoped two-level key access,
authentication metadata, transparent per-key encryption, and wire
compatibility with the legacy `Horde_Session`.

### HordeSession

`HordeSession` extends `DefaultSession` and implements two capability
interfaces: `SessionMetaInterface` and `EncryptedValuesInterface`.

It stores data in the same two-level `$data[$app][$name]` structure that
PHP's native `$_SESSION` uses in a Horde installation. This means sessions
written by the legacy `Horde_Session` through `$_SESSION` can be loaded
directly into `HordeSession` without any conversion, and vice versa.

```php
use Horde\Core\Session\HordeSession;

// Scoped access -- maps directly to $_SESSION[$app][$name]
$session->getScoped('horde', 'auth/userId');    // reads $data['horde']['auth/userId']
$session->setScoped('imp', 'mailbox', 'INBOX'); // writes $data['imp']['mailbox']
$session->keysForApp('horde');                  // ['auth/userId', 'auth/browser', ...]

// toPayload() returns the native $_SESSION structure
$payload = $session->toPayload();
// ['horde' => ['auth/userId' => 'alice'], 'imp' => ['mailbox' => 'INBOX'], '_b' => 1700000000]
```

Well-known keys (two-level `$_SESSION[$app][$name]`):

- `$_SESSION['horde']['auth/userId']` -- authenticated user
- `$_SESSION['horde']['auth/authId']` -- original login name before mapping
- `$_SESSION['horde']['auth/browser']` -- browser fingerprint at login
- `$_SESSION['horde']['auth/remoteAddr']` -- remote IP at login
- `$_SESSION['horde']['auth/timestamp']` -- authentication time (Unix epoch)
- `$_SESSION['horde']['auth_app/{app}']` -- per-application credentials (typically encrypted)
- `$_SESSION['_b']` -- session begin timestamp
- `$_SESSION['_e']` -- encryption map (`$_SESSION['_e'][$app][$name] = true`)

### HordeSessionFactory

`HordeSessionFactory` extends `DefaultSessionFactory`. It accepts
optional encryption closures and passes them to every `HordeSession`
it creates. On `createNew()`, it sets the `_b` begin timestamp
automatically.

```php
use Horde\Core\Session\HordeSessionFactory;
use Horde\SessionHandler\SessionHandler;

$factory = new HordeSessionFactory(
    encryptor: fn(string $plain): string => myEncrypt($plain),
    decryptor: fn(string $cipher): string => myDecrypt($cipher),
);

$handler = new SessionHandler(
    backend:        $backend,
    sessionFactory: $factory,
);

$session = $handler->create();
assert($session instanceof HordeSession);
$session->setEncrypted('horde', 'auth_app/imp', ['password' => 'secret']);
$handler->save($session);
```

Since `HordeSession` uses the native two-level storage format,
`restore()` passes the deserialized `$_SESSION` array directly --
no flattening or conversion is needed.

In the Horde application framework, the factory is constructed by
`SessionHandlerFactory` with closures wrapping `Horde_Secret`.

### SessionMetaInterface

An interface for session objects that carry structured metadata beyond
what `SessionMetadata` provides. Where `SessionMetadata` is
backend-reported timing data (created, modified, expires),
`SessionMetaInterface` exposes application-level metadata extracted from
the session payload:

```php
use Horde\Core\Session\SessionMetaInterface;

if ($session instanceof SessionMetaInterface) {
    $session->getAuthenticatedUser();    // ?string  -- 'alice'
    $session->getAuthId();               // ?string  -- 'alice@example.com'
    $session->getBrowserFingerprint();   // ?string  -- 'Mozilla/5.0 ...'
    $session->getRemoteAddress();        // ?string  -- '192.168.1.1'
    $session->getAuthTimestamp();        // ?DateTimeImmutable
    $session->getSessionBegin();         // ?DateTimeImmutable
    $session->getAuthenticatedApps();    // string[] -- ['imp', 'kronolith']
}
```

The admin "active sessions" page uses this interface to display session
details without parsing raw payload data. Backends do not implement
this -- session objects do.

### EncryptedValuesInterface

An interface for session objects that support transparent per-key
encryption. Horde encrypts sensitive per-application credentials
(`auth_app/*`) with a secret derived from the user's authentication
token.

```php
use Horde\Core\Session\EncryptedValuesInterface;

if ($session instanceof EncryptedValuesInterface) {
    $session->setEncrypted('horde', 'auth_app/imp', $credentials);
    $session->getEncrypted('horde', 'auth_app/imp');  // decrypted
    $session->isEncrypted('horde', 'auth_app/imp');   // true
    $session->getEncryptionMap();
    // Two-level: ['horde' => ['auth_app/imp' => true]]
}
```

The encryption map uses the same two-level structure as the session data
itself, stored at `$_SESSION['_e'][$app][$name]`. This matches the legacy
`Horde_Session` encryption tracking format.

The encryption closures are injected into `HordeSession` via its
factory. The library-level `Session` interface stays unaware of
encryption -- it sees opaque values. Without closures, `setEncrypted()`
throws and `getEncrypted()` returns the raw stored value.

During session ID regeneration, `updateEncryptionCallbacks()` decrypts
all encrypted values with the old key and re-encrypts them with the new
one.

### NativePhpSessionSerializer

Sessions stored through PHP's native handler (`session_set_save_handler`
+ `session_start`) use PHP's own serialization format (`session_encode` /
`session_decode`), not `serialize()`. `NativePhpSessionSerializer`
bridges this gap:

```php
use Horde\SessionHandler\NativePhpSessionSerializer;
use Horde\SessionHandler\SerializedSessionPayload;
B
$serializer = new NativePhpSessionSerializer();

// Deserialize a session stored by the native PHP handler
$payload = new SerializedSessionPayload($rawSessionData);
$data = $serializer->deserialize($payload);
// $data is the decoded $_SESSION array
```

This is used by the admin sessions page to read sessions written through
`$_SESSION` and load them into `HordeSession` objects.

### Putting it together: admin session introspection

The Horde admin "active sessions" page demonstrates the full integration.
It reads sessions stored by the legacy native handler and displays them
using the modern typed interfaces:

```php
use Horde\Core\Session\HordeSessionFactory;
use Horde\Core\Session\SessionMetaInterface;
use Horde\SessionHandler\NativePhpSessionSerializer;
use Horde\SessionHandler\SerializedSessionPayload;
use Horde\SessionHandler\SessionId;

$serializer = new NativePhpSessionSerializer();
$factory = new HordeSessionFactory();

foreach ($legacyHandler->getSessionIDs() as $id) {
    $raw = $legacyHandler->read($id);
    $payload = new SerializedSessionPayload($raw);
    $data = $serializer->deserialize($payload);
    $session = $factory->restore(new SessionId($id), $data);

    if ($session instanceof SessionMetaInterface) {
        echo $session->getAuthenticatedUser();
        echo $session->getBrowserFingerprint();
        echo $session->getRemoteAddress();
    }
}
```

Since `HordeSession` uses the native two-level `$_SESSION` structure,
`HordeSessionFactory::restore()` passes the deserialized array directly.
No flattening or conversion factory is needed.

### Why this lives in horde/core

`HordeSession`, `HordeSessionFactory`, `SessionMetaInterface`, and
`EncryptedValuesInterface` live in the `horde/core` package
(`Horde\Core\Session` namespace), not in this library. They embody
Horde-specific conventions: well-known authentication keys, scoped
app/key access, encryption via `Horde_Secret`. Keeping them in
`horde/core` ensures this library stays framework-agnostic.

The encryption closures are injected -- there is no dependency on
`Horde_Secret` or any other Horde package at the library level.
`Horde\Core\Factory\SessionHandlerFactory` constructs
`HordeSessionFactory` with the right closures for the Horde environment.

Backend authors do not need to think about any of this. If you are
writing a storage backend, implement `SessionStorageBackend` and the
capability interfaces your store supports. The Horde layer is separate and opt-in.
