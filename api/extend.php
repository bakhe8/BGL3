<?php
/**
 * V3 API - Extend Guarantee (Server-Driven Partial HTML)
 * Returns HTML fragment for updated record section
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
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }
    
    // Initialize services
    $db = Database::connect();
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    
    // ===== LIFECYCLE GATE: Prevent extension on pending guarantees =====
    $statusCheck = $db->prepare("
        SELECT status 
        FROM guarantee_decisions 
        WHERE guarantee_id = ?
    ");
    $statusCheck->execute([$guaranteeId]);
    $currentStatus = $statusCheck->fetchColumn();
    
    if ($currentStatus !== 'ready') {
        http_response_code(400);
        echo '<div id="record-form-section" class="card" data-current-event-type="current">';
        echo '<div class="card-body" style="color: red;">لا يمكن تمديد ضمان غير مكتمل. يجب اختيار المورد والبنك أولاً.</div>';
        echo '</div>';
        exit;
    }
    // ================================================================
    
    // --------------------------------------------------------------------
    // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
    // --------------------------------------------------------------------

    // 1. SNAPSHOT: Capture state BEFORE any modification
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
    
    // 2. UPDATE: Execute system changes
    // 🆕 Calculate new expiry date directly (always +1 year)
    $guarantee = $guaranteeRepo->find($guaranteeId);
    $raw = $guarantee->rawData;
    $oldExpiry = $raw['expiry_date'] ?? '';
    $newExpiry = date('Y-m-d', strtotime($oldExpiry . ' +1 year'));
    
    // Update source of truth (Raw Data)
    $raw['expiry_date'] = $newExpiry;
    
    // Update raw_data through repository
    $guaranteeRepo->updateRawData($guaranteeId, json_encode($raw));

    // 3. NEW (Phase 3): Set Active Action
    $decisionRepo->setActiveAction($guaranteeId, 'extension');

    // 4. RECORD: Strict Event Recording (UE-02 Extend)
    // 🆕 Record ONLY in guarantee_history (no guarantee_actions)
    \App\Services\TimelineRecorder::recordExtensionEvent(
        $guaranteeId, 
        $oldSnapshot, 
        $newExpiry
    );

    // --------------------------------------------------------------------
    
    // Prepare record data
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null,
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $newExpiry,  // 🆕 Use calculated value
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'extended'
    ];
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);

    // Include partial template
    echo '<div id="record-form-section" class="decision-card" data-current-event-type="current">';
    include __DIR__ . '/../partials/record-form.php';
    echo '</div>';
    
    
} catch (\Throwable $e) {
    // Return 400 for logic errors (like "Cannot extend after release")
    http_response_code(400);
    echo '<div id="record-form-section" class="card" data-current-event-type="current">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
