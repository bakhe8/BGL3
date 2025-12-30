<?php
$db = new PDO('sqlite:storage/database/app.sqlite');

// Get timeline for guarantee ID 5
$stmt = $db->prepare("
    SELECT id, event_type, event_subtype, event_details, created_at, created_by
    FROM guarantee_history 
    WHERE guarantee_id = 5
    ORDER BY created_at DESC
");
$stmt->execute();

echo "=== Timeline for Guarantee ID 5 ===\n\n";
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "ID: " . $row['id'] . "\n";
    echo "Type: " . $row['event_type'] . "\n";
    echo "Subtype: " . ($row['event_subtype'] ?? 'NULL') . "\n";
    echo "Created By: " . $row['created_by'] . "\n";
    echo "Created At: " . $row['created_at'] . "\n";
    
    $details = json_decode($row['event_details'], true);
    echo "Event Details:\n";
    echo json_encode($details, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";
    echo "---\n\n";
}
