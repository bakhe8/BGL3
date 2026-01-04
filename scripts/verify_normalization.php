<?php
/**
 * Verify Normalization Correctness
 * 
 * Purpose: Verify populated normalized_supplier_name matches manual normalization
 * Run: AFTER population script
 */

require __DIR__ . '/../app/Support/Database.php';
require __DIR__ . '/../app/Utils/ArabicNormalizer.php';

use App\Support\Database;
use App\Utils\ArabicNormalizer;

echo "=================================================\n";
echo "Verifying Normalization Correctness\n";
echo "=================================================\n\n";

$db = Database::connect();

// ============================================================
// Test 1: Guarantees Normalization
// ============================================================

echo "[1/2] Verifying guarantees.normalized_supplier_name...\n";

$stmt = $db->query("
    SELECT id, raw_data, normalized_supplier_name
    FROM guarantees
    WHERE normalized_supplier_name IS NOT NULL
    ORDER BY RANDOM()
    LIMIT 100
");

$guaranteeErrors = 0;
$guaranteeTested = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rawData = json_decode($row['raw_data'], true);
    $supplierName = $rawData['supplier'] ?? null;
    
    if (!$supplierName) {
        continue;  // Skip if no supplier name
    }
    
    $expected = ArabicNormalizer::normalize($supplierName);
    $actual = $row['normalized_supplier_name'];
    
    if ($expected !== $actual) {
        echo "  ❌ ID {$row['id']}: Expected='$expected', Actual='$actual'\n";
        $guaranteeErrors++;
    }
    
    $guaranteeTested++;
}

if ($guaranteeErrors === 0) {
    echo "✅ All $guaranteeTested guarantees normalized correctly\n";
} else {
    echo "❌ Found $guaranteeErrors mismatches in guarantees\n";
}
echo "\n";

// ============================================================
// Test 2: Learning Confirmations Normalization
// ============================================================

echo "[2/2] Verifying learning_confirmations.normalized_supplier_name...\n";

$stmt = $db->query("
    SELECT id, raw_supplier_name, normalized_supplier_name
    FROM learning_confirmations
    ORDER BY RANDOM()
    LIMIT 100
");

$learningErrors = 0;
$learningTested = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $expected = ArabicNormalizer::normalize($row['raw_supplier_name']);
    $actual = $row['normalized_supplier_name'];
    
    if ($expected !== $actual) {
        echo "  ❌ ID {$row['id']}: Expected='$expected', Actual='$actual'\n";
        $learningErrors++;
    }
    
    $learningTested++;
}

if ($learningErrors === 0) {
    echo "✅ All $learningTested learning records normalized correctly\n";
} else {
    echo "❌ Found $learningErrors mismatches in learning_confirmations\n";
}
echo "\n";

// ============================================================
// Final Result
// ============================================================

$totalErrors = $guaranteeErrors + $learningErrors;

echo "=================================================\n";
if ($totalErrors === 0) {
    echo "✅ Verification PASSED\n";
    echo "=================================================\n";
    echo "All normalized values are correct.\n";
    exit(0);
} else {
    echo "❌ Verification FAILED\n";
    echo "=================================================\n";
    echo "Found $totalErrors normalization errors.\n";
    echo "RECOMMENDATION: Rollback and investigate.\n";
    exit(1);
}
