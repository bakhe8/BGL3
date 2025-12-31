<?php
/**
 * V3 API - Enhanced Smart Paste Parse (Text Analysis)
 * 🔥 Enhanced with comprehensive logging and improved extraction patterns
 * Extracts guarantee details from unstructured text
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Models/Guarantee.php';
require_once __DIR__ . '/../app/Repositories/GuaranteeRepository.php';
require_once __DIR__ . '/../app/Services/TimelineRecorder.php';
require_once __DIR__ . '/../app/Support/autoload.php'; // 🆕 Load all services/repositories

use App\Repositories\GuaranteeRepository;
use App\Models\Guarantee;
use App\Support\Database;

header('Content-Type: application/json; charset=utf-8');

// ============================================================================
// HELPER: Enhanced Logging Function
// ============================================================================
function logPasteAttempt($text, $extracted, $success, $error = null) {
    $logFile = __DIR__ . '/../storage/paste_debug.log';
    $timestamp = date('Y-m-d H:i:s');
    
    $logEntry = "\n" . str_repeat("=", 80) . "\n";
    $logEntry .= "PASTE ATTEMPT @ {$timestamp}\n";
    $logEntry .= str_repeat("=", 80) . "\n";
    $logEntry .= "STATUS: " . ($success ? "✅ SUCCESS" : "❌ FAILED") . "\n";
    if ($error) {
        $logEntry .= "ERROR: {$error}\n";
    }
    $logEntry .= "\n--- ORIGINAL TEXT ---\n{$text}\n";
    $logEntry .= "\n--- EXTRACTED DATA ---\n";
    $logEntry .= json_encode($extracted, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    $logEntry .= str_repeat("=", 80) . "\n";
    
    file_put_contents($logFile, $logEntry, FILE_APPEND);
}

// ============================================================================
// HELPER: Multi-Pattern Extraction (tries multiple patterns in order)
// ============================================================================
function extractWithPatterns($text, $patterns, $fieldName) {
    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $text, $m)) {
            $value = trim($m[1]);
            error_log("✅ [{$fieldName}] Matched with pattern: {$pattern} => {$value}");
            return $value;
        }
    }
    error_log("❌ [{$fieldName}] No match found in " . count($patterns) . " patterns");
    return null;
}

// ============================================================================
// HELPER: Smart Table Parser for TAB-separated data (Multi-row support)
// ============================================================================
function parseTabularData($text) {
    $lines = explode("\n", $text);
    $allRows = []; // Array to hold all detected table rows
    
    foreach ($lines as $line) {
        // Look for lines with multiple TABs (likely table rows)
        if (substr_count($line, "\t") >= 4) {
            $columns = explode("\t", $line);
            $columns = array_map('trim', $columns);
            
            // Initialize row data
            $rowData = [
                'supplier' => null,
                'guarantee_number' => null,
                'bank' => null,
                'amount' => null,
                'expiry_date' => null,
                'contract_number' => null,
            ];
            
            error_log("🔍 [TABLE] Analyzing row with " . count($columns) . " columns");
            
            // Analyze each column to identify its type
            foreach ($columns as $col) {
                if (empty($col)) continue;
                
                // Skip row numbers at start
                if (strlen($col) <= 3 && is_numeric($col)) continue;
                
                // Detect AMOUNT: Numbers with commas or decimals
                if (preg_match('/^[0-9,]+(\.[0-9]{2})?$/', $col) && !$rowData['amount']) {
                    $rowData['amount'] = $col;
                    continue;
                }
                
                // Detect DATE: Various date formats
                if (preg_match('/^[0-9]{1,2}[-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[-\/][0-9]{4}$/i', $col) && !$rowData['expiry_date']) {
                    $rowData['expiry_date'] = $col;
                    continue;
                }
                if (preg_match('/^[0-9]{1,2}[-\/][0-9]{1,2}[-\/][0-9]{4}$/', $col) && !$rowData['expiry_date']) {
                    $rowData['expiry_date'] = $col;
                    continue;
                }
                if (preg_match('/^[0-9]{4}[-\/][0-9]{1,2}[-\/][0-9]{1,2}$/', $col) && !$rowData['expiry_date']) {
                    $rowData['expiry_date'] = $col;
                    continue;
                }
                
                // Detect BANK CODE
                // Validation: Must be short (< 60 chars) and reasonably clean
                $cleanCol = trim(preg_replace('/\s+/', ' ', $col));
                
                if (strlen($cleanCol) < 60) {
                    // Exact match with known bank codes (including SAB)
                    if (preg_match('/^(SNB|ANB|SAB|SABB|NCB|RIBL|SAMBA|BSF|ALRAJHI|ALINMA|BNP\s*PARIBAS|BANQUE\s*SAUDI\s*FRANSI|BSF)$/i', $cleanCol) && !$rowData['bank']) {
                        $rowData['bank'] = $cleanCol;
                        continue;
                    }
                    // Pattern for bank names containing "BANK" or "BANQUE"
                    if (preg_match('/\b(BANK|BANQUE|ALRAJHI|ALINMA)\b/i', $cleanCol) && !$rowData['bank']) {
                        // Extra check: prevent capturing sentences containing "bank"
                        if (str_word_count($cleanCol) < 10) {
                            $rowData['bank'] = $cleanCol;
                            continue;
                        }
                    }
                    // Fallback: Short uppercase codes (2-5 letters) likely to be bank codes
                    // This catches unrecognized banks like "SAB", "RBS", etc.
                    if (preg_match('/^[A-Z]{2,5}$/', $cleanCol) && !$rowData['bank']) {
                        $rowData['bank'] = $cleanCol;
                        continue;
                    }
                }
                
                // Detect GUARANTEE NUMBER (prioritize longer codes, handle letters at end)
                if (preg_match('/^[A-Z0-9]{10,}$/i', $col) && !$rowData['guarantee_number']) {
                    $rowData['guarantee_number'] = $col;
                    continue;
                }
                if (preg_match('/^[A-Z]{3,4}[0-9]{6,}[A-Z]?$/i', $col) && !$rowData['guarantee_number']) {
                    $rowData['guarantee_number'] = $col;
                    continue;
                }
                if (preg_match('/^[0-9]{6,}[A-Z]$/i', $col) && !$rowData['guarantee_number']) {
                    $rowData['guarantee_number'] = $col;
                    continue;
                }
                if (preg_match('/^[A-Z]{1,2}[0-9]{6,}$/i', $col) && !$rowData['guarantee_number']) {
                    $rowData['guarantee_number'] = $col;
                    continue;
                }
                
                // Detect CONTRACT NUMBER
                if (preg_match('/^[A-Z]+\/[A-Z0-9]{4,}\/[0-9]{2}$/i', $col) && !$rowData['contract_number']) {
                    $rowData['contract_number'] = $col;
                    continue;
                }
                if (preg_match('/^(PO|CNT|C)-[0-9]+/i', $col) && !$rowData['contract_number']) {
                    $rowData['contract_number'] = $col;
                    continue;
                }
                
                // Detect SUPPLIER: Text that isn't already categorized
                // Must contain letters and be reasonable length
                // Detect SUPPLIER: Text that isn't already categorized
                // Must contain letters and be reasonable length (but not huge garbage)
                $cleanSupp = trim(preg_replace('/\s+/', ' ', $col));
                if (!$rowData['supplier'] && preg_match('/[A-Za-zء-ي]/', $col) && strlen($cleanSupp) >= 8 && strlen($cleanSupp) < 100) {
                    // Skip if it looks like a date, amount, code, or bank we already have
                    if (!preg_match('/^[0-9,\.]+$/', $cleanSupp) && 
                        !preg_match('/^[A-Z0-9]{1,4}[0-9]+[A-Z]?$/i', $cleanSupp) &&
                        !preg_match('/[0-9]{1,2}[-\/][A-Za-z0-9]{1,3}[-\/][0-9]{2,4}/', $cleanSupp) &&
                        !preg_match('/(BANK|BANQUE)/i', $cleanSupp) &&
                        strpos($cleanSupp, '<') === false) { // Avoid HTML tags
                        
                        $rowData['supplier'] = $cleanSupp;
                        continue;
                    }
                }
            }
            
            // If we found enough data, add this row
            if ($rowData['guarantee_number'] && $rowData['amount']) {
                error_log("✅ [TABLE] Valid row found - G#: {$rowData['guarantee_number']}, Amount: {$rowData['amount']}");
                $allRows[] = $rowData;
            }
        }
    }
    
    if (count($allRows) > 0) {
        error_log("🎯 [TABLE] Total rows detected: " . count($allRows));
        return $allRows;
    }
    
    return null;
}


// ============================================================================
// HELPER: Process single table row and create guarantee
// ============================================================================
function processTableRow($rowData, $text, $repo) {
    // Convert date format if needed (month name format)
    $expiryDate = $rowData['expiry_date'];
    if ($expiryDate && preg_match('/([0-9]{1,2})[-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[-\/]([0-9]{4})/i', $expiryDate, $m)) {
        $months = ['jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
                   'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'];
        $month = $months[strtolower($m[2])];
        $expiryDate = $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
    }
    
    // Parse amount
    $amount = null;
    if ($rowData['amount']) {
        $amountStr = str_replace(',', '', $rowData['amount']);
        $amount = (float)$amountStr;
    }
    
    // Check if exists
    $existing = $repo->findByNumber($rowData['guarantee_number']);
    if ($existing) {
        // Record duplicate import event
        try {
            \App\Services\TimelineRecorder::recordDuplicateImportEvent($existing->id, 'smart_paste');
        } catch (\Throwable $t) {
            error_log("Failed to record duplicate import: " . $t->getMessage());
        }
        
        return [
            'id' => $existing->id,
            'guarantee_number' => $rowData['guarantee_number'],
            'exists_before' => true
        ];
    }
    
    // Create new
    $rawData = [
        'bg_number' => $rowData['guarantee_number'],
        'supplier' => $rowData['supplier'],
        'bank' => $rowData['bank'],
        'amount' => $amount,
        'expiry_date' => $expiryDate,
        'contract_number' => $rowData['contract_number'],
        'type' => 'ابتدائي',
        'source' => 'smart_paste_multi',
        'original_text' => $text
    ];
    
    $guaranteeModel = new Guarantee(
        id: null,
        guaranteeNumber: $rowData['guarantee_number'],
        rawData: $rawData,
        importSource: 'Smart Paste (Multi)',
        importedAt: date('Y-m-d H:i:s'),
        importedBy: 'Web User'
    );
    
    $saved = $repo->create($guaranteeModel);
    
    // 🔔 RECORD HISTORY EVENT
    try {
        \App\Services\TimelineRecorder::recordImportEvent($saved->id, 'smart_paste');
    } catch (\Throwable $t) {
        error_log("Failed to record history: " . $t->getMessage());
    }
    
    return [
        'id' => $saved->id,
        'guarantee_number' => $rowData['guarantee_number'],
        'supplier' => $rowData['supplier'],
        'amount' => $amount,
        'exists_before' => false
    ];
}


// ============================================================================
// MAIN PROCESSING
// ============================================================================
try {
    $input = json_decode(file_get_contents('php://input'), true);
    $text = $input['text'] ?? '';

    if (empty($text)) {
        throw new \RuntimeException("لم يتم إدخال أي نص للتحليل");
    }

    // Initialize extraction result
    $extracted = [
        'guarantee_number' => null,
        'amount' => null,
        'currency' => 'SAR',
        'supplier' => null,
        'bank' => null,
        'expiry_date' => null,
        'issue_date' => null,
        'contract_number' => null,
        'type' => 'ابتدائي',
        'intent' => null, // Will be detected if mentioned in text
        'source_text' => $text
    ];

    // Field tracking for detailed feedback
    $fieldStatus = [];

    // ========================================================================
    // 📊 MULTI-ROW TABLE DETECTION: Handle multiple guarantees in one paste
    // ========================================================================
    $tableRows = parseTabularData($text);
    
    if ($tableRows && is_array($tableRows) && count($tableRows) > 1) {
        // Multi-row detected! Process each separately
        error_log("🎯 [MULTI] Processing " . count($tableRows) . " guarantees from multi-row table");
        
        $db = Database::connect();
        $repo = new GuaranteeRepository($db);
        
        $results = [];
        foreach ($tableRows as $rowData) {
            try {
                $result = processTableRow($rowData, $text, $repo);
                $results[] = $result;
                error_log("✅ [MULTI] Processed G#: {$result['guarantee_number']} - " . ($result['exists_before'] ? 'Exists' : 'Created'));
            } catch (\Exception $e) {
                error_log("❌ [MULTI] Failed to process G#: {$rowData['guarantee_number']} - " . $e->getMessage());
                $results[] = [
                    'guarantee_number' => $rowData['guarantee_number'],
                    'error' => $e->getMessage(),
                    'failed' => true
                ];
            }
        }
        
        // ✨ AUTO-MATCHING: Apply Smart Processing to all new guarantees
        try {
            $processor = new \App\Services\SmartProcessingService();
            $autoMatchStats = $processor->processNewGuarantees(count($results));
            error_log("✅ Smart Paste auto-matched: {$autoMatchStats['auto_matched']} out of " . count($results));
        } catch (\Throwable $e) {
            error_log("Auto-matching failed (non-critical): " . $e->getMessage());
        }
        
        // Return multi-row success response
        echo json_encode([
            'success' => true,
            'multi' => true,
            'count' => count($results),
            'results' => $results,
            'message' => "تم استيراد " . count($results) . " ضمان بنجاح"
        ]);
        exit;
    }
    
    // ========================================================================
    // SINGLE ROW OR NON-TABLE: Continue with existing logic
    // ========================================================================
    
    // Detect intent (for future use - not actionable)
    $extracted['intent'] = null;
    if (preg_match('/تمديد|extend|extension|للتمديد|لتمديد/iu', $text)) {
        $extracted['intent'] = 'extension';
        error_log("🔄 [INTENT] Detected: EXTENSION (logged only, no action)");
    }
    elseif (preg_match('/تخفيض|reduce|reduction|للتخفيض|لتخفيض/iu', $text)) {
        $extracted['intent'] = 'reduction';
        error_log("📉 [INTENT] Detected: REDUCTION (logged only, no action)");
    }
    elseif (preg_match('/إفراج|افراج|release|cancel|للإفراج|لإفراج/iu', $text)) {
        $extracted['intent'] = 'release';
        error_log("🔓 [INTENT] Detected: RELEASE (logged only, no action)");
    }
    else {
        error_log("📥 [INTENT] None detected - pure data import");
    }
    
    // If single row detected from table, use it
    if ($tableRows && is_array($tableRows) && count($tableRows) === 1) {
        $tableData = $tableRows[0];
        error_log("🎯 [TABLE] Using single row from table");
        if ($tableData['supplier']) $extracted['supplier'] = $tableData['supplier'];
        if ($tableData['guarantee_number']) $extracted['guarantee_number'] = $tableData['guarantee_number'];
        if ($tableData['bank']) $extracted['bank'] = $tableData['bank'];
        if ($tableData['amount']) {
            $amountStr = str_replace(',', '', $tableData['amount']);
            $extracted['amount'] = (float)$amountStr;
        }
        if ($tableData['expiry_date']) $extracted['expiry_date'] = $tableData['expiry_date'];
        if ($tableData['contract_number']) $extracted['contract_number'] = $tableData['contract_number'];
    }

    // ========================================================================
    // Continue with regex-based extraction for missing fields...
    // ========================================================================

    // ========================================================================
    // 1. GUARANTEE NUMBER - Multiple Patterns (only if not found in table)
    // ========================================================================
    if (!$extracted['guarantee_number']) {
        $guaranteePatterns = [
            // Pattern 1: REF/LG/NO followed by alphanumeric
            '/(?:REF|LG|NO|رقم|الرقم|ر\.ض)[:\s\-#]*([A-Z0-9\-\/]{4,25})/iu',
            // Pattern 2: Specific formats like 040XXXXXX
            '/\b(040[A-Z0-9]{5,})\b/i',
            // Pattern 3: G- or BG- prefix
            '/\b([GB]G?[\-\s]?[A-Z0-9]{5,20})\b/i',
            // Pattern 4: B followed by 6 digits (e.g., B323790)
            '/\b(B[0-9]{6,})\b/i',
            // Pattern 5: Just uppercase alphanumeric strings
            '/\b([A-Z]{2,}[0-9]{4,}[A-Z0-9]*)\b/',
            // Pattern 6: Arabic "رقم الضمان" followed by value
            '/رقم\s*الضمان[:\s]*([A-Z0-9\-\/]+)/iu',
        ];
        
        $extracted['guarantee_number'] = extractWithPatterns($text, $guaranteePatterns, 'GUARANTEE_NUMBER');
    }
    $fieldStatus['guarantee_number'] = $extracted['guarantee_number'] ? '✅' : '❌';

    // ========================================================================
    // 2. AMOUNT - Enhanced with multiple formats
    // ========================================================================
    $amountPatterns = [
        // Pattern 1: With explicit keywords (Amount, مبلغ, Value, SAR)
        '/(?:Amount|مبلغ|القيمة|value|SAR|SR|ر\.س|ريال)[:\s]*([0-9,]+(?:\.[0-9]{2})?)/iu',
        // Pattern 2: Number followed by currency
        '/([0-9,]+(?:\.[0-9]{2})?)\s*(?:SAR|SR|ر\.س|ريال)/iu',
        // Pattern 3: Large numbers (likely amounts) with thousand separators
        '/\b([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{2})?)\b/',
        // Pattern 4: Simple large numbers without separators
        '/\b([0-9]{5,}(?:\.[0-9]{2})?)\b/',
    ];
    
    $amountStr = extractWithPatterns($text, $amountPatterns, 'AMOUNT');
    if ($amountStr) {
        $extracted['amount'] = (float)str_replace(',', '', $amountStr);
        $fieldStatus['amount'] = '✅';
    } else {
        $fieldStatus['amount'] = '❌';
    }

    // ========================================================================
    // 3. EXPIRY DATE - Multiple date formats
    // ========================================================================
    $expiryPatterns = [
        // Pattern 1: YYYY-MM-DD or YYYY/MM/DD
        '/(?:Expiry|Until|تاريخ|انتهاء|الانتهاء|ينتهي)[:\s]*([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})/iu',
        // Pattern 2: DD-MM-YYYY or DD/MM/YYYY
        '/(?:Expiry|Until|تاريخ|انتهاء|الانتهاء|ينتهي)[:\s]*([0-9]{1,2}[\-\/][0-9]{1,2}[\-\/][0-9]{4})/iu',
        // Pattern 3: Date with month name (6-Jan-2026, 15-Dec-2025)
        '/\b([0-9]{1,2}[\-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\-\/][0-9]{4})\b/i',
        // Pattern 4: Just dates in YYYY-MM-DD format
        '/\b([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})\b/',
        // Pattern 5: Just dates in DD-MM-YYYY format (will need conversion)
        '/\b([0-9]{1,2}[\-\/][0-9]{1,2}[\-\/][0-9]{4})\b/',
    ];
    
    $dateStr = extractWithPatterns($text, $expiryPatterns, 'EXPIRY_DATE');
    if ($dateStr) {
        // Convert month name format to YYYY-MM-DD
        if (preg_match('/([0-9]{1,2})[\-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\-\/]([0-9]{4})/i', $dateStr, $m)) {
            $months = ['jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
                       'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'];
            $month = $months[strtolower($m[2])];
            $extracted['expiry_date'] = $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        } else {
            // Normalize other formats
            $extracted['expiry_date'] = str_replace('/', '-', $dateStr);
        }
        $fieldStatus['expiry_date'] = '✅';
    } else {
        $fieldStatus['expiry_date'] = '❌';
    }

    // ========================================================================
    // 4. ISSUE DATE - Similar patterns to expiry
    // ========================================================================
    $issueDatePatterns = [
        '/(?:Issue|Issued|تاريخ\s*الإصدار|صدر|إصدار)[:\s]*([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})/iu',
        '/(?:Issue|Issued|تاريخ\s*الإصدار|صدر|إصدار)[:\s]*([0-9]{1,2}[\-\/][0-9]{1,2}[\-\/][0-9]{4})/iu',
    ];
    
    $issueDateStr = extractWithPatterns($text, $issueDatePatterns, 'ISSUE_DATE');
    if ($issueDateStr) {
        $extracted['issue_date'] = str_replace('/', '-', $issueDateStr);
        $fieldStatus['issue_date'] = '✅';
    } else {
        $fieldStatus['issue_date'] = '⚠️'; // Optional field
    }

    // ========================================================================
    // 5. SUPPLIER - Enhanced patterns (only if not found in table)
    // ========================================================================
    if (!$extracted['supplier']) {
        $supplierPatterns = [
            '/(?:Supplier|Beneficiary|المورد|المستفيد|لصالح)[:\s]*([^\n\r]+)/iu',
            '/(?:لصالح|ل\s*صالح)[:\s]*([^\n\r]+)/iu',
            '/(?:شركة)\s+([^\n\r،,\.]+)/iu', // Company + name
            // Pattern for TAB-separated table: Look for long English text before TAB and alphanumeric
            '/^([A-Z][A-Z\s&]+COMPANY)\s*\t/im',
            '/^([A-Z][A-Z\s&]+(?:COMPANY|CO\.|LTD|LLC|CORPORATION))\s*\t/im',
        ];
        
        $supplierStr = extractWithPatterns($text, $supplierPatterns, 'SUPPLIER');
        if ($supplierStr) {
            // Clean up supplier name (remove extra spaces, trailing punctuation)
            $extracted['supplier'] = preg_replace('/[،,\.]+$/', '', trim($supplierStr));
        }
    }
    $fieldStatus['supplier'] = $extracted['supplier'] ? '✅' : '❌';

    // ========================================================================
    // 6. BANK - Enhanced patterns (only if not found in table)
    // ========================================================================
    if (!$extracted['bank']) {
        $bankPatterns = [
            '/(?:Bank|البنك|بنك|مصرف)[:\s]*([^\n\r]+)/iu',
            '/(?:من|عبر)\s*(?:بنك|البنك)\s+([^\n\r،,\.]+)/iu',
            // Pattern for TAB-separated: Look for bank code like SNB, ANB, SABB after TABs
            '/\t([A-Z]{2,4})\t[0-9,]+/i',
            // Common Saudi bank codes
            '/\b(SNB|ANB|SABB|NCB|RIBL|SAMBA|BSF|ALRAJHI|ALINMA)\b/i',
        ];
        
        $bankStr = extractWithPatterns($text, $bankPatterns, 'BANK');
        if ($bankStr) {
            $extracted['bank'] = preg_replace('/[،,\.]+$/', '', trim($bankStr));
        }
    }
    $fieldStatus['bank'] = $extracted['bank'] ? '✅' : '❌';

    // ========================================================================
    // 7. CONTRACT NUMBER - Enhanced patterns
    // ========================================================================
    $contractPatterns = [
        // From subject/title line (e.g. "إفراج عن ضمان C/0061/43")
        '/^[^\n]*\b(C\/[A-Z]?[0-9]{4}\/[0-9]{2})\b/im',
        // Standard Labels
        '/(?:Contract|PO|Order|العقد|الشراء|أمر\s*الشراء|رقم\s*العقد)[:\s#]*([A-Z0-9\-\/]+)/iu',
        '/(?:عقد|ع\.ر)[:\s#]*([A-Z0-9\-\/]+)/iu',
        // Specific Formats (PO-123, C/123/22)
        '/\b([CP]O[\-\/][0-9]{4,})\b/i',
        '/\b(C\/[0-9]{4}\/[0-9]{2})\b/i',
    ];
    
    $contractStr = extractWithPatterns($text, $contractPatterns, 'CONTRACT_NUMBER');
    if ($contractStr) {
        $extracted['contract_number'] = trim($contractStr);
        $fieldStatus['contract_number'] = '✅';
    } else {
        $fieldStatus['contract_number'] = '❌';
    }

    // ========================================================================
    // 8. TYPE - Try to detect if it's initial (ابتدائي) or final (نهائي)
    // ========================================================================
    if (preg_match('/نهائي|final|performance/iu', $text)) {
        $extracted['type'] = 'نهائي';
    } elseif (preg_match('/ابتدائي|initial|bid/iu', $text)) {
        $extracted['type'] = 'ابتدائي';
    }
    // Default is already set to 'ابتدائي'

    // ========================================================================
    // VALIDATION: Check for mandatory fields
    // ========================================================================
    $missing = [];
    if (!$extracted['guarantee_number']) $missing[] = "رقم الضمان";
    if (!$extracted['supplier']) $missing[] = "اسم المورد";
    if (!$extracted['bank']) $missing[] = "اسم البنك";
    if (!$extracted['amount']) $missing[] = "القيمة";
    if (!$extracted['expiry_date']) $missing[] = "تاريخ الانتهاء";
    if (!$extracted['contract_number']) $missing[] = "رقم العقد";

    // Log the attempt
    $success = empty($missing);
    $error = $success ? null : "بيانات غير مكتملة. الحقول الناقصة: " . implode(', ', $missing);
    logPasteAttempt($text, array_merge($extracted, ['field_status' => $fieldStatus]), $success, $error);

    if (!empty($missing)) {
        // Return detailed error with field status
        echo json_encode([
            'success' => false,
            'error' => $error,
            'extracted' => $extracted,
            'field_status' => $fieldStatus,
            'missing_fields' => $missing
        ]);
        http_response_code(400);
        exit;
    }

    // ========================================================================
    // DATABASE: Check if exists, or create new
    // ========================================================================
    $foundId = null;
    $existsBefore = false;
    
    $db = Database::connect();
    $repo = new GuaranteeRepository($db);
    $existing = $repo->findByNumber($extracted['guarantee_number']);
    
    if ($existing) {
        $existsBefore = true;
        $foundId = $existing->id;
        
        // Record duplicate import event
        try {
            \App\Services\TimelineRecorder::recordDuplicateImportEvent($existing->id, 'smart_paste');
        } catch (\Throwable $t) {
            error_log("Failed to record duplicate import: " . $t->getMessage());
        }
    } else {
        // Create new guarantee
        $rawData = [
            'bg_number' => $extracted['guarantee_number'],
            'supplier' => $extracted['supplier'],
            'bank' => $extracted['bank'],
            'amount' => $extracted['amount'],
            'expiry_date' => $extracted['expiry_date'],
            'issue_date' => $extracted['issue_date'],
            'contract_number' => $extracted['contract_number'],
            'type' => $extracted['type'],
            'source' => 'smart_paste',
            'original_text' => $text,
            'detected_intent' => $extracted['intent'] // Store intent
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
        
        // 🔔 RECORD HISTORY EVENT
        try {
            \App\Services\TimelineRecorder::recordImportEvent($saved->id, 'smart_paste');
        } catch (\Throwable $t) {
            error_log("Failed to record history: " . $t->getMessage());
        }
        
        // ✨ AUTO-MATCHING: Apply Smart Processing
        try {
            $processor = new \App\Services\SmartProcessingService();
            $autoMatchStats = $processor->processNewGuarantees(1);
            
            if ($autoMatchStats['auto_matched'] > 0) {
                error_log("✅ Smart Paste auto-matched: Guarantee #{$saved->id}");
            }
        } catch (\Throwable $e) {
            error_log("Auto-matching failed (non-critical): " . $e->getMessage());
        }
    }

    // Success response
    echo json_encode([
        'success' => true,
        'id' => $foundId,
        'extracted' => $extracted,
        'field_status' => $fieldStatus,
        'exists_before' => $existsBefore,
        'intent' => $extracted['intent'], // Include intent in response
        'message' => $existsBefore ? 'تم العثور على الضمان' : 'تم إنشاء ضمان جديد بنجاح'
    ]);

} catch (\Throwable $e) {
    // Log error
    logPasteAttempt(
        $input['text'] ?? 'NO TEXT',
        $extracted ?? [],
        false,
        $e->getMessage()
    );
    
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        'extracted' => $extracted ?? [],
        'field_status' => $fieldStatus ?? []
    ]);
}
