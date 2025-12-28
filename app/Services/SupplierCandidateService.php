<?php
/**
 * =============================================================================
 * SupplierCandidateService - Supplier Matching Engine
 * =============================================================================
 * 
 * VERSION: 1.0 (2025-12-19) - Extracted from CandidateService
 * 
 * 📚 DOCUMENTATION: docs/09-Supplier-System-Refactoring.md
 * 
 * PURPOSE:
 * --------
 * This service finds potential matches (candidates) for raw supplier names
 * imported from Excel files. Uses fuzzy matching + cache-first approach.
 * 
 * ARCHITECTURE:
 * -------------
 * 1. Check supplier_suggestions cache FIRST
 * 2. If cache miss, generate candidates and save to cache
 * 3. Blocking handled via block_count (gradual penalty)
 * 
 * KEY BUSINESS RULES:
 * -------------------
 * 1. EMPTY CANDIDATES IS VALID: If no supplier scores >= 70%
 * 2. THRESHOLDS:
 *    - MATCH_AUTO_THRESHOLD (90%): Auto-accept
 *    - MATCH_REVIEW_THRESHOLD (70%): Minimum to appear
 * 3. STAR RATINGS (UNIFIED):
 *    - >=200 points = 3 stars ⭐⭐⭐
 *    - >=120 points = 2 stars ⭐⭐
 *    - <120 points = 1 star ⭐
 * 
 * @see docs/09-Supplier-System-Refactoring.md
 * =============================================================================
 */
declare(strict_types=1);

namespace App\Services;

use App\Repositories\SupplierAlternativeNameRepository;
use App\Repositories\SupplierRepository;
use App\Repositories\SupplierLearningCacheRepository;
use App\Repositories\SupplierOverrideRepository;
use App\Support\Normalizer;
use App\Support\Settings;
use App\Support\Config;
use App\Support\SimilarityCalculator;
use App\Support\ScoringConfig;

class SupplierCandidateService
{
    private ?array $cachedSuppliers = null;

    public function __construct(
        private SupplierRepository $suppliers,
        private SupplierAlternativeNameRepository $supplierAlts,
        private ?Normalizer $normalizer = null,
        private ?SupplierOverrideRepository $overrides = null,
        private ?Settings $settings = null,
    ) {
        $this->normalizer = $normalizer ?? new Normalizer();
        $this->overrides = $overrides ?? new SupplierOverrideRepository();
        $this->settings = $settings ?? new Settings();
    }

    // ═══════════════════════════════════════════════════════════════════
    // SCORING SYSTEM
    // ═══════════════════════════════════════════════════════════════════

    private function calculateBaseScore(float $fuzzyScore, string $matchType): int
    {
        if ($matchType === 'exact') return 100;
        if ($fuzzyScore >= 0.90) return 80;
        if ($fuzzyScore >= 0.80) return 60;
        return 40;
    }

    private function calculateBonusPoints(?array $usageData): int
    {
        if (!$usageData || empty($usageData['usage_count'])) return 0;
        
        $bonus = 50;
        $count = (int)$usageData['usage_count'];
        $bonus += min(($count - 1) * 25, 150);
        
        if (isset($usageData['last_used_at'])) {
            $daysSince = (new \DateTime())->diff(new \DateTime($usageData['last_used_at']))->days;
            if ($daysSince <= 30) $bonus += 25;
        }
        
        return $bonus;
    }

    private function assignStarRating(int $totalScore): int
    {
        return ScoringConfig::getStarRating($totalScore);
    }

