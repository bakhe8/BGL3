<?php
/**
 * Process Word Files API
 * Process all Word (.docx) files from input/word folder
 */

require_once __DIR__ . '/../SetupDatabase.php';
require_once __DIR__ . '/../SetupNormalizer.php';
require_once __DIR__ . '/../SimpleWordReader.php';

header('Content-Type: application/json');

try {
    $wordFolder = __DIR__ . '/../input/word';
    
    if (!is_dir($wordFolder)) {
        throw new Exception('مجلد Word غير موجود');
    }
    
    $files = glob($wordFolder . '/*.{docx,doc}', GLOB_BRACE);
    
    if (empty($files)) {
        throw new Exception('لا توجد ملفات Word في المجلد');
    }
    
    $uniqueSuppliers = [];
    $uniqueBanks = [];
    $processedFiles = [];
    
    foreach ($files as $file) {
        $fileName = basename($file);
        $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
        
        if ($ext !== 'docx') {
            $processedFiles[] = [
                'file' => $fileName,
                'status' => 'skipped',
                'error' => 'Only .docx files are supported'
            ];
            continue;
        }
        
        try {
            // Extract text from Word using full extraction
            $text = SimpleWordReader::extractText($file);
            
            // Extract supplier and bank names using patterns
            $extracted = SimpleWordReader::extractSupplierAndBank($text);
            
            // DEBUG: Log what was extracted
            error_log("Extracted from $fileName:");
            error_log("Banks: " . count($extracted['banks']));
            error_log("Banks with info: " . count($extracted['banks_with_info']));
            if (!empty($extracted['banks_with_info'])) {
                error_log("Sample bank info: " . json_encode($extracted['banks_with_info'][0], JSON_UNESCAPED_UNICODE));
            }
            
            // Process suppliers
            foreach ($extracted['suppliers'] as $supplierName) {
                $supplierName = trim($supplierName);
                if (empty($supplierName)) continue;
                
                $normalized = SetupNormalizer::normalize($supplierName);
                if (empty($normalized)) continue;
                
                if (!isset($uniqueSuppliers[$normalized])) {
                    $uniqueSuppliers[$normalized] = ['original' => $supplierName, 'count' => 0];
                }
                $uniqueSuppliers[$normalized]['count']++;
            }
            
            // Process banks WITH their associated info (sequential structure)
            if (!empty($extracted['banks_with_info'])) {
                foreach ($extracted['banks_with_info'] as $bankData) {
                    $bankName = trim($bankData['bank_name']);
                    if (empty($bankName)) continue;
                    
                    $normalized = SetupNormalizer::normalize($bankName);
                    if (empty($normalized)) continue;
                    
                    if (!isset($uniqueBanks[$normalized])) {
                        $uniqueBanks[$normalized] = [
                            'original' => $bankName,
                            'count' => 0,
                            'departments' => [],
                            'emails' => [],
                            'addresses' => []
                        ];
                    }
                    
                    $uniqueBanks[$normalized]['count']++;
                    
                    // Add info if available
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
            
            $processedFiles[] = [
                'file' => $fileName,
                'suppliers_found' => count($extracted['suppliers']),
                'banks_found' => count($extracted['banks']),
                'status' => 'success'
            ];
            
        } catch (Exception $e) {
            $processedFiles[] = [
                'file' => $fileName,
                'status' => 'error',
                'error' => $e->getMessage()
            ];
        }
    }
    
    // Save to database (CUMULATIVE - append to existing data)
    $db = SetupDatabase::connect();
    $db->beginTransaction();
    
    try {
        // Upsert suppliers (cumulative)
        foreach ($uniqueSuppliers as $normalized => $data) {
            $stmt = $db->prepare('SELECT id, occurrence_count FROM temp_suppliers WHERE supplier_name = ? LIMIT 1');
            $stmt->execute([$data['original']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing) {
                $stmt = $db->prepare('UPDATE temp_suppliers SET occurrence_count = occurrence_count + ? WHERE id = ?');
                $stmt->execute([$data['count'], $existing['id']]);
            } else {
                $stmt = $db->prepare('INSERT INTO temp_suppliers (supplier_name, normalized_name, occurrence_count) VALUES (?, ?, ?)');
                $stmt->execute([$data['original'], $normalized, $data['count']]);
            }
        }
        
        // Upsert banks (cumulative) WITH bank_info
        foreach ($uniqueBanks as $normalized => $data) {
            $stmt = $db->prepare('SELECT id, occurrence_count FROM temp_banks WHERE bank_name = ? LIMIT 1');
            $stmt->execute([$data['original']]);
            $existing = $stmt->fetch(PDO::FETCH_ASSOC);
            
            // Prepare bank_info JSON
            $bankInfoJson = null;
            if (!empty($data['departments']) || !empty($data['emails']) || !empty($data['addresses'])) {
                $bankInfoJson = json_encode([
                    'department' => !empty($data['departments']) ? implode(', ', array_unique($data['departments'])) : null,
                    'email' => !empty($data['emails']) ? implode(', ', array_unique($data['emails'])) : null,
                    'address' => !empty($data['addresses']) ? implode(', ', array_unique($data['addresses'])) : null
                ], JSON_UNESCAPED_UNICODE);
            }
            
            if ($existing) {
                $stmt = $db->prepare('UPDATE temp_banks SET occurrence_count = occurrence_count + ? WHERE id = ?');
                $stmt->execute([$data['count'], $existing['id']]);
            } else {
                $stmt = $db->prepare('INSERT INTO temp_banks (bank_name, normalized_name, occurrence_count, bank_info) VALUES (?, ?, ?, ?)');
                $stmt->execute([$data['original'], $normalized, $data['count'], $bankInfoJson]);
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
