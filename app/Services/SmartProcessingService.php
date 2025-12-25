<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use App\Repositories\GuaranteeRepository;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\BankLearningRepository;
use App\Repositories\SupplierRepository;
use PDO;

/**
 * Smart Processing Service
 * 
 * Responsible for applying AI intelligence to new guarantees
 * regardless of their source (Excel, Manual, Paste).
 * 
 * Core functions:
 * 1. Auto-matching suppliers and banks
 * 2. Creating decisions for high-confidence matches (>90% Supplier, >80% Bank)
 * 3. Logging auto-match events
 */
class SmartProcessingService
{
    private PDO $db;
    private LearningService $learningService;
    private BankLearningRepository $bankLearningRepo;
    private ConflictDetector $conflictDetector;

    public function __construct()
    {
        $this->db = Database::connect();
        
        // Init Learning Services
        $learningRepo = new SupplierLearningRepository($this->db);
        $supplierRepo = new SupplierRepository();
        $this->learningService = new LearningService($learningRepo, $supplierRepo);
        $this->bankLearningRepo = new BankLearningRepository($this->db);
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
            
            if (!empty($supplierSuggestions)) {
                $top = $supplierSuggestions[0];
                if ($top['score'] >= 90) { // Threshold for auto-approval
                    $supplierId = $top['id'];
                    $finalSupplierName = $top['official_name'];
                    $supplierConfidence = $top['score'];
                }
            }

            // 2. Bank Match
            $bankId = null;
            $bankSuggestions = $this->bankLearningRepo->findSuggestions($bankName, 1);
            $bankConfidence = 0;
            $finalBankName = '';

            if (!empty($bankSuggestions)) {
                $top = $bankSuggestions[0];
                if ($top['score'] >= 80) { // Threshold for auto-approval
                    $bankId = $top['id'];
                    $finalBankName = $top['official_name'];
                    $bankConfidence = $top['score'];
                }
            }

            // --- Conflict Detection (Strict Gate) ---
            // Even if scores are high, we must ensure there are no ambiguities
            $candidates = [
                'supplier' => [
                    'candidates' => $supplierSuggestions,
                    // Assume normalized logic matches TextParsingService usage
                    'normalized' => mb_strtolower(trim($supplierName)) 
                ],
                'bank' => [
                    'candidates' => $bankSuggestions,
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
                $this->logAutoMatchEvents($guaranteeId, $rawData, $finalSupplierName, $supplierConfidence, $finalBankName, $bankConfidence);
                $stats['auto_matched']++;
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
     */
    private function logAutoMatchEvents(int $guaranteeId, array $raw, string $supName, int $supScore, string $bankName, int $bankScore): void
    {
        $histStmt = $this->db->prepare("
            INSERT INTO guarantee_history (guarantee_id, action, change_reason, snapshot_data, created_at, created_by)
            VALUES (?, ?, ?, ?, NOW(), ?)
        ");

        // Supplier Event
        $histStmt->execute([
            $guaranteeId,
            'auto_matched',
            "مطابقة تلقائية للمورد: {$raw['supplier']} -> {$supName} ({$supScore}%)",
            json_encode(['field' => 'supplier', 'to' => $supName]),
            'System AI'
        ]);

        // Bank Event
        $histStmt->execute([
            $guaranteeId,
            'auto_matched',
            "مطابقة تلقائية للبنك: {$raw['bank']} -> {$bankName} ({$bankScore}%)",
            json_encode(['field' => 'bank', 'to' => $bankName]),
            'System AI'
        ]);
        
        // Final Approval Event
        $histStmt->execute([
            $guaranteeId,
            'approved',
            'تم الاعتماد آلياً بناءً على ثقة عالية',
            json_encode(['status' => 'approved']),
            'System AI'
        ]);
    }
}
