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

    $db = Database::connect();
    
    // Use unified service
    \App\Services\SupplierManagementService::create($db, $data);
    
    echo json_encode(['success' => true]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
