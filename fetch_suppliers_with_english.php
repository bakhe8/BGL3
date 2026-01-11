<?php
require_once __DIR__ . '/app/Support/Database.php';
use App\Support\Database;

try {
    $db = Database::connect();
    // Fetch suppliers where english_name is not null and not empty
    $stmt = $db->query("
        SELECT id, official_name, english_name, is_confirmed, created_at 
        FROM suppliers 
        WHERE english_name IS NOT NULL AND english_name != ''
        ORDER BY official_name
    ");
    
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Found " . count($suppliers) . " suppliers with English names:\n\n";
    
    foreach ($suppliers as $s) {
        echo sprintf(
            "[%d] %s (EN: %s) - Confirmed: %s\n", 
            $s['id'], 
            $s['official_name'], 
            $s['english_name'],
            $s['is_confirmed'] ? 'Yes' : 'No'
        );
    }
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
