<?php
/**
 * Database Check: Show snapshot_data for recent events
 */

require_once __DIR__ . '/app/Support/autoload.php';

$db = Database::getInstance()->getConnection();

echo "=== Checking guarantee_history for snapshot_data ===\n\n";

// Get latest 10 events
$stmt = $db->query("
    SELECT 
        id,
        guarantee_id,
        event_type,
        CASE 
            WHEN snapshot_data IS NULL THEN 'NULL'
            WHEN snapshot_data = '' THEN 'EMPTY STRING'
            WHEN snapshot_data = '{}' THEN 'EMPTY JSON'
            ELSE 'HAS DATA'
        END as snapshot_status,
        LENGTH(snapshot_data) as data_length,
        created_at
    FROM guarantee_history
    ORDER BY id DESC
    LIMIT 20
");

$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Latest 20 events:\n";
echo str_repeat('-', 100) . "\n";
printf("%-5s | %-12s | %-15s | %-20s | %-10s | %s\n", 
    'ID', 'Guarantee', 'Event Type', 'Snapshot Status', 'Length', 'Created At');
echo str_repeat('-', 100) . "\n";

foreach ($events as $event) {
    printf("%-5s | %-12s | %-15s | %-20s | %-10s | %s\n",
        $event['id'],
        $event['guarantee_id'],
        $event['event_type'],
        $event['snapshot_status'],
        $event['data_length'] ?? '0',
        $event['created_at']
    );
}

echo "\n\n=== Sample Event Details ===\n";
// Get one event with full details
$sample = $db->query("
    SELECT id, guarantee_id, event_type, snapshot_data, event_details
    FROM guarantee_history
    ORDER BY id DESC
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

if ($sample) {
    echo "Event ID: " . $sample['id'] . "\n";
    echo "Guarantee ID: " . $sample['guarantee_id'] . "\n";
    echo "Event Type: " . $sample['event_type'] . "\n";
    echo "\nSnapshot Data:\n";
    echo $sample['snapshot_data'] . "\n\n";
    echo "Event Details:\n";
    echo $sample['event_details'] . "\n";
}
