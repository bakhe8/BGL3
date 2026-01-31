<?php
/**
 * BGL3 Logic Bridge
 * 
 * Allows the Python Guardian to invoke PHP business logic (ConflictDetector) 
 * via CLI and retrieve Structured JSON reports.
 */

declare(strict_types=1);

require_once __DIR__ . '/../../vendor/autoload.php';

use App\Services\ConflictDetector;

// Ensure we have input
$inputJson = file_get_contents('php://stdin');
if (!$inputJson) {
    echo json_encode(['status' => 'ERROR', 'message' => 'No input data provided via stdin']);
    exit(1);
}

try {
    $data = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);

    // Check for required keys for ConflictDetector
    if (!isset($data['candidates'], $data['record'])) {
        echo json_encode(['status' => 'ERROR', 'message' => 'Invalid data structure. Key "candidates" and "record" required.']);
        exit(1);
    }

    $detector = new ConflictDetector();
    $conflicts = $detector->detect($data['candidates'], $data['record']);

    echo json_encode([
        'status' => 'SUCCESS',
        'conflicts' => $conflicts,
        'timestamp' => time()
    ]);

} catch (\Throwable $e) {
    echo json_encode([
        'status' => 'ERROR',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString()
    ]);
    exit(1);
}
