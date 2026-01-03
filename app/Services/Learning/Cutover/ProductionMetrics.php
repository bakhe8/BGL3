<?php

namespace App\Services\Learning\Cutover;

/**
 * Production Metrics
 * 
 * Tracks real-time metrics during cutover:
 * - Request counts (Authority vs Legacy)
 * - Error rates
 * - Performance (p50, p95, p99)
 * - Fallback rate
 * 
 * Phase 4: Cutover monitoring
 */
class ProductionMetrics
{
    private string $metricsFile;

    public function __construct(?string $metricsFile = null)
    {
        $this->metricsFile = $metricsFile ?? __DIR__ . '/../../../../storage/cutover_metrics.json';
    }

    /**
     * Record successful request
     * 
     * @param string $source 'authority' or 'legacy'
     * @param float $durationMs Execution time in milliseconds
     * @param int $resultCount Number of suggestions returned
     */
    public function recordSuccess(string $source, float $durationMs, int $resultCount): void
    {
        $metrics = $this->loadMetrics();

        // Update counters
        $metrics[$source]['total_requests']++;
        $metrics[$source]['total_results'] += $resultCount;

        // Update performance data
        $metrics[$source]['response_times'][] = $durationMs;

        // Keep only last 1000 samples (for percentile calculation)
        if (count($metrics[$source]['response_times']) > 1000) {
            $metrics[$source]['response_times'] = array_slice(
                $metrics[$source]['response_times'],
                -1000
            );
        }

        $this->saveMetrics($metrics);
    }

    /**
     * Record error
     */
    public function recordError(string $source, string $errorMessage): void
    {
        $metrics = $this->loadMetrics();

        $metrics[$source]['total_errors']++;
        $metrics[$source]['recent_errors'][] = [
            'timestamp' => date('c'),
            'message' => $errorMessage,
        ];

        // Keep only last 100 errors
        if (count($metrics[$source]['recent_errors']) > 100) {
            $metrics[$source]['recent_errors'] = array_slice(
                $metrics[$source]['recent_errors'],
                -100
            );
        }

        $this->saveMetrics($metrics);
    }

    /**
     * Record Authority → Legacy fallback
     */
    public function recordFallback(): void
    {
        $metrics = $this->loadMetrics();
        $metrics['fallbacks']['total']++;
        $metrics['fallbacks']['last_at'] = date('c');
        $this->saveMetrics($metrics);
    }

    /**
     * Get current statistics
     */
    public function getStats(): array
    {
        $metrics = $this->loadMetrics();

        return [
            'authority' => $this->calculateStats($metrics['authority']),
            'legacy' => $this->calculateStats($metrics['legacy']),
            'fallbacks' => $metrics['fallbacks'],
            'comparison' => $this->compareStats($metrics['authority'], $metrics['legacy']),
        ];
    }

    /**
     * Calculate statistics for a source
     */
    private function calculateStats(array $sourceMetrics): array
    {
        $total = $sourceMetrics['total_requests'];
        $errors = $sourceMetrics['total_errors'];

        $stats = [
            'total_requests' => $total,
            'total_errors' => $errors,
            'error_rate_percent' => $total > 0 ? ($errors / $total) * 100 : 0,
            'avg_results' => $total > 0 ? $sourceMetrics['total_results'] / $total : 0,
        ];

        // Calculate percentiles
        $times = $sourceMetrics['response_times'];
        if (!empty($times)) {
            sort($times);
            $count = count($times);

            $stats['performance'] = [
                'p50' => $times[(int)($count * 0.50)] ?? 0,
                'p95' => $times[(int)($count * 0.95)] ?? 0,
                'p99' => $times[(int)($count * 0.99)] ?? 0,
                'avg' => array_sum($times) / $count,
                'min' => min($times),
                'max' => max($times),
            ];
        } else {
            $stats['performance'] = [
                'p50' => 0,
                'p95' => 0,
                'p99' => 0,
                'avg' => 0,
                'min' => 0,
                'max' => 0,
            ];
        }

        return $stats;
    }

    /**
     * Compare Authority vs Legacy
     */
    private function compareStats(array $authorityMetrics, array $legacyMetrics): array
    {
        $authStats = $this->calculateStats($authorityMetrics);
        $legacyStats = $this->calculateStats($legacyMetrics);

        return [
            'error_rate_delta' => $authStats['error_rate_percent'] - $legacyStats['error_rate_percent'],
            'performance_delta_p95' => $authStats['performance']['p95'] - $legacyStats['performance']['p95'],
            'avg_results_delta' => $authStats['avg_results'] - $legacyStats['avg_results'],
        ];
    }

    /**
     * Check if metrics meet cutover criteria
     */
    public function meetsRolloutCriteria(): array
    {
        $stats = $this->getStats();

        $criteria = [
            'error_rate' => [
                'pass' => $stats['authority']['error_rate_percent'] < 5.0,
                'value' => $stats['authority']['error_rate_percent'],
                'target' => '< 5%',
            ],
            'performance' => [
                'pass' => $stats['comparison']['performance_delta_p95'] < 100,
                'value' => $stats['comparison']['performance_delta_p95'],
                'target' => '< 100ms delta',
            ],
            'fallback_rate' => [
                'pass' => ($stats['fallbacks']['total'] / max(1, $stats['authority']['total_requests'])) < 0.01,
                'value' => ($stats['fallbacks']['total'] / max(1, $stats['authority']['total_requests'])) * 100,
                'target' => '< 1%',
            ],
        ];

        $criteria['overall_pass'] = $criteria['error_rate']['pass'] && 
                                     $criteria['performance']['pass'] && 
                                     $criteria['fallback_rate']['pass'];

        return $criteria;
    }

    /**
     * Reset metrics (for new rollout percentage)
     */
    public function reset(): void
    {
        $this->saveMetrics($this->createEmptyMetrics());
    }

    /**
     * Load metrics from file
     */
    private function loadMetrics(): array
    {
        if (!file_exists($this->metricsFile)) {
            return $this->createEmptyMetrics();
        }

        $content = file_get_contents($this->metricsFile);
        $metrics = json_decode($content, true);

        return $metrics ?: $this->createEmptyMetrics();
    }

    /**
     * Save metrics to file
     */
    private function saveMetrics(array $metrics): void
    {
        $dir = dirname($this->metricsFile);
        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        file_put_contents(
            $this->metricsFile,
            json_encode($metrics, JSON_PRETTY_PRINT)
        );
    }

    /**
     * Create empty metrics structure
     */
    private function createEmptyMetrics(): array
    {
        return [
            'authority' => [
                'total_requests' => 0,
                'total_errors' => 0,
                'total_results' => 0,
                'response_times' => [],
                'recent_errors' => [],
            ],
            'legacy' => [
                'total_requests' => 0,
                'total_errors' => 0,
                'total_results' => 0,
                'response_times' => [],
                'recent_errors' => [],
            ],
            'fallbacks' => [
                'total' => 0,
                'last_at' => null,
            ],
        ];
    }
}
