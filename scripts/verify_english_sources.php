<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    // 1. Analyze Guarantees (The Source)
    $stmt = $db->query("SELECT id, raw_data FROM guarantees");
    $guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $totalGuarantees = count($guarantees);
    $englishSourceCount = 0;
    $arabicSourceCount = 0;
    
    echo "--- Source Analysis (Guarantees Table) ---\n";
    foreach ($guarantees as $g) {
        $raw = json_decode($g['raw_data'], true);
        $rawName = trim($raw['supplier'] ?? '');
        
        if (empty($rawName)) continue;
        
        // Check for Arabic
        if (preg_match('/\p{Arabic}/u', $rawName)) {
            $arabicSourceCount++;
        } else {
            $englishSourceCount++;
            echo " [ENG Source] {$rawName}\n";
        }
    }
    
    echo "\nStats:\n";
    echo "Total Guarantees: $totalGuarantees\n";
    echo "Original English: $englishSourceCount\n";
    echo "Original Arabic : $arabicSourceCount\n";
    
    // 2. Analyze Suppliers (The Destination)
    echo "\n\n--- Destination Analysis (Suppliers Table) ---\n";
    $stmt = $db->query("SELECT COUNT(*) FROM suppliers WHERE english_name IS NOT NULL AND english_name != ''");
    $savedCount = $stmt->fetchColumn();
    
    echo "Suppliers with English Name saved: $savedCount\n";
    
    if ($savedCount >= $englishSourceCount) {
        echo "\nRESULT: ✅ MATCH (All English sources are accounted for)\n";
        if ($savedCount > $englishSourceCount) {
             echo "Note: There are MORE saved English names than English sources. This implies some were manually added or came from other imports.\n";
        }
    } else {
        echo "\nRESULT: ⚠️ MISMATCH (Possible missing data)\n";
    }

} catch (Exception $e) {
    echo $e->getMessage();
}
