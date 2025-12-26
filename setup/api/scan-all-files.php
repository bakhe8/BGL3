<?php
/**
 * Scan Files API - Return all files in unified input folder (including subdirectories)
 */

header('Content-Type: application/json');

try {
    $filesFolder = __DIR__ . '/../input/files';
    
    if (!is_dir($filesFolder)) {
        mkdir($filesFolder, 0777, true);
    }
    
    $allFiles = [];
    
    // Recursively scan for files
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($filesFolder, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            
            $type = null;
            if (in_array($ext, ['xlsx', 'xls', 'csv'])) {
                $type = 'Excel/CSV';
            } elseif ($ext === 'docx') {
                $type = 'Word';
            }
            
            if ($type) {
                $allFiles[] = [
                    'name' => $file->getFilename(),
                    'path' => $file->getPathname(),
                    'type' => $type,
                    'size' => $file->getSize(),
                    'modified' => date('Y-m-d H:i', $file->getMTime())
                ];
            }
        }
    }
    
    // Count by type
    $excelCount = count(array_filter($allFiles, fn($f) => $f['type'] === 'Excel/CSV'));
    $wordCount = count(array_filter($allFiles, fn($f) => $f['type'] === 'Word'));
    
    echo json_encode([
        'success' => true,
        'data' => [
            'files' => $allFiles,
            'total' => count($allFiles),
            'excel' => $excelCount,
            'word' => $wordCount
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
