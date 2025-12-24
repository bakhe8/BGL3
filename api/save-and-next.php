<?php
/**
 * V3 API - Save and Next (Server-Driven Partial HTML)
 * Saves current record decision and returns HTML for next record
 * Single endpoint = single decision
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

header('Content-Type: text/html; charset=utf-8');

try {
    // Get POST data
    $input = json_decode(file_get_contents('php://input'), true);
    
    $guaranteeId = $input['guarantee_id'] ?? null;
    $supplierId = $input['supplier_id'] ?? null;
    $bankId = $input['bank_id'] ?? null;
    $supplierName = trim($input['supplier_name'] ?? '');
    $bankName = trim($input['bank_name'] ?? '');
    $currentIndex = $input['current_index'] ?? 1;
    
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
            // Auto-create new supplier
            try {
                // Generate normalized name (required by schema)
                $normName = $normStub;
                
                $stmt = $db->prepare('INSERT INTO suppliers (official_name, normalized_name) VALUES (?, ?)');
                $stmt->execute([$supplierName, $normName]);
                $supplierId = $db->lastInsertId();
            } catch (\Exception $e) {
                $supplierError = $e->getMessage(); 
                // Retry fetch (Maybe race condition or created by normalized constraint?)
                $stmt = $db->prepare('SELECT id FROM suppliers WHERE normalized_name = ?');
                $stmt->execute([$normName]);
                $supplierId = $stmt->fetchColumn();
            }
        }
    }

    // SAFEGUARD (Bank): Check for ID/Name Mismatch
    // Trust User Input > Hidden ID
    if ($bankId && $bankName) {
        $chkStmt = $db->prepare('SELECT official_name FROM banks WHERE id = ?');
        $chkStmt->execute([$bankId]);
        $dbName = $chkStmt->fetchColumn();
        
        if ($dbName && mb_strtolower(trim($dbName)) !== mb_strtolower(trim($bankName))) {
            $bankId = null; // Mismatch! Reset ID to force new resolution/creation
        }
    }

    // 2. Resolve Bank ID if missing
    $bankError = '';
    if (!$bankId && $bankName) {
        $normStub = mb_strtolower($bankName);
        
        // Strategy A: Exact Match
        $stmt = $db->prepare('SELECT id FROM banks WHERE official_name = ?');
        $stmt->execute([$bankName]);
        $bankId = $stmt->fetchColumn();
        
        // Strategy B: Normalized Match
        if (!$bankId) {
             $stmt = $db->prepare('SELECT id FROM banks WHERE normalized_name = ?');
             $stmt->execute([$normStub]);
             $bankId = $stmt->fetchColumn();
        }
        
        // If STILL not found, Auto-Create Bank
        if (!$bankId) {
            try {
                // Generate short code (required by schema)
                $shortCode = strtoupper(substr(preg_replace('/[^a-zA-Z0-9]/', '', $bankName), 0, 10));
                if (strlen($shortCode) < 2) $shortCode = 'BNK_' . rand(100, 999);
                
                // Generate normalized name (required by schema)
                $normName = mb_strtolower(trim($bankName));
                
                $stmt = $db->prepare('INSERT INTO banks (official_name, short_code, normalized_name) VALUES (?, ?, ?)');
                $stmt->execute([$bankName, $shortCode, $normName]);
                $bankId = $db->lastInsertId();
            } catch (\Exception $e) {
                $bankError = $e->getMessage();
                // Retry fetch
                $stmt = $db->prepare('SELECT id FROM banks WHERE official_name = ?');
                $stmt->execute([$bankName]);
                $bankId = $stmt->fetchColumn();
            }
        }
    }

    // Now validate
    if (!$guaranteeId || !$supplierId || !$bankId) {
        $missing = [];
        if (!$guaranteeId) $missing[] = 'Guarantee ID';
        if (!$supplierId) $missing[] = "Supplier (Unknown" . ($supplierError ? ": $supplierError" : "") . ")";
        if (!$bankId) $missing[] = "Bank (Unknown" . ($bankError ? ": $bankError" : "") . ")";
        
        http_response_code(400); // Bad Request
        echo "Missing fields: " . implode(', ', $missing);
        exit;
    }
    
    // --- DETECT CHANGES ---
    $now = date('Y-m-d H:i:s');
    $changes = [];
    
    // --- DETECT CHANGES ---
    $now = date('Y-m-d H:i:s');
    $changes = [];
    
    // Determine "Old State" (Last Decision > Raw Data)
    $lastDecStmt = $db->prepare('SELECT supplier_id, bank_id FROM guarantee_decisions WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
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
    if ($prevDecision && $prevDecision['bank_id']) {
        $stmt = $db->prepare('SELECT official_name FROM banks WHERE id = ?');
        $stmt->execute([$prevDecision['bank_id']]);
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
    // Fetch new bank name
    $bnkStmt = $db->prepare('SELECT official_name FROM banks WHERE id = ?');
    $bnkStmt->execute([$bankId]);
    $newBank = $bnkStmt->fetchColumn();
    
    if (trim($oldBank) !== trim($newBank)) {
        $changes[] = "تغيير البنك من [{$oldBank}] إلى [{$newBank}]";
    }

    // Save decision (Use REPLACE to handle re-saves)
    $stmt = $db->prepare('
        REPLACE INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, created_at)
        VALUES (?, ?, ?, ?, ?)
    ');
    $stmt->execute([$guaranteeId, $supplierId, $bankId, 'approved', $now]);
    
    // LOG APPROVAL EVENT (Single Full Snapshot)
    // Enrich snapshot with exact names used at time of saving
    $snapshotData = json_encode([
        'supplier_id' => $supplierId, 
        'supplier_name' => $newSupplier, 
        'bank_id' => $bankId,
        'bank_name' => $newBank,
        'status' => 'approved'
    ]);
    
    // --- LOGGING LOGIC ---
    // We NO LONGER imply "Approved" status just by saving.
    // "Approved" status is ONLY achieved if there is a valid Supplier ID (Match).
    
    $historyAction = null;
    $historyReason = '';
    
    // 1. Detect Explicit Changes first
    if (!empty($changes)) {
        $historyAction = 'update';
        $historyReason = implode(' و ', $changes);
    }
    
    // 2. Detect Manual Match (The most important event)
    $isNewMatch = ($supplierId && (!$prevDecision || $supplierId != $prevDecision['supplier_id']));
    $nameChanged = (trim($oldSupplier) !== trim($newSupplier));

    if ($isNewMatch) {
         // Only log "Manual Match" if the user actually CHANGED the name
         // OR if they switched IDs (e.g. from ID 5 to ID 6).
         
         // If Name is Same, and we just resolved ID from Null -> System detected it.
         // User prefers NOT to see "Manual Match" if they didn't touch it.
         
         if ($nameChanged || ($prevDecision && $prevDecision['supplier_id'])) {
            $matchData = json_encode(['supplier_id' => $supplierId, 'supplier_name' => $newSupplier]);
            
            if ($historyAction === 'update') {
                $historyAction = 'manual_match'; 
                $historyReason .= ' | 🔗 تم ربط المورد يدوياً: [' . $newSupplier . ']';
            } else {
                 $historyAction = 'manual_match';
                 $historyReason = '🔗 تم ربط المورد يدوياً: [' . $newSupplier . ']';
            }
         }
    }
    
    // DEDUPLICATION
    $shouldLog = false;
    
    // Logic: 
    // If it's a NEW Snapshot -> Log it.
    // Except if it's just 'approved' generic (which we removed).
    
    if ($historyAction) {
        $checkDup = $db->prepare('SELECT snapshot_data FROM guarantee_history WHERE guarantee_id = ? ORDER BY id DESC LIMIT 1');
        $checkDup->execute([$guaranteeId]);
        $lastSnapshot = $checkDup->fetchColumn(); 
        
        if ($lastSnapshot !== $snapshotData) {
            $shouldLog = true;
        }
    }
    
    if ($shouldLog) {
         $db->prepare("
            INSERT INTO guarantee_history (guarantee_id, action, change_reason, snapshot_data, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, 'Web User')
        ")->execute([
            $guaranteeId, 
            $historyAction,
            $historyReason,
            $snapshotData, 
            $now
        ]);
    }
    
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
        echo '<div id="record-form-section" class="card">';
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
    $banksStmt = $db->query('SELECT id, official_name FROM banks ORDER BY official_name');
    $banks = $banksStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Include partial template to render HTML for next record
    echo '<div id="record-form-section" class="decision-card">';
    include __DIR__ . '/../partials/record-form.php';
    echo '</div>';
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo '<div id="record-form-section" class="card">';
    echo '<div class="card-body" style="color: red;">خطأ: ' . htmlspecialchars($e->getMessage()) . '</div>';
    echo '</div>';
}
