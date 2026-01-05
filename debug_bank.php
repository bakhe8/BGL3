<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Support\BankNormalizer;

$db = Database::connect();

// Test the normalization
$testName = "ALINMA BANK";
$normalized = BankNormalizer::normalize($testName);

echo "Original: $testName\n";
echo "Normalized: $normalized\n";
echo "\n";

// Check banks table
$stmt = $db->prepare("SELECT id, arabic_name, english_name FROM banks WHERE arabic_name LIKE ? OR english_name LIKE ?");
$stmt->execute(['%إنماء%', '%ALINMA%']);
$banks = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Banks matching 'إنماء' or 'ALINMA':\n";
foreach ($banks as $bank) {
    echo "ID: {$bank['id']}, Arabic: {$bank['arabic_name']}, English: {$bank['english_name']}\n";
}
echo "\n";

// Check alternative names
$stmt = $db->prepare("SELECT ban.id, ban.bank_id, ban.alternative_name, ban.normalized_name, b.arabic_name 
                      FROM bank_alternative_names ban 
                      JOIN banks b ON b.id = ban.bank_id
                      WHERE ban.normalized_name = ?");
$stmt->execute([$normalized]);
$alternatives = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Alternative names for normalized '$normalized':\n";
if (empty($alternatives)) {
    echo "  NONE FOUND!\n";
} else {
    foreach ($alternatives as $alt) {
        echo "  Bank ID: {$alt['bank_id']}, Bank: {$alt['arabic_name']}, Alt Name: {$alt['alternative_name']}, Normalized: {$alt['normalized_name']}\n";
    }
}
echo "\n";

// Show all alternative names for إنماء bank
$stmt = $db->prepare("SELECT ban.* FROM bank_alternative_names ban 
                      JOIN banks b ON b.id = ban.bank_id 
                      WHERE b.arabic_name LIKE '%إنماء%'");
$stmt->execute();
$allAlts = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "All alternative names for مصرف الإنماء:\n";
foreach ($allAlts as $alt) {
    echo "  Alternative: {$alt['alternative_name']}, Normalized: {$alt['normalized_name']}\n";
}
