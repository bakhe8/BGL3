<?php
require_once __DIR__ . '/app/Support/autoload.php';
require_once __DIR__ . '/app/Services/TimelineRecorder.php';

use App\Services\TimelineRecorder;
use App\Support\Database;

$db = Database::connect();

$guaranteeId = 999123; // New Test ID logic
// Cleanup
$db->prepare("DELETE FROM guarantees WHERE id = ?")->execute([$guaranteeId]);
$db->prepare("DELETE FROM guarantee_history WHERE guarantee_id = ?")->execute([$guaranteeId]);

echo "--- STARTING FINAL ARCHITECTURE VERIFICATION ---\n";

// 1. IMPORT (LE-00)
echo "\n[1] Testing IMPORT (System)...\n";
// Manually insert fake guarantee to satisfy constraint if needed.
$db->prepare("INSERT INTO guarantees (id, guarantee_number, raw_data, import_source, imported_at, imported_by) VALUES (?, ?, ?, ?, ?, ?)")
    ->execute([
        $guaranteeId, 
        'TEST-999-FINAL', 
        json_encode(['guarantee_number'=>'TEST-999-FINAL', 'type'=>'Bid', 'amount'=>1000, 'expiry_date'=>'2025-01-01']),
        'System Test',
        date('Y-m-d H:i:s'),
        'System'
    ]);

// Record Import
$res = TimelineRecorder::recordImportEvent($guaranteeId, 'test_script');
echo $res ? "Recorded LE-00.\n" : "FAIL: LE-00 not recorded.\n";

$stmt = $db->prepare("SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$guaranteeId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
if ($event['event_type'] !== 'import') echo "FAIL: Wrong Type for Import: " . $event['event_type'] . "\n";


// 2. DECISION (UE-01) - NO STATUS CHANGE (Pending -> Pending)
echo "\n[2] Testing DECISION (User) - No Status Change...\n";
$snapshot = TimelineRecorder::createSnapshot($guaranteeId);
$newData = ['supplier_id' => 10, 'supplier_name' => 'Sup A']; // Only supplier, so still pending
TimelineRecorder::recordDecisionEvent($guaranteeId, $snapshot, $newData, false);

$stmt->execute([$guaranteeId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Event: " . $event['event_type'] . " | Status Change: " . (json_decode($event['event_details'], true)['auto_status_change'] ?? 'None') . "\n";
// Expect 'modified' and NO auto_status_change inside it.


// 3. STATUS TRANSITION (SE-01) - Explicit Call
echo "\n[3] Testing STATUS TRANSITION (System)...\n";
$snapshot['status'] = 'pending'; // Force check
TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $snapshot, 'approved', 'auto_logic_check');

$stmt->execute([$guaranteeId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Event: " . $event['event_type'] . "\n";
echo "Changes: " . implode(', ', array_column(json_decode($event['event_details'], true)['changes'], 'field')) . "\n";
echo "Creator: " . $event['created_by'] . "\n";

if ($event['event_type'] !== 'status_change') echo "FAIL: Should be status_change\n";
if ($event['created_by'] !== 'بواسطة النظام') echo "FAIL: Should be System\n";


echo "\n--- VERIFICATION COMPLETE ---\n";
