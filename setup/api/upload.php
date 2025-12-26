<?php
/**
 * Upload API - Process CSV and extract unique suppliers/banks
 */

require_once __DIR__ . '/../SetupDatabase.php';
require_once __DIR__ . '/../SetupNormalizer.php';

header('Content-Type: application/json');

try {
    // Check if file was uploaded
    if (!isset($_FILES['csv_file'])) {
        throw new Exception('لم يتم رفع أي ملف');
    }
    
    $file = $_FILES['csv_file'];
    
    // Validate file
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('حدث خطأ أثناء رفع الملف');
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['csv', 'txt'])) {
        throw new Exception('يجب أن يكون الملف بصيغة CSV');
    }
    
    if ($file['size'] > 5 * 1024 * 1024) { // 5MB
        throw new Exception('حجم الملف كبير جداً (الحد الأقصى 5MB)');
    }
    
    // Open CSV
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        throw new Exception('فشل قراءة الملف');
    }
    
    // Read header
    $headers = fgetcsv($handle);
    if (!$headers) {
        throw new Exception('الملف فارغ');
    }
    
    // Validate columns
    if (!in_array('supplier_name', $headers) || !in_array('bank_name', $headers)) {
        throw new Exception('يجب أن يحتوي الملف على عمودي supplier_name و bank_name');
    }
    
    $supplierIndex = array_search('supplier_name', $headers);
    $bankIndex = array_search('bank_name', $headers);
    
    // Track unique values
    $uniqueSuppliers = [];
    $uniqueBanks = [];
    $totalRows = 0;
    
    // Process rows
    while (($row = fgetcsv($handle)) !== false) {
        $totalRows++;
        
        // Extract supplier
        if (isset($row[$supplierIndex])) {
            $supplierName = trim($row[$supplierIndex]);
            if (!empty($supplierName)) {
                $normalized = SetupNormalizer::normalize($supplierName);
                
                if (!isset($uniqueSuppliers[$normalized])) {
                    $uniqueSuppliers[$normalized] = [
                        'original' => $supplierName,
                        'count' => 0
                    ];
                }
                $uniqueSuppliers[$normalized]['count']++;
            }
        }
        
        // Extract bank
        if (isset($row[$bankIndex])) {
            $bankName = trim($row[$bankIndex]);
            if (!empty($bankName)) {
                $normalized = SetupNormalizer::normalize($bankName);
                
                if (!isset($uniqueBanks[$normalized])) {
                    $uniqueBanks[$normalized] = [
                        'original' => $bankName,
                        'count' => 0
                    ];
                }
                $uniqueBanks[$normalized]['count']++;
            }
        }
    }
    
    fclose($handle);
    
    // Connect to temp database
    $db = SetupDatabase::connect();
    $db->beginTransaction();
    
    try {
        // Clear existing data
        $db->exec('DELETE FROM temp_suppliers');
        $db->exec('DELETE FROM temp_banks');
        $db->exec('DELETE FROM import_metadata');
        
        // Insert suppliers
        $stmt = $db->prepare('
            INSERT INTO temp_suppliers (supplier_name, normalized_name, occurrence_count)
            VALUES (?, ?, ?)
        ');
        
        foreach ($uniqueSuppliers as $normalized => $data) {
            $stmt->execute([
                $data['original'],
                $normalized,
                $data['count']
            ]);
        }
        
        // Insert banks
        $stmt = $db->prepare('
            INSERT INTO temp_banks (bank_name, normalized_name, occurrence_count)
            VALUES (?, ?, ?)
        ');
        
        foreach ($uniqueBanks as $normalized => $data) {
            $stmt->execute([
                $data['original'],
                $normalized,
                $data['count']
            ]);
        }
        
        // Save metadata
        $db->exec("
            INSERT INTO import_metadata (id, csv_filename, total_rows, suppliers_found, banks_found)
            VALUES (1, '{$file['name']}', $totalRows, " . count($uniqueSuppliers) . ", " . count($uniqueBanks) . ")
        ");
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'data' => [
                'total_rows' => $totalRows,
                'suppliers_found' => count($uniqueSuppliers),
                'banks_found' => count($uniqueBanks),
                'filename' => $file['name']
            ]
        ]);
        
    } catch (Exception $e) {
        $db->rollBack();
        throw $e;
    }
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
