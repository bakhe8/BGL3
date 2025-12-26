<?php
/**
 * Update Supplier API - Edit name or change status
 */

require_once __DIR__ . '/../SetupDatabase.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['id'])) {
        throw new Exception('معرّف المورد مطلوب');
    }
    
    $db = SetupDatabase::connect();
    
    $updates = [];
    $params = [];
    
    if (isset($input['user_edited_name'])) {
        $updates[] = 'user_edited_name = ?';
        $params[] = trim($input['user_edited_name']);
    }
    
    if (isset($input['status'])) {
        if (!in_array($input['status'], ['pending', 'confirmed', 'rejected', 'duplicate'])) {
            throw new Exception('حالة غير صحيحة');
        }
        $updates[] = 'status = ?';
        $params[] = $input['status'];
    }
    
    if (isset($input['notes'])) {
        $updates[] = 'notes = ?';
        $params[] = trim($input['notes']);
    }
    
    if (empty($updates)) {
        throw new Exception('لا يوجد تحديثات');
    }
    
    $params[] = $input['id'];
    
    $sql = 'UPDATE temp_suppliers SET ' . implode(', ', $updates) . ' WHERE id = ?';
    $stmt = $db->prepare($sql);
    $stmt->execute($params);
    
    echo json_encode([
        'success' => true,
        'message' => 'تم التحديث بنجاح'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
