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
            is_confirmed = ?
        WHERE id = ?
    ");
    
    
    // ✅ Smart Validation: Prevent Arabic in English Name field
    $englishName = $data['english_name'] ?? '';
    if (preg_match('/\p{Arabic}/u', $englishName)) {
        // Option A: Ignore it (Save as NULL) - Keeps data clean
        $englishName = null;
    }

    // ✅ Reverse Smart: If Official Name is English (No Arabic) AND English field is empty
    // Auto-populate English Name (Assumes it's a foreign company)
    if (!preg_match('/\p{Arabic}/u', $data['official_name']) && empty($englishName)) {
        $englishName = $data['official_name'];
    }

    // ✅ FIX: Protect against ID loss
    $result = $stmt->execute([
        $data['official_name'],
        $englishName,
        $normalizedName,
        $data['is_confirmed'] ? 1 : 0,
        (int)$data['id']
    ]);
    
    if (!$result) {
        throw new Exception('Update execution failed');
    }
    
    // ✅ Verify ID preservation (using direct query due to SQLite PDO bug)
    $supplierId = (int)$data['id'];
    $verifyStmt = $db->query("SELECT id FROM suppliers WHERE id = $supplierId");
    if (!$verifyStmt->fetchColumn()) {
        throw new Exception('Critical: Supplier ID was lost during update!');
    }
    
    echo json_encode(['success' => true, 'updated' => $stmt->rowCount() > 0]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
