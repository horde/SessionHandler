<?php

/**
 * Example: Listing a session's keys.
 *
 * Demonstrates Session::keys() for introspecting what data a session
 * contains without reading the values. This is the library-level
 * primitive that enables the Horde integration layer to distinguish
 * encrypted from unencrypted keys and read only what it can.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Horde\SessionHandler\SessionHandler;
use Horde\SessionHandler\Storage\FileBackend;

// --- Setup ---

$storagePath = sys_get_temp_dir() . '/horde_session_keys_example';
if (!is_dir($storagePath)) {
    mkdir($storagePath, 0o700, true);
}

$handler = new SessionHandler(
    backend: new FileBackend($storagePath),
);

// --- Create a session with mixed data ---

$session = $handler->create();

$session->set('auth/userId', 'alice');
$session->set('auth/browser', 'Mozilla/5.0');
$session->set('auth/remoteAddr', '192.168.1.42');
$session->set('auth/timestamp', time());
$session->set('auth_app/mail', '<opaque encrypted blob>');
$session->set('auth_app/calendar', '<opaque encrypted blob>');
$session->set('_e', [
    'horde' => [
        'auth_app/mail' => true,
        'auth_app/calendar' => true,
    ],
]);

$handler->save($session);

// --- Load it back and introspect keys ---

$loaded = $handler->load($session->getId());

echo "Session: " . $loaded->getId() . "\n\n";
echo "All keys:\n";

foreach ($loaded->keys() as $key) {
    echo "  " . $key . "\n";
}

// --- Selective reading based on key names ---
//
// An application layer (like the Horde bridge) can use keys() to decide
// what to read. Here we simulate the admin sessions page pattern:
// read only unencrypted auth/ keys, extract app names from auth_app/ keys.

echo "\n--- Admin session view ---\n\n";

$encryptionMap = $loaded->get('_e') ?? [];

foreach ($loaded->keys() as $key) {
    // Skip internal metadata
    if (str_starts_with($key, '_')) {
        continue;
    }

    // Check if this key is encrypted (Horde convention: _e map)
    $isEncrypted = false;
    foreach ($encryptionMap as $app => $keys) {
        if (isset($keys[$key])) {
            $isEncrypted = true;
            break;
        }
    }

    if ($isEncrypted) {
        echo "  {$key}: [encrypted]\n";
    } else {
        echo "  {$key}: " . var_export($loaded->get($key), true) . "\n";
    }
}

// --- Extract app names from auth_app/ keys without reading values ---

echo "\nAuthenticated apps (from key names only):\n";

foreach ($loaded->keys() as $key) {
    if (str_starts_with($key, 'auth_app/')) {
        $appName = substr($key, strlen('auth_app/'));
        echo "  " . $appName . "\n";
    }
}

// --- Cleanup ---
array_map('unlink', glob($storagePath . '/horde_sh_*'));
rmdir($storagePath);

echo "\nDone.\n";
