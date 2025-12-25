<?php
/**
 * Migration API Endpoint
 * Run schema migration safely
 */

header('Content-Type: application/json');

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

try {
    $db = Database::connect();
    
    $results = [];
    
    // Check if columns already exist
    $columns = $db->query("PRAGMA table_info(guarantee_history)")->fetchAll(PDO::FETCH_ASSOC);
    $existingColumns = array_column($columns, 'name');
    
    // Add event_type
    if (!in_array('event_type', $existingColumns)) {
        $db->exec("ALTER TABLE guarantee_history ADD COLUMN event_type TEXT");
        $results[] = "✓ Added event_type column";
    } else {
        $results[] = "→ event_type already exists";
    }
    
    // Add snapshot_data
    if (!in_array('snapshot_data', $existingColumns)) {
        $db->exec("ALTER TABLE guarantee_history ADD COLUMN snapshot_data TEXT");
        $results[] = "✓ Added snapshot_data column";
    } else {
        $results[] = "→ snapshot_data already exists";
    }
    
    // Add event_details  
    if (!in_array('event_details', $existingColumns)) {
        $db->exec("ALTER TABLE guarantee_history ADD COLUMN event_details TEXT");
        $results[] = "✓ Added event_details column";
    } else {
        $results[] = "→ event_details already exists";
    }
    
    echo json_encode([
        'success' => true,
        'phase' => 1,
        'message' => 'Schema migration complete',
        'results' => $results
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
