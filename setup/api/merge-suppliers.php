<?php
/**
 * Merge Suppliers API - Real Database Implementation
 * Actually combines selected supplier rows in the temp database
 */

require_once __DIR__ . '/../SetupDatabase.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['supplier_ids']) || !is_array($input['supplier_ids'])) {
        throw new Exception('IDs الموردين مطلوبة');
    }
    
    $supplierIds = array_map('intval', $input['supplier_ids']);
    
    if (count($supplierIds) < 2) {
        throw new Exception('يجب اختيار موردين على الأقل للدمج');
    }
    
    $db = SetupDatabase::connect();
    $db->beginTransaction();
    
    try {
        // 1. Get all selected suppliers
        $placeholders = str_repeat('?,', count($supplierIds) - 1) . '?';
        $stmt = $db->prepare("SELECT * FROM temp_suppliers WHERE id IN ($placeholders)");
        $stmt->execute($supplierIds);
        $suppliers = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($suppliers) < 2) {
            throw new Exception('لم يتم العثور على الموردين المحددين');
        }
        
        // 2. Choose master supplier (prefer Arabic name)
        $master = $suppliers[0];
        foreach ($suppliers as $supplier) {
            if (!empty($supplier['supplier_name']) && preg_match('/[\x{0600}-\x{06FF}]/u', $supplier['supplier_name'])) {
                $master = $supplier;
                break;
            }
        }
        
        // 3. Collect ALL information from all suppliers (cumulative)
        $uniqueNames = [
            'arabic' => [],
            'english' => []
        ];
        
        $totalOccurrences = 0;
        
        foreach ($suppliers as $supplier) {
            $name = $supplier['supplier_name'];
            $isArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $name);
            
            // Collect all names
            if ($isArabic) {
                $uniqueNames['arabic'][] = $name;
            } else {
                $uniqueNames['english'][] = $name;
            }
            
            $totalOccurrences += intval($supplier['occurrence_count']);
        }
        
        // Remove duplicates
        $uniqueNames['arabic'] = array_unique($uniqueNames['arabic']);
        $uniqueNames['english'] = array_unique($uniqueNames['english']);
        
        // 4. Delete all selected suppliers
        $placeholders = str_repeat('?,', count($supplierIds) - 1) . '?';
        $stmt = $db->prepare("DELETE FROM temp_suppliers WHERE id IN ($placeholders)");
        $stmt->execute($supplierIds);
        
        // 5. Create merged rows with cumulative info
        // Create a NEW unique normalized_name to avoid re-merging later
        $normalizedName = $master['normalized_name'] . '_merged_' . time();
        $insertedIds = [];
        
        // Insert Arabic names
        foreach ($uniqueNames['arabic'] as $arabicName) {
            $stmt = $db->prepare('
                INSERT INTO temp_suppliers (supplier_name, normalized_name, occurrence_count, status) 
                VALUES (?, ?, ?, "pending")
            ');
            $stmt->execute([$arabicName, $normalizedName, $totalOccurrences]);
            $insertedIds[] = $db->lastInsertId();
        }
        
        // Insert English names
        foreach ($uniqueNames['english'] as $englishName) {
            $stmt = $db->prepare('
                INSERT INTO temp_suppliers (supplier_name, normalized_name, occurrence_count, status) 
                VALUES (?, ?, ?, "pending")
            ');
            $stmt->execute([$englishName, $normalizedName, $totalOccurrences]);
            $insertedIds[] = $db->lastInsertId();
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'تم دمج الموردين بنجاح - جميع المعلومات مجمعة',
            'merged_names' => [
                'arabic' => array_values($uniqueNames['arabic']),
                'english' => array_values($uniqueNames['english'])
            ],
            'total_occurrences' => $totalOccurrences,
            'new_ids' => $insertedIds
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
