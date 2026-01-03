<?php

namespace App\Services\Learning\Cutover;

use App\Services\Learning\UnifiedLearningAuthority;

/**
 * Production Router
 * 
 * Routes suggestion requests to either Legacy or Authority
 * based on CutoverManager decision.
 * 
 * Phase 4: Safe production switching
 * 
 * Usage in controller:
 * ```php
 * $router = new ProductionRouter($authority, $legacyService, $cutoverManager);
 * $suggestions = $router->getSuggestions($input);
 * ```
 */
class ProductionRouter
{
    public function __construct(
        private UnifiedLearningAuthority $authority,
        private $legacyService, // Can be any legacy service
        private CutoverManager $cutoverManager,
        private ?ProductionMetrics $metrics = null
    ) {
        $this->metrics = $metrics ?? new ProductionMetrics();
    }

    /**
     * Get suggestions (routed to Authority or Legacy)
     * 
     * @param string $rawInput User's raw input
     * @return array Suggestions
     */
    public function getSuggestions(string $rawInput): array
    {
        $useAuthority = $this->cutoverManager->shouldUseAuthority($rawInput);

        $start = microtime(true);

        try {
            if ($useAuthority) {
                // Route to Authority
                $suggestions = $this->getFromAuthority($rawInput);
                $source = 'authority';
            } else {
                // Route to Legacy
                $suggestions = $this->getFromLegacy($rawInput);
                $source = 'legacy';
            }

            $duration = (microtime(true) - $start) * 1000;

            // Record success metrics
            $this->metrics->recordSuccess($source, $duration, count($suggestions));

            return $suggestions;

        } catch (\Exception $e) {
            $duration = (microtime(true) - $start) * 1000;

            // Record error
            $this->metrics->recordError($source ?? 'unknown', $e->getMessage());

            // Automatic fallback if Authority fails
            if ($useAuthority) {
                return $this->fallbackToLegacy($rawInput, $e);
            }

            // Legacy failed - rethrow (production error)
            throw $e;
        }
    }

    /**
     * Get suggestions from Authority
     */
    private function getFromAuthority(string $rawInput): array
    {
        $suggestions = $this->authority->getSuggestions($rawInput);

        // Convert SuggestionDTO[] to array (for consistency with Legacy)
        return array_map(fn($dto) => $dto->toArray(), $suggestions);
    }

    /**
     * Get suggestions from Legacy
     */
    private function getFromLegacy(string $rawInput): array
    {
        // Legacy service method name may vary
        if (method_exists($this->legacyService, 'getSuggestions')) {
            return $this->legacyService->getSuggestions($rawInput);
        } elseif (method_exists($this->legacyService, 'find')) {
            return $this->legacyService->find($rawInput);
        } elseif (method_exists($this->legacyService, 'supplierCandidates')) {
            $result = $this->legacyService->supplierCandidates($rawInput);
            return $result['candidates'] ?? [];
        }

        throw new \RuntimeException("Unknown legacy service method");
    }

    /**
     * Fallback to Legacy if Authority fails
     */
    private function fallbackToLegacy(string $rawInput, \Exception $authorityError): array
    {
        // Log Authority failure
        error_log("Authority failed, falling back to Legacy: " . $authorityError->getMessage());

        // Record fallback
        $this->metrics->recordFallback();

        // Return Legacy results
        return $this->getFromLegacy($rawInput);
    }

    /**
     * Get current routing statistics
     */
    public function getRoutingStats(): array
    {
        return $this->metrics->getStats();
    }
}
