<?php
require_once __DIR__ . '/../app/Support/autoload.php'; // Use autoload for Normalizer
use App\Support\Database;
use App\Support\Normalizer;

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['official_name'])) {
        throw new Exception('الاسم الرسمي مطلوب');
    }

    // Auto-normalize
    $normalizer = new Normalizer();
    $normalizedName = $normalizer->normalizeSupplierName($data['official_name']);

    $db = Database::connect();
    
    $stmt = $db->prepare("
        INSERT INTO suppliers (
            official_name, english_name, normalized_name, is_confirmed, created_at, updated_at
        ) VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)
    ");
    
    $result = $stmt->execute([
        $data['official_name'],
        $data['english_name'] ?? null,
        $normalizedName, // Use generated normalized name
        $data['is_confirmed'] ? 1 : 0
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
