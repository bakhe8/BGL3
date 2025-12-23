<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

// Check actions table structure
echo "Checking guarantee_actions table structure:\n";
$stmt = $db->query('PRAGMA table_info(guarantee_actions)');
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "  - {$row['name']} ({$row['type']}) " . ($row['notnull'] ? "NOT NULL" : "NULL") . "\n";
}

echo "\nTrying to insert a test action...\n";
try {
    $stmt = $db->prepare("INSERT INTO guarantee_actions 
        (guarantee_id, action_type, action_date, action_status, performed_by, created_at) 
        VALUES (1, 'extension', datetime('now'), 'issued', 'test', datetime('now'))");
    $stmt->execute();
    echo "✅ Success!\n";
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
}

// Check if any actions exist
$count = $db->query('SELECT COUNT(*) FROM guarantee_actions')->fetchColumn();
echo "\nCurrent actions count: {$count}\n";
