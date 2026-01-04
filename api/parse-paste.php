<?php
/**
 * ═════════════════════════════════════════════════════════════════════════
 * V3 API - Smart Paste Parse (Final Refactor)
 * ═════════════════════════════════════════════════════════════════════════
 * 
 * Uses TextParsingService for cleaner architecture.
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Services/TextParsingService.php'; // 🆕 Load Service
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Services\TextParsingService;
use App\Repositories\GuaranteeRepository;
use App\Support\Database;
use App\Support\ParsingHelpers;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $text = $input['text'] ?? '';

    if (empty($text)) {
        throw new \RuntimeException("لم يتم إدخال أي نص للتحليل");
    }

    // Init Service
    $db = Database::connect();
    $repo = new GuaranteeRepository($db);
    $service = new TextParsingService($repo);

    // 1. Parse
    $result = $service->parseText($text);

    // 2. Handle Multi-Row
    if ($result['type'] === 'multi') {
        $processed = $service->processRows($result['data'], $text);
        
        // Auto-match
        try {
            $processor = new \App\Services\SmartProcessingService();
            $processor->processNewGuarantees(count($processed));
        } catch (\Throwable $e) {}

        echo json_encode([
            'success' => true,
            'multi' => true,
            'count' => count($processed),
            'results' => $processed,
            'message' => "تم استيراد " . count($processed) . " ضمان بنجاح"
        ]);
        exit;
    }

    // 3. Handle Single Row
    $extracted = $result['data'];
    $fieldStatus = []; 
    // Re-generate basic field status for UI feedback (could be moved to service too)
    foreach ($extracted as $k => $v) {
        if (in_array($k, ['guarantee_number', 'amount', 'supplier', 'bank', 'expiry_date', 'contract_number'])) {
            $fieldStatus[$k] = $v ? '✅' : '❌';
        }
    }

    // 4. Validate
    $missing = $service->validate($extracted);
    
    // Log attempt
    ParsingHelpers::logPasteAttempt($text, array_merge($extracted, ['field_status' => $fieldStatus]), empty($missing), empty($missing) ? null : "Missing fields");

    if (!empty($missing)) {
        echo json_encode([
            'success' => false,
            'error' => "بيانات غير مكتملة",
            'extracted' => $extracted,
            'field_status' => $fieldStatus,
            'missing_fields' => $missing
        ]);
        http_response_code(400);
        exit;
    }

    // 5. Save (Single)
    // Reuse the processTableRow logic for single row consistency, or manual save?
    // Let's use processTableRow logic adapted for single row or just recreate logic here for precision.
    // For "Full Architecture", we should delegate saving to the Service or Repository too.
    // But ParsingHelpers::processTableRow handles saving. Let's adapt it.
    
    $rowData = $extracted; // Compatible structure
    $saveResult = ParsingHelpers::processTableRow($rowData, $text, $repo);
    
    // Auto-match single
    if (!$saveResult['exists_before']) {
        try {
            $processor = new \App\Services\SmartProcessingService();
            $processor->processNewGuarantees(1);
        } catch (\Throwable $e) {}
    }

    echo json_encode([
        'success' => true,
        'id' => $saveResult['id'],
        'extracted' => $extracted,
        'field_status' => $fieldStatus,
        'exists_before' => $saveResult['exists_before'],
        'intent' => $extracted['intent'],
        'message' => $saveResult['exists_before'] ? 'تم العثور على الضمان' : 'تم إنشاء ضمان جديد بنجاح'
    ]);

} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
