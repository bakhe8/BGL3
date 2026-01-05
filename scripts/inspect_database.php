<?php
/**
 * Database Schema Inspector
 * Extracts all table schemas and sample data
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== DATABASE SCHEMA INSPECTION ===\n\n";

// 1. Get all table schemas
$stmt = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
$tables = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($tables as $table) {
    echo "TABLE: {$table['name']}\n";
    echo str_repeat("-", 70) . "\n";
    echo $table['sql'] . "\n\n";
}

// 2. Check guarantees table columns
echo "\n=== GUARANTEES TABLE STRUCTURE ===\n";
$pragma = $db->query("PRAGMA table_info(guarantees)")->fetchAll(PDO::FETCH_ASSOC);
foreach ($pragma as $col) {
    echo sprintf("  %s: %s %s\n", $col['name'], $col['type'], $col['notnull'] ? 'NOT NULL' : '');
}

// 3. Sample import_source values
echo "\n=== SAMPLE IMPORT_SOURCE VALUES ===\n";
$stmt = $db->query("SELECT DISTINCT import_source FROM guarantees ORDER BY imported_at DESC LIMIT 20");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['import_source']}\n";
}

// 4. Count guarantees by import_source
echo "\n=== GUARANTEES GROUPED BY IMPORT_SOURCE ===\n";
$stmt = $db->query("SELECT import_source, COUNT(*) as count, MIN(imported_at) as first_import, MAX(imported_at) as last_import FROM guarantees GROUP BY import_source ORDER BY count DESC LIMIT 20");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo sprintf("  %s: %d records (from %s to %s)\n", 
        $row['import_source'], 
        $row['count'],
        $row['first_import'],
        $row['last_import']
    );
}

// 5. Check guarantee_history table
echo "\n=== GUARANTEE_HISTORY TABLE STRUCTURE ===\n";
try {
    $pragma = $db->query("PRAGMA table_info(guarantee_history)")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pragma as $col) {
        echo sprintf("  %s: %s\n", $col['name'], $col['type']);
    }
    
    echo "\n=== EVENT TYPES IN HISTORY ===\n";
    $stmt = $db->query("SELECT event_type, event_subtype, COUNT(*) as count FROM guarantee_history GROUP BY event_type, event_subtype ORDER BY count DESC");
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo sprintf("  %s / %s: %d events\n", $row['event_type'], $row['event_subtype'] ?? 'NULL', $row['count']);
    }
} catch (Exception $e) {
    echo "  Table may not exist or error: {$e->getMessage()}\n";
}

// 6. Check if any batch/session columns exist
echo "\n=== CHECKING FOR BATCH/SESSION COLUMNS ===\n";
foreach ($tables as $table) {
    $pragma = $db->query("PRAGMA table_info({$table['name']})")->fetchAll(PDO::FETCH_ASSOC);
    $batchCols = array_filter($pragma, function($col) {
        return stripos($col['name'], 'batch') !== false || 
               stripos($col['name'], 'session') !== false ||
               stripos($col['name'], 'import_id') !== false;
    });
    
    if (!empty($batchCols)) {
        echo "  Table '{$table['name']}' has:\n";
        foreach ($batchCols as $col) {
            echo "    - {$col['name']}: {$col['type']}\n";
        }
    }
}

echo "\n=== INSPECTION COMPLETE ===\n";
