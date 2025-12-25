<?php
// Final verification after migration
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

header('Content-Type: application/json');

$db = Database::connect();

// Check new schema
$columns = $db->query("PRAGMA table_info(guarantee_history)")->fetchAll(PDO::FETCH_ASSOC);
$columnNames = array_column($columns, 'name');

$indexes = $db->query("
    SELECT name, sql FROM sqlite_master 
    WHERE type='index' AND tbl_name='guarantee_history'
")->fetchAll(PDO::FETCH_ASSOC);

$count = $db->query("SELECT COUNT(*) FROM guarantee_history")->fetchColumn();

echo json_encode([
    'success' => true,
    'table' => 'guarantee_history',
    'columns' => $columnNames,
    'total_events' => $count,
    'indexes' => array_column($indexes, 'name'),
    'has_old_columns' => [
        'action' => in_array('action', $columnNames),
        'change_reason' => in_array('change_reason', $columnNames)
    ],
    'has_new_columns' => [
        'event_type' => in_array('event_type', $columnNames),
        'snapshot_data' => in_array('snapshot_data', $columnNames),
        'event_details' => in_array('event_details', $columnNames)
    ]
], JSON_PRETTY_PRINT);
