<?php
/**
 * Database Schema Inspector - JSON Output
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$report = [];

// 1. All tables
$stmt = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name");
$report['tables'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 2. Guarantees columns
$report['guarantees_columns'] = $db->query("PRAGMA table_info(guarantees)")->fetchAll(PDO::FETCH_ASSOC);

// 3. Sample import_source values
$stmt = $db->query("SELECT DISTINCT import_source, imported_at FROM guarantees ORDER BY imported_at DESC LIMIT 30");
$report['import_sources'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 4. Grouped by import_source
$stmt = $db->query("SELECT import_source, COUNT(*) as count, MIN(imported_at) as first, MAX(imported_at) as last FROM guarantees GROUP BY import_source ORDER BY count DESC");
$report['import_source_groups'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

// 5. History Table
try {
    $report['history_columns'] = $db->query("PRAGMA table_info(guarantee_history)")->fetchAll(PDO::FETCH_ASSOC);
    $stmt = $db->query("SELECT event_type, event_subtype, COUNT(*) as count FROM guarantee_history GROUP BY event_type, event_subtype ORDER BY count DESC");
    $report['event_types'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $report['history_error'] = $e->getMessage();
}

// 6. Check for batch/session columns in all tables
$report['batch_session_columns'] = [];
foreach ($report['tables'] as $table) {
    $pragma = $db->query("PRAGMA table_info({$table['name']})")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($pragma as $col) {
        if (stripos($col['name'], 'batch') !== false || 
            stripos($col['name'], 'session') !== false ||
            stripos($col['name'], 'import') !== false) {
            $report['batch_session_columns'][] = [
                'table' => $table['name'],
                'column' => $col['name'],
                'type' => $col['type']
            ];
        }
    }
}

// Save to file
file_put_contents(__DIR__ . '/db_schema_report.json', json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Report saved to scripts/db_schema_report.json\n";

// Also print summary
echo "\n=== SUMMARY ===\n";
echo "Total Tables: " . count($report['tables']) . "\n";
echo "Guarantees Columns: " . count($report['guarantees_columns']) . "\n";
echo "Unique Import Sources: " . count($report['import_sources']) . "\n";
echo "Import Source Groups: " . count($report['import_source_groups']) . "\n";
echo "Batch/Session Columns Found: " . count($report['batch_session_columns']) . "\n";
