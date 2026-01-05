<?php

namespace App\Services\Learning\Feeders;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\Repositories\SupplierRepository;

/**
 * Fuzzy Signal Feeder
 * 
 * Provides fuzzy matching signals from official supplier names.
 * Computes similarity but does NOT apply weights or make decisions.
 * 
 * Signal Types:
 * - 'fuzzy_official_strong' (similarity >= 0.85)
 * - 'fuzzy_official_medium' (similarity >= 0.70)
 * - 'fuzzy_official_weak' (similarity >= 0.55)
 * 
 * Note: This extracts logic from SupplierCandidateService.
 * 
 * Reference: Query Pattern Audit, Query #7 (service-layer violation)
 * Reference: Service Classification Matrix (SupplierCandidateService refactor)
 */
class FuzzySignalFeeder implements SignalFeederInterface
{
    /**
     * Minimum similarity threshold to return as signal
     */
    private const MIN_SIMILARITY = 0.55;

    public function __construct(
        private SupplierRepository $supplierRepo
    ) {}

    /**
     * Get fuzzy matching signals
     * 
     * @param string $normalizedInput
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array
    {
        // Get ALL suppliers (no pre-filtering)
        $allSuppliers = $this->supplierRepo->getAllSuppliers();

        $signals = [];

        foreach ($allSuppliers as $supplier) {
            $supplierNormalized = $supplier['normalized_name'];
            
            // Skip invalid suppliers
            if (empty($supplier['id'])) {
                continue;
            }

            // Calculate similarity
            $similarity = $this->calculateSimilarity($normalizedInput, $supplierNormalized);

            // Only return if meets minimum threshold
            if ($similarity >= self::MIN_SIMILARITY) {
                // Determine signal type based on similarity strength
                $signalType = $this->determineSignalType($similarity);

                $signals[] = new SignalDTO(
                    supplier_id: $supplier['id'],
                    signal_type: $signalType,
                    raw_strength: $similarity, // Raw similarity score (NO weighting)
                    metadata: [
                        'source' => 'fuzzy_official',
                        'match_method' => 'levenshtein',
                        'similarity' => $similarity,
                        'matched_name' => $supplierNormalized,
                    ]
                );
            }
        }

        return $signals;
    }

    /**
     * Calculate similarity between two normalized strings
     * 
     * Uses Levenshtein distance normalized to 0-1 range
     * 
     * @param string $str1
     * @param string $str2
     * @return float Similarity (0.0 - 1.0)
     */
    private function calculateSimilarity(string $str1, string $str2): float
    {
        if ($str1 === $str2) {
            return 1.0; // Exact match
        }

        $maxLength = max(mb_strlen($str1), mb_strlen($str2));
        
        if ($maxLength === 0) {
            return 0.0;
        }

        $distance = levenshtein($str1, $str2);

        // Convert distance to similarity (0-1)
        $similarity = 1 - ($distance / $maxLength);

        return max(0.0, min(1.0, $similarity));
    }

    /**
     * Determine signal type based on similarity strength
     * 
     * @param float $similarity
     * @return string Signal type
     */
    private function determineSignalType(float $similarity): string
    {
        if ($similarity >= 0.85) {
            return 'fuzzy_official_strong';
        } elseif ($similarity >= 0.70) {
            return 'fuzzy_official_medium';
        } else {
            return 'fuzzy_official_weak';
        }
    }
}
