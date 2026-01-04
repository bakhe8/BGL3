<?php
declare(strict_types=1);

namespace App\Services;

/**
 * FieldExtractionService
 * 
 * Extracts guarantee fields from text using regex patterns
 * Handles all field types: guarantee number, amount, dates, supplier, bank, contract
 * 
 * ⚠️ CRITICAL: Patterns copied exactly from parse-paste.php - DO NOT MODIFY
 * 
 * @version 1.0
 */
class FieldExtractionService
{
    /**
     * Extract guarantee number from text
     * Uses 7 different patterns in priority order
     */
    public static function extractGuaranteeNumber(string $text): ?string
    {
        $patterns = [
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
        
        return self::extractWithPatterns($text, $patterns, 'GUARANTEE_NUMBER');
    }
    
    /**
     * Extract amount from text
     * Returns float value or null
     */
    public static function extractAmount(string $text): ?float
    {
        $patterns = [
            // Pattern 1: With explicit keywords (Amount, مبلغ, Value, SAR)
            '/(?:Amount|مبلغ|القيمة|value|SAR|SR|ر\.س|ريال)[:\s]*([0-9,]+(?:\.[0-9]{2})?)/iu',
            // Pattern 2: Number followed by currency
            '/([0-9,]+(?:\.[0-9]{2})?)\s*(?:SAR|SR|ر\.س|ريال)/iu',
            // Pattern 3: Large numbers (likely amounts) with thousand separators
            '/\b([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{2})?)\b/',
            // Pattern 4: Simple large numbers without separators
            '/\b([0-9]{5,}(?:\.[0-9]{2})?)\b/',
        ];
        
        $amountStr = self::extractWithPatterns($text, $patterns, 'AMOUNT');
        
        if ($amountStr) {
            return (float)str_replace(',', '', $amountStr);
        }
        
        return null;
    }
    
    /**
     * Extract expiry date from text
     * Normalizes to YYYY-MM-DD format
     */
    public static function extractExpiryDate(string $text): ?string
    {
        $patterns = [
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
        
        $dateStr = self::extractWithPatterns($text, $patterns, 'EXPIRY_DATE');
        
        if ($dateStr) {
            // Convert month name format to YYYY-MM-DD
            return self::normalizeDateFormat($dateStr);
        }
        
        return null;
    }
    
    /**
     * Extract issue date from text
     */
    public static function extractIssueDate(string $text): ?string
    {
        $patterns = [
            '/(?:Issue|Issued|تاريخ\s*الإصدار|صدر|إصدار)[:\s]*([0-9]{4}[\-\/][0-9]{1,2}[\-\/][0-9]{1,2})/iu',
            '/(?:Issue|Issued|تاريخ\s*الإصدار|صدر|إصدار)[:\s]*([0-9]{1,2}[\-\/][0-9]{1,2}[\-\/][0-9]{4})/iu',
        ];
        
        $dateStr = self::extractWithPatterns($text, $patterns, 'ISSUE_DATE');
        
        if ($dateStr) {
            return str_replace('/', '-', $dateStr);
        }
        
        return null;
    }
    
    /**
     * Extract supplier name from text
     */
    public static function extractSupplier(string $text): ?string
    {
        $patterns = [
            '/(?:Supplier|Beneficiary|المورد|المستفيد|لصالح)[:\s]*([^\n\r]+)/iu',
            '/(?:لصالح|ل\s*صالح)[:\s]*([^\n\r]+)/iu',
            '/(?:شركة)\s+([^\n\r،,\.]+)/iu', // Company + name
            // Pattern for TAB-separated table: Look for long English text before TAB and alphanumeric
            '/^([A-Z][A-Z\s&]+COMPANY)\s*\t/im',
            '/^([A-Z][A-Z\s&]+(?:COMPANY|CO\.|LTD|LLC|CORPORATION))\s*\t/im',
        ];
        
        $supplierStr = self::extractWithPatterns($text, $patterns, 'SUPPLIER');
        
        if ($supplierStr) {
            // Clean up supplier name (remove extra spaces, trailing punctuation)
            return preg_replace('/[،,\.]+$/', '', trim($supplierStr));
        }
        
        return null;
    }
    
    /**
     * Extract bank name from text
     */
    public static function extractBank(string $text): ?string
    {
        $patterns = [
            '/(?:Bank|البنك|بنك|مصرف)[:\s]*([^\n\r]+)/iu',
            '/(?:من|عبر)\s*(?:بنك|البنك)\s+([^\n\r،,\.]+)/iu',
            // Pattern for TAB-separated: Look for bank code like SNB, ANB, SABB after TABs
            '/\t([A-Z]{2,4})\t[0-9,]+/i',
            // Common Saudi bank codes
            '/\b(SNB|ANB|SABB|NCB|RIBL|SAMBA|BSF|ALRAJHI|ALINMA)\b/i',
        ];
        
        $bankStr = self::extractWithPatterns($text, $patterns, 'BANK');
        
        if ($bankStr) {
            return preg_replace('/[،,\.]+$/', '', trim($bankStr));
        }
        
        return null;
    }
    
    /**
     * Extract contract number from text
     */
    public static function extractContractNumber(string $text): ?string
    {
        $patterns = [
            // From subject/title line (e.g. "إفراج عن ضمان C/0061/43")
            '/^[^\n]*\b(C\/[A-Z]?[0-9]{4}\/[0-9]{2})\b/im',
            // Standard Labels
            '/(?:Contract|PO|Order|العقد|الشراء|أمر\s*الشراء|رقم\s*العقد)[:\s#]*([A-Z0-9\-\/]+)/iu',
            '/(?:عقد|ع\.ر)[:\s#]*([A-Z0-9\-\/]+)/iu',
            // Specific Formats (PO-123, C/123/22)
            '/\b([CP]O[\-\/][0-9]{4,})\b/i',
            '/\b(C\/[0-9]{4}\/[0-9]{2})\b/i',
        ];
        
        return self::extractWithPatterns($text, $patterns, 'CONTRACT_NUMBER');
    }
    
    /**
     * Detect guarantee type (initial or final)
     */
    public static function detectType(string $text): string
    {
        if (preg_match('/نهائي|final|performance/iu', $text)) {
            return 'نهائي';
        } elseif (preg_match('/ابتدائي|initial|bid/iu', $text)) {
            return 'ابتدائي';
        }
        
        return 'ابتدائي'; // Default
    }
    
    /**
     * Detect intent (extension, reduction, release)
     * For logging only - not actionable
     */
    public static function detectIntent(string $text): ?string
    {
        if (preg_match('/تمديد|extend|extension|للتمديد|لتمديد/iu', $text)) {
            return 'extension';
        } elseif (preg_match('/تخفيض|reduce|reduction|للتخفيض|لتخفيض/iu', $text)) {
            return 'reduction';
        } elseif (preg_match('/إفراج|افراج|release|cancel|للإفراج|لإفراج/iu', $text)) {
            return 'release';
        }
        
        return null;
    }
    
    /**
     * Multi-pattern extraction helper
     * Tries multiple patterns in order until one matches
     * 
     * ⚠️ Copy of extractWithPatterns() function from parse-paste.php
     */
    private static function extractWithPatterns(string $text, array $patterns, string $fieldName): ?string
    {
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
    
    /**
     * Normalize date format to YYYY-MM-DD
     * Handles month names (Jan, Feb, etc.)
     * 
     * ⚠️ Addresses user concern: "Code Duplication" - unified method
     */
    private static function normalizeDateFormat(string $dateStr): string
    {
        // Convert month name format to YYYY-MM-DD
        if (preg_match('/([0-9]{1,2})[\-\/](Jan|Feb|Mar|Apr|May|Jun|Jul|Aug|Sep|Oct|Nov|Dec)[\-\/]([0-9]{4})/i', $dateStr, $m)) {
            $months = [
                'jan'=>'01', 'feb'=>'02', 'mar'=>'03', 'apr'=>'04',
                'may'=>'05', 'jun'=>'06', 'jul'=>'07', 'aug'=>'08',
                'sep'=>'09', 'oct'=>'10', 'nov'=>'11', 'dec'=>'12'
            ];
            $month = $months[strtolower($m[2])];
            return $m[3] . '-' . $month . '-' . str_pad($m[1], 2, '0', STR_PAD_LEFT);
        }
        
        // Normalize other formats (replace / with -)
        return str_replace('/', '-', $dateStr);
    }
}
