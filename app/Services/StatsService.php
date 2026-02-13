<?php

namespace App\Services;

use PDO;
use App\Support\Database;
use App\Support\Settings;

class StatsService {
    /**
     * Get statistics for import/dashboard
     * 
     * @param PDO $db
     * @param bool $excludeTestData
     * @return array ['total', 'ready', 'pending', 'released']
     */
    public static function getImportStats(PDO $db, bool $excludeTestData = false): array {
        // Initialize defaults
        $stats = [
            'total' => 0,
            'ready' => 0,
            'pending' => 0,
            'released' => 0
        ];

        $testFilter = $excludeTestData ? ' AND (g.is_test_data = 0 OR g.is_test_data IS NULL)' : '';

        try {
            // Count released (is_locked = 1)
            $stmt = $db->query("
                SELECT COUNT(*) 
                FROM guarantee_decisions d
                JOIN guarantees g ON d.guarantee_id = g.id
                WHERE d.is_locked = 1 {$testFilter}
            ");
            $stats['released'] = (int)$stmt->fetchColumn();

            // Count ready (status = 'ready' AND not locked)
            $stmt = $db->query("
                SELECT COUNT(*) 
                FROM guarantee_decisions d
                JOIN guarantees g ON d.guarantee_id = g.id
                WHERE d.status = 'ready' AND (d.is_locked = 0 OR d.is_locked IS NULL) {$testFilter}
            ");
            $stats['ready'] = (int)$stmt->fetchColumn();

            // Count pending (status = 'pending' AND not locked) OR (no decision yet)
            // Note: We need to count guarantees that either:
            // 1. Have a 'pending' decision and not locked
            // 2. Have NO decision record at all
            
            // First, count strictly pending decisions
            $stmt = $db->query("
                SELECT COUNT(*) 
                FROM guarantee_decisions d
                JOIN guarantees g ON d.guarantee_id = g.id
                WHERE d.status = 'pending' AND (d.is_locked = 0 OR d.is_locked IS NULL) {$testFilter}
            ");
            $pendingDecisions = (int)$stmt->fetchColumn();

            // Second, count guarantees with NO decision record
            $stmt = $db->query("
                SELECT COUNT(g.id) 
                FROM guarantees g
                LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
                WHERE d.id IS NULL " . ($excludeTestData ? " AND (g.is_test_data = 0 OR g.is_test_data IS NULL)" : "")
            );
            $noDecisions = (int)$stmt->fetchColumn();

            $stats['pending'] = $pendingDecisions + $noDecisions;
            $stats['total'] = $stats['ready'] + $stats['pending'] + $stats['released'];

        } catch (\Exception $e) {
            // Log error but check if table exists first?
            error_log("StatsService Error: " . $e->getMessage());
        }

        return $stats;
    }

    /**
     * Get operational dashboard metrics for monitoring widgets.
     * Returns safe defaults when data sources are missing.
     */
    public function getDashboardMetrics(): array
    {
        $metrics = [
            'import_success_rate' => 0.0,
            'api_error_rate' => 0.0,
            'contract_latency_ms' => 0.0,
            'active_guarantees' => 0,
            'expired_guarantees' => 0,
            'pending_guarantees' => 0,
        ];

        // Core DB (app.sqlite) for guarantee counts
        try {
            $db = Database::connect();
            $excludeTest = false;
            try {
                $excludeTest = Settings::getInstance()->isProductionMode();
            } catch (\Throwable $e) {
                $excludeTest = false;
            }

            $importStats = self::getImportStats($db, $excludeTest);
            $metrics['active_guarantees'] = (int)($importStats['released'] ?? 0);
            $metrics['pending_guarantees'] = (int)($importStats['pending'] ?? 0);

            // Expired guarantees (best-effort)
            try {
                $stmt = $db->query(
                    "SELECT COUNT(*) FROM guarantees WHERE expiry_date IS NOT NULL AND expiry_date <> '' AND date(expiry_date) < date('now')"
                );
                $metrics['expired_guarantees'] = (int)$stmt->fetchColumn();
            } catch (\Exception $e) {
                // ignore if schema differs
            }
        } catch (\Throwable $e) {
            // ignore and keep defaults
        }

        // Runtime KPIs from agent knowledge.db (best-effort)
        try {
            $kb = function_exists('base_path') ? base_path('.bgl_core/brain/knowledge.db') : (__DIR__ . '/../../.bgl_core/brain/knowledge.db');
            if (file_exists($kb)) {
                $lite = new PDO("sqlite:" . $kb);
                $lite->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

                $scalar = function(string $sql, array $params = []) use ($lite) {
                    $stmt = $lite->prepare($sql);
                    $stmt->execute($params);
                    return (float)$stmt->fetchColumn();
                };

                $writeRoutes = [
                    '/api/create-guarantee.php','/api/update_bank.php','/api/update_supplier.php',
                    '/api/import_suppliers.php','/api/import_banks.php','/api/create-bank.php','/api/create-supplier.php'
                ];
                $writePlaceholders = implode(',', array_fill(0, count($writeRoutes), '?'));

                $totalWrites = $scalar("SELECT COUNT(*) FROM runtime_events WHERE route IN ($writePlaceholders)", $writeRoutes) ?: 0;
                $errorWrites = $scalar("SELECT COUNT(*) FROM runtime_events WHERE route IN ($writePlaceholders) AND status >= 400", $writeRoutes) ?: 0;
                if ($totalWrites > 0) {
                    $metrics['api_error_rate'] = round(($errorWrites / $totalWrites) * 100, 2);
                }

                $lat = $scalar("SELECT AVG(latency_ms) FROM runtime_events WHERE route IN ($writePlaceholders) AND latency_ms IS NOT NULL", $writeRoutes);
                if ($lat > 0) {
                    $metrics['contract_latency_ms'] = round($lat, 1);
                }

                $impTotal = $scalar("SELECT COUNT(*) FROM runtime_events WHERE event_type IN ('import_suppliers','import_banks')", []);
                $impFail  = $scalar("SELECT COUNT(*) FROM runtime_events WHERE event_type IN ('import_suppliers','import_banks') AND (status >= 400 OR error IS NOT NULL)", []);
                if ($impTotal > 0) {
                    $metrics['import_success_rate'] = round((1 - ($impFail / $impTotal)) * 100, 2);
                }
            }
        } catch (\Throwable $e) {
            // ignore and keep defaults
        }

        return $metrics;
    }
}
