<?php
/**
 * Delete all Smart Paste imports - FIXED PATH
 */

header('Content-Type: application/json');

try {
    // Correct path from api/ subdirectory
    $dbPath = dirname(__DIR__) . '/storage/database.sqlite';
    
    if (!file_exists($dbPath)) {
        throw new Exception("Database file not found at: $dbPath");
    }
    
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Get IDs of Smart Paste guarantees
    $stmt = $db->query("SELECT id, bg_number, import_source FROM guarantees WHERE import_source LIKE '%Smart Paste%'");
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($records)) {
        echo json_encode([
            'success' => true,
            'deleted' => 0,
            'message' => 'لا توجد ضمانات تم استيرادها عبر اللصق'
        ]);
        exit;
    }
    
    $ids = array_column($records, 'id');
    $idList = implode(',', $ids);
    
    // Delete decisions first
    $db->exec("DELETE FROM guarantee_decisions WHERE guarantee_id IN ($idList)");
    
    // Delete guarantees
    $db->exec("DELETE FROM guarantees WHERE import_source LIKE '%Smart Paste%'");
    
    // Get stats
    $count = $db->query("SELECT COUNT(*) FROM guarantees")->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'deleted' => count($ids),
        'deleted_records' => $records,
        'remaining' => $count,
        'message' => "تم حذف " . count($ids) . " ضمان تم استيراده عبر اللصق"
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
       'db_path_tried' => isset($dbPath) ? $dbPath : 'not set'
    ]);
}
