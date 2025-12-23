#!/usr/bin/env php
<?php
/**
 * Migration Runner for V3 Schema
 * 
 * Usage: php run_migrations.php
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

echo "===================================\n";
echo "V3 Schema Migration Runner\n";
echo "===================================\n\n";

// Connect to database
$db = Database::connect();

// List of migrations to run (in order)
$migrations = [
    'v3_000_drop_old_tables.sql' => 'Drop old V3 tables (clean slate)',
    'v3_001_create_core_tables.sql' => 'Core tables (guarantees, decisions, actions)',
    'v3_002_create_learning_tables.sql' => 'Learning tables (cache, log)',
];

$success = true;

foreach ($migrations as $file => $description) {
    $path = __DIR__ . '/' . $file;
    
    echo "Running: $description\n";
    echo "File: $file\n";
    
    if (!file_exists($path)) {
        echo "❌ ERROR: File not found: $path\n\n";
        $success = false;
        continue;
    }
    
    // Read SQL file
    $sql = file_get_contents($path);
    
    try {
        // Execute migration
        $db->exec($sql);
        echo "✅ Success\n\n";
    } catch (PDOException $e) {
        echo "❌ ERROR: " . $e->getMessage() . "\n\n";
        $success = false;
    }
}

if ($success) {
    echo "===================================\n";
    echo "✅ All migrations completed successfully!\n";
    echo "===================================\n\n";
    
    // Show created tables
    echo "Created tables:\n";
    $stmt = $db->query("
        SELECT name FROM sqlite_master 
        WHERE type='table' 
        AND name LIKE 'guarantee%' OR name LIKE 'supplier_learning%' OR name LIKE 'supplier_decisions%'
        ORDER BY name
    ");
    
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $table) {
        echo "  ✓ $table\n";
    }
    
    exit(0);
} else {
    echo "===================================\n";
    echo "❌ Some migrations failed!\n";
    echo "===================================\n";
    exit(1);
}
