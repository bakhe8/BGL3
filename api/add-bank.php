<?php
/**
 * Add New Bank to System
 * Allows admin to add completely new bank with all its alternative names
 */

require_once __DIR__ . '/../app/Support/Database.php';
require_once __DIR__ . '/../app/Support/BankNormalizer.php';

use App\Support\Database;
use App\Support\BankNormalizer;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$db = Database::connect();

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $arabicName = trim($data['arabic_name'] ?? '');
    $englishName = trim($data['english_name'] ?? '');
    $shortName = strtoupper(trim($data['short_name'] ?? ''));
    $aliases = $data['aliases'] ?? []; // Array of alternative names
    
    // Validation
    if (!$arabicName || !$englishName || !$shortName) {
        throw new Exception('جميع الحقول مطلوبة');
    }
    
    // Check if bank already exists
    $stmt = $db->prepare("SELECT id FROM banks WHERE arabic_name = ? OR short_name = ?");
    $stmt->execute([$arabicName, $shortName]);
    $exists = $stmt->fetch();
    
    if ($exists) {
        throw new Exception('بنك بنفس الاسم موجود بالفعل');
    }
    
    $db->beginTransaction();
    
    // 1. Insert bank
    $stmt = $db->prepare("
        INSERT INTO banks (arabic_name, english_name, short_name, created_at, updated_at) 
        VALUES (?, ?, ?, datetime('now'), datetime('now'))
    ");
    $stmt->execute([$arabicName, $englishName, $shortName]);
    $bankId = $db->lastInsertId();
    
    // 2. Insert alternative names
    $stmt = $db->prepare("
        INSERT INTO bank_alternative_names (bank_id, alternative_name, normalized_name)
        VALUES (?, ?, ?)
    ");
    
    $count = 0;
    
    // Add English name as alias
    $stmt->execute([
        $bankId, 
        $englishName, 
        BankNormalizer::normalize($englishName)
    ]);
    $count++;
    
    // Add user-provided aliases
    foreach ($aliases as $alias) {
        $alias = trim($alias);
        if (empty($alias)) continue;
        
        $normalized = BankNormalizer::normalize($alias);
        $stmt->execute([$bankId, $alias, $normalized]);
        $count++;
    }
    
    $db->commit();
    
    echo json_encode([
        'success' => true,
        'message' => 'تمت الإضافة بنجاح',
        'bank_id' => $bankId,
        'aliases_count' => $count
    ]);
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
