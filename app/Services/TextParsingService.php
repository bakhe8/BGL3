<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Normalizer;

/**
 * Service for parsing unstructured text into structured Guarantee Records.
 * 
 * CORE STRATEGY: SEQUENTIAL CONSUMPTION
 * -------------------------------------
 * Unlike traditional regex matching which scans the original text repeatedly,
 * this service uses a "consumption" strategy:
 * 1. A working copy of the text is created.
 * 2. Field extractors run in a specific order of specificity (Amounts -> Dates -> Codes -> Free Text).
 * 3. When a match is found, it is extracted AND replaced with spaces (consumed) in the working text.
 * 4. This prevents subsequent patterns from matching already-captured data (e.g. preventing a Contract No 
 *    from being matched as a Guarantee No, or '101' prefix being part of Supplier Name).
 * 
 * FEATURES:
 * - Multi-Row Support: Detects table-like structures and splits logic.
 * - Bilingual Support: Handles English/Arabic dates and labels.
 * - Sequential Cleaning: Removes noise as it processes.
 */
class TextParsingService
{
    private Normalizer $normalizer;

    public function __construct(Normalizer $normalizer)
    {
        $this->normalizer = $normalizer;
    }

    /**
     * Parse text potentially containing multiple records (bulk).
     * 
     * @param string $text
     * @return array[] List of parsed data arrays
     */
    public function parseBulk(string $text): array
    {
        $lines = preg_split('/(\r\n|\n|\r)/', $text);
        $validLines = [];
        
        foreach ($lines as $line) {
            // Heuristic: A valid record line usually represents money.
            // Check for Amount presence WITHOUT consuming it (just inspection).
            if ($this->hasAmount($line)) {
                $validLines[] = $line;
            }
        }
        
        // Decision: Table Mode vs Paragraph Mode
        // If we found multiple lines with amounts, it's likely a table.
        // But if strict text (Example 1) has amount split? No, usually amount is on one line.
        // Exception: Example 3 (paragraph). "Value... 269,800". One amount.
        // So > 1 Amount Lines = Bulk.
        
        if (count($validLines) > 1) {
            $results = [];
            foreach ($validLines as $line) {
                // Parse each line largely independently
                $parsed = $this->parse($line);
                
                // Quality Check: Ensure at least some data was found to avoid empty rows
                if ($parsed['amount'] || $parsed['guarantee_number'] || $parsed['contract_number']) {
                     $results[] = $parsed;
                }
            }
            return $results;
        }
        
        // Single Record Mode
        return [$this->parse($text)];
    }
    
    private function hasAmount(string $text): bool
    {
        // Simple regex to check for amount pattern without full extraction overhead
        return (bool)preg_match('/(?:[0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{2})?|[0-9]+\.[0-9]{2})/', $text);
    }

    /**
     * Parse unstructured text to extract guarantee details.
     * 
     * @param string $text
     * @return array
     */
    public function parse(string $text): array
    {
        // ... existing parse logic ...
        // Working copy of text for consumption
        $workingText = $text;

        // Order matters: Extract specific/distinct formats first, removing them from $workingText
        // to reduce false positives for subsequent vague fields.
        
        // 1. Amount & Currency (Usually distinct)
        $amount = $this->extractAmount($workingText);
        $currency = $this->extractCurrency($workingText); // Currency detection doesn't consume usually, or should it?
        
        // 2. Dates (Expiry)
        $dates = $this->extractDate($workingText, ['expiry', 'valid_until', 'end_date']);
        
        // 3. Contract Number (Specific patterns C/..., SPR...)
        $contractNo = $this->extractContractNumber($workingText);
        
        // 4. Bank (Names, Acronyms)
        $bank = $this->extractBank($workingText);
        
        // 5. Type (Keywords)
        $type = $this->extractType($workingText);
        
        // 6. Guarantee Number (Long alphanumerics, can be confused with contract if not careful)
        $guaranteeNo = $this->extractGuaranteeRef($workingText);
        
        // 7. Supplier (Whatever is left, usually)
        $supplier = $this->extractSupplier($workingText);

        return [
            'amount' => $amount,
            'currency' => $currency, // Defaulting to SAR if extractCurrency is simple
            'guarantee_number' => $guaranteeNo,
            'supplier' => $supplier,
            'bank' => $bank,
            'expiry_date' => $dates,
            'type' => $type,
            'contract_number' => $contractNo,
        ];
    }

