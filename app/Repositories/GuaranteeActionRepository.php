<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Support\Database;
use PDO;

/**
 * GuaranteeActionRepository (V3)
 * 
 * Manages guarantee_actions table (Extension/Release/Reduction)
 */
class GuaranteeActionRepository
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
    
    /**
     * Create new action (Extension, Release, Reduction)
     */
    public function create(array $data): int
    {
        $stmt = $this->db->prepare("
            INSERT INTO guarantee_actions (
                guarantee_id, action_type, action_date,
                previous_expiry_date, new_expiry_date,
                previous_amount, new_amount,
                release_reason, action_status, notes,
                created_at, created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)
        ");
        
        $stmt->execute([
            $data['guarantee_id'],
            $data['action_type'],
            $data['action_date'] ?? date('Y-m-d'),
            $data['previous_expiry_date'] ?? null,
            $data['new_expiry_date'] ?? null,
            $data['previous_amount'] ?? null,
            $data['new_amount'] ?? null,
            $data['release_reason'] ?? null,
            $data['action_status'] ?? 'pending',
            $data['notes'] ?? null,
            $data['created_by'] ?? null
        ]);
        
        return (int)$this->db->lastInsertId();
    }
    
    /**
     * Get all actions for a guarantee
     */
    public function getByGuarantee(int $guaranteeId): array
    {
        $stmt = $this->db->prepare("
            SELECT * FROM guarantee_actions
            WHERE guarantee_id = ?
            ORDER BY action_date DESC, created_at DESC
        ");
        $stmt->execute([$guaranteeId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Check if guarantee has been released
     */
    public function hasRelease(int $guaranteeId): bool
    {
        $stmt = $this->db->prepare("
            SELECT COUNT(*) FROM guarantee_actions
            WHERE guarantee_id = ?
            AND action_type = 'release'
            AND action_status = 'issued'
        ");
        $stmt->execute([$guaranteeId]);
        
        return $stmt->fetchColumn() > 0;
    }
    
    /**
     * Update action status
     */
    public function updateStatus(int $id, string $status): void
    {
        $stmt = $this->db->prepare("
            UPDATE guarantee_actions
            SET action_status = ?,
                letter_issued_at = CASE WHEN ? = 'issued' THEN CURRENT_TIMESTAMP ELSE letter_issued_at END
            WHERE id = ?
        ");
        $stmt->execute([$status, $status, $id]);
    }
}
