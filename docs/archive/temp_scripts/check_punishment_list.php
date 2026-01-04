<?php
// Check punishment list is now populated

require __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Checking Punishment List ===\n\n";

// Get rejections with supplier details
$stmt = $db->query("
    SELECT 
        lc.id,
        lc.raw_supplier_name as pattern,
        s.official_name as supplier,
        lc.count,
        lc.confidence,
        lc.updated_at
    FROM learning_confirmations lc
    LEFT JOIN suppliers s ON lc.supplier_id = s.id
    WHERE lc.action = 'reject'
    ORDER BY lc.updated_at DESC
    LIMIT 10
");
$rejections = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($rejections)) {
    echo "❌ Punishment list is EMPTY\n";
} else {
    echo "✅ Found " . count($rejections) . " rejection(s) in punishment list:\n\n";
    foreach ($rejections as $r) {
        echo "ID {$r['id']}: Pattern \"{$r['pattern']}\" → {$r['supplier']}\n";
        echo "  Count: {$r['count']}, Confidence: {$r['confidence']}%, Updated: {$r['updated_at']}\n\n";
    }
}

// Test penalty calculation
echo "\n=== Testing 25% Penalty Calculation ===\n";
echo "Base confidence: 100%\n";
for ($i = 1; $i <= 5; $i++) {
    $penaltyFactor = pow(0.75, $i);
    $finalConfidence = (int) (100 * $penaltyFactor);
    echo "After $i rejection(s): " . $finalConfidence . "%\n";
}
