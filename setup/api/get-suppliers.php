<?php
/**
 * Get Suppliers API - Return all temp suppliers
 */

require_once __DIR__ . '/../SetupDatabase.php';

header('Content-Type: application/json');

try {
    $db = SetupDatabase::connect();
    
    $stmt = $db->query('
        SELECT id, supplier_name, supplier_name_en, normalized_name, occurrence_count, status, notes, user_edited_name
        FROM temp_suppliers
        ORDER BY occurrence_count DESC, supplier_name ASC
    ');
    
    $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $suppliers
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
