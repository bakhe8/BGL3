<?php
declare(strict_types=1);

namespace App\Models;

/**
 * GuaranteeDecision Model (V3)
 * 
 * Represents the current state/decision for a guarantee
 */
class GuaranteeDecision
{
    public function __construct(
        public ?int $id,
        public int $guaranteeId,
        public string $status = 'pending',
        public bool $isLocked = false,
        public ?string $lockedReason = null,
        public ?int $supplierId = null,
        public ?int $bankId = null,
        public string $decisionSource = 'manual',
        public ?float $confidenceScore = null,
        public ?string $decidedAt = null,
        public ?string $decidedBy = null,
        public ?string $lastModifiedAt = null,
        public ?string $lastModifiedBy = null,
        public bool $manualOverride = false,
        public ?string $createdAt = null,
        public ?string $updatedAt = null,
    ) {}
    
    /**
     * Check if decision is approved (P3: Renamed from isReady, canonical term)
     */
    public function isApproved(): bool
    {
        // Check for 'approved' status (canonical) and both supplier & bank exist
        return ($this->status === 'approved' || $this->status === 'ready') 
            && $this->supplierId !== null 
            && $this->bankId !== null;
    }
    
    /**
     * Check if can be modified
     */
    public function canModify(): bool
    {
        return !$this->isLocked;
    }
    
    /**
     * To array for API responses
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'guarantee_id' => $this->guaranteeId,
            'status' => $this->status,
            'is_locked' => $this->isLocked,
            'locked_reason' => $this->lockedReason,
            'supplier_id' => $this->supplierId,
            'bank_id' => $this->bankId,
            'decision_source' => $this->decisionSource,
            'confidence_score' => $this->confidenceScore,
            'decided_at' => $this->decidedAt,
            'manual_override' => $this->manualOverride,
        ];
    }
}
