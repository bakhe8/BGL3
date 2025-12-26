<?php
/**
 * Merge Banks API - Real Database Implementation
 * Actually combines selected bank rows in the temp database
 */

require_once __DIR__ . '/../SetupDatabase.php';

header('Content-Type: application/json');

try {
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['bank_ids']) || !is_array($input['bank_ids'])) {
        throw new Exception('IDs البنوك مطلوبة');
    }
    
    $bankIds = array_map('intval', $input['bank_ids']);
    
    if (count($bankIds) < 2) {
        throw new Exception('يجب اختيار بنكين على الأقل للدمج');
    }
    
    $db = SetupDatabase::connect();
    $db->beginTransaction();
    
    try {
        // 1. Get all selected banks
        $placeholders = str_repeat('?,', count($bankIds) - 1) . '?';
        $stmt = $db->prepare("SELECT * FROM temp_banks WHERE id IN ($placeholders)");
        $stmt->execute($bankIds);
        $banks = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($banks) < 2) {
            throw new Exception('لم يتم العثور على البنوك المحددة');
        }
        
        // 2. Choose master bank (the one with most data or first one)
        $master = $banks[0];
        foreach ($banks as $bank) {
            // Prefer bank with Arabic name
            if (!empty($bank['bank_name']) && preg_match('/[\x{0600}-\x{06FF}]/u', $bank['bank_name'])) {
                $master = $bank;
                break;
            }
        }
        
        // 3. Collect ALL information from all banks (cumulative)
        $uniqueNames = [
            'arabic' => [],
            'english' => [],
            'short' => []
        ];
        
        $additionalInfo = [
            'departments' => [],
            'emails' => [],
            'addresses' => []
        ];
        
        $totalOccurrences = 0;
        
        foreach ($banks as $bank) {
            $name = $bank['bank_name'];
            $isArabic = preg_match('/[\x{0600}-\x{06FF}]/u', $name);
            $isShort = strlen($name) <= 10 && preg_match('/^[A-Z\s&]+$/', $name);
            
            // Collect all names
            if ($isArabic) {
                $uniqueNames['arabic'][] = $name;
            } elseif ($isShort) {
                $uniqueNames['short'][] = $name;
            } else {
                $uniqueNames['english'][] = $name;
            }
            
            // Collect additional info from JSON
            if (!empty($bank['bank_info'])) {
                $info = json_decode($bank['bank_info'], true);
                if (!empty($info['department'])) {
                    $additionalInfo['departments'][] = $info['department'];
                }
                if (!empty($info['email'])) {
                    $additionalInfo['emails'][] = $info['email'];
                }
                if (!empty($info['address'])) {
                    $additionalInfo['addresses'][] = $info['address'];
                }
            }
            
            $totalOccurrences += intval($bank['occurrence_count']);
        }
        
        // Remove duplicates and get unique values
        $uniqueNames['arabic'] = array_unique($uniqueNames['arabic']);
        $uniqueNames['english'] = array_unique($uniqueNames['english']);
        $uniqueNames['short'] = array_unique($uniqueNames['short']);
        $additionalInfo['departments'] = array_unique($additionalInfo['departments']);
        $additionalInfo['emails'] = array_unique($additionalInfo['emails']);
        $additionalInfo['addresses'] = array_unique($additionalInfo['addresses']);
        
        // 4. Delete all selected banks
        $stmt = $db->prepare("DELETE FROM temp_banks WHERE id IN ($placeholders)");
        $stmt->execute($bankIds);
        
        // 5. Create merged rows with cumulative info
        // Create a NEW unique normalized_name to avoid re-merging later
        $normalizedName = $master['normalized_name'] . '_merged_' . time();
        $insertedIds = [];
        
        // Prepare bank_info JSON
        $bankInfoJson = json_encode([
            'department' => !empty($additionalInfo['departments']) ? implode(', ', $additionalInfo['departments']) : null,
            'email' => !empty($additionalInfo['emails']) ? implode(', ', $additionalInfo['emails']) : null,
            'address' => !empty($additionalInfo['addresses']) ? implode(', ', $additionalInfo['addresses']) : null
        ], JSON_UNESCAPED_UNICODE);
        
        // Insert Arabic names
        foreach ($uniqueNames['arabic'] as $arabicName) {
            $stmt = $db->prepare('
                INSERT INTO temp_banks (bank_name, normalized_name, occurrence_count, bank_info, status) 
                VALUES (?, ?, ?, ?, "pending")
            ');
            $stmt->execute([$arabicName, $normalizedName, $totalOccurrences, $bankInfoJson]);
            $insertedIds[] = $db->lastInsertId();
        }
        
        // Insert English names
        foreach ($uniqueNames['english'] as $englishName) {
            $stmt = $db->prepare('
                INSERT INTO temp_banks (bank_name, normalized_name, occurrence_count, bank_info, status) 
                VALUES (?, ?, ?, ?, "pending")
            ');
            $stmt->execute([$englishName, $normalizedName, $totalOccurrences, $bankInfoJson]);
            $insertedIds[] = $db->lastInsertId();
        }
        
        // Insert Short names
        foreach ($uniqueNames['short'] as $shortName) {
            $stmt = $db->prepare('
                INSERT INTO temp_banks (bank_name, normalized_name, occurrence_count, bank_info, status) 
                VALUES (?, ?, ?, ?, "pending")
            ');
            $stmt->execute([$shortName, $normalizedName, $totalOccurrences, $bankInfoJson]);
            $insertedIds[] = $db->lastInsertId();
        }
        
        $db->commit();
        
        echo json_encode([
            'success' => true,
            'message' => 'تم دمج البنوك بنجاح - جميع المعلومات مجمعة',
            'merged_names' => [
                'arabic' => array_values($uniqueNames['arabic']),
                'english' => array_values($uniqueNames['english']),
                'short' => array_values($uniqueNames['short'])
            ],
            'additional_info' => $additionalInfo,
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
