<?php
/**
 * ═════════════════════════════════════════════════════════════════════════
 * V3 API - Save Decision and Fetch Next (Final Refactor)
 * ═════════════════════════════════════════════════════════════════════════
 * 
 * Uses DecisionWorkflowService for full "Service Layer" architecture.
 * This file is now just a thin Controller.
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Services/DecisionWorkflowService.php'; // 🆕 Load Service
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\DecisionWorkflowService;
use App\Models\Guarantee;

header('Content-Type: application/json; charset=utf-8');

try {
    // 1. Input Parsing
    $input = json_decode(file_get_contents('php://input'), true);
    
    $guaranteeId = $input['guarantee_id'] ?? null;
    $supplierId = $input['supplier_id'] ?? null;
    $supplierName = trim($input['supplier_name'] ?? '');

    if (!$guaranteeId) {
        throw new \Exception('Missing guarantee_id');
    }

    // 2. Delegate to Service
    $service = new DecisionWorkflowService();
    $result = $service->processDecision((int)$guaranteeId, $supplierId ? (int)$supplierId : null, $supplierName);

    // 3. Fetch Next Record (Logic kept in Controller or moved to separate Service call)
    // To keep it clean, we can fetch next record here or via another method.
    // Let's use the existing Repo logic for fetching next.
    
    $db = \App\Support\Database::connect();
    $repo = new \App\Repositories\GuaranteeRepository($db);
    $nextRecord = $repo->findNextPending((int)$guaranteeId);
    
    // 4. Return Response
    echo json_encode([
        'success' => true,
        'saved_status' => $result['status'],
        'next_record' => $nextRecord ? [
            'id' => $nextRecord->id,
            'guarantee_number' => $nextRecord->guaranteeNumber,
            'supplier_name' => $nextRecord->rawData['supplier'] ?? '',
            'amount' => $nextRecord->rawData['amount'] ?? 0,
            // Add other fields as needed for the frontend
            'raw_data' => $nextRecord->rawData
        ] : null,
        'message' => 'Decision saved successfully'
    ]);

} catch (\Throwable $e) {
    $code = $e->getCode();
    // Valid HTTP error codes usually between 400 and 599
    $httpCode = ($code >= 400 && $code < 600) ? $code : 500;
    
    http_response_code($httpCode);
    echo json_encode([
        'success' => false, 
        'error' => $e->getMessage(),
        'error_code' => $e->getMessage(),// Send message as code for frontend logic failure (e.g. 'supplier_required')
        'supplier_name' => $supplierName ?? '' // 🔥 Return requested name for UI prompt
    ]);
    error_log("Save Decision Failed: " . $e->getMessage());
}
