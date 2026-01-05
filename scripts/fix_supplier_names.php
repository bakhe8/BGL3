<?php
/**
 * Fix supplier names in snapshots for guarantees 9 & 18
 * 
 * bank_match: Should show Excel supplier name (before manual match)
 * status_change: Should show matched supplier name (after manual match)
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== Fixing Supplier Names in Snapshots ===" . PHP_EOL . PHP_EOL;

foreach ([9, 18] as $gid) {
    echo "Guarantee $gid:" . PHP_EOL;
    
    // 1. Get data
    $stmt = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
    $stmt->execute([$gid]);
    $g = $stmt->fetch();
    $raw = json_decode($g['raw_data'], true);
    $excelSupplier = $raw['supplier'];
    
    $stmt = $db->prepare("
        SELECT d.supplier_id, s.official_name 
        FROM guarantee_decisions d
        JOIN suppliers s ON s.id = d.supplier_id
        WHERE d.guarantee_id = ?
    ");
    $stmt->execute([$gid]);
    $supplier = $stmt->fetch();
    $supplierId = $supplier['supplier_id'];
    $matchedSupplierName = $supplier['official_name'];
    
    echo "  Excel supplier: $excelSupplier" . PHP_EOL;
    echo "  Matched supplier: $matchedSupplierName" . PHP_EOL;
    
    // 2. Fix bank_match snapshot
    $stmt = $db->prepare("
        SELECT id, snapshot_data 
        FROM guarantee_history 
        WHERE guarantee_id = ? AND event_subtype = 'bank_match'
    ");
    $stmt->execute([$gid]);
    $event = $stmt->fetch();
    
    $snapshot = json_decode($event['snapshot_data'], true);
    $snapshot['supplier_name'] = $excelSupplier;  // ✅ From Excel (before manual match)
    $snapshot['raw_supplier_name'] = $excelSupplier;
    
    $stmt = $db->prepare("UPDATE guarantee_history SET snapshot_data = ? WHERE id = ?");
    $stmt->execute([json_encode($snapshot), $event['id']]);
    
    echo "  ✓ Fixed bank_match snapshot: supplier_name = '$excelSupplier'" . PHP_EOL;
    
    // 3. Fix status_change snapshot
    $stmt = $db->prepare("
        SELECT id, snapshot_data 
        FROM guarantee_history 
        WHERE guarantee_id = ? AND event_type = 'status_change'
    ");
    $stmt->execute([$gid]);
    $event = $stmt->fetch();
    
    $snapshot = json_decode($event['snapshot_data'], true);
    $snapshot['supplier_id'] = $supplierId;  // ✅ Already matched
    $snapshot['supplier_name'] = $matchedSupplierName;  // ✅ Matched name (after manual match)
    $snapshot['raw_supplier_name'] = $excelSupplier;  // Keep Excel name in raw
    
    $stmt = $db->prepare("UPDATE guarantee_history SET snapshot_data = ? WHERE id = ?");
    $stmt->execute([json_encode($snapshot), $event['id']]);
    
    echo "  ✓ Fixed status_change snapshot: supplier_name = '$matchedSupplierName'" . PHP_EOL;
    echo PHP_EOL;
}

echo "=== DONE ===" . PHP_EOL;
echo "Supplier names now show correctly in timeline!" . PHP_EOL;
