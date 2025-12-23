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
}
