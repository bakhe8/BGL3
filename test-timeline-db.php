<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Check history for guarantee_id=1
$stmt = $db->prepare('SELECT COUNT(*) as count FROM guarantee_history WHERE guarantee_id = ?');
$stmt->execute([1]);
$count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

echo "Timeline/History events for guarantee_id=1: $count\n\n";

if ($count > 0) {
    $stmt = $db->prepare('SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at DESC LIMIT 10');
    $stmt->execute([1]);
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Last 10 events:\n";
    echo str_repeat("=", 80) . "\n";
    foreach ($events as $i => $event) {
        echo "\n" . ($i + 1) . ". Event ID: {$event['id']}\n";
        echo "   Action: {$event['action']}\n";
        echo "   Date: {$event['created_at']}\n";
        echo "   User: " . ($event['created_by'] ?? 'N/A') . "\n";
        if (!empty($event['details'])) {
            echo "   Details: {$event['details']}\n";
        }
    }
} else {
    echo "No timeline events found.\n";
}

// Check table schema
echo "\n\n" . str_repeat("=", 80) . "\n";
echo "Table schema:\n";
$stmt = $db->query('PRAGMA table_info(guarantee_history)');
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "- {$col['name']} ({$col['type']})\n";
}
