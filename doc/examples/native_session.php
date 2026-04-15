<?php

/**
 * Example: Using the session handler with PHP's native $_SESSION global.
 *
 * This registers the SessionHandler as PHP's session save handler via
 * session_set_save_handler(), then uses session_start() and $_SESSION
 * as usual. The FileBackend persists the data.
 *
 * This is the migration path for legacy code that depends on $_SESSION.
 *
 * Note: In a real web application, session_start() is called once at the
 * beginning of the request. This CLI example captures the session ID and
 * verifies the file was written after session_write_close().
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Horde\SessionHandler\SessionHandler;
use Horde\SessionHandler\SessionId;
use Horde\SessionHandler\Storage\FileBackend;

// --- Setup ---

$storagePath = sys_get_temp_dir() . '/horde_session_global_example';
if (!is_dir($storagePath)) {
    mkdir($storagePath, 0o700, true);
}

$handler = new SessionHandler(
    backend: new FileBackend($storagePath),
);

// Register as PHP's session handler
session_set_save_handler($handler, true);

// Prevent "headers already sent" warnings in CLI
ob_start();
session_start();
ob_end_clean();

$sid = session_id();
echo "Session ID: " . $sid . "\n";

// Write through $_SESSION as usual
$_SESSION['username'] = 'bob';
$_SESSION['preferences'] = ['theme' => 'dark', 'language' => 'en'];
$_SESSION['counter'] = ($_SESSION['counter'] ?? 0) + 1;

echo "Username: " . $_SESSION['username'] . "\n";
echo "Counter: " . $_SESSION['counter'] . "\n";
echo "Theme: " . $_SESSION['preferences']['theme'] . "\n";

// Close the session — this triggers write() on the handler
session_write_close();
echo "\nSession written and closed.\n";

// --- Verify it was persisted by the FileBackend ---
//
// Note: PHP's native session system uses its own serialization format
// (session_encode), which differs from PHP's serialize(). The explicit
// API and the native $_SESSION path use different serialization, so
// they are not interchangeable for the same session data. Use one or
// the other — not both for the same session.

$sessionFile = $storagePath . '/horde_sh_' . $sid;
echo "Session file exists: " . (file_exists($sessionFile) ? 'yes' : 'no') . "\n";
echo "Session file size: " . filesize($sessionFile) . " bytes\n";

// --- Cleanup ---

array_map('unlink', glob($storagePath . '/horde_sh_*'));
@rmdir($storagePath);

echo "\nDone.\n";
