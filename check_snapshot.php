<?php
require_once 'app/Support/Database.php';

$db = App\Support\Database::connect();

echo "=== Checking letter_snapshot in database ===\n\n";

// Check recent extension events
$stmt = $db->prepare("
    SELECT id, event_type, event_subtype, 
           CASE WHEN letter_snapshot IS NULL THEN 'NULL' 
                WHEN letter_snapshot = '' THEN 'EMPTY'
                ELSE SUBSTR(letter_snapshot, 1, 100)
           END as letter_snap_preview,
           created_at
    FROM guarantee_history 
    WHERE event_subtype IN ('extension', 'reduction', 'release')
    ORDER BY id DESC 
    LIMIT 5
");

$stmt->execute();
$results = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($results as $row) {
    echo "ID: {$row['id']}\n";
    echo "  Type: {$row['event_type']} / {$row['event_subtype']}\n";
    echo "  Letter Snapshot: {$row['letter_snap_preview']}\n";
    echo "  Created: {$row['created_at']}\n";
    echo "  ---\n";
}

echo "\nTotal action events found: " . count($results) . "\n";
