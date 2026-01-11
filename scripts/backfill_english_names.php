<?php
/**
 * Backfill English Names Script
 * 
 * Iterates through all guarantees, checks their raw imported data (English),
 * finds the linked supplier, and backfills the `english_name` if pertinent.
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Repositories/GuaranteeRepository.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

try {
    $db = Database::connect();
    $repo = new GuaranteeRepository($db);
    
    // 1. Fetch all guarantees
    // Note: findAll might be paginated or limited? Let's assume there are only 22 as user said.
    // If Repository doesn't expose generic findAll, we query directly.
    $stmt = $db->query("SELECT * FROM guarantees");
    $guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Processing " . count($guarantees) . " guarantees...\n";
    $updatedCount = 0;
    
    foreach ($guarantees as $g) {
        $raw = json_decode($g['raw_data'], true);
        $rawSupplier = trim($raw['supplier'] ?? '');
        
        if (!$rawSupplier) continue;
        
        // Check if raw data is English (contains no Arabic)
        if (preg_match('/\p{Arabic}/u', $rawSupplier)) {
            // Raw data is Arabic, nothing to backfill (it's not an English source)
            continue;
        }
        
        // Find linked decision -> supplier
        $decStmt = $db->prepare("SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1");
        $decStmt->execute([$g['id']]);
        $supplierId = $decStmt->fetchColumn();
        
        if (!$supplierId) {
            // No decision yet. Try to find if a supplier exists with the *Arabic* translation? 
            // Or maybe the user hasn't processed this one.
            // Let's try to match existing supplier by ID if the user manually linked it previously?
            // Actually, if there is no decision, we can't be sure which supplier it is.
            // But wait, user might have just imported them.
            // If the user *changed* the name to Arabic, they must have *Saved* it.
            // If they saved it, there *is* a decision.
            echo " - Guarantee #{$g['guarantee_number']}: No decision/supplier link found. Skipping.\n";
            continue;
        }
        
        // Fetch Supplier
        $supStmt = $db->prepare("SELECT * FROM suppliers WHERE id = ?");
        $supStmt->execute([$supplierId]);
        $supplier = $supStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$supplier) continue;
        
        // Check if Supplier Official Name is Arabic
        if (!preg_match('/\p{Arabic}/u', $supplier['official_name'])) {
            // Supplier official name is English. 
            // If English Name is empty, populate it just in case.
            if (empty($supplier['english_name'])) {
                 $update = $db->prepare("UPDATE suppliers SET english_name = ? WHERE id = ?");
                 $update->execute([$supplier['official_name'], $supplier['id']]);
                 echo " - Guarantee #{$g['guarantee_number']}: Copied English Official Name to English Name field ({$supplier['official_name']})\n";
                 $updatedCount++;
            }
            continue;
        }
        
        // Use Case: Official is Arabic, Raw is English.
        // Update English Name !
        if (empty($supplier['english_name']) || $supplier['english_name'] != $rawSupplier) {
            $update = $db->prepare("UPDATE suppliers SET english_name = ? WHERE id = ?");
            $update->execute([$rawSupplier, $supplier['id']]);
            echo " - Guarantee #{$g['guarantee_number']}: Updated Supplier [{$supplier['official_name']}] English Name -> '{$rawSupplier}'\n";
            $updatedCount++;
        }
    }
    
    echo "\nDone! Updated $updatedCount supplier records.\n";
    
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
