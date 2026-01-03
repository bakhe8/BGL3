<?php

namespace App\Services\Learning\DualRun;

use App\Services\Learning\UnifiedLearningAuthority;
use App\Services\Learning\DualRun\ComparisonResult;
use App\Services\Learning\DualRun\ComparisonLogger;

/**
 * Shadow Executor
 * 
 * Executes Authority in shadow mode (parallel to Legacy).
 * Captures results, compares, logs, returns Legacy (zero user impact).
 * 
 * Phase 3: Core dual-run orchestration
 * 
 * Usage:
 * ```php
 * $executor = new ShadowExecutor($authority, $logger);
 * 
 * // Execute Legacy + Authority in shadow
 * $suggestions = $executor->execute(
 *     rawInput: $rawInput,
 *     legacyCallable: fn() => $legacyService->getSuggestions($rawInput)
 * );
 * 
 * // User receives Legacy results (unchanged)
 * return $suggestions;
 * ```
 */
class ShadowExecutor
{
    public function __construct(
        private UnifiedLearningAuthority $authority,
        private ComparisonLogger $logger,
        private bool $enabled = true
    ) {}

    /**
     * Execute both Legacy and Authority, compare, return Legacy
     * 
     * @param string $rawInput User's raw input
     * @param callable $legacyCallable Function that returns legacy suggestions
     * @return array Legacy suggestions (unchanged for user)
     */
    public function execute(string $rawInput, callable $legacyCallable): array
    {
        // Execute Legacy (production)
        $legacyStart = microtime(true);
        $legacySuggestions = $legacyCallable();
        $legacyDuration = (microtime(true) - $legacyStart) * 1000; // ms

        // If shadow disabled, return early
        if (!$this->enabled) {
            return $legacySuggestions;
        }

        // Execute Authority (shadow)
        try {
            $authorityStart = microtime(true);
            $authoritySuggestions = $this->authority->getSuggestions($rawInput);
            $authorityDuration = (microtime(true) - $authorityStart) * 1000; // ms

            // Compare and log
            $this->compareAndLog(
                $rawInput,
                $legacySuggestions,
                $authoritySuggestions,
                $legacyDuration,
                $authorityDuration
            );

        } catch (\Exception $e) {
            // Authority failed - log error but don't impact user
            $this->logAuthorityError($rawInput, $e);
        }

        // ALWAYS return Legacy (zero user impact)
        return $legacySuggestions;
    }

    /**
     * Compare Legacy vs Authority and log result
     */
    private function compareAndLog(
        string $rawInput,
        array $legacySuggestions,
        array $authoritySuggestions,
        float $legacyMs,
        float $authorityMs
    ): void {
        // Normalize input (for comparison)
        $normalized = $this->authority->normalizeInput($rawInput);

        // Create comparison result
        $result = new ComparisonResult(
            input_raw: $rawInput,
            input_normalized: $normalized,
            legacy_suggestions: $legacySuggestions,
            authority_suggestions: $authoritySuggestions,
            legacy_execution_ms: $legacyMs,
            authority_execution_ms: $authorityMs,
            metrics: [
                'legacy_count' => count($legacySuggestions),
                'authority_count' => count($authoritySuggestions),
            ],
            timestamp: date('c')
        );

        // Log
        $this->logger->log($result);
    }

    /**
     * Log Authority execution error
     */
    private function logAuthorityError(string $rawInput, \Exception $e): void
    {
        $errorLog = storage_path('logs/dual_run/authority_errors.log');
        
        $entry = sprintf(
            "[%s] Input: %s | Error: %s | Trace: %s\n",
            date('c'),
            $rawInput,
            $e->getMessage(),
            $e->getTraceAsString()
        );

        file_put_contents($errorLog, $entry, FILE_APPEND | LOCK_EX);
    }

    /**
     * Enable shadow execution
     */
    public function enable(): void
    {
        $this->enabled = true;
    }

    /**
     * Disable shadow execution (for testing/debugging)
     */
    public function disable(): void
    {
        $this->enabled = false;
    }

    /**
     * Check if shadow execution is enabled
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }
}
