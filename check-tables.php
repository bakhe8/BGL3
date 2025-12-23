<?php
$db = new PDO('sqlite:V3/storage/database/app.sqlite');
$tables = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo "=== Tables in V3 Database ===\n\n";
foreach ($tables as $table) {
    echo "- $table\n";
    
    // Get column count
    $info = $db->query("PRAGMA table_info($table)")->fetchAll();
    echo "  Columns: " . count($info) . "\n";
}
