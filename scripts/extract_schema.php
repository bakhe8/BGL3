<?php

$dbPath = __DIR__ . '/../storage/database/app.sqlite';
$db = new SQLite3($dbPath, SQLITE3_OPEN_READONLY);

// Get all tables
$result = $db->query("SELECT name FROM sqlite_master WHERE type='table' ORDER BY name");
$tables = [];
while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $tables[] = $row['name'];
}

$output = '';

foreach ($tables as $table) {
    $output .= "\n" . str_repeat('=', 80) . "\n";
    $output .= "TABLE: $table\n";
    $output .= str_repeat('=', 80) . "\n\n";
    
    // Get CREATE TABLE statement
    $result = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='$table'");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    if ($row) {
        $output .= $row['sql'] . ";\n\n";
    }
    
    // Get indexes
    $result = $db->query("SELECT name, sql FROM sqlite_master WHERE type='index' AND tbl_name='$table' AND sql IS NOT NULL");
    $hasIndexes = false;
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        if (!$hasIndexes) {
            $output .= "INDEXES:\n";
            $hasIndexes = true;
        }
        $output .= "  - " . $row['name'] . "\n";
        $output .= "    " . $row['sql'] . ";\n";
    }
    
    // Get row count
    $result = $db->query("SELECT COUNT(*) as count FROM `$table`");
    $row = $result->fetchArray(SQLITE3_ASSOC);
    $output .= "\nROW COUNT: " . number_format($row['count']) . "\n";
}

file_put_contents(__DIR__ . '/schema_output.txt', $output);
echo "Schema exported to scripts/schema_output.txt\n";
echo "Total tables: " . count($tables) . "\n";

$db->close();
