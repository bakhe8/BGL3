<?php
// Show ALL modified events for guarantee 350 to understand the pattern
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

$stmt = $db->query("
    SELECT id, created_at, event_details 
    FROM guarantee_history 
    WHERE guarantee_id = 350 
    AND event_type = 'modified'
    ORDER BY created_at DESC
    LIMIT 10
");

$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($events as $event) {
    echo "═══════════════════════════════════════\n";
    echo "Event ID: " . $event['id'] . "\n";
    echo "Created: " . $event['created_at'] . "\n";
    
    $details = json_decode($event['event_details'], true);
    if (!$details || !isset($details['changes'])) {
        echo "  No changes\n";
        continue;
    }
    
    echo "Changes:\n";
    foreach ($details['changes'] as $change) {
        $field = $change['field'] ?? 'unknown';
        $trigger = $change['trigger'] ?? 'manual';
        
        if ($field === 'supplier_id' || $field === 'bank_id') {
            $oldName = is_array($change['old_value']) ? ($change['old_value']['name'] ?? 'null') : $change['old_value'];
            $newName = is_array($change['new_value']) ? ($change['new_value']['name'] ?? 'null') : $change['new_value'];
            echo "  - $field: $oldName → $newName (trigger: $trigger)\n";
        } else {
            $oldVal = $change['old_value'] ?? 'null';
            $newVal = $change['new_value'] ?? 'null';
            echo "  - $field: $oldVal → $newVal (trigger: $trigger)\n";
        }
    }
    echo "\n";
}
