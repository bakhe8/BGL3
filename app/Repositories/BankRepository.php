<?php
declare(strict_types=1);

namespace App\Repositories;

use App\Models\Bank;
use App\Support\Database;
use PDO;

class BankRepository
{
    public function findByNormalizedName(string $normalizedName): ?Bank
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM banks WHERE normalized_name = :n LIMIT 1');
        $stmt->execute(['n' => $normalizedName]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$row) {
            return null;
        }
        return $this->map($row);
    }

    public function findByNormalizedKey(string $key): ?Bank
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM banks WHERE normalized_name = :k LIMIT 1');
        $stmt->execute(['k' => $key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->map($row) : null;
    }

    public function find(int $id): ?Bank
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT * FROM banks WHERE id = :id LIMIT 1');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? $this->map($row) : null;
    }

    private function map(array $row): Bank
    {
        return new Bank(
            (int) $row['id'],
            $row['arabic_name'],
            $row['english_name'] ?? null,
            $row['arabic_name'],
            null, // normalized_key removed
            $row['short_name'] ?? null,
            1, // is_confirmed - always confirmed in new schema
            $row['created_at'] ?? null,
            null, // department removed
            null, // address_line_1 removed
            null, // address_line_2 removed
            null  // contact_email removed
        );
    }
    
    /**
     * Get bank details for display (used in guarantee view)
     * 
     * @param int $bankId Bank ID
     * @return array|null Bank details array or null if not found
     */
    public function getBankDetails(int $bankId): ?array
    {
        try {
            $pdo = Database::connection();
            $stmt = $pdo->prepare('
                SELECT 
                    arabic_name as official_name,
                    department,
                    address_line1 as po_box,
                    contact_email as email
                FROM banks WHERE id = ?
            ');
            $stmt->execute([$bankId]);
            $bank = $stmt->fetch(PDO::FETCH_ASSOC);
            
            return $bank ?: null;
        } catch (\Exception $e) {
            return null;
        }
    }

    public function allNormalized(): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->query('
            SELECT id, arabic_name as official_name, english_name as official_name_en, 
                   short_name as short_code, created_at
            FROM banks
            ORDER BY arabic_name ASC
        ');
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** @return array<int, array{id:int, arabic_name:string, english_name:?string, short_name:?string, created_at:?string, updated_at:?string}> */
    public function search(string $normalizedLike): array
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('SELECT id, arabic_name, english_name, short_name, created_at, updated_at FROM banks WHERE short_name LIKE :q OR arabic_name LIKE :q');
        $stmt->execute(['q' => "%{$normalizedLike}%"]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    public function create(array $data): Bank
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('INSERT INTO banks 
            (official_name, official_name_en, normalized_name, short_code, is_confirmed, department, address_line_1, address_line_2, contact_email) 
            VALUES (:o, :oe, :nk, :sc, :c, :dept, :addr1, :addr2, :email)');
        $stmt->execute([
            'o' => $data['official_name'],
            'oe' => $data['official_name_en'] ?? null,
            'nk' => $data['normalized_key'] ?? null,
            'sc' => $data['short_code'] ?? null,
            'c' => $data['is_confirmed'] ?? 0,
            'dept' => $data['department'] ?? null,
            'addr1' => $data['address_line_1'] ?? null,
            'addr2' => $data['address_line_2'] ?? null,
            'email' => $data['contact_email'] ?? null,
        ]);
        $id = (int) $pdo->lastInsertId();
        return new Bank(
            $id, 
            $data['official_name'], 
            $data['official_name_en'] ?? null, 
            $data['official_name'], // officialNameAr default
            $data['normalized_key'] ?? null, 
            $data['short_code'] ?? null, 
            (int) ($data['is_confirmed'] ?? 0), 
            date('c'),
            $data['department'] ?? null,
            $data['address_line_1'] ?? null,
            $data['address_line_2'] ?? null,
            $data['contact_email'] ?? null
        );
    }

    public function update(int $id, array $data): void
    {
        $pdo = Database::connection();
        
        // Build dynamic UPDATE statement to support partial updates
        $fields = [];
        $params = ['id' => $id];
        
        if (isset($data['official_name'])) {
            $fields[] = 'official_name = :o';
            $params['o'] = $data['official_name'];
        }
        if (isset($data['official_name_en'])) {
            $fields[] = 'official_name_en = :oe';
            $params['oe'] = $data['official_name_en'];
        }
        if (isset($data['normalized_key'])) {
            $fields[] = 'normalized_key = :nk';
            $params['nk'] = $data['normalized_key'];
        }
        if (isset($data['short_code'])) {
            $fields[] = 'short_code = :sc';
            $params['sc'] = $data['short_code'];
        }
        
        // Address fields
        if (isset($data['department'])) {
            $fields[] = 'department = :dept';
            $params['dept'] = $data['department'];
        }
        if (isset($data['address_line_1'])) {
            $fields[] = 'address_line_1 = :addr1';
            $params['addr1'] = $data['address_line_1'];
        }
        if (isset($data['address_line_2'])) {
            $fields[] = 'address_line_2 = :addr2';
            $params['addr2'] = $data['address_line_2'];
        }
        if (isset($data['contact_email'])) {
            $fields[] = 'contact_email = :email';
            $params['email'] = $data['contact_email'];
        }
        
        if (empty($fields)) {
            return; // Nothing to update
        }
        
        $sql = 'UPDATE banks SET ' . implode(', ', $fields) . ' WHERE id = :id';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }

    public function delete(int $id): void
    {
        $pdo = Database::connection();
        $stmt = $pdo->prepare('DELETE FROM banks WHERE id=:id');
        $stmt->execute(['id' => $id]);
    }
}
