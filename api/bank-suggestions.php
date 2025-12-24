<?php
/**
 * V3 API - Get Bank Suggestions
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Services\LearningService;
use App\Repositories\BankLearningRepository;
use App\Repositories\BankRepository;

header('Content-Type: application/json; charset=utf-8');
ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $raw = $_GET['raw'] ?? ''; // Raw bank name from Excel/Input

    if (empty($raw)) {
        echo json_encode(['suggestions' => []]);
        exit;
    }

    // Init Logic
    $db = Database::connect();
    $learningRepo = new BankLearningRepository($db);
    $bankRepo = new BankRepository();
    
    // Get suggestions directly from repository
    $normalized = mb_strtolower($raw);
    $normalized = preg_replace('/[^\p{L}\p{N}\s]/u', '', $normalized);
    $normalized = preg_replace('/\s+/', ' ', trim($normalized));
    
    $suggestions = $learningRepo->findSuggestions($normalized);
    
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
