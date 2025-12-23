<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Get table schema
echo "Table: guarantee_attachments\n";
echo str_repeat("=", 50) . "\n\n";

$stmt = $db->query('PRAGMA table_info(guarantee_attachments)');
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Columns:\n";
foreach ($columns as $col) {
    echo "- {$col['name']} ({$col['type']})";
    if ($col['notnull']) echo " NOT NULL";
    if ($col['dflt_value']) echo " DEFAULT {$col['dflt_value']}";
    if ($col['pk']) echo " PRIMARY KEY";
    echo "\n";
}

echo "\n" . str_repeat("=", 50) . "\n\n";

// Get sample data
$stmt = $db->query('SELECT * FROM guarantee_attachments WHERE guarantee_id = 1 LIMIT 2');
$samples = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Sample data (first 2 rows):\n";
foreach ($samples as $i => $row) {
    echo "\nRow " . ($i + 1) . ":\n";
    foreach ($row as $key => $value) {
        echo "  $key: " . (is_null($value) ? 'NULL' : $value) . "\n";
    }
}
