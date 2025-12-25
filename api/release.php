<?php
/**
 * V3 API - Release Guarantee (Server-Driven Partial HTML)
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
    
    // --------------------------------------------------------------------
    // STRICT TIMELINE DISCIPLINE: Snapshot -> Update -> Record
    // --------------------------------------------------------------------

    // 1. SNAPSHOT: Capture state BEFORE release
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);

    // 2. UPDATE: Execute system changes
    // Create release through Service
    $result = $service->createRelease($guaranteeId, $reason);

    // Issue immediately (locks the guarantee)
    $service->issueRelease($result['action_id'], $guaranteeId);

    // 3. RECORD: Strict Event Recording (UE-04 Release)
    \App\Services\TimelineRecorder::recordReleaseEvent($guaranteeId, $oldSnapshot, $reason);

    // --------------------------------------------------------------------
    
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
