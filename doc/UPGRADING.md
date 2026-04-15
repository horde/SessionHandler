# Upgrading to Horde\SessionHandler (src/)

This document covers the migration from the legacy `Horde_SessionHandler`
classes in `lib/` to the modern `Horde\SessionHandler` namespace in `src/`.

## Coexistence

Both namespaces autoload from the same package. You do not need to choose
one or the other immediately:

```json
{
    "autoload": {
        "psr-0": { "Horde_SessionHandler": "lib/" },
        "psr-4": { "Horde\\SessionHandler\\": "src/" }
    }
}
```

Legacy code using `Horde_SessionHandler` and `Horde_SessionHandler_Storage_Sql`
continues to work. New code can use `Horde\SessionHandler\SessionHandler` and
`Horde\SessionHandler\Storage\SqlBackend` in the same application.

## Namespace mapping

| Legacy (lib/) | Modern (src/) |
|---|---|
| `Horde_SessionHandler` | `Horde\SessionHandler\SessionHandler` |
| `Horde_SessionHandler_Storage` (abstract) | `Horde\SessionHandler\SessionStorageBackend` (interface) |
| `Horde_SessionHandler_Storage_Sql` | `Horde\SessionHandler\Storage\SqlBackend` |
| `Horde_SessionHandler_Storage_File` | `Horde\SessionHandler\Storage\FileBackend` |
| `Horde_SessionHandler_Storage_Hashtable` | `Horde\SessionHandler\Storage\HashtableBackend` |
| `Horde_SessionHandler_Storage_Stack` | `Horde\SessionHandler\Storage\StackBackend` |
| `Horde_SessionHandler_Storage_External` | `Horde\SessionHandler\Storage\ExternalBackend` |
| `Horde_SessionHandler_Storage_Builtin` | `Horde\SessionHandler\Storage\BuiltinBackend` |
| `Horde_SessionHandler_Exception` | `Horde\SessionHandler\Exception\SessionException` |
| _(none)_ | `Horde\SessionHandler\Exception\CapabilityException` |
| _(none)_ | `Horde\SessionHandler\Exception\SessionLockException` |
| _(none)_ | `Horde\SessionHandler\Exception\SessionNotFoundException` |
| _(none)_ | `Horde\SessionHandler\Exception\SerializationException` |

## Dropped backends

| Legacy class | Reason | Alternative |
|---|---|---|
| `Horde_SessionHandler_Storage_Memcache` | Deprecated | Use `HashtableBackend` with `Horde_HashTable_Memcache` |
| `Horde_SessionHandler_Storage_Mongo` | ext-mongo removed from PHP | Needs ext-mongodb rewrite (future) |

## Constructor changes

Legacy constructors accept `array $params`. Modern constructors use typed named parameters:

### SQL

```php
// Legacy
$storage = new Horde_SessionHandler_Storage_Sql([
    'db' => $adapter,
    'table' => 'horde_sessionhandler',
]);

// Modern
use Horde\SessionHandler\Storage\SqlBackend;
$backend = new SqlBackend(
    db: $adapter,                          // Horde\Db\Adapter
    table: 'horde_sessionhandler',         // default
);
```

The modern backend accepts `Horde\Db\Adapter` (the PSR-4 interface). Since
`Horde\Db\Adapter extends Horde_Db_Adapter` any old or new adapter satisfies the type hint.

### HashTable

```php
// Legacy
$storage = new Horde_SessionHandler_Storage_Hashtable([
    'hashtable' => $ht,
    'track' => true,
    'track_id' => 'horde_sessions_track_ht',
]);

// Modern
use Horde\SessionHandler\Storage\HashtableBackend;
$backend = new HashtableBackend(
    hashTable: $ht,                        // Horde_HashTable_Base & Horde_HashTable_Lock
    track: true,
    trackKey: 'horde_sessions_track_ht',   // default
);
```

**Breaking change:** the modern constructor requires the hash table to
implement `Horde_HashTable_Lock` (enforced by an intersection type). 
The legacy class checked `$ht->locking` at runtime. If your hash table does not
support locking, you cannot use `HashtableBackend`.

### Stack

```php
// Legacy
$storage = new Horde_SessionHandler_Storage_Stack([
    'stack' => [$cacheStorage, $masterStorage],
]);

// Modern
use Horde\SessionHandler\Storage\StackBackend;
$backend = new StackBackend($cacheBackend, $masterBackend);
```

The modern constructor is variadic. The last argument is the master. At
least one backend is required.

### External

```php
// Legacy -- six string callbacks
$storage = new Horde_SessionHandler_Storage_External([
    'open'    => 'myOpen',
    'close'   => 'myClose',
    'read'    => 'myRead',
    'write'   => 'myWrite',
    'destroy' => 'myDestroy',
    'gc'      => 'myGc',
]);

// Modern -- three Closure parameters
use Horde\SessionHandler\Storage\ExternalBackend;
$backend = new ExternalBackend(
    readCallback:   fn(SessionId $id): ?SerializedSessionPayload => ...,
    writeCallback:  fn(SessionId $id, SerializedSessionPayload $p, DateTimeImmutable $exp): void => ...,
    deleteCallback: fn(SessionId $id): void => ...,
);
```

The `open`, `close`, and `gc` callbacks are gone. `open`/`close` are no-ops
in the modern handler. Garbage collection is handled by
`SessionHandler::gc()` using `IterableSessionBackend` and
`SessionMetadataBackend` if the backend supports them.

### File

```php
// Legacy
$storage = new Horde_SessionHandler_Storage_File(['path' => '/var/sessions']);

// Modern
use Horde\SessionHandler\Storage\FileBackend;
$backend = new FileBackend(path: '/var/sessions');
```

### Builtin