    /**
     * Helper to "consume" a matched string from the working text.
     * Replaces the matched substring with spaces to preserve offsets but prevent re-matching.
     * 
     * @param string $text Working text (passed by reference)
     * @param array $matches Preg_match results
     */
    private function consumeMatch(string &$text, array $matches): void
    {
        // Replace matched string with spaces to maintain offsets/separators
        $matchStr = $matches[0];
        $pattern = '/' . preg_quote($matchStr, '/') . '/';
        $text = preg_replace($pattern, str_repeat(' ', mb_strlen($matchStr)), $text, 1);
    }

    private function extractAmount(string &$text): ?float
    {
        // 1. Explicit Labels
        if (preg_match('/(?:SAR|SR|Amount|Value|مبلغ)[:\s]*([0-9,]+\.?[0-9]*)/iu', $text, $matches)) {
            $this->consumeMatch($text, $matches);
            $value = str_replace(',', '', $matches[1]);
            return is_numeric($value) ? (float)$value : null;
        }
        
        // 2. Fallback: Largest number with decimals
        // Must have at least one comma OR a decimal point with 2 digits
        if (preg_match_all('/(?:^|[\s\t])([0-9]{1,3}(?:,[0-9]{3})+(?:\.[0-9]{2})?|[0-9]+\.[0-9]{2})(?=\s|\t|$)/', $text, $matches)) {
             $bestVal = 0.0;
             $bestMatch = '';
             
             foreach ($matches[1] as $idx => $m) {
                 $val = (float)str_replace(',', '', $m);
                 if ($val > $bestVal) {
                     $bestVal = $val;
                     $bestMatch = $matches[0][$idx];
                 }
             }
             
             if ($bestVal > 0) {
                 // Consume the best match
                 $text = preg_replace('/' . preg_quote($bestMatch, '/') . '/', str_repeat(' ', strlen($bestMatch)), $text, 1);
                 return $bestVal;
             }
        }

        return null;
    }

    private function extractCurrency(string &$text): string
    {
         if (preg_match('/(SAR|USD|EUR|GBP|ريال|دولار)/iu', $text, $matches)) {
             // We don't necessarily consume currency as it's small, but we can.
             $this->consumeMatch($text, $matches);
             return strtoupper($matches[1]);
         }
         return 'SAR'; // Default
    }

    private function extractGuaranteeRef(string &$text): ?string
    {
        // 1. Explicit labels
        if (preg_match('/(?:Ref|Reference|No|Number|رقم)[:\.\s]+([A-Z0-9\-\/]+)(?=\s|\n|$)/iu', $text, $matches)) {
             $this->consumeMatch($text, $matches);
             return trim($matches[1]);
        }
        
        // 2. Strong patterns (LG-, RLG-, PEB-, APNGCU-)
        if (preg_match('/(?:RLG|LG|L\/G|PEB|APN|APNGCU)[\-]?[A-Z0-9]*([A-Z0-9\-\/]+)/i', $text, $matches)) {
             $this->consumeMatch($text, $matches);
             return trim($matches[0]);
        }
        
        // 3. Fallback: Long Alphanumeric (8-25 chars)
        if (preg_match('/(?:^|[\s\t])(?!(?:BANK|LTD|CO|INC))([A-Z0-9]*[0-9]+[A-Z0-9]*)(?=\s|\t|$)/i', $text, $matches)) {
             $val = trim($matches[1]);
             if (strlen($val) >= 8 && strlen($val) <= 25 && preg_match('/[0-9]/', $val)) {
                 $this->consumeMatch($text, $matches);
                 return $val;
             }
        }
        
        return null;
    }

