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
    
    // 3. Learn Supplier (Direct Repository Logic)
    try {
        $rawSupplier = trim($input['supplier']);
        if (!empty($rawSupplier)) {
            // Normalize
            $normalized = mb_strtolower($rawSupplier);
            $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
            $normalized = preg_replace('/\s+/', ' ', $normalized);
            $normalized = trim($normalized);
            
            if (!empty($normalized)) {
                $existing = $supplierRepo->findByNormalizedName($normalized);
                if (!$existing) {
                    $supplierRepo->create([
                        'official_name' => $rawSupplier,
                        'normalized_name' => $normalized,
                        'is_confirmed' => 1, // User manually entered it
                        'display_name' => $rawSupplier
                    ]);
                }
            }
        }
    } catch (\Throwable $e) {
        error_log("Supplier learning failed: " . $e->getMessage());
    }

    // 4. Create Initial Decision (active status)
    $decision = new GuaranteeDecision(
        id: null,
        guaranteeId: $guaranteeId,
        status: 'active',
        isLocked: false,
        lockedReason: null,
        supplierId: null, // Initial manual entry might not link ID immediately
        bankId: null,
        decisionSource: 'manual',
        confidenceScore: 1.0,
        decidedAt: date('Y-m-d H:i:s'),
        decidedBy: 'Web User'
    );

    $decisionRepo->createOrUpdate($decision);
    
    // 5. Create History Event
    $snapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
    \App\Services\TimelineRecorder::saveImportEvent($guaranteeId, 'manual');

    echo json_encode(['success' => true, 'id' => $guaranteeId, 'message' => 'تم إنشاء الضمان بنجاح']);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
