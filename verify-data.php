<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

echo "Checking final database state...\n\n";

$tables = [
    'guarantee_decisions',
    'guarantee_actions',
    'guarantee_history',
    'guarantee_attachments',
    'guarantee_notes',
    'supplier_decisions_log',
    'supplier_alternative_names',
    'suppliers',
    'banks'
];

foreach ($tables as $table) {
    $count = $db->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    echo str_pad($table, 30) . ": {$count}\n";
}

echo "\n\nSample data from supplier_decisions_log:\n";
$stmt = $db->query('SELECT * FROM supplier_decisions_log LIMIT 5');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - Guarantee {$row['guarantee_id']}: {$row['raw_input']} -> {$row['chosen_supplier_name']} ({$row['confidence_score']}%)\n";
}

echo "\n\nSample data from supplier_alternative_names:\n";
$stmt = $db->query('SELECT * FROM supplier_alternative_names LIMIT 5');
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - Supplier {$row['supplier_id']}: {$row['alternative_name']} (used {$row['usage_count']} times)\n";
}
