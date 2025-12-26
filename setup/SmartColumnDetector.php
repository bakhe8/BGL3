<?php
/**
 * Smart Excel Column Detector - FIXED VERSION
 * Correctly identifies and separates supplier and bank columns
 */

class SmartColumnDetector
{
    /**
     * Check if a value looks like a guarantee number (code/ID)
     */
    public static function looksLikeGuaranteeNumber($value): bool
    {
        $value = trim($value);
        
        if (empty($value)) return false;
        
        // Patterns that indicate it's a code/number
        $patterns = [
            '/^[A-Z0-9]{5,}$/',
            '/^[0-9]{6,}$/',
            '/^[A-Z]{1,3}[0-9]{5,}$/',
            '/[A-Z]+LG[0-9]+/',
            '/^[0-9]+[A-Z]+$/',
            '/\//',
            '/^[A-Z]+\d+[A-Z]+$/',
        ];
        
        foreach ($patterns as $pattern) {
            if (preg_match($pattern, $value)) {
                return true;
            }
        }
        
        $numCount = preg_match_all('/[0-9]/', $value);
        $length = mb_strlen($value);
        if ($length > 0 && ($numCount / $length) > 0.6) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Check if a value looks like a valid name
     */
    public static function looksLikeValidName($value): bool
    {
        $value = trim($value);
        
        if (empty($value)) return false;
        if (self::looksLikeGuaranteeNumber($value)) return false;
        
        // Accept short abbreviations like "SNB", "ANB" for banks
        if (strlen($value) <= 10 && preg_match('/^[A-Z\s&]+$/', $value)) {
            return true;
        }
        
        if (preg_match('/[\p{Arabic}]/u', $value)) return true;
        
        if (preg_match('/^[a-zA-Z\s&.,()]+$/', $value) && strlen($value) >= 3) {
            return true;
        }
        
        return false;
    }
    
    /**
     * Find ALL supplier columns
     */
    public static function findSupplierColumns($headers, $sampleRows = []): array
    {
        $supplierKeywords = [
            'contractor', 'supplier', 'company', 'vendor',
            'مورد', 'مقاول', 'منفذ', 'متعهد'
        ];
        
        // STRICTLY exclude all bank-related columns
        $excludeKeywords = ['bank', 'بنك', 'مصرف', 'guarantee', 'ضمان'];
        
        $columns = [];
        
        foreach ($headers as $index => $header) {
            $normalized = mb_strtolower(trim($header));
            
            // Skip if it contains ANY bank-related keywords
            $shouldExclude = false;
            foreach ($excludeKeywords as $exclude) {
                if (strpos($normalized, mb_strtolower($exclude)) !== false) {
                    $shouldExclude = true;
                    break;
                }
            }
            if ($shouldExclude) continue;
            
            // Check supplier keywords
            foreach ($supplierKeywords as $keyword) {
                if (strpos($normalized, mb_strtolower($keyword)) !== false) {
                    if (self::validateColumn($sampleRows, $index, 'supplier')) {
                        $columns[] = $index;
                        break;
                    }
                }
            }
        }
        
        return array_unique($columns);
    }
    
    /**
     * Find ALL bank columns
     */
    public static function findBankColumns($headers, $sampleRows = []): array
    {
        $bankKeywords = ['bank', 'بنك', 'مصرف'];
        
        // Exclude guarantee number columns
        $excludeKeywords = ['guarantee', 'ضمان', 'number', 'رقم'];
        
        $columns = [];
        
        foreach ($headers as $index => $header) {
            $normalized = mb_strtolower(trim($header));
            
            // Check exclusions
            $isExcluded = false;
            foreach ($excludeKeywords as $exclude) {
                if (strpos($normalized, mb_strtolower($exclude)) !== false) {
                    $isExcluded = true;
                    break;
                }
            }
            
            if ($isExcluded) continue;
            
            // Check bank keywords
            foreach ($bankKeywords as $keyword) {
                if (strpos($normalized, mb_strtolower($keyword)) !== false) {
                    if (self::validateColumn($sampleRows, $index, 'bank')) {
                        $columns[] = $index;
                        break;
                    }
                }
            }
        }
        
        return array_unique($columns);
    }
    
    /**
     * Validate column contains valid names
     */
    private static function validateColumn($sampleRows, $columnIndex, $type): bool
    {
        if (empty($sampleRows)) return true;
        
        $validCount = 0;
        $total = 0;
        
        foreach ($sampleRows as $row) {
            if (!isset($row[$columnIndex])) continue;
            
            $value = trim($row[$columnIndex]);
            if (empty($value)) continue;
            
            $total++;
            if (self::looksLikeValidName($value)) {
                $validCount++;
            }
        }
        
        return $total > 0 && ($validCount / $total) >= 0.5;
    }
}
