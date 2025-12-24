<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Checking guarantee_actions table ===\n\n";

$stmt = $db->query('SELECT * FROM guarantee_actions ORDER BY created_at DESC LIMIT 5');
$actions = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (empty($actions)) {
    echo "No actions found in guarantee_actions table.\n";
} else {
    echo "Found " . count($actions) . " actions:\n";
    foreach ($actions as $action) {
        echo "- ID: {$action['id']}, Type: {$action['action_type']}, Guarantee: {$action['guarantee_id']}, Status: {$action['action_status']}, Date: {$action['created_at']}\n";
    }
}

echo "\n=== Checking guarantee_history table ===\n\n";

$stmt2 = $db->query('SELECT * FROM guarantee_history ORDER BY created_at DESC LIMIT 5');
$history = $stmt2->fetchAll(PDO::FETCH_ASSOC);

if (empty($history)) {
    echo "No history found in guarantee_history table.\n";
} else {
    echo "Found " . count($history) . " history entries:\n";
    foreach ($history as $h) {
        echo "- ID: {$h['id']}, Guarantee: {$h['guarantee_id']}, Action: {$h['action']}, Date: {$h['created_at']}\n";
    }
}
