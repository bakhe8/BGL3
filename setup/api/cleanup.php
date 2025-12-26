<?php
/**
 * Cleanup API - Delete temp database and uploaded files
 */

require_once __DIR__ . '/../SetupDatabase.php';

header('Content-Type: application/json');

try {
    // Delete temp database
    SetupDatabase::reset();
    
    // Delete uploaded files if any
    $uploadDir = __DIR__ . '/../uploads';
    if (is_dir($uploadDir)) {
        $files = glob($uploadDir . '/*');
        foreach ($files as $file) {
            if (is_file($file)) {
                unlink($file);
            }
        }
        rmdir($uploadDir);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'تم الحذف بنجاح'
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
