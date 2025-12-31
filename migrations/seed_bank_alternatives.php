<?php
/**
 * Bank Alternative Names Seeder
 * 
 * Populates the bank_alternative_names table with comprehensive variations
 * for all Saudi and international banks from banks.json
 * 
 * USAGE: php migrations/seed_bank_alternatives.php
 */

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/../app/Support/autoload.php';
require_once __DIR__ . '/../app/Support/BankNormalizer.php';

use App\Support\Database;
use App\Support\BankNormalizer;

try {
    $db = Database::connect();
    $db->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
    
    echo "🏦 Starting Bank Alternative Names Seeding...\n\n";
    
    // Load banks.json
    $banksJsonPath = __DIR__ . '/../banks.json';
    if (!file_exists($banksJsonPath)) {
        throw new RuntimeException("banks.json not found at: $banksJsonPath");
    }
    
    $banksData = json_decode(file_get_contents($banksJsonPath), true);
    if (!$banksData || !isset($banksData['banks'])) {
        throw new RuntimeException("Invalid banks.json format");
    }
    
    echo "📁 Loaded banks.json (version {$banksData['version']})\n";
    echo "   Total banks: " . count($banksData['banks']) . "\n\n";
    
    // Start transaction
    $db->beginTransaction();
    
    // Clear existing data
    $db->exec("DELETE FROM bank_alternative_names");
    echo "🗑️  Cleared existing alternative names\n\n";
    
    $totalInserted = 0;
    $banksMapped = 0;
    
    // Process each bank from banks.json
    foreach ($banksData['banks'] as $bankData) {
        $officialName = $bankData['official'];
        $englishName = $bankData['english'];
        $shortNames = $bankData['short'] ?? [];
        
        // Find matching bank in database
        $stmt = $db->prepare("
            SELECT id, arabic_name 
            FROM banks 
            WHERE arabic_name = ? OR english_name = ?
            LIMIT 1
        ");
        $stmt->execute([$officialName, $englishName]);
        $bank = $stmt->fetch(\PDO::FETCH_ASSOC);
        
        if (!$bank) {
            // Bank doesn't exist in DB yet - create it
            $insertBank = $db->prepare("
                INSERT INTO banks (arabic_name, english_name) 
                VALUES (?, ?)
            ");
            $insertBank->execute([$officialName, $englishName]);
            $bankId = $db->lastInsertId();
            
            echo "➕ Created new bank: $officialName (ID: $bankId)\n";
        } else {
            $bankId = $bank['id'];
            echo "✓ Found existing bank: {$bank['arabic_name']} (ID: $bankId)\n";
        }
        
        // Prepare insert statement for alternative names
        $insertAlt = $db->prepare("
            INSERT INTO bank_alternative_names 
            (bank_id, alternative_name, normalized_name) 
            VALUES (?, ?, ?)
        ");
        
        $inserted = 0;
        $uniqueNormalized = []; // Track to avoid duplicates
        
        // Insert official names
        $variants = array_merge(
            [$officialName, $englishName],
            $shortNames
        );
        
        foreach ($variants as $variant) {
            $variant = trim($variant);
            if (empty($variant)) continue;
            
            $normalized = BankNormalizer::normalize($variant);
            
            // Skip if already added (avoid duplicates)
            if (in_array($normalized, $uniqueNormalized)) {
                continue;
            }
            
            try {
                $insertAlt->execute([$bankId, $variant, $normalized]);
                $uniqueNormalized[] = $normalized;
                $inserted++;
            } catch (\PDOException $e) {
                // Skip duplicates silently
                if ($e->getCode() !== '23000') { // Not a duplicate error
                    throw $e;
                }
            }
        }
        
        echo "   → Inserted $inserted alternative names\n";
        $totalInserted += $inserted;
        $banksMapped++;
    }
    
    // Commit transaction
    $db->commit();
    
    echo "\n✅ Seeding completed successfully!\n";
    echo "   Banks mapped: $banksMapped\n";
    echo "   Total alternative names: $totalInserted\n\n";
    
    // Verify
    $stmt = $db->query("SELECT COUNT(*) FROM bank_alternative_names");
    $count = $stmt->fetchColumn();
    echo "🔍 Database verification: $count rows in bank_alternative_names\n\n";
    
    // Show sample mappings
    echo "📋 Sample mappings:\n";
    $sample = $db->query("
        SELECT b.arabic_name, a.alternative_name, a.normalized_name
        FROM bank_alternative_names a
        JOIN banks b ON a.bank_id = b.id
        LIMIT 10
    ")->fetchAll(\PDO::FETCH_ASSOC);
    
    foreach ($sample as $row) {
        echo "   {$row['arabic_name']} ← \"{$row['alternative_name']}\" (normalized: {$row['normalized_name']})\n";
    }
    
    echo "\n🎉 Ready to auto-match banks during import!\n";
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "   File: " . $e->getFile() . "\n";
    echo "   Line: " . $e->getLine() . "\n\n";
    exit(1);
}
