<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * NavigationService
 * 
 * Handles navigation and pagination logic for guarantees list
 * Supports filtering by status (all, ready, pending, released)
 * 
 * @version 1.0
 */
class NavigationService
{
    /**
     * Get navigation information for a guarantee
     * 
     * @param PDO $db Database connection
     * @param int|null $currentId Current guarantee ID (null for first record)
     * @param string $statusFilter Filter: 'all', 'ready', 'pending', 'released'
     * @param string|null $searchTerm Search query if active
     * @return array Navigation data with totalRecords, currentIndex, prevId, nextId
     */
    public static function getNavigationInfo(
        PDO $db, 
        ?int $currentId, 
        string $statusFilter = 'all',
        ?string $searchTerm = null
    ): array {
        $filterConditions = self::buildFilterConditions($statusFilter, $searchTerm);
        
        // Get total count
        $totalRecords = self::getTotalCount($db, $filterConditions);
        
        // If no current ID, return defaults (unless we want to find the first ID for the search?)
        // If currentId is null but we have search results, logic usually handled by controller (index.php) finding the first ID
        if (!$currentId) {
            return [
                'totalRecords' => $totalRecords,
                'currentIndex' => 1,
                'prevId' => null,
                'nextId' => null
            ];
        }
        
        // Get current position
        $currentIndex = self::getCurrentPosition($db, $currentId, $filterConditions);
        
        // Get prev/next IDs
        $prevId = self::getPreviousId($db, $currentId, $filterConditions);
        $nextId = self::getNextId($db, $currentId, $filterConditions);
        
        return [
            'totalRecords' => $totalRecords,
            'currentIndex' => $currentIndex,
            'prevId' => $prevId,
            'nextId' => $nextId
        ];
    }
    
    /**
     * Build SQL WHERE conditions based on status filter
     */
    private static function buildFilterConditions(string $filter, ?string $searchTerm = null): string
    {
        // ✅ Search Mode: Overrides standard status filters
        if ($searchTerm) {
            $searchSafe = stripslashes($searchTerm);
            
            // Search in directly (Raw Data) AND Linked Official Names
            return " AND (
                g.guarantee_number LIKE '%$searchSafe%' OR
                g.raw_data LIKE '%$searchSafe%' OR
                s.official_name LIKE '%$searchSafe%'
            )";
        }
        
        if ($filter === 'released') {
            // Show only released
            return ' AND d.is_locked = 1';
        } else {
            // Exclude released for other filters
            $conditions = ' AND (d.is_locked IS NULL OR d.is_locked = 0)';
            
            // Apply specific status filter
            if ($filter === 'ready') {
                $conditions .= ' AND d.status = "ready"';
            } elseif ($filter === 'pending') {
                $conditions .= ' AND (d.id IS NULL OR d.status = "pending")';
            }
            // 'all' filter has no additional conditions
            
            return $conditions;
        }
    }
    
    /**
     * Get total count of guarantees matching filter
     */
    private static function getTotalCount(PDO $db, string $filterConditions): int
    {
        $query = '
            SELECT COUNT(*) FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            LEFT JOIN suppliers s ON d.supplier_id = s.id
            WHERE 1=1
        ' . $filterConditions;
        
        $stmt = $db->prepare($query);
        $stmt->execute();
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Get current position (1-indexed) of a guarantee in filtered list
     */
    private static function getCurrentPosition(PDO $db, int $currentId, string $filterConditions): int
    {
        try {
            $query = '
                SELECT COUNT(*) as position
                FROM guarantees g
                LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                WHERE g.id < ?
            ' . $filterConditions;
            
            $stmt = $db->prepare($query);
            $stmt->execute([$currentId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return ((int)($result['position'] ?? 0)) + 1;
        } catch (\Exception $e) {
            return 1; // Default to first position on error
        }
    }
    
    /**
     * Get ID of previous guarantee in filtered list
     */
    private static function getPreviousId(PDO $db, int $currentId, string $filterConditions): ?int
    {
        try {
            $query = '
                SELECT g.id FROM guarantees g
                LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                WHERE g.id < ?
            ' . $filterConditions . '
                ORDER BY g.id DESC LIMIT 1
            ';
            
            $stmt = $db->prepare($query);
            $stmt->execute([$currentId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? (int)$result['id'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Get ID of next guarantee in filtered list
     */
    private static function getNextId(PDO $db, int $currentId, string $filterConditions): ?int
    {
        try {
            $query = '
                SELECT g.id FROM guarantees g
                LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                WHERE g.id > ?
            ' . $filterConditions . '
                ORDER BY g.id ASC LIMIT 1
            ';
            
            $stmt = $db->prepare($query);
            $stmt->execute([$currentId]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $result ? (int)$result['id'] : null;
        } catch (\Exception $e) {
            return null;
        }
    }
}
