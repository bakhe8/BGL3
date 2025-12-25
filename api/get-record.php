<?php
/**
 * V3 API - Get Record by Index (Server-Driven Partial HTML)
 * Returns HTML fragment for record form section
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

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
                if ($record['status'] === 'pending' && $top['score'] >= 80) {
                    try {
                        // 1. Capture snapshot BEFORE change
                        $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
                        
                        // 2. Update record data
                        $record['supplier_name'] = $top['official_name'];
                        $record['supplier_id'] = $top['id'];
                        
                        // 3. Prepare change data
                        $newData = [
                            'supplier_id' => $top['id'],
                            'supplier_name' => $top['official_name'],
                            'supplier_trigger' => 'ai_match',
                            'supplier_confidence' => $top['score']
                        ];
                        
                        // 4. Detect changes
                        $changes = \App\Services\TimelineRecorder::detectChanges($oldSnapshot, $newData);
                        
                        // 5. Save to guarantee_decisions
                        // 5. Save to guarantee_decisions
                        if (!empty($changes)) {
                            // Check if Bank implies status change
                            $decStmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
                            $decStmt->execute([$guaranteeId]);
                            $currentDec = $decStmt->fetch(PDO::FETCH_ASSOC);
                            $bankId = $currentDec['bank_id'] ?? null;
                            
                            $newStatus = ($top['id'] && $bankId) ? 'approved' : 'pending';

                            $stmt = $db->prepare('
                                INSERT OR REPLACE INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decided_at, decision_source, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ');
                            $stmt->execute([
                                $guaranteeId,
                                $top['id'],
                                $bankId,
                                $newStatus,
                                date('Y-m-d H:i:s'),
                                'ai_quick',
                                date('Y-m-d H:i:s')
                            ]);
                            
                            // 6. Save timeline event (Strict UE-01)
                            \App\Services\TimelineRecorder::recordDecisionEvent($guaranteeId, $oldSnapshot, $newData, true, $top['score']);
                            
                            // 7. Save Status Transition (SE-01) if changed
                            \App\Services\TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $newStatus, 'ai_completeness_check');
                        }
                    } catch (\Throwable $e) { /* Ignore match error */ }
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
                    try {
                        // 1. Capture snapshot BEFORE change
                        $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
                        
                        // 2. Update record data
                        $record['bank_id'] = $top['id'];
                        
                        // 3. Prepare change data
                        $newData = [
                            'bank_id' => $top['id'],
                            'bank_name' => $top['official_name'],
                            'bank_trigger' => 'ai_match',
                            'bank_confidence' => $top['score']
                        ];
                        
                        // 4. Detect changes
                        $changes = \App\Services\TimelineRecorder::detectChanges($oldSnapshot, $newData);
                        
                        // 5. Update guarantee_decisions
                        if (!empty($changes)) {
                            // Fetch current decision to preserve supplier_id
                            $decStmt = $db->prepare('SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ?');
                            $decStmt->execute([$guaranteeId]);
                            $currentDec = $decStmt->fetch(PDO::FETCH_ASSOC);
                            $supplierId = $currentDec['supplier_id'] ?? null;
                            
                            $newStatus = ($supplierId && $top['id']) ? 'approved' : 'pending';
                            
                            $stmt = $db->prepare('
                                INSERT OR REPLACE INTO guarantee_decisions 
                                (guarantee_id, supplier_id, bank_id, status, decided_at, decision_source, created_at)
                                VALUES (?, ?, ?, ?, ?, ?, ?)
                            ');
                            $stmt->execute([
                                $guaranteeId,
                                $supplierId,
                                $top['id'],
                                $newStatus,
                                date('Y-m-d H:i:s'),
                                'ai_quick',
                                date('Y-m-d H:i:s')
                            ]);
                            
                            // 6. Save timeline event (Strict UE-01)
                            \App\Services\TimelineRecorder::recordDecisionEvent($guaranteeId, $oldSnapshot, $newData, true, $top['score']);
                            
                            // 7. Save Status Transition (SE-01) if changed
                            \App\Services\TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $newStatus, 'ai_completeness_check');
                        }
                    } catch (\Throwable $e) { /* Ignore match error */ }
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
