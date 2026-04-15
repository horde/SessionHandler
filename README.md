# Horde\SessionHandler

Session management with typed objects, capability-detected backends, and no
framework opinions.

This library is right for people who like Symfony's rigor, Aura's cleanliness, all
my own bugs and PSR-style composability but don't want a framework in their session layer.

## Install

```
composer require horde/sessionhandler
```

## Quick start

```php
use Horde\SessionHandler\SessionHandler;
use Horde\SessionHandler\Storage\FileBackend;

$handler = new SessionHandler(
    backend: new FileBackend('/var/sessions'),
);

// Create, read, write, done.
$session = $handler->create();
$session->set('user', 'alice');
$handler->save($session);

// Later...
$session = $handler->load($session->getId());
echo $session->get('user'); // alice
```

Or register it as PHP's native handler and keep using `$_SESSION`:

```php
session_set_save_handler($handler, true);
session_start();
$_SESSION['user'] = 'alice';
```

## Architecture

The library is built around three ideas:

**Typed value objects.** Session IDs are `SessionId`, not strings that might be
empty. Payloads are `SerializedSessionPayload`, not strings that might be JSON
or might be PHP-serialized. Metadata is `SessionMetadata` with
`DateTimeImmutable` fields, not integer timestamps cast from database columns.

**Capability interfaces.** A backend declares what it can do through the
interfaces it implements. The core asks the type system, not a method that
returns `true`:

| Interface | What it means |
|---|---|
| `SessionStorageBackend` | load / save / delete (required) |
| `IterableSessionBackend` | can list active sessions |
| `SessionMetadataBackend` | can report created/modified/expires times |
| `AdministrativeSessionBackend` | can force-expire a session |
| `LockingSessionBackend` | can acquire/release per-session locks |

The orchestrator (`SessionHandler`) checks `instanceof` before calling
capability methods and throws `CapabilityException` when the backend
does not support the requested operation.

**PSR composability.** The handler takes an optional PSR-14
`EventDispatcherInterface` for lifecycle events and a PSR-20
`ClockInterface` for deterministic time in tests.

## Backends

| Class | Capabilities | Requires |
|---|---|---|
| `FileBackend` | all five | writable directory |
| `SqlBackend` | storage, iteration, metadata, admin | `horde/db` |
| `HashtableBackend` | storage, iteration*, admin | `horde/hashtable` |
| `StackBackend` | storage only | two or more backends |
| `ExternalBackend` | storage only | three closures |
| `BuiltinBackend` | storage only | PHP's default handler |

*HashtableBackend iteration requires `track: true` at construction.

### Constructors

```php
new FileBackend(string $path)
new SqlBackend(Horde\Db\Adapter $db, string $table = 'horde_sessionhandler')
new HashtableBackend(Horde_HashTable_Base & Horde_HashTable_Lock $ht, bool $track = false)
new StackBackend(SessionStorageBackend ...$backends)   // last is master
new ExternalBackend(Closure $read, Closure $write, Closure $delete)
new BuiltinBackend(string $path = '')
```

## The Session object

`Session` is an interface. The default implementation (`DefaultSession`) is an
in-memory key-value store with dirty tracking:

```php
$session->set('cart', []);       // marks dirty
$session->get('cart');           // []
$session->has('cart');           // true
$session->remove('cart');        // marks dirty
$session->keys();                // ['remaining', 'keys']
$session->isDirty();             // true
```

`SessionHandler::save()` is a no-op when `isDirty()` returns false.

## Events

When an `EventDispatcherInterface` is provided, the handler dispatches:

- `SessionCreated` -- after `create()`
- `SessionLoaded` -- after `load()`
- `SessionSaved` -- after `save()`
- `SessionDestroyed` -- after `destroySession()`
- `SessionExpired` -- after `expire()`
- `SessionIdRegenerated` -- after `regenerate()`, carries both old and new IDs

Two informational event classes (`BackendError`, `LockFailed`) exist for
application-level error monitoring.

## Extending

Custom backends implement `SessionStorageBackend` and optionally the
capability interfaces they support. Custom serializers implement
`SessionSerializer`. Custom session objects implement `Session` and come
with a matching `SessionFactory`.

The Horde application framework provides `HordeSession` (in `horde/core`,
namespace `Horde\Core\Session`) which extends `DefaultSession` with
scoped key access, authentication metadata via `SessionMetaInterface`,
and transparent per-key encryption via `EncryptedValuesInterface`.
`HordeSession` stores data in the same two-level `$data[$app][$name]`
structure as PHP's native `$_SESSION`, so sessions written by the legacy
handler are readable without conversion.

A `NativePhpSessionSerializer` bridges sessions stored through `$_SESSION`
with the object API.

See [doc/EXTENDING.md](doc/EXTENDING.md) for the full guide.

## Upgrading from the legacy Horde_SessionHandler

The `lib/` tree ships alongside `src/` for backward compatibility. Both
autoload paths are active in `composer.json`. Existing code using
`Horde_SessionHandler` and `Horde_SessionHandler_Storage_*` continues to
work without changes.

New code should use the `Horde\SessionHandler` namespace. The storage
format is wire-compatible for file and SQL backends, but the serialization
path differs between the native `$_SESSION` interface and the explicit
object API -- do not mix both for the same session.

See [doc/UPGRADING.md](doc/UPGRADING.md) for the detailed migration guide.

## License

LGPL-2.1-only. See [LICENSE](LICENSE).
