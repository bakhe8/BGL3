<?php
declare(strict_types=1);

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\SupplierRepository;
use App\Support\Database;
use App\Models\Guarantee;
use App\Models\GuaranteeDecision;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['guarantee_number', 'supplier', 'bank', 'amount', 'contract_number', 'expiry_date'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new \RuntimeException("الحقل مطلوب: $field");
        }
    }

    $db = Database::connect();
    $repo = new GuaranteeRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $supplierRepo = new SupplierRepository();

    // 1. Prepare Raw Data
    $rawData = [
        'bg_number' => $input['guarantee_number'],
        'supplier' => $input['supplier'],
        'bank' => $input['bank'],
        'amount' => $input['amount'],
        'contract_number' => $input['contract_number'],
        'expiry_date' => $input['expiry_date'] ?? null,
        'issue_date' => $input['issue_date'] ?? null,
        'type' => $input['type'] ?? 'Initial',
        'currency' => 'SAR',
        'details' => $input['comment'] ?? '',
        'source' => 'manual_entry',
        'related_to' => $input['related_to'] ?? 'contract', // 🔥 NEW
    ];

    // 2. Create Guarantee Record
    // Check duplication first
    if ($repo->findByNumber($input['guarantee_number'])) {
        throw new \RuntimeException("رقم الضمان موجود بالفعل: " . $input['guarantee_number']);
    }

    // Create Model Instance
    $guaranteeModel = new Guarantee(
        id: null,
        guaranteeNumber: $input['guarantee_number'],
        rawData: $rawData,
        importSource: 'Manual Entry',
        importedAt: date('Y-m-d H:i:s'),
        importedBy: 'Web User'
    );

    $savedGuarantee = $repo->create($guaranteeModel);
    $guaranteeId = $savedGuarantee->id;
    
    // Record History Event (SmartProcessingService will handle all matching & decision creation!)
    $snapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
    // ✅ ARCHITECTURAL ENFORCEMENT: Use $savedGuarantee->rawData (Post-Persist State)
    \App\Services\TimelineRecorder::recordImportEvent($guaranteeId, 'manual', $savedGuarantee->rawData);

    // ✨ AUTO-MATCHING: Apply Smart Processing
    try {
        $processor = new \App\Services\SmartProcessingService();
        $autoMatchStats = $processor->processNewGuarantees(1);
        
        if ($autoMatchStats['auto_matched'] > 0) {
            error_log("✅ Manual entry auto-matched: Guarantee #$guaranteeId");
        }
    } catch (\Throwable $e) {
        error_log("Auto-matching failed (non-critical): " . $e->getMessage());
    }

    echo json_encode(['success' => true, 'id' => $guaranteeId, 'message' => 'تم إنشاء الضمان بنجاح']);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
