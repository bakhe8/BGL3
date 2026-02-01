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

header('Content-Type: application/json; charset=utf-8');

/**
 * خروج موحّد حتى في حالة Fatal.
 */
function safe_output(array $payload, int $code = 0): void {
    if ($code !== 0) {
        http_response_code(500);
    }
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit($code);
}

// Catch fatals وتحويلها لـ JSON
register_shutdown_function(function () {
    $err = error_get_last();
    if ($err && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR])) {
        safe_output([
            'status' => 'ERROR',
            'message' => $err['message'] ?? 'fatal',
            'fatal' => true,
        ], 1);
    }
});

$inputJson = file_get_contents('php://stdin');
if (!$inputJson) {
    safe_output(['status' => 'ERROR', 'message' => 'No input data provided via stdin'], 1);
}

try {
    $data = json_decode($inputJson, true, 512, JSON_THROW_ON_ERROR);

    if (!isset($data['candidates'], $data['record'])) {
        safe_output(['status' => 'ERROR', 'message' => 'Invalid data structure. Key "candidates" and "record" required.'], 1);
    }

    $detector = new ConflictDetector();
    $conflicts = $detector->detect($data['candidates'], $data['record']);

    safe_output([
        'status' => 'SUCCESS',
        'conflicts' => $conflicts,
        'timestamp' => time()
    ]);

} catch (\Throwable $e) {
    safe_output([
        'status' => 'ERROR',
        'message' => $e->getMessage(),
        'trace' => $e->getTraceAsString(),
        'fatal' => false,
    ], 1);
}
