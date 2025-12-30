<?php
/**
 * V3 API - Save and Next (Server-Driven Partial HTML)
 * Saves current record decision and returns HTML for next record
 * Single endpoint = single decision
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

header('Content-Type: application/json; charset=utf-8');

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $guaranteeId = $input['guarantee_id'] ?? null;
    $supplierId = $input['supplier_id'] ?? null;
    $supplierName = trim($input['supplier_name'] ?? '');
    // Bank is no longer sent - it's set once during import/matching
    $currentIndex = $input['current_index'] ?? 1;
    
    if (!$guaranteeId) {
        echo json_encode(['success' => false, 'error' => 'guarantee_id is required']);
        exit;
    }
    
    $db = Database::connect();
    $guaranteeRepo = new GuaranteeRepository($db);
    $currentGuarantee = $guaranteeRepo->find($guaranteeId);

    // SAFEGUARD: Check for ID/Name Mismatch
    // If frontend failed to clear ID, but user changed name, we trust the NAME.
    if ($supplierId && $supplierName) {
        $chkStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
        $chkStmt->execute([$supplierId]);
        $dbName = $chkStmt->fetchColumn();
        
        // Compare normalized
        if ($dbName && mb_strtolower(trim($dbName)) !== mb_strtolower(trim($supplierName))) {
            // Mismatch detected! User changed name. Ignore old ID.
            $supplierId = null; 
        }
    }

    // 1. Resolve Supplier ID if missing (or cleared by safeguard)
    $supplierError = '';
    if (!$supplierId && $supplierName) {
        $normStub = mb_strtolower($supplierName);
        
        // Strategy A: Exact Match
        $stmt = $db->prepare('SELECT id FROM suppliers WHERE official_name = ?'); 
        $stmt->execute([$supplierName]);
        $supplierId = $stmt->fetchColumn();
        
        // Strategy B: Normalized Match (Case insensitive)
        if (!$supplierId) {
             $stmt = $db->prepare('SELECT id FROM suppliers WHERE normalized_name = ?');
             $stmt->execute([$normStub]);
             $supplierId = $stmt->fetchColumn();
        }
        
        if (!$supplierId) {
            // Auto-create new supplier with improved error handling
            try {
                // Generate normalized name (required by schema)
                $normName = $normStub;
                
                $stmt = $db->prepare('INSERT INTO suppliers (official_name, normalized_name) VALUES (?, ?)');
                $stmt->execute([$supplierName, $normName]);
                $supplierId = $db->lastInsertId();
                
                error_log("[AUTO-CREATE] Created supplier: $supplierName (ID: $supplierId)");
                
            } catch (\PDOException $e) {
                // Check if it's duplicate key error specifically (race condition)
                if ($e->getCode() == '23000') {
                    // This is the race condition - another process created it
                    error_log("[RACE-CONDITION] Supplier created by another process: $supplierName");
                    
                    $stmt = $db->prepare('SELECT id FROM suppliers WHERE normalized_name = ?');
                    $stmt->execute([$normName]);
                    $supplierId = $stmt->fetchColumn();
                    
                    if ($supplierId) {
                        error_log("[RACE-CONDITION-RESOLVED] Found supplier after race: $supplierName (ID: $supplierId)");
                    } else {
                        // Still null? Something's very wrong
                        error_log("[CRITICAL] Failed to create or find supplier after race condition: $supplierName - " . $e->getMessage());
                        $supplierError = "فشل في إنشاء المورد. الرجاء المحاولة مرة أخرى.";
                    }
                } else {
                    // Other database error - log and report
                    error_log("[DB-ERROR] Failed to create supplier '$supplierName': " . $e->getMessage());
                    $supplierError = "خطأ في قاعدة البيانات: " . $e->getMessage();
                }
            }
        }
    }

    // Validation
    if (!$guaranteeId || !$supplierId) {
        $missing = [];
        if (!$guaranteeId) $missing[] = 'Guarantee ID';
        if (!$supplierId) $missing[] = "Supplier (Unknown" . ($supplierError ? ": $supplierError" : "") . ")";
        
        http_response_code(400); // Bad Request
        echo json_encode(['success' => false, 'error' => 'Missing fields: ' . implode(', ', $missing)]);
        exit;
    }
    
    // --- DETECT CHANGES ---
    $now = date('Y-m-d H:i:s');
    $changes = [];
    
    // Determine old state (last decision > raw data)
    $lastDecStmt = $db->prepare('SELECT supplier_id FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
    $lastDecStmt->execute([$guaranteeId]);
    $prevDecision = $lastDecStmt->fetch(PDO::FETCH_ASSOC);
    

    // Resolve Old Supplier Name
    if ($prevDecision && $prevDecision['supplier_id']) {
        $stmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
        $stmt->execute([$prevDecision['supplier_id']]);
        $oldSupplier = $stmt->fetchColumn() ?: '';
    } else {
        $oldSupplier = $currentGuarantee->rawData['supplier'] ?? '';
    }

    // Resolve Old Bank Name
    // Bank is set once during import/matching and not changed by this endpoint.
    // We still need its ID for status evaluation.
    $bankStmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
    $bankStmt->execute([$guaranteeId]);
    $bankId = $bankStmt->fetchColumn() ?: null; // Get the existing bank_id

    if ($bankId) {
        $stmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
        $stmt->execute([$bankId]);
        $oldBank = $stmt->fetchColumn() ?: '';
    } else {
        $oldBank = $currentGuarantee->rawData['bank'] ?? '';
    }
    
    // 1. Check Supplier Change
    // Fetch new supplier name
    $supStmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
    $supStmt->execute([$supplierId]);
    $newSupplier = $supStmt->fetchColumn();
    
    // Normalize for comparison (Trim spaces)
    if (trim($oldSupplier) !== trim($newSupplier)) {
        $changes[] = "تغيير المورد من [{$oldSupplier}] إلى [{$newSupplier}]";
    }
    
    // 2. Check Bank Change
    // Get current bank_id (never changes after auto-match)
    $bankStmt = $db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
    $bankStmt->execute([$guaranteeId]);
    $currentBankId = $bankStmt->fetchColumn() ?: null;
    
    // Fetch new bank name (which is the current bank name, as it's not being changed here)
    $bnkStmt = $db->prepare('SELECT arabic_name FROM banks WHERE id = ?');
    $bnkStmt->execute([$currentBankId]);
    $newBank = $bnkStmt->fetchColumn();
    
    if (trim($oldBank) !== trim($newBank)) {
        $changes[] = "تغيير البنك من [{$oldBank}] إلى [{$newBank}]";
    }

    // ====================================================================
    // TIMELINE INTEGRATION - Track changes with new logic
    // ====================================================================
    
    require_once __DIR__ . '/../app/Services/TimelineRecorder.php';
    
    // 1. SNAPSHOT: Capture state BEFORE update
    $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
    
    // 2. UPDATE: Calculate status and save decision to DB
    $statusToSave = \App\Services\StatusEvaluator::evaluate($supplierId, $bankId);
    
    // UPDATE supplier only (bank remains unchanged from initial auto-match)
    $stmt = $db->prepare('
        UPDATE guarantee_decisions 
        SET supplier_id = ?, status = ?, decided_at = ?
        WHERE guarantee_id = ?
    ');
    $stmt->execute([
        $supplierId,
        $statusToSave,
        $now,
        $guaranteeId
    ]);

    // 3. RECORD: Strict Event Recording (UE-01 Decision)
    $newData = [
        'supplier_id' => $supplierId,
        'supplier_name' => $newSupplier
    ];

    \App\Services\TimelineRecorder::recordDecisionEvent($guaranteeId, $oldSnapshot, $newData, false); // isAuto = false
    
    // 4. RECORD: Status Transition Event (SE-01/SE-02) - Separate Event
    \App\Services\TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $statusToSave, 'data_completeness_check');
    
    // --- SMART LEARNING FEEDBACK LOOP ---
    try {
        // We need the raw data to learn the mapping (Raw Name -> Chosen ID)
        $guaranteeRepo = new GuaranteeRepository($db);
        $currentGuarantee = $guaranteeRepo->find($guaranteeId);
        
        if ($currentGuarantee && isset($currentGuarantee->rawData['supplier'])) {
            $learningRepo = new \App\Repositories\SupplierLearningRepository($db);
            $supplierRepo = new \App\Repositories\SupplierRepository();
            $learningService = new \App\Services\LearningService($learningRepo, $supplierRepo);
            
            $learningService->learnFromDecision($guaranteeId, [
                'supplier_id' => $supplierId,
                'raw_supplier_name' => $currentGuarantee->rawData['supplier'],
                'source' => 'manual', // User manually saved this
                'confidence' => 100
            ]);

            // --- NEGATIVE LEARNING (Penalize Ignored Suggestions) ---
            // If the user chose a supplier DIFFERENT from what we strongly suggested
            if ($supplierId) {
                $rawName = $currentGuarantee->rawData['supplier'];
                $suggestions = $learningService->getSuggestions($rawName);
                
                foreach ($suggestions as $suggestion) {
                    // Calculate score (it might be in 'score' or we assume high for aliases)
                    $score = $suggestion['score'] ?? 0;
                    
                    // If suggestion was Strong (>80) AND was NOT chosen
                    if ($suggestion['id'] != $supplierId && $score > 80) {
                        $learningService->penalizeIgnoredSuggestion($suggestion['id'], $rawName);
                    }
                }
            }
        }
    } catch (\Throwable $e) { /* Ignore learning errors to not block save */ }
    
    // Get next record index
    $nextIndex = $currentIndex + 1;
    
    // Get all guarantees IDs
    $stmtIds = $db->query('SELECT id FROM guarantees ORDER BY imported_at DESC LIMIT 100');
    $ids = $stmtIds->fetchAll(PDO::FETCH_COLUMN);
    $total = count($ids);
    
    if ($nextIndex > $total) {
        // No more records
        echo '<div id="record-form-section" class="card" data-current-event-type="current">';
        echo '<div class="card-body" style="text-align: center; padding: 40px;">';
        echo '<h2>✅ تم الانتهاء من جميع السجلات</h2>';
        echo '<p>لا توجد سجلات أخرى للمعالجة</p>';
        echo '</div>';
        echo '</div>';
        exit;
    }
    
    // Get next record
    $guaranteeRepo = new GuaranteeRepository($db);
    $nextGuaranteeId = $ids[$nextIndex - 1];
    $guarantee = $guaranteeRepo->find($nextGuaranteeId);
    
    if (!$guarantee) {
        throw new \RuntimeException('Next record not found');
    }

    $raw = $guarantee->rawData;

    // Prepare record data
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
        'status' => 'pending'
    ];
    
    // Check for latest decision
    $stmtDec = $db->prepare('SELECT status, supplier_id, bank_id FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
    $stmtDec->execute([$nextGuaranteeId]);
    $lastDecision = $stmtDec->fetch(PDO::FETCH_ASSOC);
    
    if ($lastDecision) {
        $record['status'] = $lastDecision['status'];
        $record['bank_id'] = $lastDecision['bank_id'];
    }
    
    // Get banks for dropdown
    $banksStmt = $db->query('SELECT id, arabic_name as official_name FROM banks ORDER BY arabic_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Include partial template to render HTML for next record
    // Return data for next record as JSON
    echo json_encode([
        'success' => true,
        'finished' => false,
        'record' => $record,
        'banks' => $banks,
        'currentIndex' => $nextIndex,
        'totalRecords' => $total
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
