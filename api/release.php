<?php
/**
 * V3 API - Release Guarantee (Server-Driven Partial HTML)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../lib/TimelineHelper.php';

use App\Services\ActionService;
use App\Repositories\GuaranteeActionRepository;
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
    $actionRepo = new GuaranteeActionRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    $service = new ActionService($actionRepo, $decisionRepo, $guaranteeRepo);
    
    // Create release through Service
    $result = $service->createRelease($guaranteeId, $reason);
    
    // Issue immediately (locks the guarantee)
    $service->issueRelease($result['action_id'], $guaranteeId);
    
    // ✅ UPDATE SOURCE OF TRUTH (optional for release, but status is managed by GuaranteeDecisions)
    // No change to raw_data needed for Release usually, but we refresh record
    $guarantee = $guaranteeRepo->find($guaranteeId);
    $raw = $guarantee->rawData;

    // Prepare record data for form
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
        'status' => 'released' // Explicitly released
    ];
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, official_name FROM banks ORDER BY official_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // ====================================================================
    // TIMELINE INTEGRATION - Track release action
    // ====================================================================
    
    // Actions table tracks it, but we can also add a Timeline Event if desired
    // Currently index.php reads from guarantee_actions table so it will show up!
    // No need to duplicate into guarantee_history unless we want strictly unified view
    
    // Include partial template
    echo '<div id="record-form-section" class="decision-card">';
    include __DIR__ . '/../partials/record-form.php';
    echo '</div>';
    
} catch (\Throwable $e) {
    http_response_code(400);
    echo '<div id="record-form-section" class="card">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
