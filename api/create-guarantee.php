<?php
/**
 * V3 API - Create Guarantee (Manual Entry)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../lib/TimelineHelper.php';

use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Support\Database;
use App\Models\Guarantee;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);

    // Validate required fields
    $required = ['guarantee_number', 'supplier', 'bank', 'amount', 'contract_number'];
    foreach ($required as $field) {
        if (empty($input[$field])) {
            throw new \RuntimeException("الحقل مطلوب: $field");
        }
    }

    $db = Database::connect();
    $repo = new GuaranteeRepository($db);
    $decisionRepo = new GuaranteeDecisionRepository($db);

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
        'source' => 'manual_entry'
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

    // 3. Create Initial Decision (active status)
    $decisionRepo->create([
        'guarantee_id' => $guaranteeId,
        'status' => 'active', 
        'decision_source' => 'manual',
        'decided_by' => 'Web User',
        'supplier_id' => null, 
        'bank_id' => null,
        'amount' => $input['amount']
    ]);

    // 4. Timeline Integration
    // Create first snapshot
    $snapshot = TimelineHelper::createSnapshot($guaranteeId);
    
    // Record "Created" event
    $changes = [
        [
            'field' => 'status',
            'old_value' => null,
            'new_value' => 'created',
            'trigger' => 'manual_creation'
        ]
    ];
    TimelineHelper::saveModifiedEvent($guaranteeId, $changes, $snapshot);

    echo json_encode(['success' => true, 'id' => $guaranteeId]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
