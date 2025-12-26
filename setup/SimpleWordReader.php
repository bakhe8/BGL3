<?php
/**
 * Word File Text Extractor
 * Extracts text from .docx files (which are ZIP archives with XML)
 */

class SimpleWordReader
{
    public static function extractText($filePath)
    {
        if (!file_exists($filePath)) {
            throw new Exception("File not found: $filePath");
        }

        $zip = new ZipArchive();
        if ($zip->open($filePath) !== true) {
            throw new Exception("Failed to open Word document");
        }

        // Read document.xml which contains the main text
        $xmlContent = $zip->getFromName('word/document.xml');
        $zip->close();

        if ($xmlContent === false) {
            throw new Exception("Failed to read document content");
        }

        // Parse XML
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            throw new Exception("Failed to parse XML");
        }

        // Extract all text from paragraphs
        $text = '';
        $xml->registerXPathNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $paragraphs = $xml->xpath('//w:p');

        foreach ($paragraphs as $paragraph) {
            $texts = $paragraph->xpath('.//w:t');
            $paragraphText = '';
            foreach ($texts as $t) {
                $paragraphText .= (string)$t;
            }
            if (!empty(trim($paragraphText))) {
                $text .= trim($paragraphText) . "\n";
            }
        }

        return $text;
    }

    public static function extractSupplierAndBank($text)
    {
        $suppliers = [];
        $banks = [];
        $banksWithInfo = [];

        // Split into lines
        $lines = explode("\n", $text);
        
        for ($i = 0; $i < count($lines); $i++) {
            $line = trim($lines[$i]);
            if (empty($line) || mb_strlen($line) < 5) continue;

            // Bank detection: "السادة / البنك... المحترمين" (all in one line)
            if (preg_match('/(البنك\s+[^\n]+|مصرف\s+[^\n]+)/u', $line, $matches)) {
                $bankName = trim($matches[1]);
                // Remove "المحترمين" from the SAME line
                $bankName = preg_replace('/\s*(المحترمين|الموقر|الموقرة)\s*$/u', '', $bankName);
                
                if (mb_strlen($bankName) > 5 && mb_strlen($bankName) < 100) {
                    $banks[] = $bankName;
                    
                    // Structure: Bank (line 0), المحترمين (line +1), Department (+2), Address (+3), Email (+4)
                    $bankInfo = [
                        'bank_name' => $bankName,
                        'department' => null,
                        'email' => null,
                        'address' => null
                    ];
                    
                    // Line +2: Department/Center (skip +1 which is "المحترمين")
                    if (isset($lines[$i + 2])) {
                        $dept = trim($lines[$i + 2]);
                        if (!empty($dept) && mb_strlen($dept) > 3) {
                            $bankInfo['department'] = $dept;
                        }
                    }
                    
                    // Line +3: Address
                    if (isset($lines[$i + 3])) {
                        $addr = trim($lines[$i + 3]);
                        if (!empty($addr) && mb_strlen($addr) > 3) {
                            $bankInfo['address'] = $addr;
                        }
                    }
                    
                    // Line +4: Email
                    if (isset($lines[$i + 4])) {
                        $emailLine = trim($lines[$i + 4]);
                        if (preg_match('/[\w\.-]+@[\w\.-]+\.\w{2,}/i', $emailLine, $emailMatch)) {
                            $bankInfo['email'] = trim($emailMatch[0]);
                        }
                    }
                    
                    $banksWithInfo[] = $bankInfo;
                }
            }

            // Supplier extraction: always after "على حساب"
            if (preg_match('/على\s+حساب\s+(.+)/u', $line, $matches)) {
                $supplierName = trim($matches[1]);
                
                // Stop words list - extend this list based on common extra text
                $stopWords = [
                    '،', ',', '؛', ';',
                    'وذلك', 'حيث', 'بما', 'نظراً', 'نظير', 'مقابل', 'عن',
                    'لإنتهاء', 'لانتهاء', 'ولإنتهاء', 'ولانتهاء', 
                    'والعائد', 'والعائدة', 
                    'بموجب', 'فاتورة', 'فواتير', 'رقم', 'بتاريخ',
                    'قيمة', 'دفعة', 'المتمثل', 'المتمثلة',
                    'بمبلغ', 'مبلغ'
                ];
                
                foreach ($stopWords as $word) {
                    $supplierName = preg_replace('/\s*'.preg_quote($word, '/').'.*$/u', '', $supplierName);
                }
                
                // Add common suffixes if found in nearby text but missing from captured group
                if (!preg_match('/(للتجارة|المحدودة|ذ\.م\.م|ش\.م\.م)$/u', $supplierName)) {
                    if (preg_match('/للتجارة/u', $line)) {
                        $supplierName .= ' للتجارة';
                    } elseif (preg_match('/المحدودة/u', $line)) {
                        $supplierName .= ' المحدودة';
                    }
                }
                
                // Extra cleaning for non-company names
                $supplierName = preg_replace('/(\d+)$/', '', $supplierName); // Remove trailing numbers
                $supplierName = trim($supplierName);
                
                if (mb_strlen($supplierName) > 5 && mb_strlen($supplierName) < 100) {
                    $suppliers[] = $supplierName;
                }
            }

            // Fallback: General supplier/company patterns
            // Allow dots in name (e.g. Co. Ltd.)
            if (preg_match('/(شركة|مؤسسة|الشركة|المؤسسة)\s+([^،؛\n]+)/u', $line, $matches)) {
                $supplierName = trim($matches[0]);
                
                $stopWords = [
                    '،', ',', '؛', ';',
                    'وذلك', 'حيث', 'بما', 'نظراً', 'نظير', 'مقابل', 'عن',
                    'بموجب', 'قيمة', 'دفعة', 'بمبلغ', 'مبلغ'
                ];
                
                foreach ($stopWords as $word) {
                    $supplierName = preg_replace('/\s*'.preg_quote($word, '/').'.*$/u', '', $supplierName);
                }
                
                if (!preg_match('/(للتجارة|المحدودة|ذ\.م\.م|م\.م\.ح)$/u', $supplierName)) {
                    if (preg_match('/للتجارة/u', $line)) {
                        $supplierName .= ' للتجارة';
                    } elseif (preg_match('/المحدودة/u', $line)) {
                        $supplierName .= ' المحدودة';
                    }
                }
                
                $supplierName = trim($supplierName);
                
                if (mb_strlen($supplierName) > 5 && mb_strlen($supplierName) < 150) {
                    $suppliers[] = $supplierName;
                }
            }
        }

        return [
            'suppliers' => array_unique($suppliers),
            'banks' => array_unique($banks),
            'banks_with_info' => $banksWithInfo
        ];
    }
}
