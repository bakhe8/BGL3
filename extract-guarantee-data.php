<?php
/**
 * Extract Complete Data for Guarantee Testing
 */

require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

$guaranteeId = $argv[1] ?? 1;

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║ COMPREHENSIVE DATA EXTRACTION                                  ║\n";
echo "║ Guarantee ID: {$guaranteeId}                                            ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// 1. Basic Guarantee Data
echo "1. BASIC GUARANTEE DATA:\n";
echo "─────────────────────────\n";
$stmt = $db->prepare('SELECT * FROM guarantees WHERE id = ?');
$stmt->execute([$guaranteeId]);
$guarantee = $stmt->fetch(PDO::FETCH_ASSOC);

if ($guarantee) {
    echo "ID: {$guarantee['id']}\n";
    echo "Number: {$guarantee['guarantee_number']}\n";
    echo "Import Source: {$guarantee['import_source']}\n";
    
    $rawData = json_decode($guarantee['raw_data'], true);
    echo "\nRaw Data:\n";
    foreach ($rawData as $key => $value) {
        echo "  {$key}: {$value}\n";
    }
}

// 2. Decision
echo "\n\n2. DECISION:\n";
echo "────────────\n";
$stmt = $db->prepare('SELECT * FROM guarantee_decisions WHERE guarantee_id = ?');
$stmt->execute([$guaranteeId]);
$decision = $stmt->fetch(PDO::FETCH_ASSOC);

if ($decision) {
    echo "Decision ID: {$decision['id']}\n";
    echo "Supplier ID: {$decision['supplier_id']}\n";
    echo "Bank ID: {$decision['bank_id']}\n";
    echo "Status: {$decision['status']}\n";
    echo "Confidence: {$decision['confidence_score']}%\n";
    echo "Source: {$decision['decision_source']}\n";
    echo "Was Top: " . ($decision['was_top_suggestion'] ? 'Yes' : 'No') . "\n";
    echo "Decided At: {$decision['decided_at']}\n";
} else {
    echo "❌ No decision found\n";
}

// 3. Actions
echo "\n\n3. ACTIONS:\n";
echo "───────────\n";
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
        if ($action['previous_amount']) {
            echo "Previous Amount: {$action['previous_amount']}\n";
            echo "New Amount: {$action['new_amount']}\n";
        }
        if ($action['release_reason']) {
            echo "Release Reason: {$action['release_reason']}\n";
        }
        echo "---\n";
    }
} else {
    echo "❌ No actions found\n";
}

// 4. History
echo "\n4. HISTORY:\n";
echo "───────────\n";
$stmt = $db->prepare('SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at DESC');
$stmt->execute([$guaranteeId]);
$history = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($history) > 0) {
    foreach ($history as $h) {
        echo "History ID: {$h['id']}\n";
        echo "Action: {$h['action']}\n";
        echo "Reason: {$h['change_reason']}\n";
        echo "Created At: {$h['created_at']}\n";
        echo "Created By: {$h['created_by']}\n";
        echo "---\n";
    }
} else {
    echo "❌ No history found\n";
}

// 5. Attachments
echo "\n5. ATTACHMENTS:\n";
echo "───────────────\n";
$stmt = $db->prepare('SELECT * FROM guarantee_attachments WHERE guarantee_id = ?');
$stmt->execute([$guaranteeId]);
$attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($attachments) > 0) {
    foreach ($attachments as $att) {
        echo "File: {$att['file_name']}\n";
        echo "Size: {$att['file_size']} bytes\n";
        echo "Type: {$att['file_type']}\n";
        echo "Uploaded: {$att['created_at']}\n";
        echo "---\n";
    }
} else {
    echo "❌ No attachments found\n";
}

// 6. Notes
echo "\n6. NOTES:\n";
echo "─────────\n";
$stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ?');
$stmt->execute([$guaranteeId]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($notes) > 0) {
    foreach ($notes as $note) {
        echo "Note ID: {$note['id']}\n";
        echo "Content: {$note['content']}\n";
        echo "Created: {$note['created_at']}\n";
        echo "By: {$note['created_by']}\n";
        echo "---\n";
    }
} else {
    echo "❌ No notes found\n";
}

// 7. Learning Logs
echo "\n7. LEARNING LOGS:\n";
echo "─────────────────\n";
$stmt = $db->prepare('SELECT * FROM supplier_decisions_log WHERE guarantee_id = ?');
$stmt->execute([$guaranteeId]);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($logs) > 0) {
    foreach ($logs as $log) {
        echo "Raw Input: {$log['raw_input']}\n";
        echo "Chosen Supplier: {$log['chosen_supplier_name']} (ID: {$log['chosen_supplier_id']})\n";
        echo "Source: {$log['decision_source']}\n";
        echo "Confidence: {$log['confidence_score']}%\n";
        echo "Was Top: " . ($log['was_top_suggestion'] ? 'Yes' : 'No') . "\n";
        echo "---\n";
    }
} else {
    echo "❌ No learning logs found\n";
}

echo "\n✅ Data extraction complete!\n";
