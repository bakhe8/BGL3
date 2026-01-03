<?php

/**
 * API Endpoint: Supplier Suggestions (Learning-based)
 * 
 * ✅ PHASE 4 COMPLETE: Using UnifiedLearningAuthority
 * 
 * This endpoint provides as-you-type supplier suggestions.
 * Returns canonical SuggestionDTO[] in JSON format.
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\Learning\AuthorityFactory;

// Get input
$rawInput = $_GET['raw'] ?? '';

if (empty($rawInput)) {
    echo json_encode(['success' => false, 'error' => 'raw parameter required']);
    exit;
}

try {
    // ✅ PHASE 4: Using UnifiedLearningAuthority
    $authority = AuthorityFactory::create();
    $suggestionDTOs = $authority->getSuggestions($rawInput);
    
    // Convert DTOs to array format for JSON
    $suggestions = array_map(function($dto) {
        return [
            'id' => $dto->supplier_id,
            'official_name' => $dto->official_name,
            'english_name' => $dto->english_name,
            'score' => $dto->confidence,
            'level' => $dto->level,
            'reason_ar' => $dto->reason_ar,
            'usage_count' => $dto->usage_count,
            'confirmation_count' => $dto->confirmation_count,
            'rejection_count' => $dto->rejection_count,
            'source' => $dto->primary_source ?? 'authority'
        ];
    }, $suggestionDTOs);
    
    echo json_encode([
        'success' => true,
        'count' => count($suggestions),
        'suggestions' => $suggestions,
        'source' => 'unified_authority',
        'version' => '4.0'
    ]);
    
} catch (\Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Failed to get suggestions',
        'message' => $e->getMessage()
    ]);
}
