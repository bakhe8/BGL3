<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;
use App\Support\BankNormalizer;
use PDO;

/**
 * Smart Processing Service
 * 
 * Responsible for applying AI intelligence to new guarantees
 * regardless of their source (Excel, Manual, Paste).
 * 
 * Core functions:
 * 1. Auto-matching suppliers and banks
 * 2. Creating decisions for high-confidence matches (>90% Supplier, direct match for Banks)
 * 3. Logging auto-match events
 */
class SmartProcessingService
{
    private PDO $db;
    private LearningService $learningService;
    private ConflictDetector $conflictDetector;

    public function __construct()
    {
        $this->db = Database::connect();
        
        // Init Learning Services
        $learningRepo = new SupplierLearningRepository($this->db);
        $supplierRepo = new SupplierRepository();
        $this->learningService = new LearningService($learningRepo, $supplierRepo);
        $this->conflictDetector = new ConflictDetector();
    }

    /**
     * Process any pending guarantees automatically
     * Can be called after Excel import, Manual Entry, or Paste
     * 
     * @param int $limit Max records to process at once
     * @return array statistics ['processed' => int, 'auto_matched' => int]
     */
    public function processNewGuarantees(int $limit = 500): array
    {
        // 1. Find pending guarantees (those without decisions)
        $sql = "
            SELECT g.* 
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
            WHERE d.id IS NULL
            ORDER BY g.id DESC
            LIMIT ?
        ";
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$limit]);
        $guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stats = ['processed' => 0, 'auto_matched' => 0, 'banks_matched' => 0];

        foreach ($guarantees as $row) {
            $stats['processed']++;
            $rawData = json_decode($row['raw_data'], true);
            $guaranteeId = $row['id'];
            
            $supplierName = $rawData['supplier'] ?? '';
            $bankName = $rawData['bank'] ?? '';

            if (empty($supplierName) || empty($bankName)) {
                continue;
            }

            // =====================================================================
            // STEP 1: BANK MATCHING (ALWAYS FIRST, INDEPENDENT)
            // =====================================================================
            $bankId = null;
            $finalBankName = '';
            
            if (!empty($bankName)) {
                $normalized = BankNormalizer::normalize($bankName);
                $stmt2 = $this->db->prepare("
                    SELECT b.id, b.arabic_name
                    FROM banks b
                    JOIN bank_alternative_names a ON b.id = a.bank_id
                    WHERE a.normalized_name = ?
                    LIMIT 1
                ");
                $stmt2->execute([$normalized]);
                $bank = $stmt2->fetch(PDO::FETCH_ASSOC);
                
                if ($bank) {
                    $bankId = $bank['id'];
                    $finalBankName = $bank['arabic_name'];
                    
                    // ✅ ALWAYS log bank match event (independent of supplier)
                    $this->logBankAutoMatchEvent($guaranteeId, $rawData['bank'], $finalBankName);
                    $stats['banks_matched']++;
                    
                    // Update raw_data with matched bank name
                    $this->updateBankNameInRawData($guaranteeId, $finalBankName);
                }
            }

            // =====================================================================
            // STEP 2: SUPPLIER MATCHING (INDEPENDENT)
            // =====================================================================
            $supplierId = null;
            $supplierSuggestions = $this->learningService->getSuggestions($supplierName);
            $supplierConfidence = 0;
            $finalSupplierName = '';
            $supplierSource = null;
            
            if (!empty($supplierSuggestions)) {
                $top = $supplierSuggestions[0];
                $supplierSource = $top['source'] ?? null;
                
                // SAFE LEARNING: Block auto-approval from learned aliases
                if ($top['score'] >= 0.90 && $supplierSource !== 'alias') {
                    $supplierId = $top['id'];
                    $finalSupplierName = $top['official_name'];
                    $supplierConfidence = $top['score'];
                }
            }

            // =====================================================================
            // STEP 3: DECISION CREATION (ONLY if BOTH succeeded)
            // =====================================================================
            
            // Conflict Detection
            $candidates = [
                'supplier' => [
                    'candidates' => $supplierSuggestions,
                    'normalized' => mb_strtolower(trim($supplierName)) 
                ],
                'bank' => [
                    'candidates' => [],  // Banks use direct matching
                    'normalized' => mb_strtolower(trim($bankName))
                ]
            ];
            
            $recordContext = [
                'raw_supplier_name' => $supplierName,
                'raw_bank_name' => $bankName
            ];
            
            $conflicts = $this->conflictDetector->detect($candidates, $recordContext);

            // Auto-approve ONLY if BOTH supplier AND bank matched + No conflicts
            if ($supplierId && $bankId && empty($conflicts)) {
                $this->createAutoDecision($guaranteeId, $supplierId, $bankId);
                $this->logAutoMatchEvents($guaranteeId, $rawData, $finalSupplierName, $supplierConfidence);
                $stats['auto_matched']++;
            } else if ($supplierSource === 'alias' && !empty($supplierSuggestions)) {
                // SAFE LEARNING: Log blocked auto-approval from learned alias
                error_log(sprintf(
                    "[SAFE_LEARNING] Auto-approval blocked for guarantee #%d - supplier match from learned alias (score: %d)",
                    $guaranteeId,
                    $supplierSuggestions[0]['score'] ?? 0
                ));
            }
        }

        return $stats;
    }

    /**
     * Create 'Approved' decision ensuring status becomes 'ready'
     */
    private function createAutoDecision(int $guaranteeId, int $supplierId, int $bankId): void
    {
        $stmt = $this->db->prepare("
            INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, created_at)
            VALUES (?, ?, ?, 'approved', ?)
        ");
        $stmt->execute([$guaranteeId, $supplierId, $bankId, date('Y-m-d H:i:s')]);
    }

    /**
     * Log timeline events for transparency
     * Note: Bank matching is now automatic and deterministic, so we only log supplier events
     */
    private function logAutoMatchEvents(int $guaranteeId, array $raw, string $supName, int $supScore): void
    {
        $histStmt = $this->db->prepare("
            INSERT INTO guarantee_history (guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");

        // Single Auto-Match & Approval Event
        // This represents both: supplier match + automatic approval in one logical action
        $histStmt->execute([
            $guaranteeId,
            'auto_matched',
            'ai_match',
            json_encode([
                'field' => 'supplier', 
                'from' => $raw['supplier'],
                'to' => $supName,
                'confidence' => $supScore,
                'status' => 'approved'
            ]),
            json_encode([
                'action' => 'Auto-matched and approved',
                'supplier' => ['raw' => $raw['supplier'], 'matched' => $supName, 'score' => $supScore],
                'result' => 'Automatically approved based on high confidence match'
            ]),
            date('Y-m-d H:i:s'),
            'System AI'
        ]);
    }
    
    /**
     * Update Bank Name in raw_data
     * Updates the guarantee's raw_data to use the matched bank name
     */
    private function updateBankNameInRawData(int $guaranteeId, string $matchedBankName): void
    {
        $stmt = $this->db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
        $stmt->execute([$guaranteeId]);
        $rawData = json_decode($stmt->fetchColumn(), true);
        
        if ($rawData) {
            // Update bank name with matched name
            $rawData['bank'] = $matchedBankName;
            
            $updateStmt = $this->db->prepare("UPDATE guarantees SET raw_data = ? WHERE id = ?");
            $updateStmt->execute([json_encode($rawData), $guaranteeId]);
        }
    }
    
    /**
     * Log Bank Auto-Match Event
     * Records bank matching as a separate timeline event
     * 
     * CRITICAL: snapshot_data must contain the state BEFORE the change
     */
    private function logBankAutoMatchEvent(int $guaranteeId, string $rawBankName, string $matchedBankName): void
    {
        // Get current guarantee data to build snapshot
        $stmt = $this->db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
        $stmt->execute([$guaranteeId]);
        $rawDataJson = $stmt->fetchColumn();
        $rawData = json_decode($rawDataJson, true);
        
        // Create snapshot BEFORE bank update (state before this event)
        $snapshot = [
            'guarantee_number' => $rawData['bg_number'] ?? $rawData['guarantee_number'] ?? '',
            'contract_number' => $rawData['contract_number'] ?? $rawData['document_reference'] ?? '',
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'issue_date' => $rawData['issue_date'] ?? '',
            'type' => $rawData['type'] ?? '',
            'supplier_id' => null,  // Not matched yet
            'supplier_name' => $rawData['supplier'] ?? '',  // Keep original supplier name
            'raw_supplier_name' => $rawData['supplier'] ?? '', // 🟢 explicit raw
            'bank_id' => null,      // Not matched yet (before this event)
            'bank_name' => $rawBankName,  // BEFORE matching (original user input)
            'raw_bank_name' => $rawBankName, // 🟢 explicit raw
            'status' => 'pending'
        ];
        
        $histStmt = $this->db->prepare("
            INSERT INTO guarantee_history (guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $histStmt->execute([
            $guaranteeId,
            'auto_matched',
            'bank_match',
            json_encode($snapshot),  // Full state BEFORE change
            json_encode([
                'action' => 'Bank auto-matched',
                'changes' => [[
                    'field' => 'bank_name',
                    'old_value' => $rawBankName,
                    'new_value' => $matchedBankName,
                    'trigger' => 'auto'
                ]],
                'result' => 'Automatically matched during import'
            ]),
            date('Y-m-d H:i:s'),
            'System AI'
        ]);
    }
}
