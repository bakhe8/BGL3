<?php
$db = new PDO('sqlite:storage/database/app.sqlite');

echo "=== التحقق الفعلي من أحداث الضمان 5 ===\n\n";

// Get all events for guarantee 5
$stmt = $db->prepare("
    SELECT id, event_type, event_subtype, event_details, created_at, created_by
    FROM guarantee_history 
    WHERE guarantee_id = 5
    ORDER BY created_at ASC
");
$stmt->execute();

$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "إجمالي الأحداث: " . count($events) . "\n\n";

foreach ($events as $i => $event) {
    echo "=== حدث #" . ($i+1) . " ===\n";
    echo "ID: " . $event['id'] . "\n";
    echo "Type: " . $event['event_type'] . "\n";
    echo "Subtype: " . ($event['event_subtype'] ?? 'NULL') . "\n";
    echo "Created By: " . $event['created_by'] . "\n";
    echo "Created At: " . $event['created_at'] . "\n";
    
    $details = json_decode($event['event_details'], true);
    $changes = $details['changes'] ?? [];
    
    echo "التغييرات:\n";
    foreach ($changes as $change) {
        $field = $change['field'] ?? 'unknown';
        $trigger = $change['trigger'] ?? 'unknown';
        
        echo "  - Field: $field\n";
        echo "    Trigger: $trigger\n";
        
        if ($field === 'supplier_id' || $field === 'bank_id') {
            $oldName = $change['old_value']['name'] ?? 'N/A';
            $newName = $change['new_value']['name'] ?? 'N/A';
            echo "    Old: $oldName\n";
            echo "    New: $newName\n";
        }
    }
    echo "\n";
}
