<?php
/**
 * Store Merge Mapping
 * Saves user's merge decisions for banks in a JSON file
 */

require_once __DIR__ . '/../SetupDatabase.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['merges']) || !is_array($input['merges'])) {
        throw new Exception('مطلوب: قائمة عمليات الدمج');
    }
    
    $mergeFile = __DIR__ . '/../data/bank_merges.json';
    
    // Load existing merges
    $existingMerges = [];
    if (file_exists($mergeFile)) {
        $existingMerges = json_decode(file_get_contents($mergeFile), true) ?: [];
    }
    
    // Add new merges
    foreach ($input['merges'] as $merge) {
        $existingMerges[] = [
            'group_key' => $merge['group_key'],
            'bank_ids' => $merge['bank_ids'],
            'timestamp' => time()
        ];
    }
    
    // Save back
    file_put_contents($mergeFile, json_encode($existingMerges, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
    
    echo json_encode([
        'success' => true,
        'message' => 'تم حفظ قرارات الدمج'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
