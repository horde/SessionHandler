<?php

/**
 * Example: Capability detection — listing sessions.
 *
 * The FileBackend supports session iteration (IterableSessionBackend).
 * The BuiltinBackend does not. This example shows how to detect and
 * handle both cases gracefully.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use Horde\SessionHandler\Exception\CapabilityException;
use Horde\SessionHandler\IterableSessionBackend;
use Horde\SessionHandler\SessionHandler;
use Horde\SessionHandler\Storage\BuiltinBackend;
use Horde\SessionHandler\Storage\FileBackend;

// --- Helper: create some sessions in a backend, then try to list them ---

function demonstrateBackend(string $label, SessionHandler $handler): void
{
    echo "=== {$label} ===\n\n";

    // Create a few sessions
    for ($i = 1; $i <= 3; $i++) {
        $session = $handler->create();
        $session->set('user', "user_{$i}");
        $handler->save($session);
        echo "Created session: " . $session->getId() . "\n";
    }

    echo "\n";

    // --- Option A: Check the capability before calling ---

    // The backend is accessible through the handler's constructor, but
    // in practice you'd typically just try and catch. Shown here for
    // completeness.

    // --- Option B: Try and handle the exception ---

    try {
        $sessions = $handler->listSessions();
        $count = 0;

        foreach ($sessions as $id) {
            $count++;
            echo "  Found session: " . $id . "\n";
        }

        echo "\nListed {$count} sessions.\n";
    } catch (CapabilityException $e) {
        echo "Cannot list sessions: " . $e->getMessage() . "\n";
        echo "This backend does not support iteration.\n";
    }

    echo "\n";
}

// --- FileBackend: supports iteration ---

$filePath = sys_get_temp_dir() . '/horde_session_capability_example';
if (!is_dir($filePath)) {
    mkdir($filePath, 0o700, true);
}

$fileHandler = new SessionHandler(
    backend: new FileBackend($filePath),
);

demonstrateBackend('FileBackend (supports iteration)', $fileHandler);

// --- BuiltinBackend: does NOT support iteration ---

$builtinHandler = new SessionHandler(
    backend: new BuiltinBackend(sys_get_temp_dir()),
);

demonstrateBackend('BuiltinBackend (no iteration)', $builtinHandler);

// --- Cleanup ---
array_map('unlink', glob($filePath . '/horde_sh_*'));
rmdir($filePath);

echo "Done.\n";
