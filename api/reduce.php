<?php
/**
 * V3 API - Reduce Guarantee (Server-Driven Partial HTML)
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
    $newAmount = $input['new_amount'] ?? null;
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }
    
    if (!$newAmount || !is_numeric($newAmount)) {
        throw new \RuntimeException('المبلغ غير صحيح');
    }
    
    // Initialize services
    $db = Database::connect();
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    
    // --------------------------------------------------------------------
    // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
    // --------------------------------------------------------------------

    // 1. SNAPSHOT: Capture state BEFORE any modification
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);

    // 2. UPDATE: Execute system changes
    // 🆕 Directly update amount (no guarantee_actions)
    $guarantee = $guaranteeRepo->find($guaranteeId);
    $raw = $guarantee->rawData;
    $previousAmount = $raw['amount'] ?? 0;
    $raw['amount'] = (float)$newAmount;
    
    // Update raw_data through repository
    $guaranteeRepo->updateRawData($guaranteeId, json_encode($raw));

    // 3. RECORD: Strict Event Recording (UE-03 Reduce)
    // 🆕 Record ONLY in guarantee_history (no guarantee_actions)
    \App\Services\TimelineRecorder::recordReductionEvent(
        $guaranteeId, 
        $oldSnapshot, 
        (float)$newAmount,
        $previousAmount
    );

    // --------------------------------------------------------------------

    // Prepare record data for form
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null,
        'amount' => (float)$newAmount,  // 🆕 Use parameter value
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'reduced'
    ];
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Include partial template
    echo '<div id="record-form-section" class="decision-card" data-current-event-type="current">';
    include __DIR__ . '/../partials/record-form.php';
    echo '</div>';
    
} catch (\Throwable $e) {
    // Return 400 for logic errors so JS handles them nicely
    http_response_code(400); 
    echo '<div id="record-form-section" class="card" data-current-event-type="current">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
