<?php
require __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Checking learning_confirmations schema ===\n\n";

// Check table structure
$stmt = $db->query("PRAGMA table_info(learning_confirmations)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Columns:\n";
foreach ($columns as $col) {
    echo "  - {$col['name']} ({$col['type']}) - NULL: {$col['notnull']}, Default: {$col['dflt_value']}\n";
}

echo "\n=== Recent records ===\n";
$stmt = $db->query("
    SELECT id, action, raw_supplier_name, created_at, updated_at
    FROM learning_confirmations
    ORDER BY id DESC
    LIMIT 5
");
foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
    echo "ID {$r['id']}: {$r['action']} - Created: " . ($r['created_at'] ?? 'NULL') . ", Updated: " . ($r['updated_at'] ?? 'NULL') . "\n";
}
