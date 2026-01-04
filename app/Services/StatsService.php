<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * StatsService
 * 
 * Provides statistics about guarantees (ready/pending/released counts)
 * 
 * @version 1.0
 */
class StatsService
{
    /**
     * Get import statistics
     * 
     * Returns counts for all guarantee statuses:
     * - total: All guarantees
     * - ready: Has supplier AND bank (not released)
     * - pending: Missing supplier OR bank (not released)
     * - released: Locked guarantees
     * 
     * @param PDO $db Database connection
     * @return array Stats: ['total' => int, 'ready' => int, 'pending' => int, 'released' => int]
     */
    public static function getImportStats(PDO $db): array
    {
        $query = $db->prepare('
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN (d.is_locked IS NULL OR d.is_locked = 0) AND d.id IS NOT NULL THEN 1 ELSE 0 END) as ready,
                SUM(CASE WHEN (d.is_locked IS NULL OR d.is_locked = 0) AND d.id IS NULL THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN d.is_locked = 1 THEN 1 ELSE 0 END) as released
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
        ');
        
        $query->execute();
        $stats = $query->fetch(PDO::FETCH_ASSOC);
        
        // Ensure integers (NULL from SUM becomes 0)
        return [
            'total' => (int)$stats['total'],
            'ready' => (int)$stats['ready'],
            'pending' => (int)$stats['pending'],
            'released' => (int)$stats['released']
        ];
    }
}
