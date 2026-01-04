<?php
require __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Check confirmations with NULL dates
echo "=== Confirmations with NULL updated_at ===\n";
$stmt = $db->query("
    SELECT 
        lc.id,
        lc.raw_supplier_name as pattern,
        lc.supplier_id,
        s.official_name,
        lc.count,
        lc.updated_at,
        lc.created_at
    FROM learning_confirmations lc
    LEFT JOIN suppliers s ON lc.supplier_id = s.id
    WHERE lc.action = 'confirm' AND lc.updated_at IS NULL
    ORDER BY lc.count DESC
");
$nullDates = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($nullDates)) {
    echo "✅ No confirmations with NULL dates\n";
} else {
    foreach ($nullDates as $row) {
        echo "ID: {$row['id']}\n";
        echo "  Pattern: {$row['pattern']}\n";
        echo "  Supplier: {$row['official_name']}\n";
        echo "  Count: {$row['count']}\n";
        echo "  Updated: " . ($row['updated_at'] ?? 'NULL') . "\n";
        echo "  Created: " . ($row['created_at'] ?? 'NULL') . "\n\n";
    }
}

// Check rejections (punishment list)
echo "\n=== Rejections (Punishment List) ===\n";
$stmt2 = $db->query("
    SELECT 
        lc.id,
        lc.raw_supplier_name as pattern,
        lc.supplier_id,
        s.official_name,
        lc.count,
        lc.updated_at
    FROM learning_confirmations lc
    LEFT JOIN suppliers s ON lc.supplier_id = s.id
    WHERE lc.action = 'reject'
    ORDER BY lc.updated_at DESC
    LIMIT 20
");
$rejections = $stmt2->fetchAll(PDO::FETCH_ASSOC);

if (empty($rejections)) {
    echo "❌ Punishment list is EMPTY! No rejections found.\n";
} else {
    echo "Found " . count($rejections) . " rejections:\n";
    foreach ($rejections as $row) {
        echo "- {$row['pattern']} → {$row['official_name']} (Count: {$row['count']}, Updated: {$row['updated_at']})\n";
    }
}

// Check total confirmations
echo "\n=== Total Confirmations ===\n";
$total = $db->query("SELECT COUNT(*) FROM learning_confirmations WHERE action = 'confirm'")->fetchColumn();
echo "Total: $total\n";

// Show sample of all confirmations
echo "\n=== Sample of All Confirmations (first 5) ===\n";
$stmt3 = $db->query("
    SELECT 
        lc.id,
        lc.raw_supplier_name,
        lc.supplier_id,
        s.official_name,
        lc.count,
        lc.updated_at
    FROM learning_confirmations lc
    LEFT JOIN suppliers s ON lc.supplier_id = s.id
    WHERE lc.action = 'confirm'
    ORDER BY lc.id ASC
    LIMIT 5
");
$sample = $stmt3->fetchAll(PDO::FETCH_ASSOC);
foreach ($sample as $row) {
    echo "ID {$row['id']}: {$row['raw_supplier_name']} → {$row['official_name']} (Updated: " . ($row['updated_at'] ?? 'NULL') . ")\n";
}
