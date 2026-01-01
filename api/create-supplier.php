<?php
/**
 * V3 API - Create Supplier (AJAX)
 * Adds a new supplier to the master list
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $name = trim($input['name'] ?? '');
    
    if (!$name) {
        throw new \RuntimeException('اسم المورد مطلوب');
    }
    
    $db = Database::connect();
    
    // Check duplicates
    $stmt = $db->prepare('SELECT id FROM suppliers WHERE official_name = ?');
    $stmt->execute([$name]);
    if ($stmt->fetchColumn()) {
        throw new \RuntimeException('المورد موجود بالفعل');
    }
    
    // Generate normalized name
    $normName = mb_strtolower($name);
    
    // Insert
    $stmt = $db->prepare('INSERT INTO suppliers (official_name, normalized_name) VALUES (?, ?)');
    $stmt->execute([$name, $normName]);
    $id = $db->lastInsertId();
    
    echo json_encode([
        'success' => true,
        'supplier_id' => $id,
        'official_name' => $name,
        'supplier' => [
            'id' => $id,
            'name' => $name
        ]
    ]);
    
} catch (\Throwable $e) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
