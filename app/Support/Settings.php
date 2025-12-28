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

        // Score Weights (multipliers applied to raw scores)
        'WEIGHT_OFFICIAL' => 1.0,        // Official name match
        'WEIGHT_ALT_CONFIRMED' => 0.95,  // Confirmed alternative name
        'WEIGHT_ALT_LEARNING' => 0.75,   // Learned alternative name
        'WEIGHT_FUZZY' => 0.80,          // Fuzzy match penalty

        // Limits
        'CANDIDATES_LIMIT' => 20,        // Max suggestions shown
    ];

    public function __construct(string $path = '')
    {
        $this->path = $path ?: storage_path('settings.json');
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