    private function extractSupplier(string &$text): ?string
    {
        // 1. Explicit Labels
        $nextKeywords = 'Ref|Reference|Bank|Issuer|Amount|Value|Date|Expiry|LG|RLG|L\/G';
        if (preg_match('/(?:Beneficiary|Supplier|Applicant|المستفيد|المورد|لصالح)[:\s]+(.*?)(?=\s+(?:' . $nextKeywords . ')|\n|$)/iu', $text, $matches)) {
             $this->consumeMatch($text, $matches);
             return $this->cleanString($matches[1]);
        }
        
        // 2. Remaining Text: Look for "Company", "Factory", "Ltd", "Inc"
        // Improved: Ignore leading digits/IDs (e.g. 101, 183)
        // Matches: (Start/Space) [Optional Digits+Space] (Name with specific suffix) (End/Space)
        if (preg_match('/(?:^|[\s\t])(?:[0-9]+[\s\t]+)?([a-zA-Z][a-zA-Z0-9\s\.\-&]+(?:Company|Factory|Co\.?|Ltd\.?|Est\.?|Inc\.?|L\.?L\.?C\.?))(?=\s|\t|$)/iu', $text, $matches)) {
            $this->consumeMatch($text, $matches);
            return $this->cleanString($matches[1]);
        }
        
        return null;
    }

    private function extractContractNumber(string &$text): ?string
    {
        // 1. Explicit Labels
        if (preg_match('/(?:Contract|PO|Purchase Order|Agreement|رقم العقد|أمر الشراء|عقد رقم|برقم العقد المرجعي|برقم العقد)[:\.\s]+([A-Z0-9\-\/]+)(?=\s|\n|,|$)/iu', $text, $matches)) {
             $this->consumeMatch($text, $matches);
             return trim($matches[1]);
        }
        
        // 2. Pattern C/XXXX/XX or similar
        // Matches C/0017/24
        if (preg_match('/(?:^|[\s\t])((?:C|PO)\/[0-9]+\/[0-9]+)(?=\s|\t|$)/i', $text, $matches)) {
            $this->consumeMatch($text, $matches);
            return trim($matches[1]);
        }
        
        return null;
    }

    private function extractBank(string &$text): ?string
    {
        // 1. Known Acronyms (SAB, SNB, etc.) - Priority
        $knownBanks = 'SAB|SABB|SNB|ANB|RIB|BJAZ|NCB|SAMBA|BSF|ALJAZIRA|ALBILAD|ALINMA|SAIB';
        if (preg_match('/(?:^|[\s\t])(' . $knownBanks . ')(?=\s|\t|$)/', $text, $matches)) {
             $this->consumeMatch($text, $matches);
             return trim($matches[1]);
        }

        // 2. Keyword "Bank"
        if (preg_match('/([a-zA-Z\s]+BANK)(?=\t|\s{2,}|$)/i', $text, $matches)) {
             $bank = $matches[1];
             if (strlen($bank) < 50 && strlen(trim($bank)) > 4) {
                 $this->consumeMatch($text, $matches);
                 return $this->cleanString($bank);
             }
        }
        
        // 3. Label
        if (preg_match('/(?:Bank|Issuer|Issued by|البنك)[:\s]+(.*?)(?=\s|\n|$)/iu', $text, $matches)) {
             $this->consumeMatch($text, $matches);
             return $this->cleanString($matches[1]);
        }
        return null;
    }

