<?php
/**
 * V3 API - Smart Paste Parse (Text Analysis)
 * 
 * ✨ REFACTORED - Phase 10
 * Extracts guarantee details from unstructured text
 * Now uses specialized services for better maintainability
 * 
 * @version 2.0
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Models/Guarantee.php';
require_once __DIR__ . '/../app/Repositories/GuaranteeRepository.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Support\Input;
use App\Services\ParseCoordinatorService;

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// MAIN PROCESSING
// ============================================================================
try {
    // Get input
    $input = json_decode(file_get_contents('php://input'), true);
    if (!is_array($input)) {
        $input = [];
    }
    $text = Input::string($input, 'text', '');

    if (empty($text)) {
        throw new \RuntimeException("لم يتم إدخال أي نص للتحليل");
    }

    // Connect to database
    $db = Database::connect();
    
    // 🎯 NEW: Use ParseCoordinatorService to handle everything
    // This replaces 688 lines of inline logic with clean service calls
    $result = ParseCoordinatorService::parseText($text, $db);
    
    // Return result
    echo json_encode($result);
    
    // Set appropriate HTTP status
    if (!$result['success']) {
        http_response_code(400);
    }

} catch (\Throwable $e) {
    // Error handling
    error_log("Parse-paste error: " . $e->getMessage());
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'extracted' => [],
        'field_status' => []
    ]);
}
