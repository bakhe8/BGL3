<?php
/**
 * V3 API - Get Record by Index  
 * With Timeline Support
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeHistoryRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\NoteRepository;

header('Content-Type: application/json');

try {
    $index = isset($_GET['index']) ? intval($_GET['index']) : 1;
    
    if ($index < 1) {
        throw new \RuntimeException('Invalid index');
    }
    
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    $historyRepo = new GuaranteeHistoryRepository();
    $attachRepo = new AttachmentRepository($db);
    $noteRepo = new NoteRepository($db);
    
    // Get all guarantees (could be optimized with pagination, but for now 100 limit is fine)
    // Actually, getting all IDs first is better for index navigation
    $stmt = $db->query('SELECT id FROM guarantees ORDER BY imported_at DESC LIMIT 100');
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    $total = count($ids);
    
    if ($index > $total) {
        throw new \RuntimeException('Index out of range');
    }
    
    $guaranteeId = $ids[$index - 1];
    $guarantee = $guaranteeRepo->find($guaranteeId);
    
    if (!$guarantee) {
        throw new \RuntimeException('Record not found');
    }

    $raw = $guarantee->rawData;

    // Fetch History for Timeline
    $history = $historyRepo->getHistory($guaranteeId);
    $timeline = [];

    // Add initial event
    $timeline[] = [
        'id' => 'init_' . $guaranteeId,
        'description' => 'تم الاستيراد',
        'date' => $guarantee->importedAt ?? date('Y-m-d H:i:s'),
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
            'history_id' => $h['id'],
            'snapshot' => null // Don't send full snapshot to list to save bandwidth, unless needed
        ];
    }
    
    // Sort timeline desc
    usort($timeline, function($a, $b) {
        return strtotime($b['date']) - strtotime($a['date']);
    });

    // Fetch Attachments & Notes
    $attachments = $attachRepo->getByGuaranteeId($guaranteeId);
    $notes = $noteRepo->getByGuaranteeId($guaranteeId);
    
    // Prepare record data
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '', // This might be overridden by approved suppplier if implemented
        'bank_name' => $raw['bank'] ?? '',
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'pending' // Should fetch real status from decision
    ];
    
    // Check for latest decision for status
    $stmtDec = $db->prepare('SELECT status, supplier_id, bank_id FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
    $stmtDec->execute([$guaranteeId]);
    $lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC);
    
    if ($lastDecision) {
        $record['status'] = $lastDecision['status'];
        // Ideally fetch supplier name if ID exists... but raw is fine for now
    }
    
    echo json_encode([
        'success' => true,
        'record' => $record,
        'timeline' => $timeline,
        'attachments' => $attachments,
        'notes' => $notes,
        'index' => $index,
        'total' => $total
    ]);
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
