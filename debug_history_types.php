<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    $stmt = $db->query("
        SELECT DISTINCT event_type, event_subtype, created_by 
        FROM guarantee_history 
        ORDER BY event_type
    ");
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
