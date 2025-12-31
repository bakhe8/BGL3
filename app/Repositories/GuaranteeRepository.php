<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Guarantee;
use App\Support\Database;
use PDO;

/**
 * GuaranteeRepository (V3)
 * 
 * Manages guarantees table - raw data storage only
 */
class GuaranteeRepository
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
    
    /**
     * Find guarantee by ID
     */
    public function find(int $id): ?Guarantee
    {
        $stmt = $this->db->prepare("
            SELECT * FROM guarantees WHERE id = ?
        ");
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * Find guarantee by guarantee_number
     */
    public function findByNumber(string $guaranteeNumber): ?Guarantee
    {
        $stmt = $this->db->prepare("
            SELECT * FROM guarantees WHERE guarantee_number = ?
        ");
        $stmt->execute([$guaranteeNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $row ? $this->hydrate($row) : null;
    }
    
    /**
     * Create new guarantee
     */
    public function create(Guarantee $guarantee): Guarantee
    {
        $stmt = $this->db->prepare("
            INSERT INTO guarantees (
                guarantee_number,
                raw_data,
                import_source,
                imported_at,
                imported_by
            ) VALUES (?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $guarantee->guaranteeNumber,
            json_encode($guarantee->rawData),
            $guarantee->importSource,
            $guarantee->importedAt ?? date('Y-m-d H:i:s'),
            $guarantee->importedBy
        ]);
        
        $id = (int)$this->db->lastInsertId();
        
        // ✅ STRICT INTEGRITY: Re-fetch from DB to ensure returned object 
        // reflects exactly what was stored (Post-Persist State).
        // This captures any DB-side normalization or encoding effects.
        $persisted = $this->find($id);
        
        if (!$persisted) {
             // Fallback (should never happen in normal operation)
             $guarantee->id = $id;
             return $guarantee;
        }
        
        return $persisted;
    }
    
    /**
     * Update guarantee raw_data (P2: Mutation Isolation)
     * 
     * Centralized mutation point for raw_data updates (e.g., extend, reduce actions)
     * Ensures all raw_data changes go through repository layer
     * 
     * @param int $guaranteeId Guarantee ID
     * @param string $rawData Updated JSON raw_data
     * @return void
     */
    public function updateRawData(int $guaranteeId, string $rawData): void
    {
        $stmt = $this->db->prepare('
            UPDATE guarantees 
            SET raw_data = ? 
            WHERE id = ?
        ');
        $stmt->execute([$rawData, $guaranteeId]);
    }    
    
    /**
     * Get all guarantees with filters
     */
    public function getAll(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        [$whereClause, $params] = $this->buildWhereClause($filters);
        
        $sql = "
            SELECT * FROM guarantees
            {$whereClause}
            ORDER BY imported_at DESC
            LIMIT ? OFFSET ?
        ";
        
        $params[] = $limit;
        $params[] = $offset;
        
        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        
        $guarantees = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $guarantees[] = $this->hydrate($row);
        }
        
        return $guarantees;
    }
    
    /**
     * Count guarantees
     */
    public function count(array $filters = []): int
    {
        [$whereClause, $params] = $this->buildWhereClause($filters);
        
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM guarantees {$whereClause}");
        $stmt->execute($params);
        
        return (int)$stmt->fetchColumn();
    }
    
    /**
     * Build WHERE clause from filters
     */
    private function buildWhereClause(array $filters): array
    {
        $where = [];
        $params = [];
        
        if (!empty($filters['import_source'])) {
            $where[] = 'import_source = ?';
            $params[] = $filters['import_source'];
        }
        
        if (!empty($filters['date_from'])) {
            $where[] = 'DATE(imported_at) >= ?';
            $params[] = $filters['date_from'];
        }
        
        if (!empty($filters['date_to'])) {
            $where[] = 'DATE(imported_at) <= ?';
            $params[] = $filters['date_to'];
        }
        
        $whereClause = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);
        
        return [$whereClause, $params];
    }
    
    /**
     * Hydrate Guarantee from DB row
     */
    private function hydrate(array $row): Guarantee
    {
        return new Guarantee(
            id: $row['id'],
            guaranteeNumber: $row['guarantee_number'],
            rawData: json_decode($row['raw_data'], true),
            importSource: $row['import_source'],
            importedAt: $row['imported_at'],
            importedBy: $row['imported_by']
        );
    }
}
