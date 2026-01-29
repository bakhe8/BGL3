<?php

namespace App\Services;

use PDO;

class StatsService {
    /**
     * Get statistics for import/dashboard
     * 
     * @param PDO $db
     * @return array ['total', 'ready', 'pending', 'released']
     */
    public static function getImportStats(PDO $db): array {
        // Initialize defaults
        $stats = [
            'total' => 0,
            'ready' => 0,
            'pending' => 0,
            'released' => 0
        ];

        try {
            // Count released (is_locked = 1)
            $stmt = $db->query("
                SELECT COUNT(*) 
                FROM guarantee_decisions 
                WHERE is_locked = 1
            ");
            $stats['released'] = (int)$stmt->fetchColumn();

            // Count ready (status = 'ready' AND not locked)
            $stmt = $db->query("
                SELECT COUNT(*) 
                FROM guarantee_decisions 
                WHERE status = 'ready' AND (is_locked = 0 OR is_locked IS NULL)
            ");
            $stats['ready'] = (int)$stmt->fetchColumn();

            // Count pending (status = 'pending' AND not locked) OR (no decision yet)
            // Note: We need to count guarantees that either:
            // 1. Have a 'pending' decision and not locked
            // 2. Have NO decision record at all
            
            // First, count strictly pending decisions
            $stmt = $db->query("
                SELECT COUNT(*) 
                FROM guarantee_decisions 
                WHERE status = 'pending' AND (is_locked = 0 OR is_locked IS NULL)
            ");
            $pendingDecisions = (int)$stmt->fetchColumn();

            // Second, count guarantees with NO decision record
            $stmt = $db->query("
                SELECT COUNT(g.id) 
                FROM guarantees g
                LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
                WHERE d.id IS NULL
            ");
            $noDecisions = (int)$stmt->fetchColumn();

            $stats['pending'] = $pendingDecisions + $noDecisions;
            $stats['total'] = $stats['ready'] + $stats['pending'] + $stats['released'];

        } catch (\Exception $e) {
            // Log error but check if table exists first?
            error_log("StatsService Error: " . $e->getMessage());
        }

        return $stats;
    }
}
