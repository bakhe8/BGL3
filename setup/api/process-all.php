<?php
/**
 * Unified File Processing API
 * Auto-detects file type and processes Excel/CSV or Word files
 */

require_once __DIR__ . '/../SetupDatabase.php';
require_once __DIR__ . '/../SetupNormalizer.php';
require_once __DIR__ . '/../SimpleXlsxReader.php';
require_once __DIR__ . '/../SimpleWordReader.php';

header('Content-Type: application/json');

try {
    $inputFolder = __DIR__ . '/../input/files';
    
    if (!is_dir($inputFolder)) {
        mkdir($inputFolder, 0777, true);
    }
    
    // Recursively find all files
    $allFiles = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($inputFolder, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $ext = strtolower($file->getExtension());
            if (in_array($ext, ['xlsx', 'xls', 'csv', 'docx'])) {
                $allFiles[] = $file->getPathname();
            }
        }
    }
    
    if (empty($allFiles)) {
        throw new Exception('لا توجد ملفات في المجلد أو المجلدات الفرعية');
    }
    
    $uniqueSuppliers = [];
    $uniqueBanks = [];
    $processedFiles = [];
    
    foreach ($allFiles as $file) {
        $fileName = basename($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        try {
            if (in_array($ext, ['xlsx', 'xls', 'csv'])) {
                // Process Excel/CSV
                $result = processExcelFile($file, $uniqueSuppliers, $uniqueBanks);
                $processedFiles[] = [
                    'file' => $fileName,
                    'type' => 'Excel/CSV',
                    'status' => 'success',
                    'suppliers' => $result['suppliers'],
                    'banks' => $result['banks']
                ];
                
            } elseif ($ext === 'docx') {
                // Process Word
                $result = processWordFile($file, $uniqueSuppliers, $uniqueBanks);
                $processedFiles[] = [
                    'file' => $fileName,
                    'type' => 'Word',
                    'status' => 'success',
                    'suppliers' => $result['suppliers'],
                    'banks' => $result['banks']
                ];
            }
            
        } catch (Exception $e) {
            $processedFiles[] = [
                'file' => $fileName,
                'type' => strtoupper($ext),
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Save to database (cumulative)
    saveToDatabase($uniqueSuppliers, $uniqueBanks);
    
    echo json_encode([
        'success' => true,
        'data' => [
            'processed' => count($processedFiles),
            'suppliers' => count($uniqueSuppliers),
            'banks' => count($uniqueBanks),
            'files' => $processedFiles
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function processExcelFile($file, &$uniqueSuppliers, &$uniqueBanks) {
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    $data = [];
    
    if ($ext === 'csv') {
        $handle = fopen($file, 'r');
        if (!$handle) throw new Exception('فشل فتح ملف CSV');
        
        while (($row = fgetcsv($handle)) !== false) {
            $data[] = $row;
        }
        fclose($handle);
    } else {
        $data = SimpleXlsxReader::read($file);
    }
    
    if (empty($data)) {
        throw new Exception('الملف فارغ');
    }
    
    $supplierCount = 0;
    $bankCount = 0;
    
    foreach ($data as $row) {
        foreach ($row as $value) {
            $value = trim($value);
            
            if (empty($value) || strlen($value) < 3) continue;
            
            $normalized = SetupNormalizer::normalize($value);
            if (empty($normalized)) continue;
            
            // Supplier detection
            if (preg_match('/(شركة|مؤسسة|للتجارة|المحدودة)/u', $value)) {
                $arName = $value;
                $enName = null;
                
                // Check if name contains English part in parentheses: "Company (English)"
                // 1. Try standard (English) - loose match, ignores text after closing paren
                if (preg_match('/^(.+?)\s*\(([A-Za-z0-9\s\.,&-\/:\(\)]+)\)/u', $value, $m)) {
                    $arName = trim($m[1]);
                    $enName = trim($m[2]);
                } 
                // 2. Try truncated (English without closing paren
                elseif (preg_match('/^(.+?)\s*\(([A-Za-z0-9\s\.,&-\/:\(\)]+)$/u', $value, $m)) {
                    $arName = trim($m[1]);
                    $enName = trim($m[2]);
                }
                
                // Validate enName has at least one letter
                if ($enName && !preg_match('/[A-Za-z]/', $enName)) {
                    $enName = null; // Revert if only numbers/symbols
                    $arName = $value;
                }
                
                $normalized = SetupNormalizer::normalize($arName);
                
                // If Arabic name is generic (just "Company"), try to use English name for unique key
                $genericNames = ['شركه', 'موسسه', 'الشركه', 'الموسسه', 'للتجاره', 'المحدوده', 'ذمم'];
                if (in_array($normalized, $genericNames) && !empty($enName)) {
                    $normalized = SetupNormalizer::normalize($enName);
                }
                
                if (!empty($normalized)) {
                    if (!isset($uniqueSuppliers[$normalized])) {
                        $uniqueSuppliers[$normalized] = [
                            'original' => $arName, 
                            'en' => $enName,
                            'count' => 0
                        ];
                    } else if (empty($uniqueSuppliers[$normalized]['en']) && !empty($enName)) {
                        $uniqueSuppliers[$normalized]['en'] = $enName;
                    }
                    
                    $uniqueSuppliers[$normalized]['count']++;
                    $supplierCount++;
                }
            }
            // Bank detection  
            elseif (preg_match('/(بنك|مصرف|bank)/ui', $value)) {
                $lowerVal = mb_strtolower($value);
                $ignoredBanks = [
                    'bank name', 'name of bank', 'bank', 'banks', 
                    'bank guarantee number', 'bank guarantee', 
                    'bank account', 'account bank', 
                    'iban', 'swift', 'currency', 'code'
                ];
                
                // Skip if it matches ignore list exactly or contains "guarantee number"
                if (in_array($lowerVal, $ignoredBanks) || strpos($lowerVal, 'guarantee number') !== false) {
                    continue;
                }
                
                if (!isset($uniqueBanks[$normalized])) {
                    $uniqueBanks[$normalized] = ['original' => $value, 'count' => 0, 'departments' => [], 'emails' => [], 'addresses' => []];
                }
                $uniqueBanks[$normalized]['count']++;
                $bankCount++;
            }
        }
    }
    
    return ['suppliers' => $supplierCount, 'banks' => $bankCount];
}

function processWordFile($file, &$uniqueSuppliers, &$uniqueBanks) {
    $text = SimpleWordReader::extractText($file);
    $extracted = SimpleWordReader::extractSupplierAndBank($text);
    
    // Process suppliers
    foreach ($extracted['suppliers'] as $supplierName) {
        $arName = $supplierName;
        $enName = null;
        
        // Check if name contains English part in parentheses
        // 1. Try standard (English) - loose match, ignores text after closing paren
        if (preg_match('/^(.+?)\s*\(([A-Za-z0-9\s\.,&-\/:\(\)]+)\)/u', $supplierName, $m)) {
            $arName = trim($m[1]);
            $enName = trim($m[2]);
        } 
        // 2. Try truncated (English without closing paren
        elseif (preg_match('/^(.+?)\s*\(([A-Za-z0-9\s\.,&-\/:\(\)]+)$/u', $supplierName, $m)) {
            $arName = trim($m[1]);
            $enName = trim($m[2]);
        }
        
        // Validate enName has at least one letter
        if ($enName && !preg_match('/[A-Za-z]/', $enName)) {
            $enName = null;
            $arName = $supplierName;
        }

        $normalized = SetupNormalizer::normalize($arName);
        
        // If Arabic name is generic (just "Company"), try to use English name for unique key
        $genericNames = ['شركه', 'موسسه', 'الشركه', 'الموسسه', 'للتجاره', 'المحدوده', 'ذمم'];
        if (in_array($normalized, $genericNames) && !empty($enName)) {
            $normalized = SetupNormalizer::normalize($enName);
        }

        if (!empty($normalized)) {
            if (!isset($uniqueSuppliers[$normalized])) {
                $uniqueSuppliers[$normalized] = [
                    'original' => $arName, 
                    'en' => $enName,
                    'count' => 0
                ];
            } else if (empty($uniqueSuppliers[$normalized]['en']) && !empty($enName)) {
                $uniqueSuppliers[$normalized]['en'] = $enName;
            }
            $uniqueSuppliers[$normalized]['count']++;
        }
    }
    
    // Process banks with info
    if (!empty($extracted['banks_with_info'])) {
        foreach ($extracted['banks_with_info'] as $bankData) {
            $normalized = SetupNormalizer::normalize($bankData['bank_name']);
            if (!empty($normalized)) {
                if (!isset($uniqueBanks[$normalized])) {
                    $uniqueBanks[$normalized] = [
                        'original' => $bankData['bank_name'],
                        'count' => 0,
                        'departments' => [],
                        'emails' => [],
                        'addresses' => []
                    ];
                }
                $uniqueBanks[$normalized]['count']++;
                
                if (!empty($bankData['department'])) {
                    $uniqueBanks[$normalized]['departments'][] = $bankData['department'];
                }
                if (!empty($bankData['email'])) {
                    $uniqueBanks[$normalized]['emails'][] = $bankData['email'];
                }
                if (!empty($bankData['address'])) {
                    $uniqueBanks[$normalized]['addresses'][] = $bankData['address'];
                }
            }
        }
    }
    
    return ['suppliers' => count($extracted['suppliers']), 'banks' => count($extracted['banks'])];
}

function saveToDatabase($uniqueSuppliers, $uniqueBanks) {
    // Hard reset if requested (drops file so schema matches)
    if (isset($_GET['reset']) && $_GET['reset'] === 'true') {
        SetupDatabase::reset();
    }

    $db = SetupDatabase::connect();
    $db->beginTransaction();
    
    try {
        // Upsert suppliers
        foreach ($uniqueSuppliers as $normalized => $data) {
            $stmt = $db->prepare('SELECT id, supplier_name_en FROM temp_suppliers WHERE supplier_name = ? LIMIT 1');
            $stmt->execute([$data['original']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // Update count and set English name if it was missing
                $newEn = !empty($data['en']) ? $data['en'] : $existing['supplier_name_en'];
                $stmt = $db->prepare('UPDATE temp_suppliers SET occurrence_count = occurrence_count + ?, supplier_name_en = ? WHERE id = ?');
                $stmt->execute([$data['count'], $newEn, $existing['id']]);
            } else {
                $stmt = $db->prepare('INSERT INTO temp_suppliers (supplier_name, supplier_name_en, normalized_name, occurrence_count) VALUES (?, ?, ?, ?)');
                $stmt->execute([$data['original'], $data['en'] ?? null, $normalized, $data['count']]);
            }
        }
        
        // Upsert banks
        foreach ($uniqueBanks as $normalized => $data) {
            $stmt = $db->prepare('SELECT id, bank_info FROM temp_banks WHERE bank_name = ? LIMIT 1');
            $stmt->execute([$data['original']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            $bankInfoJson = null;
            if (!empty($data['departments']) || !empty($data['emails']) || !empty($data['addresses'])) {
                $bankInfoJson = json_encode([
                    'department' => !empty($data['departments']) ? implode(', ', array_unique($data['departments'])) : null,
                    'email' => !empty($data['emails']) ? implode(', ', array_unique($data['emails'])) : null,
                    'address' => !empty($data['addresses']) ? implode(', ', array_unique($data['addresses'])) : null
                ], JSON_UNESCAPED_UNICODE);
            }
            
            if ($existing) {
                $stmt = $db->prepare('UPDATE temp_banks SET occurrence_count = occurrence_count + ?, bank_info = COALESCE(?, bank_info) WHERE id = ?');
                $stmt->execute([$data['count'], $bankInfoJson, $existing['id']]);
            } else {
                $stmt = $db->prepare('INSERT INTO temp_banks (bank_name, normalized_name, occurrence_count, bank_info) VALUES (?, ?, ?, ?)');
                $stmt->execute([$data['original'], $normalized, $data['count'], $bankInfoJson]);
            }
        }
        
        $db->commit();
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
}
