<?php
/**
 * Comprehensive Test Script - Test 5 Different Guarantees
 */

require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Get 5 different guarantees
$stmt = $db->query('SELECT id FROM guarantees ORDER BY id LIMIT 5');
$guaranteeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

echo "=== COMPREHENSIVE GUARANTEE TESTS ===\n";
echo "Testing " . count($guaranteeIds) . " guarantees\n\n";

foreach ($guaranteeIds as $index => $guaranteeId) {
    $testNumber = $index + 1;
    echo "╔════════════════════════════════════════════════════════════════╗\n";
    echo "║ TEST #{$testNumber} - Guarantee ID: {$guaranteeId}                                    ║\n";
    echo "╚════════════════════════════════════════════════════════════════╝\n\n";
    
    // 1. Basic Guarantee Data
    echo "📋 BASIC GUARANTEE DATA:\n";
    echo "─────────────────────────\n";
    $stmt = $db->prepare('SELECT * FROM guarantees WHERE id = ?');
    $stmt->execute([$guaranteeId]);
    $guarantee = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($guarantee) {
        echo "ID: {$guarantee['id']}\n";
        echo "Number: {$guarantee['guarantee_number']}\n";
        echo "Import Source: {$guarantee['import_source']}\n";
        echo "Imported At: {$guarantee['imported_at']}\n";
        echo "Imported By: {$guarantee['imported_by']}\n";
        
        $rawData = json_decode($guarantee['raw_data'], true);
        echo "\nRaw Data Fields:\n";
        foreach ($rawData as $key => $value) {
            echo "  - {$key}: {$value}\n";
        }
    }
    
    // 2. Decision Data
    echo "\n\n💡 DECISION DATA:\n";
    echo "─────────────────\n";
    $stmt = $db->prepare('SELECT * FROM guarantee_decisions WHERE guarantee_id = ?');
    $stmt->execute([$guaranteeId]);
    $decisions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($decisions) > 0) {
        foreach ($decisions as $decision) {
            echo "Decision ID: {$decision['id']}\n";
            echo "Status: {$decision['status']}\n";
            echo "Supplier ID: " . ($decision['supplier_id'] ?? 'NULL') . "\n";
            echo "Bank ID: " . ($decision['bank_id'] ?? 'NULL') . "\n";
            echo "Decision Source: " . ($decision['decision_source'] ?? 'NULL') . "\n";
            echo "Confidence Score: " . ($decision['confidence_score'] ?? 'NULL') . "\n";
            echo "Decided At: {$decision['decided_at']}\n";
            echo "---\n";
        }
    } else {
        echo "❌ No decisions found\n";
    }
    
    // 3. Actions (Timeline)
    echo "\n⏱️ ACTIONS (TIMELINE):\n";
    echo "──────────────────────\n";
    $stmt = $db->prepare('SELECT * FROM guarantee_actions WHERE guarantee_id = ? ORDER BY created_at DESC');
    $stmt->execute([$guaranteeId]);
    $actions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($actions) > 0) {
        foreach ($actions as $action) {
            echo "Action ID: {$action['id']}\n";
            echo "Type: {$action['action_type']}\n";
            echo "Date: {$action['action_date']}\n";
            echo "Status: {$action['action_status']}\n";
            if ($action['previous_expiry_date']) {
                echo "Previous Expiry: {$action['previous_expiry_date']}\n";
                echo "New Expiry: {$action['new_expiry_date']}\n";
            }
            echo "Created At: {$action['created_at']}\n";
            echo "---\n";
        }
    } else {
        echo "❌ No actions found\n";
    }
    
    // 4. History
    echo "\n📜 HISTORY:\n";
    echo "───────────\n";
    $stmt = $db->prepare('SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at DESC');
    $stmt->execute([$guaranteeId]);
    $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($history) > 0) {
        foreach ($history as $h) {
            echo "History ID: {$h['id']}\n";
            echo "Action: {$h['action']}\n";
            echo "Reason: " . ($h['change_reason'] ?? 'N/A') . "\n";
            echo "Created At: {$h['created_at']}\n";
            echo "Created By: {$h['created_by']}\n";
            echo "---\n";
        }
    } else {
        echo "❌ No history found\n";
    }
    
    // 5. Attachments
    echo "\n📎 ATTACHMENTS:\n";
    echo "───────────────\n";
    $stmt = $db->prepare('SELECT * FROM guarantee_attachments WHERE guarantee_id = ?');
    $stmt->execute([$guaranteeId]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($attachments) > 0) {
        foreach ($attachments as $att) {
            echo "File: {$att['file_name']}\n";
            echo "Size: {$att['file_size']} bytes\n";
            echo "Type: {$att['file_type']}\n";
            echo "Uploaded By: {$att['uploaded_by']}\n";
            echo "Uploaded At: {$att['created_at']}\n";
            echo "---\n";
        }
    } else {
        echo "❌ No attachments found\n";
    }
    
    // 6. Notes
    echo "\n📝 NOTES:\n";
    echo "─────────\n";
    $stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ?');
    $stmt->execute([$guaranteeId]);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (count($notes) > 0) {
        foreach ($notes as $note) {
            echo "Note ID: {$note['id']}\n";
            echo "Content: {$note['content']}\n";
            echo "Created By: {$note['created_by']}\n";
            echo "Created At: {$note['created_at']}\n";
            echo "---\n";
        }
    } else {
        echo "❌ No notes found\n";
    }
    
    // 7. Learning Data (if supplier is linked)
    if (isset($rawData['supplier'])) {
        echo "\n🧠 LEARNING DATA:\n";
        echo "─────────────────\n";
        
        // Check for supplier decisions
        $stmt = $db->prepare("
            SELECT * FROM supplier_decisions_log 
            WHERE guarantee_id = ?
        ");
        $stmt->execute([$guaranteeId]);
        $learningLogs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (count($learningLogs) > 0) {
            foreach ($learningLogs as $log) {
                echo "Raw Input: {$log['raw_input']}\n";
                echo "Chosen Supplier: {$log['chosen_supplier_name']} (ID: {$log['chosen_supplier_id']})\n";
                echo "Source: {$log['decision_source']}\n";
                echo "Confidence: " . ($log['confidence_score'] ?? 'N/A') . "\n";
                echo "Was Top: " . ($log['was_top_suggestion'] ? 'Yes' : 'No') . "\n";
                echo "---\n";
            }
        } else {
            echo "❌ No learning logs found\n";
        }
    }
    
    // 8. API Test
    echo "\n🔌 API TEST:\n";
    echo "────────────\n";
    echo "Testing: /V3/api/get-record.php?index=" . ($index + 1) . "\n";
    
    $apiUrl = "http://localhost:8000/V3/api/get-record.php?index=" . ($index + 1);
    $apiResponse = @file_get_contents($apiUrl);
    
    if ($apiResponse) {
        $apiData = json_decode($apiResponse, true);
        if ($apiData && $apiData['success']) {
            echo "✅ API Response: SUCCESS\n";
            echo "Record ID: {$apiData['record']['id']}\n";
            echo "Timeline Events: " . count($apiData['timeline']) . "\n";
            echo "Attachments: " . count($apiData['attachments']) . "\n";
            echo "Notes: " . count($apiData['notes']) . "\n";
        } else {
            echo "❌ API Response: FAILED\n";
        }
    } else {
        echo "❌ Could not connect to API\n";
    }
    
    echo "\n\n";
}

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║ TESTS COMPLETED                                                ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n";
