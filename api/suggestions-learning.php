<?php
require __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\LearningRepository;
use App\Repositories\SupplierRepository;
use App\Services\Suggestions\ArabicLevelBSuggestions;
use App\Services\Suggestions\ConfidenceCalculator;
use App\Services\Suggestions\LearningSuggestionService;

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = $_GET['raw'] ?? '';

    if (empty($raw)) {
        echo json_encode(['success' => true, 'suggestions' => []]);
        exit;
    }

    $db = Database::connect();
    
    // Instantiate Dependencies
    $learningRepo = new LearningRepository($db);
    $supplierRepo = new SupplierRepository($db);
    $arabicService = new ArabicLevelBSuggestions($db);
    $calculator = new ConfidenceCalculator();

    // Instantiate Service
    $service = new LearningSuggestionService(
        $learningRepo,
        $arabicService,
        $calculator,
        $supplierRepo
    );

    // Get Suggestions
    $suggestions = $service->getSuggestions($raw);

    echo json_encode([
        'success' => true, 
        'suggestions' => $suggestions,
        'debug_info' => [
            'raw' => $raw,
            'count' => count($suggestions)
        ]
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
