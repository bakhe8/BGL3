<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['arabic_name'])) {
        throw new Exception('الاسم العربي مطلوب');
    }

    $db = Database::connect();
    
    $stmt = $db->prepare("
        INSERT INTO banks (
            arabic_name, english_name, short_name, department, address_line1, contact_email, created_at, updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    
    $result = $stmt->execute([
        $data['arabic_name'],
        $data['english_name'] ?? null,
        $data['short_name'] ?? null,
        $data['department'] ?? null,
        $data['address_line1'] ?? null,
        $data['contact_email'] ?? null
    ]);
    
    if ($result) {
        echo json_encode(['success' => true]);
    } else {
        throw new Exception('Create failed');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
