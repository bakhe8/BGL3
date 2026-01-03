<?php

namespace App\Services\Learning\DualRun;

/**
 * Comparison Logger
 * 
 * Logs dual-run comparisons to file for analysis.
 * Tracks coverage, divergence, performance, and gaps.
 * 
 * Phase 3: Shadow execution logging
 */
class ComparisonLogger
{
    private string $logDirectory;
    private string $summaryFile;

    public function __construct(?string $logDirectory = null)
    {
        $this->logDirectory = $logDirectory ?? __DIR__ . '/../../../../storage/logs/dual_run';
        $this->summaryFile = $this->logDirectory . '/summary.json';
        
        // Ensure directory exists
        if (!is_dir($this->logDirectory)) {
            mkdir($this->logDirectory, 0755, true);
        }
    }

    /**
     * Log a comparison result
     * 
     * Creates daily log file and updates running summary
     * 
     * @param ComparisonResult $result
     */
    public function log(ComparisonResult $result): void
    {
        // Write to daily log file
        $this->writeToDaily($result);

        // Update summary stats
        $this->updateSummary($result);
    }

    /**
     * Write to daily log file
     */
    private function writeToDaily(ComparisonResult $result): void
    {
        $date = date('Y-m-d');
        $dailyFile = $this->logDirectory . "/comparisons_{$date}.jsonl";

        $line = json_encode($result->toArray(), JSON_UNESCAPED_UNICODE) . "\n";

        file_put_contents($dailyFile, $line, FILE_APPEND | LOCK_EX);
    }

    /**
     * Update running summary statistics
     */
    private function updateSummary(ComparisonResult $result): void
    {
        // Load existing summary
        $summary = $this->loadSummary();

        // Update counters
        $summary['total_comparisons']++;
        $summary['last_updated'] = date('c');

        // Coverage stats
        $coverage = $result->getCoverage();
        $summary['coverage']['total'] += $coverage;
        $summary['coverage']['count']++;
        $summary['coverage']['average'] = $summary['coverage']['total'] / $summary['coverage']['count'];

        if ($coverage < 100) {
            $summary['coverage']['gaps_detected']++;
        }

        // Performance stats
        $perfDelta = $result->authority_execution_ms - $result->legacy_execution_ms;
        $summary['performance']['total_delta_ms'] += $perfDelta;
        $summary['performance']['count']++;
        $summary['performance']['average_delta_ms'] = $summary['performance']['total_delta_ms'] / $summary['performance']['count'];

        if ($perfDelta > 0) {
            $summary['performance']['slower_count']++;
        } else {
            $summary['performance']['faster_count']++;
        }

        // Divergence stats
        $divergence = $result->getConfidenceDivergence();
        if ($divergence !== null) {
            $summary['divergence']['total'] += $divergence;
            $summary['divergence']['count']++;
            $summary['divergence']['average'] = $summary['divergence']['total'] / $summary['divergence']['count'];
        }

        // Gaps
        $missed = $result->getMissedSuppliers();
        if (!empty($missed)) {
            if (!isset($summary['gaps']['missed_suppliers'])) {
                $summary['gaps']['missed_suppliers'] = [];
            }
            foreach ($missed as $supplierId) {
                if (!isset($summary['gaps']['missed_suppliers'][$supplierId])) {
                    $summary['gaps']['missed_suppliers'][$supplierId] = 0;
                }
                $summary['gaps']['missed_suppliers'][$supplierId]++;
            }
        }

        // Save summary
        file_put_contents(
            $this->summaryFile,
            json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)
        );
    }

    /**
     * Load summary from file or create new
     */
    private function loadSummary(): array
    {
        if (!file_exists($this->summaryFile)) {
            return $this->createEmptySummary();
        }

        $content = file_get_contents($this->summaryFile);
        $summary = json_decode($content, true);

        return $summary ?: $this->createEmptySummary();
    }

    /**
     * Create empty summary structure
     */
    private function createEmptySummary(): array
    {
        return [
            'started_at' => date('c'),
            'last_updated' => date('c'),
            'total_comparisons' => 0,
            'coverage' => [
                'total' => 0.0,
                'count' => 0,
                'average' => 0.0,
                'gaps_detected' => 0,
            ],
            'performance' => [
                'total_delta_ms' => 0.0,
                'count' => 0,
                'average_delta_ms' => 0.0,
                'faster_count' => 0,
                'slower_count' => 0,
            ],
            'divergence' => [
                'total' => 0.0,
                'count' => 0,
                'average' => 0.0,
            ],
            'gaps' => [
                'missed_suppliers' => [],
            ],
        ];
    }

    /**
     * Get current summary statistics
     */
    public function getSummary(): array
    {
        return $this->loadSummary();
    }

    /**
     * Generate human-readable report
     */
    public function generateReport(): string
    {
        $summary = $this->getSummary();

        $report = "=== Dual Run Summary Report ===\n\n";
        $report .= "Period: {$summary['started_at']} to {$summary['last_updated']}\n";
        $report .= "Total Comparisons: {$summary['total_comparisons']}\n\n";

        $report .= "--- Coverage ---\n";
        $report .= sprintf("Average: %.2f%%\n", $summary['coverage']['average']);
        $report .= "Gaps Detected: {$summary['coverage']['gaps_detected']}\n\n";

        $report .= "--- Performance ---\n";
        $report .= sprintf("Average Delta: %.2f ms\n", $summary['performance']['average_delta_ms']);
        $report .= "Authority Faster: {$summary['performance']['faster_count']}\n";
        $report .= "Authority Slower: {$summary['performance']['slower_count']}\n\n";

        $report .= "--- Confidence Divergence ---\n";
        $report .= sprintf("Average: %.2f points\n", $summary['divergence']['average']);
        $report .= "\n";

        if (!empty($summary['gaps']['missed_suppliers'])) {
            $report .= "--- Top Missed Suppliers ---\n";
            arsort($summary['gaps']['missed_suppliers']);
            $top = array_slice($summary['gaps']['missed_suppliers'], 0, 10, true);
            foreach ($top as $supplierId => $count) {
                $report .= "Supplier ID {$supplierId}: missed {$count} times\n";
            }
        }

        return $report;
    }
}
