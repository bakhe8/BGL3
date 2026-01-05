<?php
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

// Check what happened to guarantees 9 & 18
echo "=== Checking Current State ===" . PHP_EOL . PHP_EOL;

foreach ([9, 18] as $gid) {
    echo "Guarantee $gid:" . PHP_EOL;
    
    // Check decision
    $stmt = $db->prepare("SELECT * FROM guarantee_decisions WHERE guarantee_id = ?");
    $stmt->execute([$gid]);
    $decision = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($decision) {
        echo "  Decision: status={$decision['status']}, bank_id={$decision['bank_id']}, supplier_id={$decision['supplier_id']}" . PHP_EOL;
    } else {
        echo "  Decision: NONE" . PHP_EOL;
    }
    
    // Check timeline
    $stmt = $db->prepare("SELECT event_type, event_subtype, created_at FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at");
    $stmt->execute([$gid]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "  Timeline events: " . count($events) . PHP_EOL;
    foreach ($events as $e) {
        echo "    - {$e['event_type']}/{$e['event_subtype']} at {$e['created_at']}" . PHP_EOL;
    }
    echo PHP_EOL;
}

// Check guarantee_history schema
echo "=== guarantee_history schema ===" . PHP_EOL;
$stmt = $db->query("PRAGMA table_info(guarantee_history)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "  {$col['name']} ({$col['type']})" . PHP_EOL;
}
