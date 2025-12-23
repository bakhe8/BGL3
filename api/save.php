<?php
/**
 * V3 API - Save Decision and Load Next Record
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\DecisionService;
use App\Services\LearningService;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeHistoryRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\NoteRepository;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;
use App\Support\Database;

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    $guaranteeId = $input['guarantee_id'] ?? null;
    $supplierId = $input['supplier_id'] ?? null;
    $bankId = $input['bank_id'] ?? null;
    $currentIndex = $input['current_index'] ?? 1;
    
    if (!$guaranteeId) {
        throw new \RuntimeException('Missing guarantee_id');
    }
    
    // Initialize services
    $db = Database::connect();
    $decisionRepo = new GuaranteeDecisionRepository($db);
    $guaranteeRepo = new GuaranteeRepository($db);
    $historyRepo = new GuaranteeHistoryRepository();
    $attachRepo = new AttachmentRepository($db);
    $noteRepo = new NoteRepository($db);
    
    // Learning Dependencies
    $learningRepo = new SupplierLearningRepository($db);
    $supplierRepo = new SupplierRepository($db);
    $learningService = new LearningService($learningRepo, $supplierRepo);
    
    $service = new DecisionService($decisionRepo, $guaranteeRepo, $learningService);
    
    // Save decision
    $decision = $service->save($guaranteeId, [
        'supplier_id' => $supplierId,
        'bank_id' => $bankId,
        'decision_source' => $input['decision_source'] ?? 'manual',
        'status' => 'ready',
        'confidence_score' => $input['confidence_score'] ?? null,
        'raw_supplier_name' => $input['supplier_name'] ?? null,
    ]);
    
    // Get next record
    $nextIndex = $currentIndex + 1;
    
    // Get all guarantee IDs
    $stmt = $db->query('SELECT id FROM guarantees ORDER BY imported_at DESC LIMIT 100');
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $total = count($ids);
    
    // Check if there's a next record
    if ($nextIndex > $total) {
        // No more records, return current
        echo json_encode([
            'success' => true,
            'decision' => $decision->toArray(),
            'completed' => true,
            'message' => 'تم الانتهاء من جميع السجلات'
        ]);
        exit;
    }
    
    // Load next record
    $nextGuaranteeId = $ids[$nextIndex - 1];
    $nextGuarantee = $guaranteeRepo->find($nextGuaranteeId);
    
    if (!$nextGuarantee) {
        throw new \RuntimeException('Next record not found');
    }
    
    $raw = $nextGuarantee->rawData;
    
    // Fetch History for Timeline
    $history = $historyRepo->getHistory($nextGuaranteeId);
    $timeline = [];
    
    // Add initial event
    $timeline[] = [
        'id' => 'init_' . $nextGuaranteeId,
        'description' => 'تم الاستيراد',
        'date' => $nextGuarantee->importedAt ?? date('Y-m-d H:i:s'),
        'details' => 'استيراد أولي للنظام',
        'history_id' => null
    ];
    
    // Add history events
    foreach ($history as $h) {
        $timeline[] = [
            'id' => 'hist_' . $h['id'],
            'description' => $h['action'] === 'decision_update' ? 'قرار جديد' : $h['action'],
            'date' => $h['created_at'],
            'details' => $h['change_reason'] ?? $h['created_by'],
            'history_id' => $h['id']
        ];
    }
    
    // Sort timeline desc
    usort($timeline, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });
    
    // Fetch Attachments & Notes
    $attachments = $attachRepo->getByGuaranteeId($nextGuaranteeId);
    $notes = $noteRepo->getByGuaranteeId($nextGuaranteeId);
    
    // Prepare next record data
    $nextRecord = [
        'id' => $nextGuarantee->id,
        'guarantee_number' => $nextGuarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'raw_supplier_name' => $raw['supplier'] ?? '',
        'excel_supplier' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'ابتدائي',
        'status' => 'pending'
    ];
    
    // Check for latest decision
    $stmtDec = $db->prepare('SELECT status FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
    $stmtDec->execute([$nextGuaranteeId]);
    $lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC);
    
    if ($lastDecision) {
        $nextRecord['status'] = $lastDecision['status'];
    }
    
    echo json_encode([
        'success' => true,
        'decision' => $decision->toArray(),
        'record' => $nextRecord,
        'timeline' => $timeline,
        'attachments' => $attachments,
        'notes' => $notes,
        'index' => $nextIndex,
        'total' => $total
    ]);
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
