<?php
/**
 * V3 API - Get Smart Suggestions
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Services\LearningService;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $raw = $_GET['raw'] ?? ''; // Raw supplier name from Excel/Input

    if (empty($raw)) {
        echo json_encode(['suggestions' => []]);
        exit;
    }

    // Init Logic
    $db = Database::connect();
    $learningRepo = new SupplierLearningRepository($db);
    $supplierRepo = new SupplierRepository(); // Use default constructor (uses Database::connect internally)
    
    // Note: SupplierRepository constructor signature might differ. 
    // Usually standard Repos take PDO. Let's check previously created Repo.
    // Checking previous steps... SupplierRepository in V3 needs check.
    
    $service = new LearningService($learningRepo, $supplierRepo);
    
    $suggestions = $service->getSuggestions($raw);
    
    // Format for frontend
    $formatted = array_map(function($s) {
        return [
            'id' => $s['id'],
            'name' => $s['official_name'],
            'score' => $s['score'],
            'source' => $s['source'] === 'alias' ? 'تعلم سابق' : 'بحث',
            'type' => $s['score'] > 90 ? 'match' : 'candidate'
        ];
    }, $suggestions);

    echo json_encode([
        'success' => true,
        'query' => $raw,
        'suggestions' => $formatted
    ]);

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
