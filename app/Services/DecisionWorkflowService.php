<?php
declare(strict_types=1);

namespace App\Services;

use PDO;
use App\Support\Database;
use App\Repositories\GuaranteeRepository;

/**
 * ═════════════════════════════════════════════════════════════════════════
 * Decision Workflow Service (T2.1 Full Implementation)
 * ═════════════════════════════════════════════════════════════════════════
 */
class DecisionWorkflowService
{
    private PDO $db;
    private GuaranteeRepository $guaranteeRepo;
    
    public function __construct(?PDO $db = null)
    {
        // Explicitly resolve dependency
        if ($db === null) {
            $db = Database::connect();
        }
        
        if ($db === null) {
            throw new \RuntimeException("Failed to initialize Database connection in DecisionWorkflowService");
        }
        
        $this->db = $db;
        $this->guaranteeRepo = new GuaranteeRepository($this->db);
        
        // Ensure dependencies are loaded (using autoload preferrably, but safe requirements here)
        // We use class_exists to avoid re-requiring if autoload works
        if (!class_exists('App\Support\BankNormalizer')) {
            require_once __DIR__ . '/../Support/BankNormalizer.php';
        }
        if (!class_exists('App\Services\StatusEvaluator')) {
            require_once __DIR__ . '/StatusEvaluator.php';
        }
        if (!class_exists('App\Services\TimelineRecorder')) {
            require_once __DIR__ . '/TimelineRecorder.php';
        }
    }
    
    public function processDecision(int $guaranteeId, ?int $supplierId, string $supplierName): array
    {
        // 1. Resolve Supplier & Validate
        $supplierId = $this->resolveAndValidateSupplier($supplierId, $supplierName);
        
        // 2. Fetch Context (Guarantee + Previous Decision) in optimized way
        $context = $this->fetchDecisionContext($guaranteeId);
        
        // 3. Detect Changes
        $changes = $this->detectChanges($context, $supplierId);
        
        // 4. Capture Snapshot (BEFORE update)
        $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($guaranteeId);
        
        // 5. Calculate New Status
        $bankId = $this->resolveBankId($guaranteeId, $context['raw_data']);
        $newStatus = \App\Services\StatusEvaluator::evaluate($supplierId, $bankId);
        
        // 6. Save Decision (With Transaction)
        $this->saveToDatabase($guaranteeId, $supplierId, $bankId, $newStatus);
        
        // 7. Post-Save Actions (Events & Learning)
        $this->handlePostSaveActions($guaranteeId, $oldSnapshot, $supplierId, $context, $newStatus, $changes);
        
        return [
            'success' => true,
            'status' => $newStatus,
            'supplier_id' => $supplierId,
            'bank_id' => $bankId
        ];
    }
    
    // ─── Helpers ─────────────────────────────────────────────────────────
    
    private function resolveAndValidateSupplier(?int $supplierId, string $supplierName): int
    {
        if ($supplierId && $supplierName) {
            $stmt = $this->db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            if (!$stmt) throw new \RuntimeException("DB Prepare failed: " . implode(' ', $this->db->errorInfo()));
            $stmt->execute([$supplierId]);
            $dbName = $stmt->fetchColumn();
            
            if ($dbName && mb_strtolower(trim($dbName)) !== mb_strtolower(trim($supplierName))) {
                $supplierId = null; 
            }
        }
        
        if (!$supplierId && $supplierName) {
            $normStub = mb_strtolower(trim($supplierName));
            
            $stmt = $this->db->prepare('SELECT id FROM suppliers WHERE official_name = ?');
            $stmt->execute([$supplierName]);
            $supplierId = $stmt->fetchColumn();
            
            if (!$supplierId) {
                $stmt = $this->db->prepare('SELECT id FROM suppliers WHERE normalized_name = ?');
                $stmt->execute([$normStub]);
                $supplierId = $stmt->fetchColumn();
            }
        }
        
        if (!$supplierId) {
            throw new \RuntimeException('supplier_required', 400); 
        }
        
        return (int)$supplierId;
    }
    
