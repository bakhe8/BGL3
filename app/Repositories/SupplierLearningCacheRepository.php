<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * SupplierLearning CacheRepository (V3)
 * 
 * Manages supplier_learning_cache table for fast suggestions
 */
class SupplierLearningCacheRepository
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
    
    /**
     * Get suggestions for normalized input
     */
    public function getSuggestions(string $normalizedInput, int $limit = 10): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                id, normalized_input, supplier_id,
                fuzzy_score, source_weight, usage_count, block_count,
                total_score, effective_score, star_rating,
                last_used_at
            FROM supplier_learning_cache
            WHERE normalized_input = ?
            AND effective_score > 0
            ORDER BY effective_score DESC
            LIMIT ?
        ");
        $stmt->execute([$normalizedInput, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Create or update cache entry
     */
    public function upsert(string $normalizedInput, int $supplierId, array $data): void
    {
        // Check if exists
        $stmt = $this->db->prepare("
            SELECT id FROM supplier_learning_cache
            WHERE normalized_input = ? AND supplier_id = ?
        ");
        $stmt->execute([$normalizedInput, $supplierId]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            // Update
            $stmt = $this->db->prepare("
                UPDATE supplier_learning_cache
                SET fuzzy_score = ?,
                    source_weight = ?,
                    usage_count = ?,
                    block_count = ?,
                    last_used_at = CURRENT_TIMESTAMP
                WHERE id = ?
            ");
            $stmt->execute([
                $data['fuzzy_score'] ?? 0.0,
                $data['source_weight'] ?? 0,
                $data['usage_count'] ?? 0,
                $data['block_count'] ?? 0,
                $existing['id']
            ]);
        } else {
            // Insert
            $stmt = $this->db->prepare("
                INSERT INTO supplier_learning_cache (
                    normalized_input, supplier_id,
                    fuzzy_score, source_weight, usage_count, block_count
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $normalizedInput,
                $supplierId,
                $data['fuzzy_score'] ?? 0.0,
                $data['source_weight'] ?? 0,
                $data['usage_count'] ?? 0,
                $data['block_count'] ?? 0
            ]);
        }
    }
    
    /**
     * Increment usage count (gradual learning)
     */
    public function incrementUsage(string $normalizedInput, int $supplierId, int $increment = 1): void
    {
        $stmt = $this->db->prepare("
            UPDATE supplier_learning_cache
            SET usage_count = usage_count + ?
            WHERE normalized_input = ? AND supplier_id = ?
        ");
        $stmt->execute([$increment, $normalizedInput, $supplierId]);
    }
    
    /**
     * Increment block count (gradual blocking)
     */
    public function incrementBlock(string $normalizedInput, int $supplierId, int $increment = 1): void
    {
        $stmt = $this->db->prepare("
            UPDATE supplier_learning_cache
            SET block_count = block_count + ?
            WHERE normalized_input = ? AND supplier_id = ?
        ");
        $stmt->execute([$increment, $normalizedInput, $supplierId]);
    }
    /**
     * Get blocked supplier IDs for normalized input
     * where block_count > 0
     */
    public function getBlockedSupplierIds(string $normalizedInput): array
    {
        $stmt = $this->db->prepare("
            SELECT supplier_id 
            FROM supplier_learning_cache 
            WHERE normalized_input = ? 
            AND block_count > 0
        ");
        $stmt->execute([$normalizedInput]);
        
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    }
}
