<?php
/**
 * =============================================================================
 * BankCandidateService - Bank Matching Engine
 * =============================================================================
 * 
 * VERSION: 1.0 (2025-12-19) - Extracted from CandidateService
 * 
 * PURPOSE:
 * --------
 * This service finds potential matches (candidates) for raw bank names
 * imported from Excel files. Uses fuzzy matching + learning-based approach.
 * 
 * SIMPLIFIED LOGIC (compared to suppliers):
 * -----------------------------------------
 * 1. Check bank_learning for aliases/blocks
 * 2. Match by short code (exact/fuzzy)
 * 3. Match by normalized name (exact/fuzzy)
 * 
 * @see docs/09-Supplier-System-Refactoring.md
 * =============================================================================
 */
declare(strict_types=1);

namespace App\Services;

use App\Repositories\BankRepository;
use App\Support\Normalizer;
use App\Support\Settings;
use App\Support\Config;
use App\Support\SimilarityCalculator;

class BankCandidateService
{
    private ?array $cachedBanks = null;

    public function __construct(
        private BankRepository $banks = new BankRepository(),
        private Normalizer $normalizer = new Normalizer(),
        private Settings $settings = new Settings()
    ) {
        // BankLearning removed - now using direct normalization
    }

    /**
     * مرشحي البنوك (official + fuzzy بسيط).
     *
     * @return array{normalized:string, candidates: array<int, array{source:string, bank_id:int, name:string, score:float}>}
     */
    public function bankCandidates(string $rawBank): array
    {
        $normalized = $this->normalizer->normalizeBankName($rawBank);
        $short = $this->normalizer->normalizeBankShortCode($rawBank);
        $reviewThreshold = $this->settings->get('MATCH_REVIEW_THRESHOLD', Config::MATCH_REVIEW_THRESHOLD);
        
        if ($normalized === '') {
            return ['normalized' => '', 'candidates' => []];
        }

        // Cache Banks
        if ($this->cachedBanks === null) {
            $this->cachedBanks = $this->banks->allNormalized();
        }

        $candidates = [];

        // Iterate Cache Once for both Short and Long
        foreach ($this->cachedBanks as $row) {

            // Short Code Logic
            if ($short !== '') {
                $sc = strtoupper(trim((string) ($row['short_code'] ?? '')));
                if ($sc !== '') {
                    if ($sc === $short) {
                        $candidates[] = [
                            'source' => 'short_exact',
                            'bank_id' => (int) $row['id'],
                            'name' => $row['official_name'] ?? '',
                            'score' => 1.0,
                            'score_raw' => 1.0,
                        ];
                    } else {
                        $score = SimilarityCalculator::safeLevenshteinRatio($short, $sc);
                        if ($score >= 0.9) {
                            $candidates[] = [
                                'source' => 'short_fuzzy',
                                'bank_id' => (int) $row['id'],
                                'name' => $row['official_name'] ?? '',
                                'score' => $score,
                                'score_raw' => $score,
                            ];
                        }
                    }
                }
            }

            // Full Name Logic
            $key = $row['normalized_key'] ?? '';
            if ($key !== '') {
                if ($key === $normalized) {
                    $candidates[] = [
                        'source' => 'official',
                        'bank_id' => (int) $row['id'],
                        'name' => $row['official_name'] ?? '',
                        'score' => 1.0,
                        'score_raw' => 1.0,
                    ];
                } else {
                    $score = SimilarityCalculator::safeLevenshteinRatio($normalized, $key);
                    $bankFuzzyTh = (float) $this->settings->get('BANK_FUZZY_THRESHOLD', 0.95);
                    if ($score >= $bankFuzzyTh) {
                        $candidates[] = [
                            'source' => 'fuzzy_official',
                            'bank_id' => (int) $row['id'],
                            'name' => $row['official_name'] ?? '',
                            'score' => $score,
                            'score_raw' => $score,
                        ];
                    }
                }
            }
        }

        // تصفية حسب العتبة
        $candidates = array_filter($candidates, fn($c) => ($c['score'] ?? 0) >= $reviewThreshold);

        // أفضل لكل بنك
        $best = [];
        foreach ($candidates as $c) {
            $bid = $c['bank_id'];
            if (!isset($best[$bid]) || $c['score'] > $best[$bid]['score']) {
                $best[$bid] = $c;
            }
        }

        $unique = array_values($best);
        usort($unique, fn($a, $b) => $b['score'] <=> $a['score']);

        return ['normalized' => $normalized, 'candidates' => $unique];
    }
}