    private function fetchDecisionContext(int $guaranteeId): array
    {
        $guarantee = $this->guaranteeRepo->find($guaranteeId);
        if (!$guarantee) {
            throw new \RuntimeException('Guarantee not found');
        }
        
        $stmt = $this->db->prepare('
            SELECT d.supplier_id, d.bank_id, d.status,
                   s.official_name as supplier_name,
                   b.arabic_name as bank_name
            FROM guarantee_decisions d
            LEFT JOIN suppliers s ON d.supplier_id = s.id
            LEFT JOIN banks b ON d.bank_id = b.id
            WHERE d.guarantee_id = ?
            ORDER BY d.id DESC LIMIT 1
        ');
        $stmt->execute([$guaranteeId]);
        $decision = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return [
            'guarantee' => $guarantee,
            'raw_data' => $guarantee->rawData,
            'last_decision' => $decision
        ];
    }
    
    private function resolveBankId(int $guaranteeId, array $rawData): ?int
    {
        $stmt = $this->db->prepare('SELECT bank_id FROM guarantee_decisions WHERE guarantee_id = ?');
        $stmt->execute([$guaranteeId]);
        $bankId = $stmt->fetchColumn();
        
        if ($bankId) return (int)$bankId;
        
        $rawBankName = $rawData['bank'] ?? '';
        if (!$rawBankName) return null;
        
        $stmt = $this->db->prepare('SELECT id FROM banks WHERE arabic_name = ?');
        $stmt->execute([$rawBankName]);
        $id = $stmt->fetchColumn();
        
        if ($id) return (int)$id;
        
        $norm = \App\Support\BankNormalizer::normalize($rawBankName);
        $stmt = $this->db->prepare("
            SELECT b.id FROM banks b 
            JOIN bank_alternative_names a ON b.id = a.bank_id 
            WHERE a.normalized_name = ? 
            LIMIT 1
        ");
        $stmt->execute([$norm]);
        return $stmt->fetchColumn() ?: null;
    }
    
    private function detectChanges(array $context, int $newSupplierId): array
    {
        $changes = [];
        $lastDec = $context['last_decision'];
        $raw = $context['raw_data'];
        
        $oldSupplierName = $lastDec['supplier_name'] ?? $raw['supplier'] ?? '';
        
        $stmt = $this->db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
        $stmt->execute([$newSupplierId]);
        $newSupplierName = $stmt->fetchColumn();
        
        if (trim($oldSupplierName) !== trim((string)$newSupplierName)) {
            $changes[] = "تغيير المورد من [{$oldSupplierName}] إلى [{$newSupplierName}]";
        }
        
        return $changes;
    }
    
    private function saveToDatabase(int $guaranteeId, int $supplierId, ?int $bankId, string $status): void
    {
        $now = date('Y-m-d H:i:s');
        
        $check = $this->db->prepare('SELECT id FROM guarantee_decisions WHERE guarantee_id = ?');
        $check->execute([$guaranteeId]);
        $exists = $check->fetchColumn();
        
        if ($exists) {
            $stmt = $this->db->prepare('
                UPDATE guarantee_decisions 
                SET supplier_id = ?, status = ?, decided_at = ?
                WHERE guarantee_id = ?
            ');
            $stmt->execute([$supplierId, $status, $now, $guaranteeId]);
        } else {
            $stmt = $this->db->prepare('
                INSERT INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, decided_at, created_at)
                VALUES (?, ?, ?, ?, ?, ?)
            ');
            $stmt->execute([$guaranteeId, $supplierId, $bankId, $status, $now, $now]);
        }
    }
    
    private function handlePostSaveActions($guaranteeId, $oldSnapshot, $supplierId, $context, $newStatus, $changes): void
    {
        if (!empty($changes) && ($context['last_decision']['status'] ?? '') === 'ready') {
            $this->db->exec("UPDATE guarantee_decisions SET active_action = NULL WHERE guarantee_id = $guaranteeId");
        }
        
        $stmt = $this->db->prepare("SELECT official_name FROM suppliers WHERE id = ?");
        $stmt->execute([$supplierId]);
        $newSupplierName = $stmt->fetchColumn();
        
        \App\Services\TimelineRecorder::recordDecisionEvent($guaranteeId, $oldSnapshot, [
            'supplier_id' => $supplierId,
            'supplier_name' => $newSupplierName
        ], false);
        
        \App\Services\TimelineRecorder::recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $newStatus, 'data_completeness');
        
        $this->logLearning($guaranteeId, $context['raw_data']['supplier'] ?? '', $supplierId);
    }
    
    private function logLearning($guaranteeId, $rawName, $supplierId): void
    {
        if (!$rawName) return;
        
        try {
            $repo = new \App\Repositories\LearningRepository($this->db);
            $repo->logDecision([
                'guarantee_id' => $guaranteeId,
                'raw_supplier_name' => $rawName,
                'supplier_id' => $supplierId,
                'action' => 'confirm',
                'confidence' => 100,
                'decision_time_seconds' => 0
            ]);
        } catch (\Throwable $e) {
            error_log("Learning log error: " . $e->getMessage());
        }
    }
}
