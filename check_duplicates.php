<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

echo "=== Events for Guarantees 9 & 18 ===" . PHP_EOL . PHP_EOL;

foreach ([9, 18] as $gid) {
    echo "Guarantee $gid:" . PHP_EOL;
    
    $stmt = $db->prepare("
        SELECT id, event_type, event_subtype, created_at 
        FROM guarantee_history 
        WHERE guarantee_id = ? 
        ORDER BY created_at
    ");
    $stmt->execute([$gid]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($events as $e) {
        echo "  ID {$e['id']}: {$e['event_type']}/{$e['event_subtype']} at {$e['created_at']}" . PHP_EOL;
    }
    echo PHP_EOL;
}

// Count duplicates at 14:35:03
$stmt = $db->query("
    SELECT guarantee_id, COUNT(*) as count
    FROM guarantee_history 
    WHERE guarantee_id IN (9, 18) 
      AND created_at = '2026-01-05 14:35:03'
    GROUP BY guarantee_id
");

echo "=== Events at 14:35:03 ===" . PHP_EOL;
while ($row = $stmt->fetch()) {
    echo "Guarantee {$row['guarantee_id']}: {$row['count']} events" . PHP_EOL;
}
