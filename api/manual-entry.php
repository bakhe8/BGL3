<?php
/**
 * V3 API - Manual Entry
 * Create single guarantee manually
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\ImportService;

header('Content-Type: application/json; charset=utf-8');
error_reporting(E_ALL);
ini_set('display_errors', 1);

try {
    // Get JSON input
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        throw new \RuntimeException('بيانات غير صالحة');
    }

    // Create using ImportService
    $service = new ImportService();
    $id = $service->createManually($input, 'web_user');

    echo json_encode([
        'success' => true,
        'id' => $id,
        'message' => 'تم إضافة السجل بنجاح!',
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
    ]);
}
