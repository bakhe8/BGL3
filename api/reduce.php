<?php
/**
 * V3 API - Reduce Guarantee
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Services\RecordHydratorService;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

header('Content-Type: text/html; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $guaranteeId = $input['guarantee_id'] ?? null;
    $newAmount = $input['new_amount'] ?? null;
    
    if (!$guaranteeId) {
        http_response_code(400); 
        echo 'Missing guarantee_id';
        exit;
    }

    if (!$newAmount || !is_numeric($newAmount)) {
        http_response_code(400);
        echo 'المبلغ المطلوب غير صالح';
        exit;
    }
    
    $newAmount = (float)$newAmount;
    
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    $guarantee = $guaranteeRepo->find($guaranteeId);
    
    // 1. Get Current Amount from raw_data (Source of Truth)
    $currentAmount = (float)($guarantee->rawData['amount'] ?? 0);
    
    // Get previous decision for supplier/bank IDs
    $lastDecStmt = $db->prepare('SELECT * FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
    $lastDecStmt->execute([$guaranteeId]);
    $prevDecision = $lastDecStmt->fetch(PDO::FETCH_ASSOC);
    
    // Validate: Can only reduce if new amount is LESS than current
    if ($newAmount >= $currentAmount) {
         http_response_code(400);
         echo "لا يمكن تخفيض المبلغ إلى قيمة أعلى أو مساوية ($currentAmount)";
         exit;
    }

    // Restore missing variables
    $supplierId = $prevDecision['supplier_id'] ?? null;
    $bankId = $prevDecision['bank_id'] ?? null;

    // 2. Create/Update Decision
    // Check if decision exists (Unique Constraint on guarantee_id)
    $stmtCheck = $db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ?');
    $stmtCheck->execute([$guaranteeId]);
    $existingId = $stmtCheck->fetchColumn();

    $now = date('Y-m-d H:i:s');

    if ($existingId) {
        // UPDATE Existing
        $stmt = $db->prepare("
            UPDATE guarantee_decisions 
            SET status = 'reduced', 
                amount = ?, 
                updated_at = ?,
                decided_at = ?,
                decision_source = 'manual'
            WHERE guarantee_id = ?
        ");
        $stmt->execute([$newAmount, $now, $now, $guaranteeId]);
    } else {
        // INSERT New
        $stmt = $db->prepare("
            INSERT INTO guarantee_decisions 
            (guarantee_id, status, supplier_id, bank_id, amount, decision_source, decided_at, decided_by, created_at, updated_at) 
            VALUES (?, 'reduced', ?, ?, ?, 'manual', ?, 'Web User', ?, ?)
        ");
        
        $stmt->execute([
            $guaranteeId,
            $supplierId, 
            $bankId,
            $newAmount,
            $now,
            $now,
            $now
        ]);
    }
    
    // ✅ UPDATE SOURCE OF TRUTH: Update raw_data with new amount
    $raw = $guarantee->rawData;
    $raw['amount'] = $newAmount;
    $updateRawStmt = $db->prepare('UPDATE guarantees SET raw_data = ? WHERE id = ?');
    $updateRawStmt->execute([json_encode($raw), $guaranteeId]);

    
    // 3. Log History
    $histStmt = $db->prepare("
        INSERT INTO guarantee_history (guarantee_id, action, change_reason, snapshot_data, created_at, created_by)
        VALUES (?, 'reduction', ?, ?, ?, 'Web User')
    ");
    
    // Snapshot
    $snap = $prevDecision ?: []; // Base
    $snap['amount'] = $newAmount;
    
    $histStmt->execute([
        $guaranteeId,
        '📉 تم تخفيض المبلغ من [' . number_format($currentAmount) . '] إلى [' . number_format($newAmount) . ']',
        json_encode($snap),
        $now
    ]);
    
    // 4. Return Updated View
    // Use RecordHydratorService to hydrate the record
    $hydrator = new RecordHydratorService($db);
    
    // Simple lookup for decision
    $decision = null;
    if ($supplierId || $bankId) {
        $decision = (object)[
            'supplierId' => $supplierId,
            'bankId' => $bankId,
            'status' => 'reduced'
        ];
    }
    
    // Hydrate the record
    $record = $hydrator->hydrate($guarantee, $decision);
    $record['status'] = 'reduced'; // Override status

    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, official_name FROM banks ORDER BY official_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check match scores (optional, maybe skip for reduction)
    $supplierMatch = ['score'=>0, 'suggestions'=>[]]; 
    $bankMatch = [];
    
    include __DIR__ . '/../partials/record-form.php';
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div style="color:red; padding:10px;">خطأ في التخفيض: ' . htmlspecialchars($e->getMessage()) . '</div>';
}
