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

        $stats = ['processed' => 0, 'auto_matched' => 0];

        foreach ($guarantees as $row) {
            $stats['processed']++;
            $rawData = json_decode($row['raw_data'], true);
            $guaranteeId = $row['id'];
            
            $supplierName = $rawData['supplier'] ?? '';
            $bankName = $rawData['bank'] ?? '';

            if (empty($supplierName) || empty($bankName)) {
                continue;
            }

            // --- AI Matching ---
            
            // 1. Supplier Match
            $supplierId = null;
            $supplierSuggestions = $this->learningService->getSuggestions($supplierName);
            $supplierConfidence = 0;
            $finalSupplierName = '';
            $supplierSource = null;
            
            if (!empty($supplierSuggestions)) {
                $top = $supplierSuggestions[0];
                $supplierSource = $top['source'] ?? null;
                
                // SAFE LEARNING: Block auto-approval from learned aliases
                if ($top['score'] >= 90 && $supplierSource !== 'alias') {
                    $supplierId = $top['id'];
                    $finalSupplierName = $top['official_name'];
                    $supplierConfidence = $top['score'];
                }
            }

            // 2. Bank Match - Direct matching with BankNormalizer
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
                }
            }

            // --- Conflict Detection (Strict Gate) ---
            // Even if scores are high, we must ensure there are no ambiguities
            $candidates = [
                'supplier' => [
                    'candidates' => $supplierSuggestions,
                    'normalized' => mb_strtolower(trim($supplierName)) 
                ],
                'bank' => [
                    'candidates' => [],  // Banks now use direct matching
                    'normalized' => mb_strtolower(trim($bankName))
                ]
            ];
            
            $recordContext = [
                'raw_supplier_name' => $supplierName,
                'raw_bank_name' => $bankName
            ];
            
            $conflicts = $this->conflictDetector->detect($candidates, $recordContext);

            // --- Decision Making ---

            // If BOTH matches have High Score AND No Conflicts -> Auto Approve!
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
            VALUES (?, ?, ?, 'approved', NOW())
        ");
        $stmt->execute([$guaranteeId, $supplierId, $bankId]);
    }

    /**
     * Log timeline events for transparency
     * Note: Bank matching is now automatic and deterministic, so we only log supplier events
     */
    private function logAutoMatchEvents(int $guaranteeId, array $raw, string $supName, int $supScore): void
    {
        $histStmt = $this->db->prepare("
            INSERT INTO guarantee_history (guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, NOW(), ?)
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
            'System AI'
        ]);
    }
}
