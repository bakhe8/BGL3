<?php
/**
 * Scan Folders API - List files in input folders
 */

header('Content-Type: application/json');

try {
    $excelFolder = __DIR__ . '/../input/excel';
    $wordFolder = __DIR__ . '/../input/word';
    
    $result = [
        'excel' => [],
        'word' => []
    ];
    
    // Scan Excel folder
    if (is_dir($excelFolder)) {
        $files = glob($excelFolder . '/*.{xlsx,xls,csv}', GLOB_BRACE);
        foreach ($files as $file) {
            $result['excel'][] = [
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
    }
    
    // Scan Word folder
    if (is_dir($wordFolder)) {
        $files = glob($wordFolder . '/*.docx');
        foreach ($files as $file) {
            $result['word'][] = [
                'name' => basename($file),
                'size' => filesize($file),
                'modified' => date('Y-m-d H:i:s', filemtime($file))
            ];
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
