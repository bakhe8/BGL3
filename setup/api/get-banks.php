<?php
/**
 * Get Banks API - Return all temp banks
 */

require_once __DIR__ . '/../SetupDatabase.php';

header('Content-Type: application/json');

try {
    $db = SetupDatabase::connect();
    
    $stmt = $db->query('
        SELECT id, bank_name, normalized_name, occurrence_count, status, notes, user_edited_name, bank_info
        FROM temp_banks
        ORDER BY occurrence_count DESC, bank_name ASC
    ');
    
    $banks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode([
        'success' => true,
        'data' => $banks
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
