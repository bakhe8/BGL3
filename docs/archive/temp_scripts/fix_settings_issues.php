<?php
require __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Step 1: Updating NULL dates ===\n";

// Update NULL dates
$stmt = $db->prepare("
    UPDATE learning_confirmations 
    SET updated_at = COALESCE(created_at, NOW())
    WHERE updated_at IS NULL
");
$stmt->execute();

$affected = $stmt->rowCount();
echo "✅ Updated $affected records with NULL dates\n\n";

echo "=== Step 2: Checking Rejections ===\n";

// Check all actions in table
$stmt = $db->query("
    SELECT action, COUNT(*) as count
    FROM learning_confirmations
    GROUP BY action
");
$actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Actions in learning_confirmations table:\n";
foreach ($actions as $row) {
    echo "  - {$row['action']}: {$row['count']} records\n";
}

echo "\n=== Step 3: Sample of ALL records (first 10) ===\n";
$stmt = $db->query("
    SELECT id, action, raw_supplier_name, supplier_id, count
    FROM learning_confirmations
    ORDER BY id DESC
    LIMIT 10
");
$sample = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($sample as $row) {
    echo "ID {$row['id']}: action={$row['action']}, pattern={$row['raw_supplier_name']}, count={$row['count']}\n";
}

echo "\n=== Step 4: Checking Table Structure ===\n";
$stmt = $db->query("DESCRIBE learning_confirmations");
$structure = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Table columns:\n";
foreach ($structure as $col) {
    echo "  - {$col['Field']} ({$col['Type']}) - Null: {$col['Null']}, Default: {$col['Default']}\n";
}

echo "\n✅ Investigation complete!\n";
