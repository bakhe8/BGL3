<?php
/**
 * V3 API - Release Guarantee (Server-Driven Partial HTML)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../lib/TimelineHelper.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Services\RecordHydratorService;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;

header('Content-Type: text/html; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $guaranteeId = $input['guarantee_id'] ?? null;
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }
    
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    $guarantee = $guaranteeRepo->find($guaranteeId);
    
    // 1. Get info for logging/deciding
    $lastDecStmt = $db->prepare('SELECT * FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
    $lastDecStmt->execute([$guaranteeId]);
    $prevDecision = $lastDecStmt->fetch(PDO::FETCH_ASSOC);

    // Keep inputs or defaults
    $currentAmount = $prevDecision['amount'] ?? $guarantee->rawData['amount'] ?? 0;
    $supplierId = $prevDecision['supplier_id'] ?? null;
    $bankId = $prevDecision['bank_id'] ?? null;

    // 2. Create/Update Decision (Status = Released)
    // Check if decision exists (Unique Constraint)
    $stmtCheck = $db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ?');
    $stmtCheck->execute([$guaranteeId]);
    $existingId = $stmtCheck->fetchColumn();

    $now = date('Y-m-d H:i:s');

    if ($existingId) {
        // UPDATE Existing
        $stmt = $db->prepare("
            UPDATE guarantee_decisions 
            SET status = 'released', 
                updated_at = ?,
                decided_at = ?,
                decision_source = 'manual'
            WHERE guarantee_id = ?
        ");
        $stmt->execute([$now, $now, $guaranteeId]);
    } else {
        // INSERT New
        $stmt = $db->prepare("
            INSERT INTO guarantee_decisions 
            (guarantee_id, status, supplier_id, bank_id, amount, decision_source, decided_at, decided_by, created_at, updated_at) 
            VALUES (?, 'released', ?, ?, ?, 'manual', ?, 'Web User', ?, ?)
        ");
        
        $stmt->execute([
            $guaranteeId,
            $supplierId, 
            $bankId,
            $currentAmount,
            $now,
            $now,
            $now
        ]);
    }
    
    // ====================================================================
    // TIMELINE INTEGRATION - Track release action
    // ====================================================================
    
    // 1. Capture snapshot BEFORE release
    $oldSnapshot = TimelineHelper::createSnapshot($guaranteeId);
    
    // 2. Release doesn't change data, but we track the action
    // Note: Release is a milestone, no data changes
    // We could create a simple event or skip if preferred
    // For now, we'll create an event with no changes but with release_action trigger
    
    // 3. Manual log for release (since no data changes)
    // This is optional - release is status change only
    // Could be tracked in guarantee_actions table instead
    
    // 4. Return Updated View
    // Use RecordHydratorService
    $hydrator = new RecordHydratorService($db);
    
    // Simple decision object
    $decision = null;
    if ($supplierId || $bankId) {
        $decision = (object)[
            'supplierId' => $supplierId,
            'bankId' => $bankId,
            'status' => 'released'
        ];
    }
    
    // Hydrate the record
    $record = $hydrator->hydrate($guarantee, $decision);
    $record['status'] = 'released'; // Override status

    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, official_name FROM banks ORDER BY official_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    $supplierMatch = ['score'=>0, 'suggestions'=>[]]; 
    $bankMatch = [];
    
    // Clean Output
    include __DIR__ . '/../partials/record-form.php';
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div id="record-form-section" class="card"><div class="card-body" style="color:red">';
    echo 'خطأ في الإفراج: ' . htmlspecialchars($e->getMessage());
    echo '</div></div>';
}
