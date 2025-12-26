<?php
/**
 * Process Excel Files API
 * Process all Excel/CSV files from input/excel folder
 * WITH SMART COLUMN DETECTION AND FILTERING
 */

require_once __DIR__ . '/../SetupDatabase.php';
require_once __DIR__ . '/../SetupNormalizer.php';
require_once __DIR__ . '/../SimpleXlsxReader.php';
require_once __DIR__ . '/../SmartColumnDetector.php';

header('Content-Type: application/json');

try {
    $excelFolder = __DIR__ . '/../input/excel';
    
    if (!is_dir($excelFolder)) {
        throw new Exception('مجلد Excel غير موجود');
    }
    
    $files = glob($excelFolder . '/*.{xlsx,xls,csv}', GLOB_BRACE);
    
    if (empty($files)) {
        throw new Exception('لا توجد ملفات في مجلد Excel');
    }
    
    $uniqueSuppliers = [];
    $uniqueBanks = [];
    $processedFiles = [];
    
    foreach ($files as $file) {
        $fileName = basename($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        $rowCount = 0;
        
        try {
            $data = [];
            
            if ($ext === 'csv') {
                // Process CSV
                $handle = fopen($file, 'r');
                if (!$handle) continue;
                
                while (($row = fgetcsv($handle)) !== false) {
                    $data[] = $row;
                }
                
                fclose($handle);
                
            } elseif ($ext === 'xlsx' || $ext === 'xls') {
                // Process Excel with SimpleXlsxReader
                $data = SimpleXlsxReader::read($file);
            }
            
            if (empty($data)) continue;
            
            $headers = array_shift($data); // First row
            
            // SMART DETECTION: Find ALL columns (not just one)
            $sampleRows = array_slice($data, 0, 10);
            $supplierCols = SmartColumnDetector::findSupplierColumns($headers, $sampleRows);
            $bankCols = SmartColumnDetector::findBankColumns($headers, $sampleRows);
            
            error_log("[SMART DETECTION] File: $fileName");
            error_log("[SMART DETECTION] Supplier columns: " . json_encode(array_map(fn($i) => "$i ({$headers[$i]})", $supplierCols)));
            error_log("[SMART DETECTION] Bank columns: " . json_encode(array_map(fn($i) => "$i ({$headers[$i]})", $bankCols)));
            
            foreach ($data as $row) {
                $rowCount++;
                
                // Extract from ALL supplier columns
                foreach ($supplierCols as $col) {
                    if (isset($row[$col])) {
                        $value = trim($row[$col]);
                        if (SmartColumnDetector::looksLikeValidName($value)) {
                            extractSupplier($value, $uniqueSuppliers);
                        }
                    }
                }
                
                // Extract from ALL bank columns
                foreach ($bankCols as $col) {
                    if (isset($row[$col])) {
                        $value = trim($row[$col]);
                        if (SmartColumnDetector::looksLikeValidName($value)) {
                            extractBank($value, $uniqueBanks);
                        }
                    }
                }
            }
            
            $processedFiles[] = [
                'file' => $fileName,
                'rows' => $rowCount,
                'supplier_cols' => array_map(fn($i) => $headers[$i], $supplierCols),
                'bank_cols' => array_map(fn($i) => $headers[$i], $bankCols),
                'status' => 'success'
            ];
            
        } catch (Exception $e) {
            $processedFiles[] = [
                'file' => $fileName,
                'rows' => 0,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Save to database (CUMULATIVE - don't delete existing)
    $db = SetupDatabase::connect();
    $db->beginTransaction();
    
    try {
        // Upsert suppliers (cumulative)
        foreach ($uniqueSuppliers as $normalized => $data) {
            // Check if exists (by name, not normalized_name to handle merged entries)
            $stmt = $db->prepare('SELECT id, occurrence_count FROM temp_suppliers WHERE supplier_name = ? LIMIT 1');
            $stmt->execute([$data['original']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // UPDATE: increase occurrence count
                $stmt = $db->prepare('UPDATE temp_suppliers SET occurrence_count = occurrence_count + ? WHERE id = ?');
                $stmt->execute([$data['count'], $existing['id']]);
            } else {
                // INSERT: new supplier
                $stmt = $db->prepare('INSERT INTO temp_suppliers (supplier_name, normalized_name, occurrence_count) VALUES (?, ?, ?)');
                $stmt->execute([$data['original'], $normalized, $data['count']]);
            }
        }
        
        // Upsert banks (cumulative)
        foreach ($uniqueBanks as $normalized => $data) {
            // Check if exists (by name)
            $stmt = $db->prepare('SELECT id, occurrence_count, bank_info FROM temp_banks WHERE bank_name = ? LIMIT 1');
            $stmt->execute([$data['original']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                // UPDATE: increase occurrence count
                $stmt = $db->prepare('UPDATE temp_banks SET occurrence_count = occurrence_count + ? WHERE id = ?');
                $stmt->execute([$data['count'], $existing['id']]);
            } else {
                // INSERT: new bank
                $stmt = $db->prepare('INSERT INTO temp_banks (bank_name, normalized_name, occurrence_count) VALUES (?, ?, ?)');
                $stmt->execute([$data['original'], $normalized, $data['count']]);
            }
        }
        
        $db->commit();
        
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
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function extractSupplier($value, &$uniqueSuppliers) {
    $value = trim($value);
    if (empty($value)) return;
    
    $normalized = SetupNormalizer::normalize($value);
    if (empty($normalized)) return;
    
    if (!isset($uniqueSuppliers[$normalized])) {
        $uniqueSuppliers[$normalized] = ['original' => $value, 'count' => 0];
    }
    $uniqueSuppliers[$normalized]['count']++;
}

function extractBank($value, &$uniqueBanks) {
    $value = trim($value);
    if (empty($value)) return;
    
    $normalized = SetupNormalizer::normalize($value);
    if (empty($normalized)) return;
    
    if (!isset($uniqueBanks[$normalized])) {
        $uniqueBanks[$normalized] = ['original' => $value, 'count' => 0];
    }
    $uniqueBanks[$normalized]['count']++;
}
