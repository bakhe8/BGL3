<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Support\BankNormalizer;

$db = Database::connect();

// First, find or create مصرف الإنماء
$stmt = $db->prepare("SELECT id, arabic_name FROM banks WHERE arabic_name LIKE ?");
$stmt->execute(['%إنماء%']);
$bank = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$bank) {
    echo "Creating مصرف الإنماء bank...\n";
    $stmt = $db->prepare("INSERT INTO banks (arabic_name, english_name) VALUES (?, ?)");
    $stmt->execute(['مصرف الإنماء', 'Alinma Bank']);
    $bankId = $db->lastInsertId();
    echo "Created bank ID: $bankId\n";
} else {
    $bankId = $bank['id'];
    echo "Found existing bank: {$bank['arabic_name']} (ID: $bankId)\n";
}

// Add alternative names
$alternatives = [
    'ALINMA BANK',
    'ALINMA',
    'Al Inma Bank',
    'بنك الإنماء',
    'الإنماء',
    'مصرف الإنماء'
];

echo "\nAdding alternative names...\n";
foreach ($alternatives as $altName) {
    $normalized = BankNormalizer::normalize($altName);
    
    // Check if exists
    $checkStmt = $db->prepare("SELECT id FROM bank_alternative_names WHERE bank_id = ? AND normalized_name = ?");
    $checkStmt->execute([$bankId, $normalized]);
    
    if (!$checkStmt->fetch()) {
        $insertStmt = $db->prepare("INSERT INTO bank_alternative_names (bank_id, alternative_name, normalized_name) VALUES (?, ?, ?)");
        $insertStmt->execute([$bankId, $altName, $normalized]);
        echo "  ✓ Added: $altName → $normalized\n";
    } else {
        echo "  - Already exists: $altName → $normalized\n";
    }
}

echo "\nDone! Now testing match...\n";

// Test the match
$testName = "ALINMA BANK";
$testNormalized = BankNormalizer::normalize($testName);

$stmt = $db->prepare("
    SELECT b.id, b.arabic_name, b.english_name
    FROM banks b
    JOIN bank_alternative_names a ON b.id = a.bank_id
    WHERE a.normalized_name = ?
    LIMIT 1
");
$stmt->execute([$testNormalized]);
$match = $stmt->fetch(PDO::FETCH_ASSOC);

if ($match) {
    echo "\n✅ SUCCESS! '$testName' now matches:\n";
    echo "   Bank ID: {$match['id']}\n";
    echo "   Arabic: {$match['arabic_name']}\n";
    echo "   English: {$match['english_name']}\n";
} else {
    echo "\n❌ Still not matching!\n";
}
