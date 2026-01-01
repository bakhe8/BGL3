<?php
/**
 * API: Fix Status Sync
 * Updates guarantees.status to 'ready' for all guarantees with decisions
 */

require_once __DIR__ . '/../app/Support/Database.php';

header('Content-Type: application/json');

try {
    $db = \App\Support\Database::connect();
    
    // Update all guarantees that have decisions but wrong status
    $stmt = $db->exec("
        UPDATE guarantees 
        SET status = 'ready' 
        WHERE id IN (
            SELECT guarantee_id FROM guarantee_decisions
        ) AND status != 'ready'
    ");
    
    echo json_encode([
        'success' => true,
        'updated' => $stmt,
        'message' => "تم تحديث $stmt ضمان"
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
