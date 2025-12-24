<?php
/**
 * V3 API - Get Record by Index (Server-Driven Partial HTML)
 * Returns HTML fragment for record form section
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

header('Content-Type: text/html; charset=utf-8');

try {
    $index = isset($_GET['index']) ? intval($_GET['index']) : 1;
    
    if ($index < 1) {
        throw new \RuntimeException('Invalid index');
    }
    
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    
    // Get all guarantees IDs
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

    // Prepare record data
    $record = [
        'id' => $guarantee->id,
        'guarantee_number' => $guarantee->guaranteeNumber,
        'supplier_name' => $raw['supplier'] ?? '',
        'bank_name' => $raw['bank'] ?? '',
        'bank_id' => null, // Will be set from decision if exists
        'amount' => $raw['amount'] ?? 0,
        'expiry_date' => $raw['expiry_date'] ?? '',
        'issue_date' => $raw['issue_date'] ?? '',
        'contract_number' => $raw['contract_number'] ?? '',
        'type' => $raw['type'] ?? 'Initial',
        'status' => 'pending'
    ];
    
    // Check for latest decision
    $stmtDec = $db->prepare('SELECT status, supplier_id, bank_id FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
    $stmtDec->execute([$guaranteeId]);
    $lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC);
    
    if ($lastDecision) {
        $record['status'] = $lastDecision['status'];
        $record['bank_id'] = $lastDecision['bank_id'];
        $record['supplier_id'] = $lastDecision['supplier_id']; // Ensure ID is set

        // Resolve Supplier Name from ID
        if ($record['supplier_id']) {
            $sStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            $sStmt->execute([$record['supplier_id']]);
            $sName = $sStmt->fetchColumn();
            if ($sName) {
                $record['supplier_name'] = $sName;
            }
        }
        
        // Resolve Bank Name from ID
        if ($record['bank_id']) {
            $bStmt = $db->prepare('SELECT official_name FROM banks WHERE id = ?');
            $bStmt->execute([$record['bank_id']]);
            $bName = $bStmt->fetchColumn();
            if ($bName) {
                $record['bank_name'] = $bName;
            }
        }
    }
    
    
    // Get timeline/history for this guarantee (optional - may not exist)
    $timeline = [];
    try {
        $stmtHistory = $db->prepare('
            SELECT action, change_reason, created_at, created_by 
            FROM guarantee_history 
            WHERE guarantee_id = ? 
            ORDER BY created_at DESC 
            LIMIT 20
        ');
        $stmtHistory->execute([$guaranteeId]);
        $timeline = $stmtHistory->fetchAll(PDO::FETCH_ASSOC);
        
        // Add icons based on action type
        foreach ($timeline as &$event) {
            $event['icon'] = match($event['action']) {
                'imported' => '📥',
                'extended' => '🔄',
                'reduced' => '📉',
                'released' => '📤',
                'approved' => '✅',
                'rejected' => '❌',
                'update'   => '✏️',
                'auto_matched' => '🤖',
                'manual_match' => '🔗',
                default => '📋'
            };
            $event['user'] = $event['created_by'] ?? 'System';
        }
    } catch (\PDOException $e) {
        // History table doesn't exist or query failed - timeline will be empty
        $timeline = [];
    }
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, official_name FROM banks ORDER BY official_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);

    // --- SMART LEARNING INTEGRATION ---
    
    // 1. Supplier Matching
    $supplierMatch = ['score' => 0, 'id' => null, 'name' => '', 'suggestions' => []];
    try {
        $learningRepo = new \App\Repositories\SupplierLearningRepository($db);
        $supplierRepo = new \App\Repositories\SupplierRepository();
        $learningService = new \App\Services\LearningService($learningRepo, $supplierRepo);
        
        if (!empty($record['supplier_name'])) {
            $suggestions = $learningService->getSuggestions($record['supplier_name']);
            if (!empty($suggestions)) {
                $top = $suggestions[0];
                $supplierMatch = [
                    'score' => $top['score'],
                    'id' => $top['id'],
                    'name' => $top['official_name'],
                    'suggestions' => $suggestions // Pass all suggestions for chips
                ];
                
                // Auto-fill if confidence is high and no decision yet
                if ($record['status'] === 'pending' && $top['score'] >= 90) {
                    $record['supplier_name'] = $top['official_name'];
                    $record['supplier_id'] = $top['id'];
                    
                    // --- LOG AUTO-MATCH EVENT ---
                    // Only log if we haven't logged this specific auto-match before
                    try {
                        $checkStmt = $db->prepare("SELECT id FROM guarantee_history WHERE guarantee_id = ? AND action = 'auto_matched' AND snapshot_data LIKE ?");
                        $matchData = json_encode(['field' => 'supplier', 'to' => $top['official_name']]);
                        $checkStmt->execute([$guaranteeId, "%$matchData%"]);
                        
                        if (!$checkStmt->fetch()) {
                            $histStmt = $db->prepare("
                                INSERT INTO guarantee_history (guarantee_id, action, change_reason, snapshot_data, created_at, created_by)
                                VALUES (?, 'auto_matched', ?, ?, NOW(), 'System AI')
                            ");
                            $histStmt->execute([
                                $guaranteeId,
                                "مطابقة تلقائية للمورد: {$guarantee->rawData['supplier']} -> {$top['official_name']} ({$top['score']}%)",
                                $matchData
                            ]);
                        }
                    } catch (\Throwable $e) { /* Ignore log error */ }
                }
            }
        }
    } catch (\Throwable $e) { /* Ignore learning errors */ }

    // 2. Bank Matching
    $bankMatch = ['score' => 0, 'id' => null, 'name' => ''];
    try {
        $bankRepo = new \App\Repositories\BankLearningRepository($db);
        if (!empty($record['bank_name'])) {
            $suggestions = $bankRepo->findSuggestions($record['bank_name'], 1);
            if (!empty($suggestions)) {
                $top = $suggestions[0];
                $bankMatch = [
                    'score' => $top['score'],
                    'id' => $top['id'],
                    'name' => $top['official_name']
                ];
                
                // Auto-select bank if confidence is high and no decision yet
                if ($record['status'] === 'pending' && $top['score'] >= 80) {
                    $record['bank_id'] = $top['id'];
                    
                     // --- LOG AUTO-MATCH EVENT FOR BANK ---
                    try {
                        $checkStmt = $db->prepare("SELECT id FROM guarantee_history WHERE guarantee_id = ? AND action = 'auto_matched' AND snapshot_data LIKE ?");
                        $matchData = json_encode(['field' => 'bank', 'to' => $top['official_name']]);
                        $checkStmt->execute([$guaranteeId, "%$matchData%"]);
                        
                        if (!$checkStmt->fetch()) {
                            $histStmt = $db->prepare("
                                INSERT INTO guarantee_history (guarantee_id, action, change_reason, snapshot_data, created_at, created_by)
                                VALUES (?, 'auto_matched', ?, ?, NOW(), 'System AI')
                            ");
                            $histStmt->execute([
                                $guaranteeId,
                                "مطابقة تلقائية للبنك: {$guarantee->rawData['bank']} -> {$top['official_name']} ({$top['score']}%)",
                                $matchData
                            ]);
                        }
                    } catch (\Throwable $e) { /* Ignore log error */ }
                }
            }
        }
    } catch (\Throwable $e) { /* Ignore learning errors */ }
    
    // Include only record form (timeline is separate in sidebar)
    ob_start();
    include __DIR__ . '/../partials/record-form.php';
    $html = ob_get_clean();
    
    // Wrap in container div
    echo '<div id="record-form-section" class="decision-card">';
    echo $html;
    echo '</div>';
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div id="record-form-section" class="card">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
