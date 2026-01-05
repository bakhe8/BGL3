<?php
/**
 * =============================================================================
 * Settings - Application Configuration Manager
 * =============================================================================
 * 
 * 📚 DOCUMENTATION: docs/matching-system-guide.md
 * 
 * This class manages application settings stored in storage/settings.json.
 * Default values are defined below and can be overridden via the Settings UI.
 * 
 * MATCHING THRESHOLDS EXPLAINED:
 * ------------------------------
 * - MATCH_AUTO_THRESHOLD (0.90): Scores >= 90% are auto-accepted
 * - MATCH_REVIEW_THRESHOLD (0.70): Scores < 70% are HIDDEN from suggestions
 * - MATCH_WEAK_THRESHOLD (0.70): Same as Review (kept for backward compat)
 * 
 * ⚠️ WARNING: Lowering MATCH_REVIEW_THRESHOLD will show irrelevant suggestions.
 *             This is NOT recommended as a solution for "no candidates" issues.
 *             See docs/matching-system-guide.md for proper solutions.
 * =============================================================================
 */
declare(strict_types=1);

namespace App\Support;

class Settings
{
    private string $path;

    /**
     * Default settings with documentation
     * 
     * @var array<string, mixed>
     */
    private array $defaults = [
        // Matching Thresholds
        'MATCH_AUTO_THRESHOLD' => Config::MATCH_AUTO_THRESHOLD,      // 0.90 - Auto-accept without review
        'MATCH_REVIEW_THRESHOLD' => Config::MATCH_REVIEW_THRESHOLD,  // 0.70 - Minimum to show in list
        'MATCH_WEAK_THRESHOLD' => 0.70,                              // Synced with Review Threshold
        'BANK_FUZZY_THRESHOLD' => 0.95,                              // Bank fuzzy match threshold
        'LEARNING_SCORE_CAP' => 0.90,                                // Max score for learning-based matches

        // Conflict Detection
        'CONFLICT_DELTA' => Config::CONFLICT_DELTA,                  // 0.1 - Score difference for conflicts

        // Base Scores (used by ConfidenceCalculatorV2)
        // These are the ACTUAL scores used in the matching system
        'BASE_SCORE_ALIAS_EXACT' => 100,              // Exact alias match
        'BASE_SCORE_ENTITY_ANCHOR_UNIQUE' => 90,      // Unique entity anchor
        'BASE_SCORE_ENTITY_ANCHOR_GENERIC' => 75,     // Generic entity anchor
        'BASE_SCORE_FUZZY_OFFICIAL_STRONG' => 85,     // Strong fuzzy match (>= 0.95)
        'BASE_SCORE_FUZZY_OFFICIAL_MEDIUM' => 70,     // Medium fuzzy match (0.85-0.94)
        'BASE_SCORE_FUZZY_OFFICIAL_WEAK' => 55,       // Weak fuzzy match (0.75-0.84)
        'BASE_SCORE_HISTORICAL_FREQUENT' => 60,       // Frequently used historical pattern
        'BASE_SCORE_HISTORICAL_OCCASIONAL' => 45,     // Occasionally used historical pattern

        // Learning & Penalty Settings
        'REJECTION_PENALTY_PERCENTAGE' => 25,         // Penalty per rejection (25% = 0.75 multiplier)
        'CONFIRMATION_BOOST_TIER1' => 5,              // Boost for 1-2 confirmations
        'CONFIRMATION_BOOST_TIER2' => 10,             // Boost for 3-5 confirmations
        'CONFIRMATION_BOOST_TIER3' => 15,             // Boost for 6+ confirmations

        // System Settings
        'TIMEZONE' => 'Asia/Riyadh',                  // System timezone (configurable from UI)
        'PRODUCTION_MODE' => false,                   // Enable production mode (disables debug logging)

        // Limits
        'CANDIDATES_LIMIT' => 20,        // Max suggestions shown
    ];

    public function __construct(string $path = '')
    {
        $this->path = $path ?: (__DIR__ . '/../../storage/settings.json');
    }

    public function all(): array
    {
        if (!file_exists($this->path)) {
            return $this->defaults;
        }
        $data = json_decode((string) file_get_contents($this->path), true);
        if (!is_array($data)) {
            return $this->defaults;
        }
        return array_merge($this->defaults, $data);
    }

    public function save(array $data): array
    {
        $current = $this->all();
        $merged = array_merge($current, $data);
        file_put_contents($this->path, json_encode($merged, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        return $merged;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        $all = $this->all();
        return $all[$key] ?? $default;
    }
}