```php
// Legacy
$storage = new Horde_SessionHandler_Storage_Builtin(['path' => session_save_path()]);

// Modern
use Horde\SessionHandler\Storage\BuiltinBackend;
$backend = new BuiltinBackend(path: session_save_path());
```

## Orchestrator changes

### Construction

```php
// Legacy
$handler = new Horde_SessionHandler($storage, [
    'logger' => $logger,
    'noset'  => false,
    'parse'  => 'myParseCallback',
]);

// Modern
use Horde\SessionHandler\SessionHandler;
$handler = new SessionHandler(
    backend:        $backend,
    serializer:     new PhpSessionSerializer(),      // default
    sessionFactory: new DefaultSessionFactory(),     // default
    events:         $dispatcher,                     // PSR-14, optional
    clock:          $clock,                          // PSR-20, optional
);
```

- **Logging** is replaced by PSR-14 events. Attach a listener that logs
  `BackendError` and `LockFailed` events.
- **`noset`** is gone. Call `session_set_save_handler($handler, true)`
  yourself when you want the handler registered.
- **`parse`** callback is gone. Session introspection is done through the
  `Session` interface (`keys()`, `get()`, `has()`).

### Dual API

The legacy handler is purely a `SessionHandlerInterface` implementation --
it reads and writes raw strings through PHP's session machinery.

The modern handler exposes two APIs:

1. **Object API** -- `create()`, `load()`, `save()`, `destroySession()`,
   `regenerate()`. Works with `Session` objects. Exceptions propagate.

2. **Native PHP adapter** -- `open()`, `read()`, `write()`, `destroy()`,
   `gc()`, `create_sid()`, `validateId()`, `updateTimestamp()`. Works with
   raw strings. Catches exceptions and returns `false`.

Use the object API for new code. Use the native adapter when you need
`$_SESSION` compatibility.

### Capability detection

The legacy handler calls methods like `getSessionIDs()` on the storage
base class, which returns an empty array or throws depending on the backend.

The modern handler uses `instanceof` checks:

```php
// Legacy
try {
    $ids = $storage->getSessionIDs();
} catch (Horde_SessionHandler_Exception $e) {
    // Not supported
}

// Modern -- check the backend type
if ($backend instanceof IterableSessionBackend) {
    foreach ($backend->listSessions() as $sessionId) { ... }
}

// Or let the handler throw
try {
    foreach ($handler->listSessions() as $sessionId) { ... }
} catch (CapabilityException $e) {
    // Backend does not support iteration
}
```

## Data compatibility

### File backend

Both the legacy and modern file backends use the `horde_sh_` prefix and
store raw session bytes. The file format is identical. You can switch
between legacy and modern without migrating session files.

### SQL backend

Both use the same `horde_sessionhandler` table with columns `session_id`,
`session_data`, `session_lastmodified`. The schema is unchanged. You can
switch between legacy and modern on the same table.

### HashTable backend

Both store opaque blobs keyed by session ID and (when tracking is enabled)
a JSON-encoded set of active IDs under the same default tracking key
(`horde_sessions_track_ht`). Wire compatible.

## Serialization caveat

When using the native PHP adapter (`session_set_save_handler` +
`session_start`), PHP uses its own serialization format (`session_encode` /
`session_decode`), which differs from `serialize()`.

The explicit object API uses `PhpSessionSerializer` which calls
`serialize()` / `unserialize()`.

These two formats are **not interchangeable**. A session written through
`$_SESSION` cannot be loaded through `$handler->load()` with the default
`PhpSessionSerializer`, and vice versa. Pick one path per session and
stick with it.

If you need to read sessions stored by the native handler through the
object API (for example, admin introspection), use
`NativePhpSessionSerializer`. It wraps `session_decode()` /
`session_encode()` behind the `SessionSerializer` interface:

```php
use Horde\SessionHandler\NativePhpSessionSerializer;
use Horde\SessionHandler\SerializedSessionPayload;

$serializer = new NativePhpSessionSerializer();
$payload = new SerializedSessionPayload($rawNativeSessionData);
$data = $serializer->deserialize($payload);
// $data is the decoded $_SESSION array
```

Combine this with `HordeSessionFactory` (from `horde/core`,
`Horde\Core\Session` namespace) to get `HordeSession` objects from
native session data. Since `HordeSession` uses the same two-level
`$_SESSION[$app][$name]` structure as the legacy handler, no conversion
is needed -- `restore()` passes the deserialized array directly. See
[doc/EXTENDING.md](EXTENDING.md) for the full pattern.

## Exception changes

The legacy library catches most storage exceptions internally and logs
them. The modern library propagates exceptions to application code through
the object API:

- `SessionException` -- base class (replaces `Horde_SessionHandler_Exception`)
- `CapabilityException` -- backend lacks the requested capability
- `SessionLockException` -- lock acquire/release failure
- `SessionNotFoundException` -- session not in storage
- `SerializationException` -- corrupt or unparseable session data

The native PHP adapter methods (`read()`, `write()`, `destroy()`) still
catch exceptions and return `false`, matching PHP's expected behavior for
`SessionHandlerInterface`.

## Migration strategy

1. **Install the package.** Both namespaces autoload. Nothing breaks.

2. **New code uses modern classes.** Create `SessionHandler` with the
   appropriate backend. Use the object API or register as native handler.

3. **Migrate existing code incrementally.** Replace `Horde_SessionHandler`
   instantiation with `SessionHandler`. Replace `Horde_SessionHandler_Storage_Sql`
   with `SqlBackend`. The storage format is compatible -- no data migration
   needed for file, SQL, or HashTable backends.

4. **Remove legacy usage.** Once no code references `Horde_SessionHandler`
   or its storage classes, the `lib/` tree can be removed in a future
   major version.
