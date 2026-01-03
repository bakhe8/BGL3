<?php

namespace App\Services\Learning\DualRun;

/**
 * Comparison Result
 * 
 * Represents the outcome of comparing Legacy vs Authority suggestions.
 * Used for metrics collection and gap analysis.
 */
class ComparisonResult
{
    public function __construct(
        public string $input_raw,
        public string $input_normalized,
        public array $legacy_suggestions,
        public array $authority_suggestions,
        public float $legacy_execution_ms,
        public float $authority_execution_ms,
        public array $metrics,
        public string $timestamp
    ) {}

    /**
     * Calculate coverage: % of Legacy suppliers also found by Authority
     */
    public function getCoverage(): float
    {
        if (empty($this->legacy_suggestions)) {
            return 100.0; // No legacy suggestions to cover
        }

        $legacyIds = array_map(fn($s) => $s['supplier_id'] ?? $s['id'], $this->legacy_suggestions);
        $authorityIds = array_map(fn($s) => $s->supplier_id, $this->authority_suggestions);

        $covered = array_intersect($legacyIds, $authorityIds);
        
        return (count($covered) / count($legacyIds)) * 100;
    }

    /**
     * Identify suppliers found by Legacy but NOT by Authority (gaps)
     */
    public function getMissedSuppliers(): array
    {
        $legacyIds = array_map(fn($s) => $s['supplier_id'] ?? $s['id'], $this->legacy_suggestions);
        $authorityIds = array_map(fn($s) => $s->supplier_id, $this->authority_suggestions);

        $missed = array_diff($legacyIds, $authorityIds);
        
        return array_values($missed);
    }

    /**
     * Identify suppliers found by Authority but NOT by Legacy (new discoveries)
     */
    public function getNewDiscoveries(): array
    {
        $legacyIds = array_map(fn($s) => $s['supplier_id'] ?? $s['id'], $this->legacy_suggestions);
        $authorityIds = array_map(fn($s) => $s->supplier_id, $this->authority_suggestions);

        $new = array_diff($authorityIds, $legacyIds);
        
        return array_values($new);
    }

    /**
     * Calculate average confidence divergence for matching suppliers
     */
    public function getConfidenceDivergence(): ?float
    {
        $legacyIds = array_map(fn($s) => $s['supplier_id'] ?? $s['id'], $this->legacy_suggestions);
        $authorityIds = array_map(fn($s) => $s->supplier_id, $this->authority_suggestions);

        $common = array_intersect($legacyIds, $authorityIds);

        if (empty($common)) {
            return null; // No common suppliers
        }

        $divergences = [];

        foreach ($common as $supplierId) {
            $legacySuggestion = $this->findInLegacy($supplierId);
            $authoritySuggestion = $this->findInAuthority($supplierId);

            if ($legacySuggestion && $authoritySuggestion) {
                $legacyConf = $legacySuggestion['confidence'] ?? 0;
                $authorityConf = $authoritySuggestion->confidence;

                $divergences[] = abs($legacyConf - $authorityConf);
            }
        }

        return empty($divergences) ? null : array_sum($divergences) / count($divergences);
    }

    /**
     * Convert to array for logging
     */
    public function toArray(): array
    {
        return [
            'input_raw' => $this->input_raw,
            'input_normalized' => $this->input_normalized,
            'legacy_count' => count($this->legacy_suggestions),
            'authority_count' => count($this->authority_suggestions),
            'coverage_percent' => round($this->getCoverage(), 2),
            'missed_suppliers' => $this->getMissedSuppliers(),
            'new_discoveries' => $this->getNewDiscoveries(),
            'confidence_divergence_avg' => $this->getConfidenceDivergence(),
            'legacy_execution_ms' => round($this->legacy_execution_ms, 2),
            'authority_execution_ms' => round($this->authority_execution_ms, 2),
            'performance_delta_ms' => round($this->authority_execution_ms - $this->legacy_execution_ms, 2),
            'timestamp' => $this->timestamp,
            'metrics' => $this->metrics,
        ];
    }

    /**
     * Find suggestion in legacy results by supplier ID
     */
    private function findInLegacy(int $supplierId): ?array
    {
        foreach ($this->legacy_suggestions as $suggestion) {
            if (($suggestion['supplier_id'] ?? $suggestion['id']) === $supplierId) {
                return $suggestion;
            }
        }
        return null;
    }

    /**
     * Find suggestion in authority results by supplier ID
     */
    private function findInAuthority(int $supplierId): mixed
    {
        foreach ($this->authority_suggestions as $suggestion) {
            if ($suggestion->supplier_id === $supplierId) {
                return $suggestion;
            }
        }
        return null;
    }
}