    /**
     * إرجاع قائمة المرشحين للاسم الخام من المصادر: official + alternative names
     *
     * @return array{normalized:string, candidates: array<int, array{source:string, supplier_id:int, name:string, score:float}>}
     */
    public function supplierCandidates(string $rawSupplier): array
    {
        $normalized = $this->normalizer->normalizeSupplierName($rawSupplier);
        
        $strongTh = (float) $this->settings->get('MATCH_AUTO_THRESHOLD', Config::MATCH_AUTO_THRESHOLD);
        $weakTh = (float) $this->settings->get('MATCH_WEAK_THRESHOLD', 0.80);
        $reviewThreshold = $this->settings->get('MATCH_REVIEW_THRESHOLD', Config::MATCH_REVIEW_THRESHOLD);
        
        if ($normalized === '') {
            return ['normalized' => '', 'candidates' => []];
        }

        $candidates = [];
        
        // Get blocked supplier IDs from cache
        $suggestionRepo = new SupplierLearningCacheRepository();
        $blockedIds = $suggestionRepo->getBlockedSupplierIds($normalized);
        $cachedSuggestions = $suggestionRepo->getSuggestions($normalized, 10);
        
        if (!empty($cachedSuggestions)) {
            foreach ($cachedSuggestions as $cached) {
                if ($cached['source'] === 'learning' || $cached['source'] === 'user_history') {
                    $candidates[] = [
                        'source' => 'learning',
                        'match_type' => 'exact',
                        'strength' => 'strong',
                        'supplier_id' => (int) $cached['supplier_id'],
                        'name' => $cached['display_name'],
                        'score' => (float) $this->settings->get('LEARNING_SCORE_CAP', 0.90), // From Settings
                        'score_raw' => (float) $cached['fuzzy_score'],
                        'is_learning' => true,
                        'usage_count' => (int) ($cached['usage_count'] ?? 0),
                        'star_rating' => (int) ($cached['star_rating'] ?? 1),
                    ];
                }
            }
        }

        // Cache ONCE
        if ($this->cachedSuppliers === null) {
            $this->cachedSuppliers = $this->suppliers->allNormalized();
        }

        // Overrides
        foreach ($this->overrides->allNormalized() as $ov) {
            $candNorm = $this->normalizer->normalizeSupplierName($ov['override_name']);
            $sim = $this->scoreComponents($normalized, $candNorm);
            $scoreRaw = $this->maxScore($sim);
            if ($scoreRaw < $reviewThreshold) {
                continue;
            }
            if (in_array((int) $ov['supplier_id'], $blockedIds, true)) {
                continue;
            }
            $candidates[] = [
                'source' => 'override',
                'match_type' => 'exact',
                'strength' => 'strong',
                'supplier_id' => $ov['supplier_id'],
                'name' => $ov['override_name'],
                'score' => $scoreRaw * (float) $this->settings->get('WEIGHT_OFFICIAL', Config::WEIGHT_OFFICIAL),
                'score_raw' => $scoreRaw,
                'is_learning' => false,
            ];
        }

        // Official suppliers (FROM CACHE)
        foreach ($this->cachedSuppliers as $supplier) {
            $candNorm = $this->normalizer->normalizeSupplierName($supplier['normalized_name'] ?? $supplier['official_name']);
            $sim = $this->scoreComponents($normalized, $candNorm);
            $scoreRaw = $this->maxScore($sim);

            $scoreWeighted = $scoreRaw * (float) $this->settings->get('WEIGHT_OFFICIAL', Config::WEIGHT_OFFICIAL);

            if ($scoreRaw < $reviewThreshold && $scoreRaw < $weakTh) {
                continue;
            }

            if (in_array((int) $supplier['id'], $blockedIds, true)) {
                continue;
            }

            $type = 'fuzzy_weak';
            $strength = 'weak';
            if ($scoreRaw >= 1.0) {
                $type = 'exact';
                $strength = 'strong';
            } elseif ($scoreRaw >= $strongTh) {
                $type = 'fuzzy_strong';
                $strength = 'strong';
                $scoreWeighted = $scoreRaw * (float) $this->settings->get('WEIGHT_FUZZY', Config::WEIGHT_FUZZY);
            } else {
                $scoreWeighted = $scoreRaw * (float) $this->settings->get('WEIGHT_FUZZY', Config::WEIGHT_FUZZY);
            }

            $candidates[] = [
                'source' => ($scoreRaw >= 1.0) ? 'official' : 'fuzzy_official',
                'match_type' => $type,
                'strength' => $strength,
                'supplier_id' => $supplier['id'],
                'name' => $supplier['official_name'],
                'score' => $scoreWeighted,
                'score_raw' => $scoreRaw,
                'is_learning' => false,
            ];
        }

        // Create a map for fast lookup
        $supplierMap = [];
        foreach ($this->cachedSuppliers as $s) {
            $supplierMap[$s['id']] = $s['official_name'];
        }

        // Alternative names (exact match)
        foreach ($this->supplierAlts->findAllByNormalized($normalized) as $alt) {
            if (in_array((int) $alt['supplier_id'], $blockedIds, true))
                continue;

            $officialName = $supplierMap[$alt['supplier_id']] ?? $alt['raw_name'];

            $candidates[] = [
                'source' => 'alternative',
                'match_type' => 'alternative',
                'strength' => 'strong',
                'supplier_id' => $alt['supplier_id'],
                'name' => $officialName,
                'matched_on' => $alt['raw_name'],
                'score' => 1.0 * (float) $this->settings->get('WEIGHT_ALT_CONFIRMED', Config::WEIGHT_ALT_CONFIRMED),
                'score_raw' => 1.0,
                'is_learning' => false,
            ];
        }

        // Fuzzy Alts
        foreach ($this->supplierAlts->allNormalized() as $alt) {
            if (in_array((int) $alt['supplier_id'], $blockedIds, true)) {
                continue;
            }
            $candNorm = $this->normalizer->normalizeSupplierName($alt['normalized_raw_name'] ?? $alt['raw_name']);
            $sim = $this->scoreComponents($normalized, $candNorm);
            $scoreRaw = $this->maxScore($sim);
            $score = $scoreRaw * (float) $this->settings->get('WEIGHT_FUZZY', Config::WEIGHT_FUZZY);
            if ($scoreRaw >= $weakTh) {
                $officialName = $supplierMap[$alt['supplier_id']] ?? $alt['raw_name'];
                $candidates[] = [
                    'source' => 'fuzzy_alternative',
                    'match_type' => $scoreRaw >= $strongTh ? 'fuzzy_strong' : 'fuzzy_weak',
                    'strength' => $scoreRaw >= $strongTh ? 'strong' : 'weak',
                    'supplier_id' => $alt['supplier_id'],
                    'name' => $officialName,
                    'matched_on' => $alt['raw_name'],
                    'score' => $score,
                    'score_raw' => $scoreRaw,
                    'is_learning' => false,
                ];
            }
        }

        // Best score per supplier_id
        $bestBySupplier = [];
        foreach ($candidates as $c) {
            $sid = $c['supplier_id'];
            if (!isset($bestBySupplier[$sid]) || $c['score'] > $bestBySupplier[$sid]['score']) {
                $bestBySupplier[$sid] = $c;
            }
        }

        $unique = array_values($bestBySupplier);
        $unique = array_filter($unique, fn($c) => ($c['score_raw'] ?? $c['score'] ?? 0) >= $weakTh);
        usort($unique, fn($a, $b) => $b['score'] <=> $a['score']);

        $limit = (int) $this->settings->get('CANDIDATES_LIMIT', 20);
        if ($limit > 0) {
            $unique = array_slice($unique, 0, $limit);
        }

        return ['normalized' => $normalized, 'candidates' => $unique];
    }

    private function scoreComponents(string $input, string $candidate): array
    {
        $exact = $input === $candidate ? 1.0 : 0.0;
        $starts = (str_starts_with($candidate, $input) || str_starts_with($input, $candidate)) ? 0.85 : 0.0;
        $contains = (str_contains($candidate, $input) || str_contains($input, $candidate)) ? 0.75 : 0.0;
        $lev = SimilarityCalculator::safeLevenshteinRatio($input, $candidate);
        $tokens = $this->tokenSimilarity($input, $candidate);
        return compact('exact', 'starts', 'contains', 'lev', 'tokens');
    }

    private function maxScore(array $sim): float
    {
        return max($sim['exact'], $sim['starts'], $sim['contains'], $sim['lev'], $sim['tokens']);
    }

    private function tokenSimilarity(string $a, string $b): float
    {
        $ta = array_filter(explode(' ', $a));
        $tb = array_filter(explode(' ', $b));
        if (!$ta || !$tb) {
            return 0.0;
        }
        $intersect = count(array_intersect($ta, $tb));
        $union = count(array_unique(array_merge($ta, $tb)));
        return $union === 0 ? 0.0 : $intersect / $union;
    }
}
