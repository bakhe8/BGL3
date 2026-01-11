<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    // Check for broken system events having User attribution
    $stmt = $db->query("
        SELECT id, event_type, event_subtype, created_by 
        FROM guarantee_history 
        WHERE event_type IN ('auto_matched', 'status_change') 
          AND created_by = 'بواسطة المستخدم'
    ");
    $broken = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Broken System Events (Should be System but are User): " . count($broken) . "\n";
    if (count($broken) > 0) {
        echo json_encode($broken, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo "No broken system events found.\n";
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
