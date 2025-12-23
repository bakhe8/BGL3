<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo "Database Tables:\n";
echo "================\n";
foreach ($tables as $table) {
    echo "- $table\n";
    
    // Get column info
    $columns = $db->query("PRAGMA table_info($table)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo "  * {$col['name']} ({$col['type']})\n";
    }
    echo "\n";
}
