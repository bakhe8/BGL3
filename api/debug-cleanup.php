<?php
// Debug: Show what's in event 529 changes to see why it wasn't cleaned
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

$stmt = $db->prepare("SELECT id, event_details FROM guarantee_history WHERE id = 529");
$stmt->execute();
$event = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Event 529 details:\n\n";
echo "Raw event_details:\n";
echo $event['event_details'] . "\n\n";

$details = json_decode($event['event_details'], true);
echo "Decoded changes:\n";
print_r($details['changes']);

echo "\n\nAnalysis:\n";
foreach ($details['changes'] as $i => $change) {
    echo "Change $i:\n";
    echo "  Field: " . ($change['field'] ?? 'N/A') . "\n";
    echo "  Trigger: " . ($change['trigger'] ?? 'N/A') . "\n";
    
    $field = $change['field'] ?? '';
    $trigger = $change['trigger'] ?? 'manual';
    
    if ($field === 'amount') {
        if ($trigger === 'reduction_action' || $trigger === 'release_action') {
            echo "  → KEEP (real action)\n";
        } else {
            echo "  → REMOVE (false detection, trigger=$trigger)\n";
        }
    } else if ($field === 'expiry_date') {
        if ($trigger === 'extension_action') {
            echo "  → KEEP (real action)\n";
        } else {
            echo "  → REMOVE (false detection, trigger=$trigger)\n";
        }
    } else {
        echo "  → KEEP (supplier/bank)\n";
    }
}
