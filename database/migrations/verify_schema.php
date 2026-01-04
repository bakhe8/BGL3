<?php
// Verify schema after amount removal

require_once __DIR__ . '/../../app/Support/Database.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Schema Verification After amount Removal ===\n\n";

// 1. List all columns
echo "Current columns in guarantee_decisions:\n";
$columns = $db->query("PRAGMA table_info(guarantee_decisions)")->fetchAll(PDO::FETCH_ASSOC);

foreach ($columns as $col) {
    $nullable = $col['notnull'] == 0 ? 'NULL' : 'NOT NULL';
    $default = $col['dflt_value'] ? "DEFAULT {$col['dflt_value']}" : '';
    echo "  ✓ {$col['name']} ({$col['type']}) $nullable $default\n";
}

echo "\nTotal columns: " . count($columns) . "\n";

// 2. Verify amount is gone
$hasAmount = false;
foreach ($columns as $col) {
    if ($col['name'] === 'amount') {
        $hasAmount = true;
    }
}

if ($hasAmount) {
    echo "\n❌ ERROR: amount column still exists!\n";
} else {
    echo "\n✅ SUCCESS: amount column removed\n";
}

// 3. Check indexes
echo "\nIndexes:\n";
$indexes = $db->query("
    SELECT name, sql 
    FROM sqlite_master 
    WHERE type='index' 
      AND tbl_name='guarantee_decisions'
      AND name NOT LIKE 'sqlite_%'
")->fetchAll(PDO::FETCH_ASSOC);

foreach ($indexes as $idx) {
    echo "  ✓ {$idx['name']}\n";
}

// 4. Sample query
echo "\nSample query test:\n";
$sample = $db->query("
    SELECT id, guarantee_id, status, supplier_id, bank_id 
    FROM guarantee_decisions 
    LIMIT 3
")->fetchAll(PDO::FETCH_ASSOC);

echo "  Retrieved " . count($sample) . " rows successfully\n";

// 5. Foreign key check
echo "\nForeign key integrity:\n";
$fkCheck = $db->query("PRAGMA foreign_key_check(guarantee_decisions)")->fetchAll();
if (empty($fkCheck)) {
    echo "  ✅ All foreign keys intact\n";
} else {
    echo "  ❌ Foreign key violations found:\n";
    print_r($fkCheck);
}

echo "\n=== Verification Complete ===\n";
