<?php

namespace App\Services\Learning\Cutover;

/**
 * Cutover Manager
 * 
 * Manages gradual rollout of UnifiedLearningAuthority to production.
 * Provides instant rollback capability and rollout percentage control.
 * 
 * Phase 4: Production Cutover
 * 
 * Rollout Strategy:
 * - Week 1: 10% of traffic
 * - Week 2: 25% of traffic
 * - Week 3: 50% of traffic
 * - Week 4: 100% if metrics pass
 */
class CutoverManager
{
    private string $configFile;

    public function __construct(?string $configFile = null)
    {
        $this->configFile = $configFile ?? __DIR__ . '/../../../../storage/cutover_config.json';
    }

    /**
     * Determine if this request should use Authority (vs Legacy)
     * 
     * Uses deterministic hash for consistent user experience:
     * - Same input → always same system (during gradual rollout)
     * - Prevents user confusion from system switching mid-session
     * 
     * @param string $input User's raw input (for hashing)
     * @return bool True = use Authority, False = use Legacy
     */
    public function shouldUseAuthority(string $input): bool
    {
        $config = $this->loadConfig();

        // Check if cutover is active
        if (!$config['enabled']) {
            return false; // Cutover disabled, use Legacy
        }

        // Check if full rollout (100%)
        if ($config['rollout_percentage'] >= 100) {
            return true; // Everyone uses Authority
        }

        // Gradual rollout: deterministic hash-based routing
        $hash = crc32($input); // Deterministic hash
        $bucket = $hash % 100; // 0-99

        return $bucket < $config['rollout_percentage'];
    }

    /**
     * Get current rollout percentage
     */
    public function getRolloutPercentage(): int
    {
        $config = $this->loadConfig();
        return $config['rollout_percentage'];
    }

    /**
     * Set rollout percentage (0-100)
     * 
     * @param int $percentage 0-100
     */
    public function setRolloutPercentage(int $percentage): void
    {
        if ($percentage < 0 || $percentage > 100) {
            throw new \InvalidArgumentException("Percentage must be 0-100");
        }

        $config = $this->loadConfig();
        $config['rollout_percentage'] = $percentage;
        $config['last_updated'] = date('c');
        $this->saveConfig($config);
    }

    /**
     * Enable cutover
     */
    public function enable(): void
    {
        $config = $this->loadConfig();
        $config['enabled'] = true;
        $config['last_updated'] = date('c');
        $this->saveConfig($config);
    }

    /**
     * Disable cutover (instant rollback to Legacy)
     */
    public function disable(): void
    {
        $config = $this->loadConfig();
        $config['enabled'] = false;
        $config['last_updated'] = date('c');
        $this->saveConfig($config);
    }

    /**
     * Check if cutover is enabled
     */
    public function isEnabled(): bool
    {
        $config = $this->loadConfig();
        return $config['enabled'];
    }

    /**
     * Get current status summary
     */
    public function getStatus(): array
    {
        $config = $this->loadConfig();
        
        return [
            'enabled' => $config['enabled'],
            'rollout_percentage' => $config['rollout_percentage'],
            'status_text' => $this->getStatusText($config),
            'last_updated' => $config['last_updated'],
            'started_at' => $config['started_at'],
        ];
    }

    /**
     * Emergency rollback (sets percentage to 0 and disables)
     */
    public function emergencyRollback(string $reason): void
    {
        $config = $this->loadConfig();
        $config['enabled'] = false;
        $config['rollout_percentage'] = 0;
        $config['last_updated'] = date('c');
        $config['rollback_history'][] = [
            'timestamp' => date('c'),
            'reason' => $reason,
            'previous_percentage' => $config['rollout_percentage'],
        ];
        $this->saveConfig($config);

        // Log emergency rollback
        error_log("EMERGENCY ROLLBACK: {$reason}");
    }

    /**
     * Load configuration from file
     */
    private function loadConfig(): array
    {
        if (!file_exists($this->configFile)) {
            return $this->createDefaultConfig();
        }

        $content = file_get_contents($this->configFile);
        $config = json_decode($content, true);

        return $config ?: $this->createDefaultConfig();
    }

    /**
     * Save configuration to file
     */
    private function saveConfig(array $config): void
    {
        $dir = dirname($this->configFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->configFile,
            json_encode($config, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Create default configuration
     */
    private function createDefaultConfig(): array
    {
        $config = [
            'enabled' => false,
            'rollout_percentage' => 0,
            'started_at' => null,
            'last_updated' => date('c'),
            'rollback_history' => [],
        ];

        $this->saveConfig($config);
        return $config;
    }

    /**
     * Get human-readable status text
     */
    private function getStatusText(array $config): string
    {
        if (!$config['enabled']) {
            return 'Disabled (All traffic on Legacy)';
        }

        $pct = $config['rollout_percentage'];

        if ($pct === 0) {
            return 'Enabled but 0% (No traffic on Authority)';
        } elseif ($pct === 100) {
            return 'Full Rollout (100% on Authority)';
        } else {
            return "Gradual Rollout ({$pct}% on Authority)";
        }
    }
}
