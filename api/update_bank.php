<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        throw new Exception('Missing ID');
    }

    $db = Database::connect();
    
    $stmt = $db->prepare("
        UPDATE banks 
        SET 
            arabic_name = ?,
            english_name = ?,
            short_name = ?,
            department = ?,
            address_line1 = ?,
            contact_email = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    
    $result = $stmt->execute([
        $data['arabic_name'],
        $data['english_name'] ?? null,
        $data['short_name'] ?? null,
        $data['department'] ?? null,
        $data['address_line1'] ?? null,
        $data['contact_email'] ?? null,
        $data['id']
    ]);
    
    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('Update failed');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
