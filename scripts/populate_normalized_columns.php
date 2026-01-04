<?php
/**
 * Populate normalized_supplier_name columns
 * 
 * Purpose: Fill new normalized columns from existing data
 * Run AFTER: 2026_01_04_learning_merge_schema.sql
 * 
 * This script:
 * 1. Populates guarantees.normalized_supplier_name from raw_data['supplier']
 * 2. Populates learning_confirmations.normalized_supplier_name from raw_supplier_name
 */

require __DIR__ . '/../app/Support/Database.php';
require __DIR__ . '/../app/Utils/ArabicNormalizer.php';

use App\Support\Database;
use App\Utils\ArabicNormalizer;

echo "=================================================\n";
echo "Learning Merge: Populate Normalized Columns\n";
echo "=================================================\n\n";

// Connect to database
$db = Database::connect();

// ============================================================
// STEP 1: Populate guarantees.normalized_supplier_name
// ============================================================

echo "[1/2] Populating guarantees.normalized_supplier_name...\n";

$stmt = $db->query("SELECT id, raw_data FROM guarantees");
$guaranteeCount = 0;
$guaranteeSkipped = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $rawData = json_decode($row['raw_data'], true);
    
    if (!$rawData) {
        echo "  ⚠️  Warning: Invalid JSON for guarantee ID {$row['id']}\n";
        $guaranteeSkipped++;
        continue;
    }
    
    $supplierName = $rawData['supplier'] ?? null;
    
    if (!$supplierName) {
        // No supplier name in this guarantee (possible for pending imports)
        $guaranteeSkipped++;
        continue;
    }
    
    // Normalize the supplier name
    $normalized = ArabicNormalizer::normalize($supplierName);
    
    // Update the row
    $update = $db->prepare("UPDATE guarantees SET normalized_supplier_name = ? WHERE id = ?");
    $update->execute([$normalized, $row['id']]);
    
    $guaranteeCount++;
    
    // Progress indicator
    if ($guaranteeCount % 100 === 0) {
        echo "  - Processed $guaranteeCount guarantees...\n";
    }
}

echo "✅ Populated $guaranteeCount guarantees\n";
if ($guaranteeSkipped > 0) {
    echo "⚠️  Skipped $guaranteeSkipped guarantees (no supplier name or invalid JSON)\n";
}
echo "\n";

// ============================================================
// STEP 2: Populate learning_confirmations.normalized_supplier_name
// ============================================================

echo "[2/2] Populating learning_confirmations.normalized_supplier_name...\n";

$stmt = $db->query("SELECT id, raw_supplier_name FROM learning_confirmations");
$learningCount = 0;

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    // Normalize the raw supplier name
    $normalized = ArabicNormalizer::normalize($row['raw_supplier_name']);
    
    // Update the row
    $update = $db->prepare("UPDATE learning_confirmations SET normalized_supplier_name = ? WHERE id = ?");
    $update->execute([$normalized, $row['id']]);
    
    $learningCount++;
    
    // Progress indicator
    if ($learningCount % 100 === 0) {
        echo "  - Processed $learningCount learning records...\n";
    }
}

echo "✅ Populated $learningCount learning_confirmations\n";
echo "\n";

// ============================================================
// Verification
// ============================================================

echo "=================================================\n";
echo "Verifying Population Completeness\n";
echo "=================================================\n\n";

// Check guarantees
$stmt = $db->query("
    SELECT 
        COUNT(*) as total, 
        COUNT(normalized_supplier_name) as populated,
        COUNT(*) - COUNT(normalized_supplier_name) as missing
    FROM guarantees
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Guarantees:\n";
echo "  Total:     {$result['total']}\n";
echo "  Populated: {$result['populated']}\n";
echo "  Missing:   {$result['missing']}\n";

if ($result['missing'] > 0) {
    echo "  ⚠️  WARNING: {$result['missing']} guarantees have NULL normalized_supplier_name\n";
    echo "  This is expected for guarantees without supplier names.\n";
}
echo "\n";

// Check learning_confirmations
$stmt = $db->query("
    SELECT 
        COUNT(*) as total, 
        COUNT(normalized_supplier_name) as populated,
        COUNT(*) - COUNT(normalized_supplier_name) as missing
    FROM learning_confirmations
");
$result = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Learning Confirmations:\n";
echo "  Total:     {$result['total']}\n";
echo "  Populated: {$result['populated']}\n";
echo "  Missing:   {$result['missing']}\n";

if ($result['missing'] > 0) {
    echo "  ❌ ERROR: {$result['missing']} learning records have NULL normalized_supplier_name\n";
    echo "  This should NOT happen. Investigation required.\n";
    exit(1);
} else {
    echo "  ✅ All learning records populated successfully\n";
}
echo "\n";

echo "=================================================\n";
echo "✅ Migration Complete\n";
echo "=================================================\n";
echo "\n";
echo "Next steps:\n";
echo "1. Run: php scripts/verify_normalization.php\n";
echo "2. Run: php scripts/compare_historical_queries.php\n";
echo "3. Update code to use normalized columns\n";
echo "\n";
