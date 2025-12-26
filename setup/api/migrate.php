<?php
/**
 * Migration API - Move confirmed data to main database
 */

require_once __DIR__ . '/../SetupDatabase.php';
require_once __DIR__ . '/../../app/Support/Database.php';

use App\Support\Database;

header('Content-Type: application/json');

try {
    // SECURITY: Migration is a critical operation
    // Log the migration attempt
    error_log('[SETUP MIGRATION] Migration initiated at ' . date('Y-m-d H:i:s'));
    
    // Connect to both databases
    $tempDb = SetupDatabase::connect();
    $mainDb = Database::connect();
    
    // Count what we're about to migrate for logging
    $supplierCount = $tempDb->query("SELECT COUNT(*) FROM temp_suppliers WHERE status = 'confirmed'")->fetchColumn();
    $bankCount = $tempDb->query("SELECT COUNT(*) FROM temp_banks WHERE status = 'confirmed'")->fetchColumn();
    
    error_log("[SETUP MIGRATION] About to migrate: {$supplierCount} suppliers, {$bankCount} banks");
    
    if ($supplierCount == 0 && $bankCount == 0) {
        throw new Exception('لا توجد بيانات مؤكدة للنقل');
    }
    
    $results = [
        'suppliers' => ['migrated' => 0, 'skipped' => 0],
        'banks' => ['migrated' => 0, 'skipped' => 0]
    ];
    
    $mainDb->beginTransaction();
    
    try {
        // === MIGRATE SUPPLIERS ===
        $stmt = $tempDb->query("
            SELECT id, supplier_name, normalized_name, user_edited_name
            FROM temp_suppliers
            WHERE status = 'confirmed'
        ");
        
        $confirmedSuppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($confirmedSuppliers as $supplier) {
            $finalName = $supplier['user_edited_name'] ?: $supplier['supplier_name'];
            $normalized = $supplier['normalized_name'];
            
            // Check if already exists
            $checkStmt = $mainDb->prepare("
                SELECT id FROM suppliers WHERE normalized_name = ?
            ");
            $checkStmt->execute([$normalized]);
            
            if ($checkStmt->fetch()) {
                $results['suppliers']['skipped']++;
                continue; // Skip duplicates
            }
            
            // Insert new supplier
            $insertStmt = $mainDb->prepare("
                INSERT INTO suppliers (
                    official_name,
                    normalized_name,
                    supplier_normalized_key,
                    is_confirmed
                ) VALUES (?, ?, ?, 1)
            ");
            
            $insertStmt->execute([
                $finalName,
                $normalized,
                $normalized
            ]);
            
            $results['suppliers']['migrated']++;
        }
        
        // === MIGRATE BANKS ===
        $stmt = $tempDb->query("
            SELECT id, bank_name, normalized_name, user_edited_name
            FROM temp_banks
            WHERE status = 'confirmed'
        ");
        
        $confirmedBanks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($confirmedBanks as $bank) {
            $finalName = $bank['user_edited_name'] ?: $bank['bank_name'];
            $normalized = $bank['normalized_name'];
            
            // Check if already exists
            $checkStmt = $mainDb->prepare("
                SELECT id FROM banks WHERE normalized_name = ?
            ");
            $checkStmt->execute([$normalized]);
            
            if ($checkStmt->fetch()) {
                $results['banks']['skipped']++;
                continue;
            }
            
            // Insert new bank
            $insertStmt = $mainDb->prepare("
                INSERT INTO banks (
                    official_name,
                    normalized_name,
                    is_confirmed
                ) VALUES (?, ?, 1)
            ");
            
            $insertStmt->execute([
                $finalName,
                $normalized
            ]);
            
            $results['banks']['migrated']++;
        }
        
        $mainDb->commit();
        
        echo json_encode([
            'success' => true,
            'data' => $results
        ]);
        
    } catch (Exception $e) {
        $mainDb->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
