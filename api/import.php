<?php
/**
 * V3 API - Import Excel File
 * Updated to use ImportService
 */


require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\ImportService;

header('Content-Type: application/json; charset=utf-8');
ini_set('memory_limit', '512M');
error_reporting(E_ALL);
ini_set('display_errors', 0);

try {
    // Validate upload
    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new \RuntimeException('لم يتم استلام الملف أو حدث خطأ في الرفع');
    }

    $file = $_FILES['file'];

    // Validate extension
    $filename = $file['name'];
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));

    if (!in_array($ext, ['xlsx', 'xls'])) {
        throw new \RuntimeException('نوع الملف غير مسموح. يجب أن يكون ملف Excel (.xlsx أو .xls)');
    }

    // Move to temporary location
    $uploadDir = __DIR__ . '/../storage/uploads';
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $tempPath = $uploadDir . '/temp_' . date('Ymd_His') . '_' . uniqid() . '.xlsx';
    
    if (!move_uploaded_file($file['tmp_name'], $tempPath)) {
        throw new \RuntimeException('فشل نقل الملف المرفوع');
    }

    try {
        // Import using service
        $service = new ImportService();
        $result = $service->importFromExcel($tempPath, $_POST['imported_by'] ?? 'web_user');

        // --- POST IMPORT AUTOMATION ---
        $autoMatchStats = ['processed' => 0, 'auto_matched' => 0];
        try {
            // "Smart Processing" applies to any new guarantees, regardless of source (Excel, Manual, Paste)
            $processor = new \App\Services\SmartProcessingService();
            $autoMatchStats = $processor->processNewGuarantees($result['imported']);
        } catch (\Throwable $e) { /* Ignore automation errors, keep import success */ }
        // ------------------------------

        http_response_code(200);
        echo json_encode([
            'success' => true,
            'data' => [
                'imported' => $result['imported'],
                'auto_matched' => $autoMatchStats['auto_matched'],
                'total_rows' => $result['total_rows'],
                'skipped' => count($result['skipped']),
                'errors' => count($result['errors']),
                'skipped_details' => $result['skipped'],
                'error_details' => $result['errors'],
            ],
            'message' => "تم استيراد {$result['imported']} سجل، وتمت المطابقة التلقائية لـ {$autoMatchStats['auto_matched']} سجل!",
        ]);
        
    } finally {
        // Cleanup: Delete temporary file
        if (file_exists($tempPath)) {
            @unlink($tempPath);
        }
    }

} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage(),
        'error' => [
            'file' => basename($e->getFile()),
            'line' => $e->getLine(),
        ]
    ]);
}
