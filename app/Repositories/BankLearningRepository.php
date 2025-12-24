<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * BankLearningRepository
 * 
 * Manages learned patterns and suggestions for banks
 */
class BankLearningRepository
{
    private PDO $db;

    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }

    /**
     * Get smart suggestions for a raw bank name
     */
    public function findSuggestions(string $normalizedName, int $limit = 5): array
    {
        // 1. Check aliases first (if table exists)
        try {
            $stmt = $this->db->prepare("
                SELECT b.id, b.official_name, 'alias' as source, 100 as score
                FROM bank_alternative_names a
                JOIN banks b ON a.bank_id = b.id
                WHERE a.normalized_name = ?
                LIMIT 1
            ");
            $stmt->execute([$normalizedName]);
            $alias = $stmt->fetch(PDO::FETCH_ASSOC);

            if ($alias) {
                return [$alias];
            }
        } catch (\Exception $e) {
            // Table doesn't exist yet, continue with fuzzy search
        }

        // 2. Fuzzy search on banks table
        // We use official_name because normalized_name column likely doesn't exist in legacy banks table
        $sql = "
            SELECT 
                id, 
                official_name, 
                'search' as source,
                CASE 
                    WHEN official_name LIKE ? THEN 95 
                    WHEN official_name LIKE ? THEN 80
                    ELSE 60 
                END as score
            FROM banks 
            WHERE official_name LIKE ? 
            ORDER BY score DESC, id ASC
            LIMIT ?
        ";

        $likeParam = '%' . $normalizedName . '%';
        $exactParam = $normalizedName; // Rely on DB collation for case-insensitivity
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute([$exactParam, $likeParam, $likeParam, $limit]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Increment usage for a bank (and update cache)
     */
    public function incrementUsage(int $bankId, string $rawName): void
    {
        try {
            $norm = $this->normalize($rawName);
            $stmt = $this->db->prepare("
                UPDATE bank_alternative_names 
                SET usage_count = usage_count + 1 
                WHERE bank_id = ? AND normalized_name = ?
            ");
            $stmt->execute([$bankId, $norm]);
        } catch (\Exception $e) {
            // Table doesn't exist, skip
        }
    }

    /**
     * Learn a new alias mapping
     */
    public function learnAlias(int $bankId, string $rawName): void
    {
        try {
            $norm = $this->normalize($rawName);
            
            // Check if exists
            $stmt = $this->db->prepare("SELECT id FROM bank_alternative_names WHERE normalized_name = ?");
            $stmt->execute([$norm]);
            if ($stmt->fetch()) {
                return; // Already exists
            }

            // Insert
            $stmt = $this->db->prepare("
                INSERT INTO bank_alternative_names (bank_id, alternative_name, normalized_name, source, usage_count)
                VALUES (?, ?, ?, 'learning', 1)
            ");
            $stmt->execute([$bankId, $rawName, $norm]);
        } catch (\Exception $e) {
            // Table doesn't exist, skip for now
        }
    }

    /**
     * Log the decision for auditing/analytics
     */
    public function logDecision(array $data): void
    {
        try {
            $stmt = $this->db->prepare("
                INSERT INTO bank_decisions_log 
                (guarantee_id, raw_input, normalized_input, chosen_bank_id, chosen_bank_name, decision_source, confidence_score, was_top_suggestion, decided_at)
                VALUES 
                (:gid, :raw, :norm, :bid, :bname, :src, :score, :top, :at)
            ");
            
            $stmt->execute([
                ':gid'   => $data['guarantee_id'],
                ':raw'   => $data['raw_input'],
                ':norm'  => $this->normalize($data['raw_input']),
                ':bid'   => $data['chosen_bank_id'],
                ':bname' => $data['chosen_bank_name'],
                ':src'   => $data['source'] ?? 'manual',
                ':score' => $data['score'] ?? 0,
                ':top'   => $data['was_top_suggestion'] ?? 0,
                ':at'    => date('Y-m-d H:i:s')
            ]);
        } catch (\Exception $e) {
            // Table doesn't exist, skip
        }
    }

    private function normalize(string $text): string
    {
        // Simple normalization
        $text = mb_strtolower($text);
        $text = preg_replace('/[^\p{L}\p{N}\s]/u', '', $text);
        $text = preg_replace('/\s+/', ' ', $text);
        return trim($text);
    }
}
