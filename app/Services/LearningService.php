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

    private function normalize(string $text): string
    {
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}

