<?php
/**
 * Batch Operations API
 * Handles all batch-level operations
 */

require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\BatchService;

header('Content-Type: application/json; charset=utf-8');

$method = $_SERVER['REQUEST_METHOD'];
$service = new BatchService();

try {
    if ($method === 'POST') {
        $rawBody = file_get_contents('php://input');
        $jsonInput = json_decode($rawBody, true);
        $input = array_merge($_POST, is_array($jsonInput) ? $jsonInput : []);

        $action = $input['action'] ?? '';
        $importSource = $input['import_source'] ?? '';
        
        if (!$importSource && $action !== 'list') {
            throw new \RuntimeException('import_source مطلوب');
        }
        
        switch ($action) {
            case 'extend':
                $newExpiry = $input['new_expiry'] ?? '';
                $result = $service->extendBatch(
                    $importSource,
                    $newExpiry,
                    $input['user_id'] ?? 'web_user',
                    $input['guarantee_ids'] ?? null
                );
                break;
                
            case 'release':
                $reason = $input['reason'] ?? null;
                $result = $service->releaseBatch(
                    $importSource,
                    $reason,
                    $input['user_id'] ?? 'web_user',
                    $input['guarantee_ids'] ?? null
                );
                break;
                
            case 'close':
                $result = $service->closeBatch($importSource, $input['closed_by'] ?? 'web_user');
                break;
                
            case 'update_metadata':  // Decision #2
                $batchName = $input['batch_name'] ?? null;
                $batchNotes = $input['batch_notes'] ?? null;
                $result = $service->updateMetadata($importSource, $batchName, $batchNotes);
                break;
                
            case 'reopen':  // Decision #7
                $result = $service->reopenBatch($importSource, $input['reopened_by'] ?? 'web_user');
                break;
                
            case 'summary':
                $result = $service->getBatchSummary($importSource);
                if ($result === null) {
                    $result = [
                        'success' => false,
                        'error' => 'الدفعة غير موجودة أو فارغة'
                    ];
                } else {
                    $result['success'] = true;
                }
                break;
                
            default:
                throw new \RuntimeException('Action غير معروف: ' . $action);
        }
        
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        
    } elseif ($method === 'GET') {
        // Get batch summary
        $importSource = $_GET['import_source'] ?? '';
        
        if (!$importSource) {
            throw new \RuntimeException('import_source مطلوب');
        }
        
        $result = $service->getBatchSummary($importSource);
        if ($result === null) {
            http_response_code(404);
            echo json_encode([
                'success' => false,
                'error' => 'الدفعة غير موجودة'
            ], JSON_UNESCAPED_UNICODE);
        } else {
            $result['success'] = true;
            echo json_encode($result, JSON_UNESCAPED_UNICODE);
        }
        
    } else {
        throw new \RuntimeException('Method غير مدعوم');
    }
    
} catch (\Throwable $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ], JSON_UNESCAPED_UNICODE);
}
