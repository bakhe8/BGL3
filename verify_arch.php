<?php
require_once __DIR__ . '/app/Support/autoload.php';
require_once __DIR__ . '/app/Services/TimelineRecorder.php';

use App\Services\TimelineRecorder;
use App\Support\Database;

$db = Database::connect();

$guaranteeId = 380; // Test ID

echo "--- STARTING TIMELINE ARCHITECTURE VERIFICATION ---\n";

// 1. EXTEND (UE-02)
echo "\n[1] Testing EXTEND (User)...\n";
$snapshot = TimelineRecorder::createSnapshot($guaranteeId);
$newDate = '2030-01-01'; // Future date
TimelineRecorder::recordExtensionEvent($guaranteeId, $snapshot, $newDate);
echo "Recorded. Checking Last Event...\n";

$stmt = $db->prepare("SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1");
$stmt->execute([$guaranteeId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Type: " . $event['event_type'] . "\n";
echo "Creator: " . $event['created_by'] . "\n";
$details = json_decode($event['event_details'], true);
$changes = $details['changes'];
echo "Fields Changed: " . implode(', ', array_column($changes, 'field')) . "\n";

if ($event['created_by'] !== 'بواسطة المستخدم') echo "FAIL: Wrong Creator\n";
if ($details['changes'][0]['field'] !== 'expiry_date') echo "FAIL: Wrong Field\n";


// 2. REDUCE (UE-03)
echo "\n[2] Testing REDUCE (User)...\n";
$snapshot = TimelineRecorder::createSnapshot($guaranteeId);
$newAmount = 500;
TimelineRecorder::recordReductionEvent($guaranteeId, $snapshot, $newAmount);

$stmt->execute([$guaranteeId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Type: " . $event['event_type'] . "\n";
echo "Creator: " . $event['created_by'] . "\n";
$details = json_decode($event['event_details'], true);
$changes = $details['changes'];
echo "Fields Changed: " . implode(', ', array_column($changes, 'field')) . "\n";

if ($details['changes'][0]['field'] !== 'amount') echo "FAIL: Wrong Field for Reduce\n";


// 3. DECISION (UE-01)
echo "\n[3] Testing DECISION (User Manual)...\n";
$snapshot = TimelineRecorder::createSnapshot($guaranteeId);
$newData = [
    'supplier_id' => 999,
    'supplier_name' => 'NEW SUPPLIER TEST',
    'bank_id' => 888,
    'bank_name' => 'NEW BANK TEST'
];
TimelineRecorder::recordDecisionEvent($guaranteeId, $snapshot, $newData, false); // isAuto = false

$stmt->execute([$guaranteeId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Type: " . $event['event_type'] . "\n";
echo "Creator: " . $event['created_by'] . "\n";
$details = json_decode($event['event_details'], true);
$changes = $details['changes'];
echo "Fields Changed: " . implode(', ', array_column($changes, 'field')) . "\n";

if (strpos($event['created_by'], 'المستخدم') === false) echo "FAIL: Should be User\n";


// 4. RELEASE (UE-04)
echo "\n[4] Testing RELEASE (User)...\n";
$snapshot = TimelineRecorder::createSnapshot($guaranteeId);
TimelineRecorder::recordReleaseEvent($guaranteeId, $snapshot, "Test Reason");

$stmt->execute([$guaranteeId]);
$event = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Type: " . $event['event_type'] . "\n";
echo "Fields Changed: " . implode(', ', array_column(json_decode($event['event_details'], true)['changes'], 'field')) . "\n";


echo "\n--- VERIFICATION COMPLETE ---\n";
