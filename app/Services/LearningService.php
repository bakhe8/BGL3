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

        // === PHASE 1: LEARNING GATE - Safety Checks ===
        // Only proceed with learning if ALL safety conditions pass
        
        // Gate 1: Session Load (Fatigue Protection)
        $sessionLoad = $this->getSessionLoad();
        if ($sessionLoad >= 20) {
            error_log(sprintf(
                "[SAFE_LEARNING] Learning blocked - session load too high (%d decisions in 30min)",
                $sessionLoad
            ));
            return; // Skip learning silently
        }
        
        // Gate 2: Circular Learning Prevention
        if (isset($input['suggested_by_alias']) && $input['suggested_by_alias']) {
            error_log("[SAFE_LEARNING] Learning blocked - decision based on learned alias (circular)");
            return;
        }
        
        // Gate 3: Official Name Conflict Check
        if ($this->hasOfficialNameConflict($supplierId, $rawName)) {
            error_log(sprintf(
                "[SAFE_LEARNING] Learning blocked - raw name '%s' conflicts with official supplier name",
                $rawName
            ));
            return;
        }
        
        // === All gates passed - proceed with learning ===
        
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
    
    /**
     * PHASE 1: Get session load (decisions in last 30 minutes)
     */
    private function getSessionLoad(): int
    {
        try {
            $stmt = $this->learningRepo->db->prepare("
                SELECT COUNT(*) as count
                FROM supplier_decisions_log
                WHERE decided_at >= datetime('now', '-30 minutes')
            ");
            $stmt->execute();
            $result = $stmt->fetch(\PDO::FETCH_ASSOC);
            return (int)($result['count'] ?? 0);
        } catch (\Exception $e) {
            // If error, assume safe (return 0)
            return 0;
        }
    }
    
    /**
     * PHASE 1: Check if raw name conflicts with official supplier name
     */
    private function hasOfficialNameConflict(int $supplierId, string $rawName): bool
    {
        try {
            // Get the official name
            $supplier = $this->supplierRepo->find($supplierId);
            if (!$supplier) {
                return false;
            }
            
            $normalizedRaw = $this->normalize($rawName);
            $normalizedOfficial = $this->normalize($supplier->officialName);
            
            // If they match, no conflict
            if ($normalizedRaw === $normalizedOfficial) {
                return false;
            }
            
            // Check if this raw name matches ANOTHER supplier's official name
            $stmt = $this->learningRepo->db->prepare("
                SELECT id FROM suppliers
                WHERE normalized_name = ? AND id != ?
                LIMIT 1
            ");
            $stmt->execute([$normalizedRaw, $supplierId]);
            $conflict = $stmt->fetch(\PDO::FETCH_ASSOC);
            
            return (bool)$conflict;
        } catch (\Exception $e) {
            // If error, assume no conflict (safe to proceed)
            return false;
        }
    }
}
