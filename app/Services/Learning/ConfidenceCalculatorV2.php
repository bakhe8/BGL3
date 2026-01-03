<?php

namespace App\Services\Learning;

use App\DTO\SignalDTO;

/**
 * Confidence Calculator V2
 * 
 * Implements UNIFIED confidence formula from Charter Part 2, Section 4.
 * This is the SINGLE source of truth for confidence calculation.
 * 
 * Formula:
 * - Base score determined by primary signal type (85-100 for strong signals)
 * - Boosts from confirmations (+5/+10/+15 based on count)
 * - Penalties from rejections (-10 per rejection)
 * - Clamped to 0-100 range
 * 
 * Reference: Charter Part 2, Section 4 (Unified Scoring Semantics)
 * Reference: Authority Intent Declaration, Section 1.2 (Meaning of Confidence)
 */
class ConfidenceCalculatorV2
{
    private \App\Support\Settings $settings;

    public function __construct(?\App\Support\Settings $settings = null)
    {
        $this->settings = $settings ?? new \App\Support\Settings();
    }

    /**
     * Base scores by signal type (Charter-defined)
     */
    private const BASE_SCORES = [
        'alias_exact' => 100,
        'entity_anchor_unique' => 90,
        'entity_anchor_generic' => 75,
        'fuzzy_official_strong' => 85,
        'fuzzy_official_medium' => 70,
        'fuzzy_official_weak' => 55,
        'historical_frequent' => 60,
        'historical_occasional' => 45,
    ];

    /**
     * Minimum confidence threshold for display
     * @deprecated Use Settings::MATCH_WEAK_THRESHOLD instead
     */
    private const MIN_DISPLAY_THRESHOLD = 40;

    /**
     * Calculate confidence from aggregated signals
     * 
     * @param array<SignalDTO> $signals All signals for this supplier
     * @param int $confirmationCount Number of confirmations (from learning_confirmations)
     * @param int $rejectionCount Number of rejections
     * @return int Confidence score (0-100)
     */
    public function calculate(array $signals, int $confirmationCount = 0, int $rejectionCount = 0): int
    {
        if (empty($signals)) {
            return 0;
        }

        // 1. Identify primary signal (highest base score)
        $primarySignal = $this->identifyPrimarySignal($signals);

        // 2. Get base score
        $baseScore = $this->getBaseScore($primarySignal->signal_type, $primarySignal->raw_strength);

        // 3. Calculate confirmation boost
        $confirmBoost = $this->calculateConfirmationBoost($confirmationCount);

        // 4. Calculate rejection penalty
        $rejectionPenalty = $this->calculateRejectionPenalty($rejectionCount);

        // 5. Apply signal strength modifier (for fuzzy signals)
        $strengthModifier = $this->calculateStrengthModifier($primarySignal);

        // 6. Compute final confidence
        $confidence = $baseScore + $confirmBoost - $rejectionPenalty + $strengthModifier;

        // 7. Clamp to valid range
        return max(0, min(100, $confidence));
    }

    /**
     * Assign level based on confidence (Charter Part 2, Section 4.3)
     * 
     * @param int $confidence Confidence score (0-100)
     * @return string Level ('B', 'C', or 'D')
     */
    public function assignLevel(int $confidence): string
    {
        // Get thresholds from settings (convert 0.xx to 0-100)
        $reviewThreshold = $this->settings->get('MATCH_REVIEW_THRESHOLD', 0.70) * 100;
        
        // Level A is handled by logic > MATCH_AUTO_THRESHOLD outside (SmartProcessingService)
        
        // Level B: High confidence (Above review threshold + 15 buffer usually, but let's stick to Charter/Settings hybrid)
        // If settings says Review is 70, then > 85 is "Safe High" (B)
        // This keeps B reserved for really good matches
        if ($confidence >= 85) {
            return 'B'; // High confidence
        } elseif ($confidence >= $reviewThreshold) {
            return 'C'; // Medium confidence (Above review threshold)
        } else {
            return 'D'; // Low confidence (Below review threshold)
        }
    }

    /**
     * Check if confidence meets minimum display threshold
     */
    public function meetsDisplayThreshold(int $confidence): bool
    {
        // Use MATCH_REVIEW_THRESHOLD as the cutoff for display logic if strict
        // But traditionally we might show "Low confidence" items
        // Let's use MATCH_WEAK_THRESHOLD if available, or strict floor
        // For now, let's allow showing anything above 40 (hard floor) to avoid empty lists
        // regardless of settings, because user might want to see "Low Confidence" options
        
        return $confidence >= 40; 
    }

    /**
     * Identify the primary signal (highest base score)
     * 
     * @param array<SignalDTO> $signals
     * @return SignalDTO
     */
    private function identifyPrimarySignal(array $signals): SignalDTO
    {
        $primarySignal = null;
        $highestBaseScore = -1;

        foreach ($signals as $signal) {
            $baseScore = self::BASE_SCORES[$signal->signal_type] ?? 0;
            if ($baseScore > $highestBaseScore) {
                $highestBaseScore = $baseScore;
                $primarySignal = $signal;
            }
        }

        return $primarySignal ?? $signals[0];
    }

    /**
     * Get base score for signal type
     */
    private function getBaseScore(string $signalType, float $rawStrength): int
    {
        return self::BASE_SCORES[$signalType] ?? 40; // Default for unknown types
    }

    /**
     * Calculate confirmation boost (Charter Part 2, Section 4.2)
     * 
     * Formula:
     * - 1-2 confirmations: +5
     * - 3-5 confirmations: +10
     * - 6+ confirmations: +15
     */
    private function calculateConfirmationBoost(int $count): int
    {
        if ($count === 0) {
            return 0;
        } elseif ($count <= 2) {
            return 5;
        } elseif ($count <= 5) {
            return 10;
        } else {
            return 15;
        }
    }

    /**
     * Calculate rejection penalty (Charter Part 2, Section 4.2)
     * 
     * Formula: -10 per rejection
     */
    private function calculateRejectionPenalty(int $count): int
    {
        return $count * 10;
    }

    /**
     * Calculate strength modifier for fuzzy signals
     * 
     * For signals with raw_strength < 1.0, apply proportional adjustment
     */
    private function calculateStrengthModifier(SignalDTO $signal): int
    {
        // Only apply to fuzzy signals
        if (!str_starts_with($signal->signal_type, 'fuzzy_')) {
            return 0;
        }

        // Strength modifier: -10 to +10 based on raw_strength
        // 1.0 = +5, 0.9 = 0, 0.8 = -5, etc.
        return (int) (($signal->raw_strength - 0.9) * 50);
    }
}
