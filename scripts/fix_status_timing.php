<?php
/**
 * Fix status_change event timing
 * Should be AFTER manual supplier match, not after bank match
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Fixing Status Change Event Timing ===" . PHP_EOL . PHP_EOL;

$guarantees = [9, 18];

foreach ($guarantees as $gid) {
    echo "Guarantee $gid:" . PHP_EOL;
    
    // 1. Delete the incorrect status_change event (at 14:35:04)
    $stmt = $db->prepare("
        DELETE FROM guarantee_history 
        WHERE guarantee_id = ? 
          AND event_type = 'status_change'
          AND created_at = '2026-01-05 14:35:04'
    ");
    $stmt->execute([$gid]);
    echo "  ✓ Deleted incorrect status_change event" . PHP_EOL;
    
    // 2. Get manual_edit event timestamp
    $stmt = $db->prepare("
        SELECT created_at 
        FROM guarantee_history 
        WHERE guarantee_id = ? 
          AND event_subtype = 'manual_edit'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$gid]);
    $manualEvent = $stmt->fetch();
    $manualTime = $manualEvent['created_at'];
    
    // Status change should be 1 second AFTER manual match
    $statusChangeTime = date('Y-m-d H:i:s', strtotime($manualTime) + 1);
    
    echo "  Manual match at: $manualTime" . PHP_EOL;
    echo "  Status change at: $statusChangeTime" . PHP_EOL;
    
    // 3. Get data for snapshot
    $stmt = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
    $stmt->execute([$gid]);
    $g = $stmt->fetch();
    $raw = json_decode($g['raw_data'], true);
    
    $stmt = $db->prepare("SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ?");
    $stmt->execute([$gid]);
    $dec = $stmt->fetch();
    
    // Create snapshot BEFORE status change
    // At this point: bank matched (37), supplier matched, status still 'pending'
    $snapshot = [
        'guarantee_number' => $raw['guarantee_number'],
        'contract_number' => $raw['document_reference'] ?? '',
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'type' => $raw['type'] ?? '',
        'supplier_id' => $dec['supplier_id'],  // ✅ Matched manually
        'supplier_name' => '',  // Will be filled
        'bank_id' => 37,  // ✅ Matched auto
        'bank_name' => 'مصرف الإنماء',  // ✅ After auto-match
        'raw_bank_name' => 'مصرف الإنماء',
        'status' => 'pending'  // ✅ BEFORE changing to ready
    ];
    
    $eventDetails = [
        'changes' => [[
            'field' => 'status',
            'old_value' => 'pending',
            'new_value' => 'ready',
            'trigger' => 'data_completeness_check'
        ]],
        'reason' => 'data_completeness_check'
    ];
    
    // 4. Insert new status_change event at correct time
    $stmt = $db->prepare("
        INSERT INTO guarantee_history (
            guarantee_id,
            event_type,
            event_subtype,
            snapshot_data,
            event_details,
            created_at,
            created_by
        ) VALUES (?, ?, ?, ?, ?, ?, ?)
    ");
    
    $stmt->execute([
        $gid,
        'status_change',
        'status_change',
        json_encode($snapshot),
        json_encode($eventDetails),
        $statusChangeTime,
        'بواسطة النظام'
    ]);
    
    echo "  ✓ Added status_change event at correct time (after manual match)" . PHP_EOL;
    echo PHP_EOL;
}

echo "=== DONE ===" . PHP_EOL;
echo "Timeline order now: import → bank_match → manual_edit → status_change" . PHP_EOL;
