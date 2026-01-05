<?php
/**
 * Add status_change event for guarantees 9 & 18
 * This should happen RIGHT AFTER bank auto-match makes them ready
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

$guarantees = [
    9 => 'MD2532100019',
    18 => 'MD2504800042'
];

echo "=== Adding Status Change Events ===" . PHP_EOL . PHP_EOL;

foreach ($guarantees as $gid => $gnum) {
    echo "Processing Guarantee $gid ($gnum):" . PHP_EOL;
    
    // Get raw_data for snapshot
    $stmt = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
    $stmt->execute([$gid]);
    $guarantee = $stmt->fetch();
    $raw = json_decode($guarantee['raw_data'], true);
    
    // Get supplier_id from decision
    $stmt = $db->prepare("SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ?");
    $stmt->execute([$gid]);
    $decision = $stmt->fetch();
    
    // Create snapshot BEFORE status change (status was still 'pending')
    // This happens AFTER bank_match, so bank_id and bank_name should be matched
    $snapshot = [
        'guarantee_number' => $gnum,
        'contract_number' => $raw['document_reference'] ?? '',
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'type' => $raw['type'] ?? '',
        'supplier_id' => $decision['supplier_id'],  // Already matched
        'supplier_name' => '',  // Will be filled if exists
        'bank_id' => 37,  // ✅ AFTER auto-match
        'bank_name' => 'مصرف الإنماء',  // ✅ Matched name
        'raw_bank_name' => 'مصرف الإنماء',
        'status' => 'pending'  // ✅ BEFORE the change
    ];
    
    // Create event_details
    $eventDetails = [
        'changes' => [[
            'field' => 'status',
            'old_value' => 'pending',
            'new_value' => 'ready',
            'trigger' => 'data_completeness_check'
        ]],
        'reason' => 'data_completeness_check'
    ];
    
    // Timestamp: RIGHT AFTER bank_match (add 1 second)
    $eventTime = '2026-01-05 14:35:04';  // bank_match was at 14:35:03
    
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
        $eventTime,
        'بواسطة النظام'
    ]);
    
    echo "  ✓ Added status_change event (pending → ready) at $eventTime" . PHP_EOL;
    echo PHP_EOL;
}

echo "=== DONE ===" . PHP_EOL;
echo "Status change events added!" . PHP_EOL;
