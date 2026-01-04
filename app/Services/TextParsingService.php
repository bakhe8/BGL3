<?php
declare(strict_types=1);

namespace App\Services;

use App\Repositories\GuaranteeRepository;
use App\Models\Guarantee;
use App\Support\ParsingHelpers; // Use constants/static utils if needed

/**
 * ═════════════════════════════════════════════════════════════════════════
 * Text Parsing Service (T2.2 Full Implementation)
 * ═════════════════════════════════════════════════════════════════════════
 * 
 * تدير منطق تحليل النصوص (Smart Paste) كخدمة مستقلة.
 * 
 * المميزات:
 * - Dependency Injection لضمان سهولة الاختبار.
 * - Single Responsibility Principle.
 * - دعم الـ Logging المتقدم.
 */
class TextParsingService
{
    private GuaranteeRepository $repo;
    
    public function __construct(GuaranteeRepository $repo)
    {
        $this->repo = $repo;
    }

    /**
     * تحليل النص وإرجاع النتائج (سواء جدول أو صف فردي)
     */
    public function parseText(string $text): array
    {
        // 1. Try Tabular Parsing
        $tableRows = ParsingHelpers::parseTabularData($text);
        
        if ($tableRows && count($tableRows) > 0) {
            return [
                'type' => 'multi',
                'data' => $tableRows
            ];
        }
        
        // 2. Fallback to Single Extraction (Regex)
        $extracted = $this->extractSingle($text);
        return [
            'type' => 'single',
            'data' => $extracted
        ];
    }
    
    /**
     * معالجة وحفظ مجموعة من الضمانات
     */
    public function processRows(array $rows, string $originalText): array
    {
        $results = [];
        foreach ($rows as $rowData) {
            try {
                $results[] = ParsingHelpers::processTableRow($rowData, $originalText, $this->repo);
            } catch (\Throwable $e) {
                $results[] = [
                    'guarantee_number' => $rowData['guarantee_number'] ?? 'UNKNOWN',
                    'error' => $e->getMessage(),
                    'failed' => true
                ];
            }
        }
        return $results;
    }
    
    /**
     * استخراج بيانات مفردة باستخدام الأنماط
     */
    public function extractSingle(string $text): array
    {
        $patterns = ParsingHelpers::getPatterns();
        $extracted = [
            'guarantee_number' => ParsingHelpers::extractWithPatterns($text, $patterns['guarantee_number'], 'GUARANTEE_NUMBER'),
            'amount' => ParsingHelpers::extractWithPatterns($text, $patterns['amount'], 'AMOUNT'),
            'expiry_date' => ParsingHelpers::normalizeDate(ParsingHelpers::extractWithPatterns($text, $patterns['expiry_date'], 'EXPIRY_DATE')),
            'issue_date' => ParsingHelpers::normalizeDate(ParsingHelpers::extractWithPatterns($text, $patterns['issue_date'], 'ISSUE_DATE')),
            'supplier' => ParsingHelpers::extractWithPatterns($text, $patterns['supplier'], 'SUPPLIER'),
            'bank' => ParsingHelpers::extractWithPatterns($text, $patterns['bank'], 'BANK'),
            'contract_number' => ParsingHelpers::extractWithPatterns($text, $patterns['contract_number'], 'CONTRACT_NUMBER'),
            'type' => 'ابتدائي',
            'intent' => null
        ];
        
        // Cleanup Logic
        if ($extracted['amount']) $extracted['amount'] = (float)str_replace(',', '', $extracted['amount']);
        if ($extracted['supplier']) $extracted['supplier'] = preg_replace('/[،,\.]+$/', '', trim($extracted['supplier']));
        if ($extracted['bank']) $extracted['bank'] = preg_replace('/[،,\.]+$/', '', trim($extracted['bank']));
        
        // Intent
        if (preg_match('/نهائي|final|performance/iu', $text)) $extracted['type'] = 'نهائي';
        if (preg_match('/تمديد|extend|extension|للتمديد|لتمديد/iu', $text)) $extracted['intent'] = 'extension';
        elseif (preg_match('/تخفيض|reduce|reduction|للتخفيض|لتخفيض/iu', $text)) $extracted['intent'] = 'reduction';
        elseif (preg_match('/إفراج|افراج|release|cancel|للإفراج|لإفراج/iu', $text)) $extracted['intent'] = 'release';
        
        return $extracted;
    }
    
    public function validate(array $extracted): array
    {
        $missing = [];
        if (!$extracted['guarantee_number']) $missing[] = "رقم الضمان";
        if (!$extracted['supplier']) $missing[] = "اسم المورد";
        if (!$extracted['bank']) $missing[] = "اسم البنك";
        if (!$extracted['amount']) $missing[] = "القيمة";
        if (!$extracted['expiry_date']) $missing[] = "تاريخ الانتهاء";
        if (!$extracted['contract_number']) $missing[] = "رقم العقد";
        
        return $missing;
    }
}
