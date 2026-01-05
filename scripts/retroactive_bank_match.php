<?php
/**
 * Retroactive Bank Matching - FINAL VERSION
 * Using correct column names from guarantee_history table
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

$guaranteeIds = [9, 18];
$alinmaBankId = 37;

echo "=== Retroactive Bank Matching ===" . PHP_EOL . PHP_EOL;

foreach ($guaranteeIds as $gid) {
    echo "Guarantee $gid:" . PHP_EOL;
    
    // Get guarantee
    $stmt = $db->prepare("SELECT guarantee_number, imported_at, raw_data FROM guarantees WHERE id = ?");
    $stmt->execute([$gid]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$g) {
        echo "  ERROR: Not found!" . PHP_EOL;
        continue;
    }
    
    $raw = json_decode($g['raw_data'], true);
    $eventTime = date('Y-m-d H:i:s', strtotime($g['imported_at']) + 1);
    
    echo "  Number: {$g['guarantee_number']}" . PHP_EOL;
    echo "  Event Time: $eventTime" . PHP_EOL;
    
    // 1. Update decision
    $stmt = $db->prepare("UPDATE guarantee_decisions SET bank_id = ?, status = 'ready' WHERE guarantee_id = ?");
    $stmt->execute([$alinmaBankId, $gid]);
    echo "  ✓ Decision updated (bank_id=37, status=ready)" . PHP_EOL;
    
    // 2. Get supplier_id for snapshot
    $stmt = $db->prepare("SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ?");
    $stmt->execute([$gid]);
    $dec = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // 3. Create snapshot
    $snapshot = [
        'guarantee_number' => $g['guarantee_number'],
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'supplier_id' => $dec['supplier_id'],
        'bank_id' => $alinmaBankId,
        'bank_name' => 'مصرف الإنماء',
        'status' => 'ready'
    ];
    
    // 4. Insert timeline event
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
    
    $snapshotData = json_encode($snapshot);
    $eventDetails = json_encode([
        'bank_id' => $alinmaBankId,
        'bank_name' => 'مصرف الإنماء',
        'excel_bank' => $raw['bank'],
        'matched_automatically' => true
    ]);
    
    try {
        $stmt->execute([
            $gid,
            'modified',
            'bank_match',  // ✅ SAME as SmartProcessingService uses (line 368)
            $snapshotData,
            $eventDetails,
            $eventTime,
            'بواسطة النظام'
        ]);
        echo "  ✓ Timeline event added (modified/bank_match)" . PHP_EOL;
    } catch (Exception $e) {
        echo "  ERROR: " . $e->getMessage() . PHP_EOL;
    }
    
    echo PHP_EOL;
}

echo "=== DONE ===" . PHP_EOL;
echo "Check guarantees 9 & 18 now - bank should be matched!" . PHP_EOL;
