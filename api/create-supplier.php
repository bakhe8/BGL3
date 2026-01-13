<?php
/**
 * V3 API - Create Supplier (AJAX)
 * Adds a new supplier to the master list
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $officialName = trim($input['official_name'] ?? $input['name'] ?? '');
    $englishNameInput = $input['english_name'] ?? null;
    $englishName = is_string($englishNameInput) ? trim($englishNameInput) : null;
    $isConfirmed = isset($input['is_confirmed']) ? (int)$input['is_confirmed'] : null;
    
    if (!$officialName) {
        throw new \RuntimeException('اسم المورد مطلوب');
    }
    
    $db = Database::connect();
    
    // Smart Detection: Check if name contains Arabic characters
    // Regex: \p{Arabic} detects any Arabic script character
    $hasArabic = preg_match('/\p{Arabic}/u', $officialName);
    
    // Detailed Logic:
    // 1. If Arabic: Official = Name, English = NULL (Avoid Repetition)
    // 2. If English: Official = Name, English = Name (Common practice for foreign companies)
    if ($englishName === '') {
        $englishName = null;
    }
    if ($englishName === null) {
        $englishName = $hasArabic ? null : $officialName;
    }

    // Use unified service
    $data = [
        'official_name' => $officialName,
        'english_name' => $englishName
    ];
    if ($isConfirmed !== null) {
        $data['is_confirmed'] = $isConfirmed;
    }
    $result = \App\Services\SupplierManagementService::create($db, $data);
    
    // Return response in expected format for Decision Flow
    echo json_encode([
        'success' => true,
        'supplier_id' => $result['supplier_id'],
        'official_name' => $result['official_name'],
        'supplier' => [
            'id' => $result['supplier_id'],
            'name' => $result['official_name']
        ]
    ]);
    
} catch (\Throwable $e) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
