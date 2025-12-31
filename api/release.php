<?php
/**
 * V3 API - Release Guarantee (Server-Driven Partial HTML)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;
use App\Support\Database;

header('Content-Type: text/html; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $guaranteeId = $input['guarantee_id'] ?? null;
    $reason = $input['reason'] ?? null; // Optional
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }
    
    // Initialize services
    $db = Database::connect();
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    
    // ===== LIFECYCLE GATE: Prevent release on pending guarantees =====
    $statusCheck = $db->prepare("
        SELECT status 
        FROM guarantee_decisions 
        WHERE guarantee_id = ?
    ");
    $statusCheck->execute([$guaranteeId]);
    $currentStatus = $statusCheck->fetchColumn();
    
    if ($currentStatus !== 'approved') {
        http_response_code(400);
        echo '<div id="record-form-section" class="card" data-current-event-type="current">';
        echo '<div class="card-body" style="color: red;">لا يمكن إفراج عن ضمان غير مكتمل. يجب اختيار المورد والبنك أولاً.</div>';
        echo '</div>';
        exit;
    }
    // ================================================================
    
    // --------------------------------------------------------------------
    // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
    // --------------------------------------------------------------------

    // 1. SNAPSHOT: Capture state BEFORE release
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);

    // 2. UPDATE: Execute system changes
    // Validate that supplier and bank are selected
    $decision = $decisionRepo->findByGuarantee($guaranteeId);
    if (!$decision || empty($decision->supplierId) || empty($decision->bankId)) {
        throw new \RuntimeException('لا يمكن تنفيذ الإفراج - يجب اختيار المورد والبنك أولاً');
    }
    
    // Check if already released
    if ($decision && $decision->status === 'released') {
        throw new \RuntimeException('تم إفراج هذا الضمان مسبقاً');
    }
    
    // Lock the guarantee (set status to 'released')
    $decisionRepo->lock($guaranteeId, 'released');

    // 3. RECORD: Strict Event Recording (UE-04 Release)
    \App\Services\TimelineRecorder::recordReleaseEvent($guaranteeId, $oldSnapshot, $reason);

    // --------------------------------------------------------------------
    
    // Get updated guarantee info for display
    $guarantee = $guaranteeRepo->find($guaranteeId);
    $raw = $guarantee->rawData;
    
    // Prepare record data
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null,
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'released'
    ];
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Include partial template
    echo '<div id="record-form-section" class="decision-card" data-current-event-type="current">';
    include __DIR__ . '/../partials/record-form.php';
    echo '</div>';
    
} catch (\Throwable $e) {
    http_response_code(400);
    echo '<div id="record-form-section" class="card" data-current-event-type="current">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
