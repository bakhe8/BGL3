<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

// Get detailed status_change event from guarantee 8
$stmt = $db->query("
    SELECT event_details, snapshot_data 
    FROM guarantee_history 
    WHERE guarantee_id = 8 
      AND event_type = 'status_change'
");

$event = $stmt->fetch(PDO::FETCH_ASSOC);

echo "=== Status Change Event Structure (Guarantee 8) ===" . PHP_EOL . PHP_EOL;
echo "event_details:" . PHP_EOL;
print_r(json_decode($event['event_details'], true));
echo PHP_EOL;

echo "snapshot_data keys:" . PHP_EOL;
$snapshot = json_decode($event['snapshot_data'], true);
foreach ($snapshot as $key => $value) {
    $display = is_string($value) ? substr($value, 0, 50) : $value;
    echo "  $key: $display" . PHP_EOL;
}
