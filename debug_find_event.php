<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    // Search for the specific event by timestamp (approximate to be safe)
    $targetDate = '2026-01-05 18:18:30';
    
    $stmt = $db->prepare("
        SELECT *
        FROM guarantee_history 
        WHERE created_at = ?
    ");
    $stmt->execute([$targetDate]);
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
