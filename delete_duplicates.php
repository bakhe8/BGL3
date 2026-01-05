<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

echo "=== Deleting old auto_match events ===" . PHP_EOL . PHP_EOL;

// Delete the incorrect auto_match events (keep bank_match only)
$stmt = $db->prepare("
    DELETE FROM guarantee_history 
    WHERE guarantee_id IN (9, 18) 
      AND event_subtype = 'auto_match'
      AND created_at = '2026-01-05 14:35:03'
");

$stmt->execute();
$deleted = $stmt->rowCount();

echo "Deleted $deleted events with event_subtype='auto_match'" . PHP_EOL . PHP_EOL;

// Verify
$stmt = $db->query("
    SELECT guarantee_id, event_type, event_subtype, id
    FROM guarantee_history 
    WHERE guarantee_id IN (9, 18) 
      AND created_at = '2026-01-05 14:35:03'
    ORDER BY guarantee_id
");

echo "Remaining events at 14:35:03:" . PHP_EOL;
while ($e = $stmt->fetch()) {
    echo "  Guarantee {$e['guarantee_id']}: {$e['event_type']}/{$e['event_subtype']} (ID {$e['id']})" . PHP_EOL;
}

echo PHP_EOL . "✅ Cleanup complete!" . PHP_EOL;
