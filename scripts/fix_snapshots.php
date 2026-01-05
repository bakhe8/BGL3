<?php
/**
 * Fix event_details and snapshots for guarantees 9 & 18
 * Based on guarantee 8's correct structure
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

$guarantees = [
    9 => 'MD2532100019',
    18 => 'MD2504800042'
];

echo "=== Fixing Events for Guarantees 9 & 18 ===" . PHP_EOL . PHP_EOL;

foreach ($guarantees as $gid => $gnum) {
    echo "Processing Guarantee $gid ($gnum):" . PHP_EOL;
    
    // 1. Fix bank_match event (14:35:03)
    // event_details should have changes array showing transition
    // snapshot should show ALINMA BANK (before match)
    
    $eventDetails = json_encode([
        'action' => 'Bank auto-matched',
        'changes' => [[
            'field' => 'bank_id',
            'old_value' => ['id' => null, 'name' => 'ALINMA BANK'],
            'new_value' => ['id' => 37, 'name' => 'مصرف الإنماء'],
            'trigger' => 'ai_match'
        ]]
    ]);
    
    $snapshot = json_encode([
        'guarantee_number' => $gnum,
        'amount' => 0,  // Will be populated from raw_data
        'expiry_date' => '',
        'supplier_id' => null,  // Not matched yet at this point
        'bank_id' => null,  // BEFORE matching
        'bank_name' => 'ALINMA BANK',  // ✅ Original name BEFORE matching
        'status' => 'pending'
    ]);
    
    $stmt = $db->prepare("
        UPDATE guarantee_history 
        SET event_details = ?,
            snapshot_data = ?
        WHERE guarantee_id = ? 
          AND event_subtype = 'bank_match'
          AND created_at = '2026-01-05 14:35:03'
    ");
    $stmt->execute([$eventDetails, $snapshot, $gid]);
    echo "  ✓ Fixed bank_match event (shows ALINMA BANK → مصرف الإنماء)" . PHP_EOL;
    
    // 2. Fix manual_edit event snapshot
    // snapshot should show مصرف الإنماء (after bank auto-match)
    
    $stmt = $db->prepare("
        SELECT id, snapshot_data 
        FROM guarantee_history 
        WHERE guarantee_id = ? 
          AND event_subtype = 'manual_edit'
        ORDER BY created_at DESC
        LIMIT 1
    ");
    $stmt->execute([$gid]);
    $manualEvent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($manualEvent) {
        $oldSnapshot = json_decode($manualEvent['snapshot_data'], true);
        
        // Update bank_name to show matched name (after auto-match happened)
        $oldSnapshot['bank_name'] = 'مصرف الإنماء';  // ✅ After auto-matching
        $oldSnapshot['bank_id'] = 37;  // Already matched
        
        $stmt = $db->prepare("
            UPDATE guarantee_history 
            SET snapshot_data = ?
            WHERE id = ?
        ");
        $stmt->execute([json_encode($oldSnapshot), $manualEvent['id']]);
        echo "  ✓ Fixed manual_edit snapshot (shows مصرف الإنماء after auto-match)" . PHP_EOL;
    }
    
    echo PHP_EOL;
}

echo "=== DONE ===" . PHP_EOL;
echo "Verify timeline now - should match guarantee 8 behavior!" . PHP_EOL;
