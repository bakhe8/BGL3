<?php
// Check tables in V3
$db1 = new PDO('sqlite:V3/storage/database/app.sqlite');
$tables1 = $db1->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo "=== V3 Tables ===\n";
foreach ($tables1 as $table) {
    echo "- $table\n";
}

// Check tables in Original
$db2 = new PDO('sqlite:storage/database.sqlite');
$tables2 = $db2->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

echo "\n=== Original Tables ===\n";
foreach ($tables2 as $table) {
    echo "- $table\n";
}

// Find missing tables in V3
$missing = array_diff($tables2, $tables1);

echo "\n=== Missing in V3 ===\n";
if (empty($missing)) {
    echo "None - all tables exist!\n";
} else {
    foreach ($missing as $table) {
        echo "- $table\n";
        
        // Get CREATE statement
        $stmt = $db2->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'");
        $sql = $stmt->fetchColumn();
        echo "  SQL: " . substr($sql, 0, 100) . "...\n";
    }
}
