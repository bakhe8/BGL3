<?php
declare(strict_types=1);

namespace App\Services;

use App\Models\GuaranteeDecision;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;
use App\Repositories\GuaranteeHistoryRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\BankRepository;
use PDO;

/**
 * DecisionService (V3)
 * 
 * Handles guarantee decision logic
 */
class DecisionService
{
    public function __construct(
        private GuaranteeDecisionRepository $decisions,
        private GuaranteeRepository $guarantees,
        private ?\App\Repositories\LearningRepository $learningRepo = null, // Inject Repo directly
        private ?GuaranteeHistoryRepository $history = null,
        private ?SupplierRepository $suppliers = null,
        private ?BankRepository $banks = null,
    ) {}
    
    /**
     * Save or update decision
     */
    public function save(int $guaranteeId, array $data): GuaranteeDecision
    {
        // Validate guarantee exists
        $guarantee = $this->guarantees->find($guaranteeId);
        if (!$guarantee) {
            throw new \RuntimeException("Guarantee not found: $guaranteeId");
        }
        
        // Check if locked
        $existing = $this->decisions->findByGuarantee($guaranteeId);
        if ($existing && $existing->isLocked) {
            throw new \RuntimeException("Cannot modify locked decision: {$existing->lockedReason}");
        }
        
        // Create decision object
        $decision = new GuaranteeDecision(
            id: $existing?->id,
            guaranteeId: $guaranteeId,
            status: $data['status'] ?? 'ready', // Use 'ready' as canonical term
            supplierId: $data['supplier_id'] ?? null,
            bankId: $data['bank_id'] ?? null,
            decisionSource: $data['decision_source'] ?? 'manual',
            confidenceScore: $data['confidence_score'] ?? null,
            decidedAt: date('Y-m-d H:i:s'),
            decidedBy: $data['decided_by'] ?? null,
            manualOverride: $data['manual_override'] ?? true,
            lastModifiedBy: $data['decided_by'] ?? null,
        );
        
        $saved = $this->decisions->createOrUpdate($decision);

        // Trigger Learning
        if ($this->learningRepo && isset($data['supplier_id'])) {
            // Log the manual decision
            $this->learningRepo->logDecision([
                'guarantee_id' => $guaranteeId,
                'raw_supplier_name' => $guarantee->rawData['supplier'] ?? '',
                'supplier_id' => $data['supplier_id'],
                'action' => 'confirm', 
                'confidence' => $data['confidence_score'] ?? 100,
                'decision_time_seconds' => 0
            ]);
        }

        // Snapshot Logic
        if ($this->history) {
            $supplierName = null;
            $bankName = null;
            
            if ($saved->supplierId && $this->suppliers) {
                $sup = $this->suppliers->find($saved->supplierId);
                $supplierName = $sup?->officialName;
            }
            
            if ($saved->bankId && $this->banks) {
                $bnk = $this->banks->find($saved->bankId);
                $bankName = $bnk?->officialName;
            }

            $snapshot = [
                'guarantee_number' => $guarantee->guaranteeNumber,
                'contract_number' => $guarantee->rawData['contract_number'] ?? '',
                'amount' => $guarantee->rawData['amount'] ?? 0,
                'expiry_date' => $guarantee->rawData['expiry_date'] ?? null,
                'type' => $guarantee->rawData['type'] ?? '',
                'supplier_name' => $supplierName ?? $guarantee->rawData['supplier'] ?? null,
                'bank_name' => $bankName ?? $guarantee->rawData['bank'] ?? null,
                'supplier_id' => $saved->supplierId,
                'bank_id' => $saved->bankId,
                'action_type' => 'update', // Default
            ];

            $this->history->log(
                $guaranteeId, 
                'decision_update', 
                $snapshot, 
                'Decision Saved', 
                $data['decided_by'] ?? 'system'
            );
        }

        return $saved;
    }
    
    /**
     * Lock a decision (after extension/release)
     */
    public function lock(int $guaranteeId, string $reason): void
    {
        $this->decisions->lock($guaranteeId, $reason);
    }
    
    /**
     * Check if decision can be modified
     */
    public function canModify(int $guaranteeId): array
    {
        $decision = $this->decisions->findByGuarantee($guaranteeId);
        
        if (!$decision) {
            return ['allowed' => true];
        }
        
        if ($decision->isLocked) {
            return [
                'allowed' => false,
                'reason' => $decision->lockedReason ?? 'Decision is locked'
            ];
        }
        
        return ['allowed' => true];
    }
    
    /**
     * Smart Save (Phase 11 Refactor)
     * Encapsulates logic for auto-creating suppliers and resolving name mismatches
     */
    public function smartSave(int $guaranteeId, array $input, PDO $db): array
    {
        $supplierId = $input['supplier_id'] ?? null;
        $supplierName = $input['supplier_name'] ?? '';
        $decidedBy = $input['decided_by'] ?? 'web_user';
        
        $meta = [];
        $supplierId = (int)$supplierId ?: null; // Ensure 0 becomes null
        
        // 1. Safeguard: Check for ID/Name Mismatch
        if ($supplierId && $supplierName) {
            $stmt = $db->prepare('SELECT official_name FROM suppliers WHERE id = ?');
            $stmt->execute([$supplierId]);
            $dbName = $stmt->fetchColumn();
            
            if ($dbName && mb_strtolower(trim($dbName)) !== mb_strtolower(trim($supplierName))) {
                // Name changed: Reset ID to force re-lookup/creation
                $supplierId = null;
            }
        }
        
        // 2. Auto-Create or Lookup
        if (!$supplierId && $supplierName) {
            // Check exact or normalized match
            $stmt = $db->prepare('SELECT id FROM suppliers WHERE official_name = ? OR normalized_name = ?');
            $stmt->execute([$supplierName, \App\Support\ArabicNormalizer::normalize($supplierName)]);
            $supplierId = $stmt->fetchColumn();
            
            if (!$supplierId) {
                // Use SupplierManagementService for secure creation (includes Phase 11 Alias Conflict Check)
                // Note: We access the static method directly
                try {
                    $result = \App\Services\SupplierManagementService::create($db, [
                        'official_name' => $supplierName,
                        'is_confirmed' => 1 // Auto-created from decision
                    ]);
                    $supplierId = $result['supplier_id'];
                    $input['decision_source'] = 'auto_create';
                    $meta['created_supplier_name'] = $result['official_name'];
                } catch (\Exception $e) {
                    throw new \RuntimeException("Failed to auto-create supplier: " . $e->getMessage());
                }
            }
        }
        
        // 3. Prepare Data for Save
        $saveData = [
            'supplier_id' => $supplierId,
            'bank_id' => $input['bank_id'] ?? null,
            'status' => ($supplierId && $input['bank_id']) ? 'ready' : 'pending',
            'decision_source' => $input['decision_source'] ?? 'manual',
            'decided_by' => $decidedBy,
            'confidence_score' => $input['confidence_score'] ?? 100
        ];
        
        // 4. Save
        $decision = $this->save($guaranteeId, $saveData);
        
        return [
            'decision' => $decision,
            'meta' => $meta
        ];
    }
}
