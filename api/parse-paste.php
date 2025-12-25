<?php
/**
 * V3 API - Smart Paste Parse (Text Analysis)
 * Extracts guarantee details from unstructured text
 */

require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../lib/TimelineHelper.php';

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
            // Maybe update extracted with DB data? No, user wants to see what failed or passed
        } else {
            // Auto-create? Typically 'Smart Paste' just fills the form for review.
            // But modal-handlers.js logic (line 100) says:
            // if (data.success) { alert('Success'); reload(); }
            // This implies the API creates the record!
            
            // So we should Create it if sufficient data, or return extracted data for frontend form?
            // "handleManualEntry" does fetch('create-guarantee.php').
            // "handlePasteData" does fetch('parse-paste.php').
            
            // Looking at modal-handlers.js: 
            // if (data.success) { alert(`تم استخراج البيانات بنجاح`); window.location.reload(); }
            // This suggests it creates it.
            
            // Let's create it if we have at least Guarantee Number + Amount
            if ($extracted['amount'] && $extracted['guarantee_number']) {
                 // Create Logic similar to create-guarantee.php
                 // reuse repositories...
                 
                 // Reuse GuaranteeRepository logic
                 $rawData = [
                    'bg_number' => $extracted['guarantee_number'],
                    'supplier' => $extracted['supplier'] ?? 'Unknown',
                    'bank' => $extracted['bank'] ?? 'Unknown',
                    'amount' => $extracted['amount'],
                    'expiry_date' => $extracted['expiry_date'],
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
            } else {
                // Return success=false but with extracted data? (JS doesn't support filling form yet, just reload)
                // modal-handlers.js reload() implies it expects a save.
                
                throw new \RuntimeException("لم يتم العثور على رقم الضمان والمبلغ في النص. يرجى التأكد من التنسيق.");
            }
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
