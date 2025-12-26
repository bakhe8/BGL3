<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * SupplierAlternativeNameRepository
 * 
 * Manages aliases for suppliers (table: supplier_alternative_names)
 */
class SupplierAlternativeNameRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Find alternative name record by normalized name
     * @return object|null (Returns object with supplierId property as expected by MatchingService)
     */
    public function findByNormalized(string $normalizedName): ?object
    {
        $stmt = $this->db->prepare("
            SELECT * FROM supplier_alternative_names
            WHERE normalized_name = ?
            LIMIT 1
        ");
        $stmt->execute([$normalizedName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$row) {
            return null;
        }

        // Return object with camelCase properties to match MatchingService usage ($alt->supplierId)
        return (object) [
            'id' => (int) $row['id'],
            'supplierId' => (int) $row['supplier_id'],
            'alternativeName' => $row['alternative_name'],
            'normalizedName' => $row['normalized_name'],
            'source' => $row['source'] ?? 'manual',
            'usageCount' => (int) ($row['usage_count'] ?? 0)
        ];
    }
    
    /**
     * Find all alternative names matching normalized name (for exact matching)
     * Used by SupplierCandidateService line 215
     * @return array Array of associative arrays with supplier_id, raw_name, etc.
     */
    public function findAllByNormalized(string $normalizedName): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                id,
                supplier_id,
                alternative_name as raw_name,
                normalized_name,
                source,
                usage_count
            FROM supplier_alternative_names
            WHERE normalized_name = ?
        ");
        $stmt->execute([$normalizedName]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get all normalized alternative names (for fuzzy matching)
     * Used by SupplierCandidateService line 235
     * @return array Array of all alternative names with normalized data
     */
    public function allNormalized(): array
    {
        $stmt = $this->db->prepare("
            SELECT 
                id,
                supplier_id,
                alternative_name as raw_name,
                normalized_name,
                normalized_name as normalized_raw_name,
                source,
                usage_count
            FROM supplier_alternative_names
        ");
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}
