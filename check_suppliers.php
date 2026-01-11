<?php
require_once __DIR__ . '/app/Support/Database.php';
use App\Support\Database;

try {
    $db = Database::connect();
    $stmt = $db->query("SELECT id, official_name, english_name FROM suppliers WHERE english_name IS NOT NULL AND english_name != ''");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Count: " . count($rows) . "\n";
    foreach($rows as $r) {
        echo "[{$r['id']}] {$r['official_name']} -> EN: '{$r['english_name']}'\n";
    }
} catch (Exception $e) {
    echo $e->getMessage();
}
