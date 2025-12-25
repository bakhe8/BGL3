<?php
/**
 * V3 API - Smart Paste Parse (Text Analysis)
 * Extracts guarantee details from unstructured text
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';

use App\Repositories\GuaranteeRepository;
use App\Models\Guarantee;
use App\Support\Database;

header('Content-Type: application/json; charset=utf-8');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    $text = $input['text'] ?? '';

    if (empty($text)) {
        throw new \RuntimeException("No text provided");
    }

    // --- Extraction Logic ---
    $extracted = [
        'guarantee_number' => null,
        'amount' => null,
        'currency' => 'SAR',
        'supplier' => null,
        'bank' => null,
        'expiry_date' => null,
        'source_text' => $text
    ];

    // 1. Guarantee Number (Common patterns: LG-..., 040..., G..., REF:...)
    // Pattern: 3+ alphanumerics, often uppercase, maybe dashes/slashes
    if (preg_match('/(?:REF|LG|NO|:|رقم)[\s:\-#]*([A-Z0-9\-\/]{5,20})/iu', $text, $m)) {
        $extracted['guarantee_number'] = trim($m[1]);
    } elseif (preg_match('/\b(040[A-Z0-9]{5,})\b/', $text, $m)) {
        // Specific bank format example
        $extracted['guarantee_number'] = $m[1];
    }

    // 2. Amount (Look for numbers with thousands separators)
    if (preg_match('/(?:Amount|مبلغ|value|SAR|SR|ر.س)[:\s]*([0-9,]+(\.[0-9]{2})?)/iu', $text, $m)) {
        $amountStr = str_replace(',', '', $m[1]);
        $extracted['amount'] = (float)$amountStr;
    }

    // 3. Expiry Date
    if (preg_match('/(?:Expiry|Until|تاريخ|انتهاء)[:\s]*([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})/u', $text, $m)) {
        $extracted['expiry_date'] = str_replace('/', '-', $m[1]);
    }

    // 4. Supplier/Bank - simplistic heuristic
    // Look for lines starting with "Supplier:" or "Bank:"
    if (preg_match('/(?:Supplier|Beneficiary|مورد|مستفيد)[:\s]*([^\n\r]+)/iu', $text, $m)) {
        $extracted['supplier'] = trim($m[1]);
    }
    if (preg_match('/(?:Bank|بنك|مصرف)[:\s]*([^\n\r]+)/iu', $text, $m)) {
        $extracted['bank'] = trim($m[1]);
    }


    // 5. Contract Number (PO or Contract)
    if (preg_match('/(?:Contract|PO|Order|العقد|الشراء)[:\s]*([A-Z0-9\-\/]+)/iu', $text, $m)) {
        $extracted['contract_number'] = trim($m[1]);
    } else {
        $extracted['contract_number'] = null;
    }

    // --- Database Check ---
    // If guarantee number found, check if exists
    $foundId = null;
    $exists = false;
    
    if ($extracted['guarantee_number']) {
        $db = Database::connect();
        $repo = new GuaranteeRepository($db);
        $existing = $repo->findByNumber($extracted['guarantee_number']);
        
        if ($existing) {
            $exists = true;
            $foundId = $existing->id;
        } else {
            // STRICT VALIDATION: All 5 Mandatory Fields
            $missing = [];
            if (!$extracted['supplier']) $missing[] = "اسم المورد";
            if (!$extracted['bank']) $missing[] = "اسم البنك";
            if (!$extracted['amount']) $missing[] = "القيمة";
            if (!$extracted['expiry_date']) $missing[] = "تاريخ الانتهاء";
            if (!$extracted['contract_number']) $missing[] = "رقم العقد/أمر الشراء";

            if (!empty($missing)) {
                throw new \RuntimeException("بيانات غير مكتملة. الحقول الناقصة: " . implode(', ', $missing));
            }

            // Create Logic
             $rawData = [
                'bg_number' => $extracted['guarantee_number'],
                'supplier' => $extracted['supplier'],
                'bank' => $extracted['bank'],
                'amount' => $extracted['amount'],
                'expiry_date' => $extracted['expiry_date'],
                'contract_number' => $extracted['contract_number'],
                'source' => 'smart_paste',
                'original_text' => $text
             ];
             
             $guaranteeModel = new Guarantee(
                id: null,
                guaranteeNumber: $extracted['guarantee_number'],
                rawData: $rawData,
                importSource: 'Smart Paste',
                importedAt: date('Y-m-d H:i:s'),
                importedBy: 'Web User'
             );
             
             $saved = $repo->create($guaranteeModel);
             $foundId = $saved->id;
             $exists = true;
        }
    } else {
        throw new \RuntimeException("لم يتم العثور على رقم الضمان في النص.");
    }

    echo json_encode([
        'success' => true, 
        'id' => $foundId, 
        'extracted' => $extracted,
        'exists_before' => $exists
    ]);

} catch (\Throwable $e) {
    http_response_code(400); // Bad Request
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
