<?php
namespace App\Services;

use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;

class LearningService
{
    private SupplierLearningRepository $learningRepo;
    private SupplierRepository $supplierRepo;

    public function __construct(
        SupplierLearningRepository $learningRepo,
        SupplierRepository $supplierRepo
    ) {
        $this->learningRepo = $learningRepo;
        $this->supplierRepo = $supplierRepo;
    }

    /**
     * Process a decision made by the user
     */
    public function learnFromDecision(int $guaranteeId, array $input): void
    {
        // Require minimal data
        if (empty($input['supplier_id']) || empty($input['raw_supplier_name'])) {
            return;
        }

        $supplierId = (int)$input['supplier_id'];
        $rawName = $input['raw_supplier_name'];
        $source = $input['source'] ?? 'manual';
        
        $supplier = $this->supplierRepo->find($supplierId);
        if (!$supplier) {
            return;
        }

        // === LEARNING POLICY ===
        // All user decisions are learned. No blocks or gates.
        // Protection against score inflation/drift is via existing caps:
        //   - USAGE_BONUS_MAX (75 points = 5 uses max effect)
        //   - usage_count floor (-5 = max penalty)
        // Philosophy: "Always learn, but with limits" not "Block learning"
        
        // 1. Learn Alias if needed
        // If the user selected a supplier that is DIFFERENT from an exact string match, record it as an alias
        // (Simplified logic: always try to learn alias if it's a manual selection)
        if ($source === 'manual') {
            $this->learningRepo->learnAlias($supplierId, $rawName);
        }

        // 2. Increment Usage - SAFE LEARNING: Only for manual decisions
        if ($source === 'manual') {
            $this->learningRepo->incrementUsage($supplierId, $rawName);
        }

        // 3. Log Decision
        $this->learningRepo->logDecision([
            'guarantee_id' => $guaranteeId,
            'raw_input' => $rawName,
            'chosen_supplier_id' => $supplierId,
            'chosen_supplier_name' => $supplier->officialName,
            'source' => $source,
            'score' => $input['confidence'] ?? 0,
            'was_top_suggestion' => $input['was_top'] ?? 0
        ]);
    }

    /**
     * Process a penalty for an ignored suggestion (Negative Learning)
     */
    public function penalizeIgnoredSuggestion(int $ignoredSupplierId, string $rawName): void
    {
        // Require minimal data
        if (empty($ignoredSupplierId) || empty($rawName)) {
            return;
        }

        // Just decrement basic usage count for this raw->supplier pair
        $this->learningRepo->decrementUsage($ignoredSupplierId, $rawName);
    }

    /**
     * Get suggestions for a raw name
     */
    public function getSuggestions(string $rawName): array
    {
        $normalized = $this->normalize($rawName);
        if (empty($normalized)) {
            return [];
        }

        return $this->learningRepo->findSuggestions($normalized);
    }

    /**
     * Normalize text for matching
     * 
     * PHASE 1: Using ArabicNormalizer for improved Unicode handling
     */
    private function normalize(string $text): string
    {
        return \App\Utils\ArabicNormalizer::normalize($text);
    }

    /**
     * Apply Targeted Negative Learning Penalty
     * 
     * This is Phase 2 of the Explainable Trust Gate architecture.
     * When we override Trust Gate due to a high-confidence match,
     * we penalize the specific conflicting alias that was blocking trust.
     * 
     * CRITICAL SAFEGUARDS:
     * - Only penalizes if culprit exists
     * - Protects import_official sources from penalty
     * - Logs all penalties for audit trail
     * 
     * @param array $culprit The blocking alias identified by evaluateTrust
     */
    public function applyTargetedPenalty(array $culprit): void
    {
        // Safeguard: Require valid culprit data
        if (empty($culprit) || empty($culprit['supplier_id']) || empty($culprit['alternative_name'])) {
            error_log('[TARGETED_PENALTY] Invalid culprit data, skipping penalty');
            return;
        }

        // Safeguard: Protect import_official sources from penalty
        // These are historical truths that should not be degraded
        if (($culprit['source'] ?? '') === 'import_official') {
            error_log(sprintf(
                "[TARGETED_PENALTY] Skipping penalty for import_official alias: '%s'",
                $culprit['alternative_name']
            ));
            return;
        }

        // Apply the penalty
        $this->learningRepo->decrementUsage(
            $culprit['supplier_id'],
            $culprit['alternative_name']
        );

        // Audit log
        error_log(sprintf(
            "[TARGETED_PENALTY] Penalized alias '%s' (source: %s, usage_count: %d) due to Trust Override",
            $culprit['alternative_name'],
            $culprit['source'] ?? 'unknown',
            $culprit['usage_count'] ?? 0
        ));
    }
}

