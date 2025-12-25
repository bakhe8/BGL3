<?php
/**
 * V3 API - Extend Guarantee (Server-Driven Partial HTML)
 * Returns HTML fragment for updated record section
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
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }
    
    // Initialize services
    $db = Database::connect();
    $actionRepo = new GuaranteeActionRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    $service = new ActionService($actionRepo, $decisionRepo, $guaranteeRepo);
    
    // Create extension
    $result = $service->createExtension($guaranteeId);
    
    // Issue immediately
    $service->issueExtension($result['action_id']);
    
    // ✅ UPDATE SOURCE OF TRUTH: Update raw_data with new expiry
    $guarantee = $guaranteeRepo->find($guaranteeId);
    $raw = $guarantee->rawData;
    $raw['expiry_date'] = $result['new_expiry_date']; // Update with new date
    
    // Save back to database
    $stmt = $db->prepare('UPDATE guarantees SET raw_data = ? WHERE id = ?');
    $stmt->execute([json_encode($raw), $guaranteeId]);

    // Prepare record data
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null,
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $result['new_expiry_date'] ?? ($raw['expiry_date'] ?? ''),
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'extended'
    ];
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, official_name FROM banks ORDER BY official_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================================================================
    // TIMELINE INTEGRATION - Track extension action
    // ====================================================================
    
    // 1. Capture snapshot BEFORE extension
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
    
    // 2. Prepare change data
    $newData = [
        'expiry_date' => $result['new_expiry_date'],
        'expiry_trigger' => 'extension_action',
        'action_id' => $result['action_id']
    ];
    
    // 3. Detect changes
    $changes = \App\Services\TimelineRecorder::detectChanges($oldSnapshot, $newData);
    
    // 4. Save timeline event
    if (!empty($changes)) {
        \App\Services\TimelineRecorder::saveModifiedEvent($guaranteeId, $changes, $oldSnapshot);
    }
    
    // Include partial template to render HTML
    echo '<div id="record-form-section" class="decision-card">';
    include __DIR__ . '/../partials/record-form.php';
    echo '</div>';
    
    
} catch (\Throwable $e) {
    // Return 400 for logic errors (like "Cannot extend after release")
    http_response_code(400);
    echo '<div id="record-form-section" class="card">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
