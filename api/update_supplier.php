<?php
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;
use App\Support\Normalizer;

header('Content-Type: application/json');

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    if (empty($data['id'])) {
        throw new Exception('Missing ID');
    }
    
    if (empty($data['official_name'])) {
        throw new Exception('Official name is required');
    }

    // Auto-normalize
    $normalizer = new Normalizer();
    $normalizedName = $normalizer->normalizeSupplierName($data['official_name']);

    $db = Database::connect();
    
    $stmt = $db->prepare("
        UPDATE suppliers
        SET 
            official_name = ?,
            english_name = ?,
            normalized_name = ?,
            is_confirmed = ?,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = ?
    ");
    
    $result = $stmt->execute([
        $data['official_name'],
        $data['english_name'] ?? null,
        $normalizedName,
        $data['is_confirmed'] ? 1 : 0,
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
