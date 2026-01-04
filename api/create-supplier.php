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
    
    // Use unified service
    $result = \App\Services\SupplierManagementService::create($db, [
        'official_name' => $name
    ]);
    
    // Return response in expected format for Decision Flow
    echo json_encode([
        'success' => true,
        'supplier_id' => $result['supplier_id'],
        'official_name' => $result['official_name'],
        'supplier' => [
            'id' => $result['supplier_id'],
            'name' => $result['official_name']
        ]
    ]);
    
} catch (\Throwable $e) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
