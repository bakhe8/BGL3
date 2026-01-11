<?php

namespace App\Services\Learning\Feeders;

use App\Contracts\SignalFeederInterface;
use App\DTO\SignalDTO;
use App\Repositories\GuaranteeDecisionRepository;

/**
 * Historical Signal Feeder
 * 
 * Provides signals from historical guarantee decisions.
 * Counts how often each supplier was selected for similar inputs.
 * 
 * Signal Types:
 * - 'historical_frequent' (selected 5+ times)
 * - 'historical_occasional' (selected 1-4 times)
 * 
 * Note: Currently uses fragile JSON search (Query Pattern Audit #3).
 * Phase 6: Will use structured query after schema improvement.
 * 
 * Reference: Query Pattern Audit, Query #3 (fragile JSON query)
 */
class HistoricalSignalFeeder implements SignalFeederInterface
{
    public function __construct(
        private GuaranteeDecisionRepository $decisionRepo
    ) {}

    /**
     * Get historical selection signals
     * 
     * @param string $normalizedInput
     * @return array<SignalDTO>
     */
    public function getSignals(string $normalizedInput): array
    {
        // Get historical selections for this input
        // Note: Uses fragile LIKE query (Phase 1 limitation)
        // TODO Phase 6: Update to use structured normalized_input column
        $historicalSelections = $this->decisionRepo->getHistoricalSelections($normalizedInput);

        $signals = [];

        foreach ($historicalSelections as $item) {
            $supplierId = $item['supplier_id'];
            
            // Skip invalid or null supplier IDs (e.g. Bank-only matches)
            if (empty($supplierId)) {
                continue;
            }

            $selectionCount = $item['count'];

            $signalType = $this->determineSignalType($selectionCount);
            $strength = $this->calculateHistoricalStrength($selectionCount);

            $signals[] = new SignalDTO(
                supplier_id: $supplierId,
                signal_type: $signalType,
                raw_strength: $strength,
                metadata: [
                    'source' => 'historical',
                    'selection_count' => $selectionCount,
                    'data_source' => 'guarantee_decisions',
                ]
            );
        }

        return $signals;
    }

    /**
     * Determine signal type based on selection frequency
     * 
     * @param int $count
     * @return string Signal type
     */
    private function determineSignalType(int $count): string
    {
        if ($count >= 5) {
            return 'historical_frequent';
        } else {
            return 'historical_occasional';
        }
    }

    /**
     * Calculate historical strength based on frequency
     * 
     * @param int $count Selection count
     * @return float Strength (0.0 - 1.0)
     */
    private function calculateHistoricalStrength(int $count): float
    {
        // Logarithmic scale (diminishing returns)
        // 1 selection = 0.3
        // 5 selections = 0.6
        // 10 selections = 0.7
        // 20+ selections = 0.8+
        
        if ($count === 0) {
            return 0.0;
        }

        $strength = 0.3 + (0.5 * log($count + 1) / log(20));

        return min(1.0, $strength);
    }
}
