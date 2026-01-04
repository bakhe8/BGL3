<?php
namespace App\Support;

use App\Repositories\GuaranteeRepository;
use App\Models\Guarantee;
use App\Support\Database;

/**
 * Parsing Helpers - Helper functions for Smart Paste
 * 
 * يحتوي على منطق تحليل النصوص والجداول المستخرج من parse-paste.php
 * 
 * @created 2026-01-04
 */
class ParsingHelpers {

    /**
     * Regex Patterns for Extraction
     */
    public static function getPatterns(): array {
        return [
            'guarantee_number' => [
                '/(?:REF|LG|NO|رقم|الرقم|ر\.ض)[:\s\-#]*([A-Z0-9\-\/]{4,25})/iu',
                '/\b(040[A-Z0-9]{5,})\b/i',
                '/\b([GB]G?[\-\s]?[A-Z0-9]{5,20})\b/i',
                '/\b(B[0-9]{6,})\b/i',
                '/\b([A-Z]{2,}[0-9]{4,}[A-Z0-9]*)\b/',
                '/رقم\s*الضمان[:\s]*([A-Z0-9\-\/]+)/iu',
            ],
            'amount' => [
                '/(?:Amount|مبلغ|القيمة|value|SAR|SR|ر\.س|ريال)[:\s]*([0-9,]+(?:\.[0-9]{2})?)/iu',
                '/([0-9,]+(?:\.[0-9]{2})?)\s*(?:SAR|SR|ر\.س|ريال)/iu',
                '/\b([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{2})?)\b/',
                '/\b([0-9]{5,}(?:\.[0-9]{2})?)\b/',
            ],
            'expiry_date' => [
                '/(?:Expiry|Until|تاريخ|انتهاء|الانتهاء|ينتهي)[:\s]*([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})/iu',
                '/(?:Expiry|Until|تاريخ|انتهاء|الانتهاء|ينتهي)[:\s]*([0-9]{1,2}[\-\/][0-9]{1,2}[\-\/][0-9]{4})/iu',
                '/\b([0-9]{1,2}[\-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\-\/][0-9]{4})\b/i',
                '/\b([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})\b/',
                '/\b([0-9]{1,2}[\-\/][0-9]{1,2}[\-\/][0-9]{4})\b/',
            ],
            'issue_date' => [
                '/(?:Issue|Issued|تاريخ\s*الإصدار|صدر|إصدار)[:\s]*([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})/iu',
                '/(?:Issue|Issued|تاريخ\s*الإصدار|صدر|إصدار)[:\s]*([0-9]{1,2}[\-\/][0-9]{1,2}[\-\/][0-9]{4})/iu',
            ],
            'supplier' => [
                '/(?:Supplier|Beneficiary|المورد|المستفيد|لصالح)[:\s]*([^\n\r]+)/iu',
                '/(?:لصالح|ل\s*صالح)[:\s]*([^\n\r]+)/iu',
                '/(?:شركة)\s+([^\n\r،,\.]+)/iu',
                '/^([A-Z][A-Z\s&]+COMPANY)\s*\t/im',
                '/^([A-Z][A-Z\s&]+(?:COMPANY|CO\.|LTD|LLC|CORPORATION))\s*\t/im',
            ],
            'bank' => [
                '/(?:Bank|البنك|بنك|مصرف)[:\s]*([^\n\r]+)/iu',
                '/(?:من|عبر)\s*(?:بنك|البنك)\s+([^\n\r،,\.]+)/iu',
                '/\t([A-Z]{2,4})\t[0-9,]+/i',
                '/\b(SNB|ANB|SABB|NCB|RIBL|SAMBA|BSF|ALRAJHI|ALINMA)\b/i',
            ],
            'contract_number' => [
                '/^[^\n]*\b(C\/[A-Z]?[0-9]{4}\/[0-9]{2})\b/im',
                '/(?:Contract|PO|Order|العقد|الشراء|أمر\s*الشراء|رقم\s*العقد)[:\s#]*([A-Z0-9\-\/]+)/iu',
                '/(?:عقد|ع\.ر)[:\s#]*([A-Z0-9\-\/]+)/iu',
                '/\b([CP]O[\-\/][0-9]{4,})\b/i',
                '/\b(C\/[0-9]{4}\/[0-9]{2})\b/i',
            ]
        ];
    }

