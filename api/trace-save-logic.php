<?php
/**
 * TRACE COMPLETE SAVE LOGIC
 * Shows exactly what happens during save
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;
require_once __DIR__ . '/../lib/TimelineHelper.php';

$db = Database::connect();

echo "═══════════════════════════════════════════════════\n";
echo "TRACING SAVE LOGIC FOR GUARANTEE 350\n";
echo "═══════════════════════════════════════════════════\n\n";

// Simulate what save-and-next.php does
$guaranteeId = 350;

// Step 1: Create OLD snapshot (BEFORE any changes)
echo "STEP 1: CreateSnapshot (current state)\n";
echo "───────────────────────────────────────\n";
$oldSnapshot = TimelineHelper::createSnapshot($guaranteeId);
print_r($oldSnapshot);
echo "\n";

// Step 2: Prepare NEW data (what save-and-next.php sends)
echo "STEP 2: NewData (from save-and-next.php)\n";
echo "───────────────────────────────────────\n";
$newData = [
    'supplier_id' => 57,
    'supplier_name' => 'Test Supplier',
    'supplier_trigger' => 'manual',
    'bank_id' => 1,
    'bank_name' => 'Test Bank',
    'bank_trigger' => 'manual'
    // NOTE: NO amount, NO expiry_date!
];
print_r($newData);
echo "\n";

// Step 3: Detect changes
echo "STEP 3: DetectChanges (what gets saved)\n";
echo "───────────────────────────────────────\n";
$changes = TimelineHelper::detectChanges($oldSnapshot, $newData);
print_r($changes);
echo "\n";

// Analysis
echo "ANALYSIS:\n";
echo "───────────────────────────────────────\n";
echo "oldSnapshot has amount? " . (isset($oldSnapshot['amount']) ? "YES ({$oldSnapshot['amount']})" : "NO") . "\n";
echo "oldSnapshot has expiry? " . (isset($oldSnapshot['expiry_date']) ? "YES ({$oldSnapshot['expiry_date']})" : "NO") . "\n";
echo "newData has amount? " . (isset($newData['amount']) ? "YES" : "NO") . "\n";
echo "newData has expiry? " . (isset($newData['expiry_date']) ? "YES" : "NO") . "\n";
echo "\n";
echo "Expected behavior:\n";
echo "- Since newData has NO amount/expiry\n";
echo "- detectChanges should use isset() check\n";
echo "- Result: NO amount/expiry changes detected\n";
echo "\n";
echo "Actual changes count: " . count($changes) . "\n";
if (count($changes) > 0) {
    echo "Changes detected for fields: ";
    foreach ($changes as $change) {
        echo $change['field'] . " ";
    }
    echo "\n";
}
