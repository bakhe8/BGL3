<?php
/**
 * V3 API - Save and Next (Refactored Phase 11)
 * Uses DecisionService::smartSave for business logic
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Support\Input;
use App\Support\Logger;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\LearningRepository;
use App\Repositories\GuaranteeHistoryRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;
use App\Services\DecisionService;
use App\Services\NavigationService;

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Setup & Input
    $input = json_decode(file_get_contents('php://input'), true) ?? [];
    $guaranteeId = Input::int($input, 'guarantee_id');
    
    if (!$guaranteeId) {
        throw new \InvalidArgumentException('guarantee_id is required');
    }

    $db = Database::connect();
    
    // 2. Initialize Service Layer
    // Dependency Injection manually (Legacy shim)
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    $learningRepo = new LearningRepository($db);
    $historyRepo = new GuaranteeHistoryRepository($db);
    $supplierRepo = new SupplierRepository($db);
    $bankRepo = new BankRepository($db);

    $service = new DecisionService(
        $decisionRepo,
        $guaranteeRepo,
        $learningRepo,
        $historyRepo,
        $supplierRepo,
        $bankRepo
    );

    // 3. Execute Smart Save (Business Logic)
    // This handles: Auto-creation, Name Mismatches, Locking checks, and History Logging
    $result = $service->smartSave($guaranteeId, $input, $db);
    
    // 4. Navigation (Next Record)
    $statusFilter = Input::string($input, 'status_filter', 'all');
    $navInfo = NavigationService::getNavigationInfo($db, $guaranteeId, $statusFilter);
    $nextGuaranteeId = $navInfo['nextId'];

    if (!$nextGuaranteeId) {
        echo json_encode([
            'success' => true,
            'finished' => true,
            'message' => 'تم الانتهاء من جميع السجلات',
            'meta' => $result['meta']
        ]);
        exit;
    }

    // 5. Fetch Next Record Data
    $nextGuarantee = $guaranteeRepo->find($nextGuaranteeId);
    if (!$nextGuarantee) {
        throw new \RuntimeException('Next record found in index but failed to load');
    }
    
    // Prepare next record Response
    $raw = $nextGuarantee->rawData;
    $record = [
        'id' => $nextGuarantee->id,
        'guarantee_number' => $nextGuarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null,
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'pending'
    ];

    // Check for existing decision on next record
    $existingDecision = $decisionRepo->findByGuarantee($nextGuaranteeId);
    if ($existingDecision) {
        $record['status'] = $existingDecision->status;
        $record['bank_id'] = $existingDecision->bankId;
    }

    // Get Banks List (Cached query could be better, but fast enough)
    $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);

    // Get updated navigation info for pagination UI
    $nextNavInfo = NavigationService::getNavigationInfo($db, $nextGuaranteeId, $statusFilter);

    echo json_encode([
        'success' => true,
        'finished' => false,
        'record' => $record,
        'banks' => $banks,
        'currentIndex' => $nextNavInfo['currentIndex'],
        'totalRecords' => $nextNavInfo['totalRecords'],
        'meta' => $result['meta']
    ]);

} catch (\Exception $e) {
    http_response_code($e instanceof \InvalidArgumentException ? 400 : 500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
