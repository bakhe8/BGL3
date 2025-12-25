<?php
/**
 * V3 API - Reduce Guarantee (Server-Driven Partial HTML)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Services\ActionService;
use App\Repositories\GuaranteeActionRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;
use App\Support\Database;

header('Content-Type: text/html; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $guaranteeId = $input['guarantee_id'] ?? null;
    $newAmount = $input['new_amount'] ?? null;
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }
    
    if (!$newAmount || !is_numeric($newAmount)) {
        throw new \RuntimeException('المبلغ غير صحيح');
    }
    
    // Initialize services
    $db = Database::connect();
    $actionRepo = new GuaranteeActionRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    $service = new ActionService($actionRepo, $decisionRepo, $guaranteeRepo);
    
    // --------------------------------------------------------------------
    // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
    // --------------------------------------------------------------------

    // 1. SNAPSHOT: Capture state BEFORE any modification
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);

    // 2. UPDATE: Execute system changes
    // Create reduction through Service
    $result = $service->createReduction($guaranteeId, (float)$newAmount);
    
    // Update raw_data with new amount
    $guarantee = $guaranteeRepo->find($guaranteeId);
    $raw = $guarantee->rawData;
    $raw['amount'] = $result['new_amount']; 
    $stmt = $db->prepare('UPDATE guarantees SET raw_data = ? WHERE id = ?');
    $stmt->execute([json_encode($raw), $guaranteeId]);

    // 3. RECORD: Strict Event Recording (UE-03 Reduce)
    // We pass previous_amount explicitly to avoid reliance on snapshot if it was already dirty (safety)
    \App\Services\TimelineRecorder::recordReductionEvent(
        $guaranteeId, 
        $oldSnapshot, 
        $result['new_amount'],
        $result['previous_amount']
    );

    // --------------------------------------------------------------------

    // Prepare record data for form
    // Re-fetch to get latest state or manual construction
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null, // Will be filled by suggestions logic if needed
        'amount' => $result['new_amount'],
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'reduced'
    ];
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, official_name FROM banks ORDER BY official_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Include partial template
    echo '<div id="record-form-section" class="decision-card">';
    include __DIR__ . '/../partials/record-form.php';
    echo '</div>';
    
} catch (\Throwable $e) {
    // Return 400 for logic errors so JS handles them nicely
    http_response_code(400); 
    echo '<div id="record-form-section" class="card">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
