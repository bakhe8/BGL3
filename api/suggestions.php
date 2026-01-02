<?php
/**
 * V3 API - Get Smart Suggestions
 * Returns HTML fragment (for index.php) or JSON (for import.php)
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Services\LearningService;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;
use App\Services\Suggestions\ArabicLevelBSuggestions;

// Detect format: check for 'format=json' parameter or Accept header
$format = $_GET['format'] ?? 'html';
if (isset($_SERVER['HTTP_ACCEPT']) && strpos($_SERVER['HTTP_ACCEPT'], 'application/json') !== false) {
    $format = 'json';
}

ini_set('display_errors', 1);
error_reporting(E_ALL);

try {
    $raw = $_GET['raw'] ?? '';

    if (empty($raw)) {
        $suggestions = [];
    } else {
        $db = Database::connect();
        
        // Normalize input
        $normalized = mb_strtolower(trim($raw));
        $normalized = preg_replace('/\s+/', ' ', $normalized);
        $normalized = preg_replace('/[\x{064B}-\x{065F}]/u', '', $normalized);
        
        // Check if Arabic
        $hasArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $raw);
        
        if ($hasArabic) {
            // Use ADR-007 Arabic Level B System
            $arabicService = new ArabicLevelBSuggestions($db);
            $levelBSuggestions = $arabicService->find($normalized);
            
            // Convert to expected format
            $suggestions = array_map(function($sugg) {
                return [
                    'id' => $sugg['supplier_id'],
                    'official_name' => $sugg['official_name'],
                    'english_name' => $sugg['english_name'],
                    'level' => $sugg['level'],
                    'confidence' => $sugg['confidence'],
                    'matched_anchor' => $sugg['matched_anchor'],
                    'reason' => $sugg['reason_ar'],
                    'requires_confirmation' => $sugg['requires_confirmation'],
                    'is_unique_anchor' => $sugg['is_unique_anchor']
                ];
            }, $levelBSuggestions);
        } else {
            // Use existing Level A logic for English
            $learningRepo = new SupplierLearningRepository($db);
            $supplierRepo = new SupplierRepository();
            $service = new LearningService($learningRepo, $supplierRepo);
            $suggestions = $service->getSuggestions($raw);
        }
    }

    // Return format based on caller
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'success' => true,
            'suggestions' => $suggestions
        ]);
    } else {
        // Return HTML fragment for server-driven UI
        header('Content-Type: text/html; charset=utf-8');
        include __DIR__ . '/../partials/supplier-suggestions.php';
    }

} catch (Exception $e) {
    if ($format === 'json') {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(500);
        echo json_encode([
            'success' => false,
            'error' => $e->getMessage()
        ]);
    } else {
        header('Content-Type: text/html; charset=utf-8');
        echo '<div id="supplier-suggestions"><p>خطأ: ' . htmlspecialchars($e->getMessage()) . '</p></div>';
    }
}
