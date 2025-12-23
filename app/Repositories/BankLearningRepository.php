<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * BankLearningRepository
 * 
 * Manages learned patterns for banks
 */
class BankLearningRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Find learning record by normalized name
     */
    public function findByNormalized(string $normalizedName): ?array
    {
        try {
            $stmt = $this->db->prepare("
                SELECT * FROM bank_learning 
                WHERE normalized_name = ? 
                LIMIT 1
            ");
            $stmt->execute([$normalizedName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);

            return $row ?: null;
        } catch (\PDOException $e) {
            return null;
        }
    }
}
