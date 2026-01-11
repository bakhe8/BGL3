<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    // Check ID 12
    $stmt = $db->prepare("SELECT id, event_type, event_subtype, created_by FROM guarantee_history WHERE guarantee_id = 12 ORDER BY created_at DESC");
    $stmt->execute();
    
    $results = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "Events for GID 12:\n" . json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