    /**
     * Enhanced Logging Function
     */
    public static function logPasteAttempt($text, $extracted, $success, $error = null) {
        $logFile = __DIR__ . '/../../storage/paste_debug.log';
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

    /**
     * Multi-Pattern Extraction
     */
    public static function extractWithPatterns($text, $patterns, $fieldName) {
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $text, $m)) {
                $value = trim($m[1]);
                // Log unnecessary here to reduce noise, logic is clear
                return $value;
            }
        }
        return null;
    }

    /**
     * Smart Table Parser for TAB-separated data
     */
    public static function parseTabularData($text) {
        $lines = explode("\n", $text);
        $allRows = []; 
        
        foreach ($lines as $line) {
            if (substr_count($line, "\t") >= 4) {
                $columns = explode("\t", $line);
                $columns = array_map('trim', $columns);
                
                $rowData = [
                    'supplier' => null,
                    'guarantee_number' => null,
                    'bank' => null,
                    'amount' => null,
                    'expiry_date' => null,
                    'contract_number' => null,
                ];
                
                foreach ($columns as $col) {
                    if (empty($col)) continue;
                    if (strlen($col) <= 3 && is_numeric($col)) continue; // Skip row numbers
                    
                    // Detect fields
                    if (preg_match('/^[0-9,]+(\.[0-9]{2})?$/', $col) && !$rowData['amount']) {
                        $rowData['amount'] = $col; continue;
                    }
                    if (self::isDate($col) && !$rowData['expiry_date']) {
                        $rowData['expiry_date'] = $col; continue;
                    }
                    if (self::isBankCode($col) && !$rowData['bank']) {
                        $rowData['bank'] = $col; continue;
                    }
                    if (self::isGuaranteeNumber($col) && !$rowData['guarantee_number']) {
                        $rowData['guarantee_number'] = $col; continue;
                    }
                    if (self::isContractNumber($col) && !$rowData['contract_number']) {
                        $rowData['contract_number'] = $col; continue;
                    }
                    
                    // Supplier fallback
                    $cleanSupp = trim(preg_replace('/\s+/', ' ', $col));
                    if (!$rowData['supplier'] && preg_match('/[A-Za-zء-ي]/', $col) && strlen($cleanSupp) >= 8 && strlen($cleanSupp) < 100) {
                        if (!preg_match('/^[0-9,\.]+$/', $cleanSupp) && 
                            !preg_match('/^[A-Z0-9]{1,4}[0-9]+[A-Z]?$/i', $cleanSupp) &&
                            strpos($cleanSupp, '<') === false) {
                            $rowData['supplier'] = $cleanSupp;
                        }
                    }
                }
                
                if ($rowData['guarantee_number'] && $rowData['amount']) {
                    $allRows[] = $rowData;
                }
            }
        }
        
        return count($allRows) > 0 ? $allRows : null;
    }

    /**
     * Process single table row and create guarantee
     */
    public static function processTableRow($rowData, $text, $repo) {
        // Convert date format
        $expiryDate = self::normalizeDate($rowData['expiry_date']);
        
        // Parse amount
        $amount = $rowData['amount'] ? (float)str_replace(',', '', $rowData['amount']) : null;
        
        // Check if exists
        $existing = $repo->findByNumber($rowData['guarantee_number']);
        if ($existing) {
            try {
                \App\Services\TimelineRecorder::recordDuplicateImportEvent($existing->id, 'smart_paste');
            } catch (\Throwable $t) {}
            
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
        
        try {
            \App\Services\TimelineRecorder::recordImportEvent($saved->id, 'smart_paste', $saved->rawData);
        } catch (\Throwable $t) {}
        
        return [
            'id' => $saved->id,
            'guarantee_number' => $rowData['guarantee_number'],
            'supplier' => $rowData['supplier'],
            'amount' => $amount,
            'exists_before' => false
        ];
    }
    
    // --- Private Validators ---

    private static function isDate($col) {
        return preg_match('/^[0-9]{1,2}[-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[-\/][0-9]{4}$/i', $col) ||
               preg_match('/^[0-9]{1,2}[-\/][0-9]{1,2}[-\/][0-9]{4}$/', $col) ||
               preg_match('/^[0-9]{4}[-\/][0-9]{1,2}[-\/][0-9]{1,2}$/', $col);
    }

    private static function isBankCode($col) {
        $clean = trim(preg_replace('/\s+/', ' ', $col));
        if (strlen($clean) >= 60) return false;
        
        return preg_match('/^(SNB|ANB|SAB|SABB|NCB|RIBL|SAMBA|BSF|ALRAJHI|ALINMA|BNP\s*PARIBAS|BANQUE\s*SAUDI\s*FRANSI|BSF)$/i', $clean) ||
               (preg_match('/\b(BANK|BANQUE|ALRAJHI|ALINMA)\b/i', $clean) && str_word_count($clean) < 10) ||
               preg_match('/^[A-Z]{2,5}$/', $clean);
    }

    private static function isGuaranteeNumber($col) {
        return preg_match('/^[A-Z0-9]{10,}$/i', $col) ||
               preg_match('/^[A-Z]{3,4}[0-9]{6,}[A-Z]?$/i', $col) ||
               preg_match('/^[0-9]{6,}[A-Z]$/i', $col) ||
               preg_match('/^[A-Z]{1,2}[0-9]{6,}$/i', $col);
    }
    
    private static function isContractNumber($col) {
        return preg_match('/^[A-Z]+\/[A-Z0-9]{4,}\/[0-9]{2}$/i', $col) ||
               preg_match('/^(PO|CNT|C)-[0-9]+/i', $col);
    }

    public static function normalizeDate($dateStr) {
        if (!$dateStr) return null;
        if (preg_match('/([0-9]{1,2})[-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[-\/]([0-9]{4})/i', $dateStr, $m)) {
            $months = ['jan'=>'01','feb'=>'02','mar'=>'03','apr'=>'04','may'=>'05','jun'=>'06',
                       'jul'=>'07','aug'=>'08','sep'=>'09','oct'=>'10','nov'=>'11','dec'=>'12'];
            $month = $months[strtolower($m[2])];
            return $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        return str_replace('/', '-', $dateStr);
    }
}
