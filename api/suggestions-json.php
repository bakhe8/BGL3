<?php
/**
 * PILOT BRIDGE ENDPOINT (Phase 5 Only)
 * 
 * Pure JSON API for supplier suggestions
 * Used ONLY by: records.controller.js, pilot-metrics.js
 * 
 * This is a TEMPORARY bridge to enable Phase 5 Pilot.
 * Can be removed or re-evaluated after Pilot completion.
 * 
 * DO NOT use this from HTML rendering paths.
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;
use App\Services\LearningService;
use App\Repositories\SupplierLearningRepository;
use App\Repositories\SupplierRepository;
use App\Services\Suggestions\ArabicLevelBSuggestions;

header('Content-Type: application/json; charset=utf-8');

try {
    $raw = $_GET['raw'] ?? '';

    if (empty($raw)) {
        echo json_encode([
            'success' => true,
            'suggestions' => []
        ]);
        exit;
    }

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
        
        // Convert to JSON format expected by frontend
        $suggestions = array_map(function($sugg) {
            return [
                'id' => $sugg['supplier_id'],
                'official_name' => $sugg['official_name'],
                'english_name' => $sugg['english_name'],
                'level' => $sugg['level'],
                'confidence' => $sugg['confidence'],
                'matched_anchor' => $sugg['matched_anchor'],
                'reason' => $sugg['reason_ar'],
                'reason_ar' => $sugg['reason_ar'],
                'requires_confirmation' => $sugg['requires_confirmation'],
                'is_unique_anchor' => $sugg['is_unique_anchor'] ?? false,
                'anchor_type' => $sugg['anchor_type'] ?? ''
            ];
        }, $levelBSuggestions);
    } else {
        // Use existing Level A logic for English
        $learningRepo = new SupplierLearningRepository($db);
        $supplierRepo = new SupplierRepository();
        $service = new LearningService($learningRepo, $supplierRepo);
        $suggestions = $service->getSuggestions($raw);
        
        // Ensure consistent format
        $suggestions = array_map(function($sugg) {
            return [
                'id' => $sugg['id'],
                'official_name' => $sugg['official_name'],
                'english_name' => $sugg['english_name'] ?? null,
                'level' => 'A',
                'confidence' => 100,
                'matched_anchor' => '',
                'reason' => '',
                'reason_ar' => '',
                'requires_confirmation' => false,
                'is_unique_anchor' => false,
                'is_learning' => $sugg['is_learning'] ?? false,
                'star_rating' => $sugg['star_rating'] ?? null
            ];
        }, $suggestions);
    }

    echo json_encode([
        'success' => true,
        'suggestions' => $suggestions
    ]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'suggestions' => []
    ]);
}
