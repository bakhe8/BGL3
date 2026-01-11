<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    // Check distribution
    $stmt = $db->query("SELECT created_by, count(*) as c FROM guarantee_history GROUP BY created_by");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Current Distribution:\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    
    // Check a few 'system' candidates (e.g. status status_change)
    $stmt2 = $db->query("
        SELECT id, event_type, event_subtype, created_by 
        FROM guarantee_history 
        WHERE event_type IN ('status_change', 'auto_matched') 
        LIMIT 5
    ");
    $systemRows = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "Sample System Events:\n" . json_encode($systemRows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
