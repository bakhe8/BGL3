<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\GuaranteeActionRepository;
use App\Repositories\GuaranteeDecisionRepository;
use App\Repositories\GuaranteeRepository;

/**
 * ActionService (V3)
 * 
 * Handles guarantee actions (Extension, Release, Reduction)
 */
class ActionService
{
    public function __construct(
        private GuaranteeActionRepository $actions,
        private GuaranteeDecisionRepository $decisions,
        private GuaranteeRepository $guarantees,
    ) {}
    
    /**
     * Create extension (always +1 year)
     */
    public function createExtension(int $guaranteeId): array
    {
        $guarantee = $this->guarantees->find($guaranteeId);
        if (!$guarantee) {
            throw new \RuntimeException("Guarantee not found");
        }
        
        // VALIDATION: Require both supplier and bank before allowing extension
        $decision = $this->decisions->findByGuarantee($guaranteeId);
        if (!$decision || empty($decision->supplierId) || empty($decision->bankId)) {
            throw new \RuntimeException(
                'لا يمكن تنفيذ التمديد - يجب اختيار المورد والبنك أولاً'
            );
        }
        
        // Check if currently released (Source of Truth: GuaranteeDecision)
        // We do NOT check historical actions because a release might have been reverted/overridden.
        if ($decision && $decision->status === 'released') {
             throw new \RuntimeException("Cannot extend after release");
        }

        // Void any historical blocking releases in the actions table to satisfy DB Triggers
        $this->actions->voidReleases($guaranteeId);
        
        $currentExpiry = $guarantee->getExpiryDate();
        if (!$currentExpiry) {
            throw new \RuntimeException("No expiry date found");
        }
        
        // Calculate new expiry (+1 year)
        $newExpiry = date('Y-m-d', strtotime($currentExpiry . ' +1 year'));
        
        $actionId = $this->actions->create([
            'guarantee_id' => $guaranteeId,
            'action_type' => 'extension',
            'previous_expiry_date' => $currentExpiry,
            'new_expiry_date' => $newExpiry,
            'action_status' => 'pending',
        ]);
        
        return [
            'action_id' => $actionId,
            'previous_expiry_date' => $currentExpiry,
            'new_expiry_date' => $newExpiry,
        ];
    }
    
    /**
     * Issue extension (mark as issued)
     */
    public function issueExtension(int $actionId): void
    {
        $this->actions->updateStatus($actionId, 'issued');
    }
    
    /**
     * Create release
     */
    public function createRelease(int $guaranteeId, ?string $reason = null): array
    {
        $guarantee = $this->guarantees->find($guaranteeId);
        if (!$guarantee) {
            throw new \RuntimeException("Guarantee not found");
        }
        
        // VALIDATION: Require both supplier and bank before allowing release
        $decision = $this->decisions->findByGuarantee($guaranteeId);
        if (!$decision || empty($decision->supplierId) || empty($decision->bankId)) {
            throw new \RuntimeException(
                'لا يمكن تنفيذ الإفراج - يجب اختيار المورد والبنك أولاً'
            );
        }
        
        $actionId = $this->actions->create([
            'guarantee_id' => $guaranteeId,
            'action_type' => 'release',
            'release_reason' => $reason ?? 'إفراج عن ضمان',
            'action_status' => 'pending',
        ]);
        
        return [
            'action_id' => $actionId,
            'guarantee_number' => $guarantee->guaranteeNumber,
        ];
    }
    
    /**
     * Issue release (mark as issued and lock decision)
     */
    public function issueRelease(int $actionId, int $guaranteeId): void
    {
        $this->actions->updateStatus($actionId, 'issued');
        $this->decisions->lock($guaranteeId, 'released');
    }
    
    /**
     * Create reduction (amount only)
     */
    public function createReduction(int $guaranteeId, float $newAmount): array
    {
        $guarantee = $this->guarantees->find($guaranteeId);
        if (!$guarantee) {
            throw new \RuntimeException("Guarantee not found");
        }
        
        // VALIDATION: Require both supplier and bank before allowing reduction
        $decision = $this->decisions->findByGuarantee($guaranteeId);
        if (!$decision || empty($decision->supplierId) || empty($decision->bankId)) {
            throw new \RuntimeException(
                'لا يمكن تنفيذ التخفيض - يجب اختيار المورد والبنك أولاً'
            );
        }
        
        $currentAmount = (float)$guarantee->getAmount();
        
        if ($newAmount >= $currentAmount) {
            throw new \RuntimeException("Reduction amount must be less than current");
        }

        if ($newAmount <= 0) {
            throw new \RuntimeException("New amount must be positive");
        }
        
        $actionId = $this->actions->create([
            'guarantee_id' => $guaranteeId,
            'action_type' => 'reduction',
            'previous_amount' => $currentAmount,
            'new_amount' => $newAmount,
            'action_status' => 'pending',
        ]);
        
        return [
            'action_id' => $actionId,
            'previous_amount' => $currentAmount,
            'new_amount' => $newAmount,
        ];
    }
    
    /**
     * Get action history for guarantee
     */
    public function getHistory(int $guaranteeId): array
    {
        return $this->actions->getByGuarantee($guaranteeId);
    }
}
