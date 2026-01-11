<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    // Fetch a sample of events that SHOULD be manual but might be showing as System
    $stmt = $db->query("
        SELECT id, event_type, created_by, created_at
        FROM guarantee_history 
        WHERE event_type IN ('manual_match', 'extension')
        ORDER BY created_at DESC
        LIMIT 5
    ");
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
