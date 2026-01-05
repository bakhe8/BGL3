<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

echo "=== Checking Status Change Events ===" . PHP_EOL . PHP_EOL;

// Find guarantees that have status_change events
$stmt = $db->query("
    SELECT DISTINCT guarantee_id 
    FROM guarantee_history 
    WHERE event_type = 'status_change'
    LIMIT 5
");

$ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
echo "Guarantees with status_change events: " . implode(', ', $ids) . PHP_EOL . PHP_EOL;

if (!empty($ids)) {
    $gid = $ids[0];
    echo "Example from Guarantee $gid:" . PHP_EOL;
    
    $stmt = $db->prepare("
        SELECT event_type, event_subtype, event_details, snapshot_data, created_at
        FROM guarantee_history 
        WHERE guarantee_id = ? 
          AND event_type = 'status_change'
        LIMIT 1
    ");
    $stmt->execute([$gid]);
    $event = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($event) {
        echo "event_type: {$event['event_type']}" . PHP_EOL;
        echo "event_subtype: {$event['event_subtype']}" . PHP_EOL;
        echo "created_at: {$event['created_at']}" . PHP_EOL;
        echo PHP_EOL . "event_details:" . PHP_EOL;
        echo $event['event_details'] . PHP_EOL . PHP_EOL;
        
        echo "snapshot (first 200 chars):" . PHP_EOL;
        echo substr($event['snapshot_data'], 0, 200) . "..." . PHP_EOL;
    }
}

// Check guarantee 8's timeline for status changes
echo PHP_EOL . "=== Guarantee 8 Timeline ===" . PHP_EOL;
$stmt = $db->query("
    SELECT event_type, event_subtype, created_at
    FROM guarantee_history 
    WHERE guarantee_id = 8
    ORDER BY created_at
");

while ($e = $stmt->fetch()) {
    echo "{$e['event_type']}/{$e['event_subtype']} at {$e['created_at']}" . PHP_EOL;
}
