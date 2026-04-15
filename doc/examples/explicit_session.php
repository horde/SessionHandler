<?php

/**
 * Example: Using the session handler without PHP's $_SESSION global.
 *
 * This demonstrates the explicit object-oriented API where sessions are
 * created, loaded, saved, and destroyed through the SessionHandler directly.
 * No globals, no session_start(), no $_SESSION.
 *
 * Suitable for CLI tools, API backends, workers, and testing.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Horde\SessionHandler\SessionHandler;
use Horde\SessionHandler\Storage\FileBackend;

// --- Setup ---

$storagePath = sys_get_temp_dir() . '/horde_session_example';
if (!is_dir($storagePath)) {
    mkdir($storagePath, 0o700, true);
}

$handler = new SessionHandler(
    backend: new FileBackend($storagePath),
);

// --- Create a new session ---

$session = $handler->create();
echo "Created session: " . $session->getId() . "\n";

// Store some data
$session->set('username', 'alice');
$session->set('role', 'admin');
$session->set('login_time', time());

echo "Dirty after set: " . ($session->isDirty() ? 'yes' : 'no') . "\n";

// Persist it
$handler->save($session);
echo "Session saved.\n";

// --- Load it back (simulating a later request) ---

$loaded = $handler->load($session->getId());
echo "\nLoaded session: " . $loaded->getId() . "\n";
echo "Username: " . $loaded->get('username') . "\n";
echo "Role: " . $loaded->get('role') . "\n";
echo "Login time: " . $loaded->get('login_time') . "\n";
echo "Dirty after load: " . ($loaded->isDirty() ? 'yes' : 'no') . "\n";

// --- Regenerate the session ID ---

$regenerated = $handler->regenerate($loaded);
echo "\nRegenerated: " . $loaded->getId() . " -> " . $regenerated->getId() . "\n";
echo "Username still: " . $regenerated->get('username') . "\n";

// Old ID is gone
$gone = $handler->load($loaded->getId());
echo "Old session exists: " . ($gone !== null ? 'yes' : 'no') . "\n";

// Save the regenerated session (need to mark dirty first)
$regenerated->set('regenerated', true);
$handler->save($regenerated);

// --- Destroy ---

$handler->destroySession($regenerated->getId());
$destroyed = $handler->load($regenerated->getId());
echo "\nAfter destroy: " . ($destroyed !== null ? 'exists' : 'gone') . "\n";

// --- Cleanup ---
array_map('unlink', glob($storagePath . '/horde_sh_*'));
rmdir($storagePath);

echo "\nDone.\n";