    private function extractDate(string &$text, array $keywords): ?string
    {
        // Pre-processing: Translate Arabic months to English for parsing
        $enMonths = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
        $arMonths = [
            'Jan' => 'يناير|محرم',
            'Feb' => 'فبراير|صفر',
            'Mar' => 'مارس|ربيع الأول',
            'Apr' => 'أبريل|إبريل|ربيع الآخر',
            'May' => 'مايو|جمادى الأولى',
            'Jun' => 'يونيو|جمادى الآخرة',
            'Jul' => 'يوليو|رجب',
            'Aug' => 'أغسطس|شعبان',
            'Sep' => 'سبتمبر|رمضان',
            'Oct' => 'أكتوبر|شوال',
            'Nov' => 'نوفمبر|ذو القعدة',
            'Dec' => 'ديسمبر|ذو الحجة'
        ];
        
        $monthPattern = implode('|', $enMonths);
        foreach ($arMonths as $k => $v) {
            $monthPattern .= '|' . $v;
        }
        
        $datePattern = '(?:[0-9]{4}-[0-9]{2}-[0-9]{2}|[0-9]{2}\/[0-9]{2}\/[0-9]{4}|[0-9]{1,2}[\s\-]*(?:' . $monthPattern . ')[\s\-]*[0-9]{4})';
        
        // 1. Near Keywords
        $keywordsPattern = implode('|', $keywords);
        if (preg_match("/(?:{$keywordsPattern})[:\s]+({$datePattern})/iu", $text, $matches)) {
             $this->consumeMatch($text, $matches);
             return $this->normalizeDate($matches[1]);
        }
        
        // 2. Fallback: Any Date Pattern
        if (preg_match_all("/(?:^|[\s\t,])({$datePattern})(?=\s|\t|,|\.|$)/iu", $text, $matches)) {
             $dates = [];
             foreach ($matches[1] as $d) {
                 $ts = $this->parseFlexibleDate($d);
                 if ($ts) $dates[] = $ts;
             }
             
             // Consume all matches
             foreach ($matches[0] as $m) {
                 $text = preg_replace('/' . preg_quote($m, '/') . '/', str_repeat(' ', mb_strlen($m)), $text, 1);
             }
             
             if (!empty($dates)) {
                 return date('Y-m-d', max($dates));
             }
        }
        
        return null;
    }
    
    private function parseFlexibleDate(string $dateStr): ?int
    {
         $replacements = [
             'يناير' => 'Jan', 'فبراير' => 'Feb', 'مارس' => 'Mar', 'أبريل' => 'Apr', 'إبريل' => 'Apr', 'مايو' => 'May', 'يونيو' => 'Jun',
             'يوليو' => 'Jul', 'أغسطس' => 'Aug', 'سبتمبر' => 'Sep', 'أكتوبر' => 'Oct', 'نوفمبر' => 'Nov', 'ديسمبر' => 'Dec'
         ];
         
         $dateStr = strtr($dateStr, $replacements);
         return strtotime($dateStr) ?: null;
    }
    
    private function normalizeDate(string $date): string 
    {
        $ts = $this->parseFlexibleDate($date);
        return $ts ? date('Y-m-d', $ts) : $date;
    }
    
    private function extractType(string &$text): ?string
    {
        $types = [
            'FINAL' => ['Final', 'نهائي'],
            'ADVANCE' => ['Advance', 'دفعة مقدمة'],
            'PERFORMANCE' => ['Performance', 'حسن تنفيذ', 'أداء']
        ];
        
        foreach ($types as $key => $phrases) {
            foreach ($phrases as $phrase) {
                if (preg_match('/(?:^|[\s\t])(' . preg_quote($phrase, '/') . ')(?=\s|\t|$)/iu', $text, $matches)) {
                    $this->consumeMatch($text, $matches);
                    return $key;
                }
            }
        }
        return null;
    }

    private function cleanString(string $val): string
    {
        $val = trim($val, " \t\n\r\0\x0B.,;:");
        return preg_replace('/\s+/u', ' ', $val);
    }
}
