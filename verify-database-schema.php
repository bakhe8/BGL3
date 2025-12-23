<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "=== DATABASE SCHEMA VERIFICATION ===\n\n";

// 1. Check if table exists
echo "1. Checking if guarantee_notes table exists:\n";
$stmt = $db->query("SELECT sql FROM sqlite_master WHERE type='table' AND name='guarantee_notes'");
$result = $stmt->fetch(PDO::FETCH_ASSOC);

if ($result) {
    echo "✅ Table EXISTS\n";
    echo "Schema:\n" . $result['sql'] . "\n\n";
} else {
    echo "❌ Table DOES NOT EXIST!\n\n";
    exit(1);
}

// 2. Check table structure
echo "2. Checking table columns:\n";
$stmt = $db->query("PRAGMA table_info(guarantee_notes)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($columns as $col) {
    echo "  - {$col['name']} ({$col['type']})" . ($col['notnull'] ? " NOT NULL" : "") . "\n";
}
echo "\n";

// 3. Check if guarantee_id column exists and is linked
echo "3. Checking if guarantee_id exists:\n";
$hasGuaranteeId = false;
foreach ($columns as $col) {
    if ($col['name'] === 'guarantee_id') {
        $hasGuaranteeId = true;
        echo "✅ guarantee_id column EXISTS\n\n";
        break;
    }
}
if (!$hasGuaranteeId) {
    echo "❌ guarantee_id column MISSING!\n\n";
}

// 4. Check sample data
echo "4. Checking sample data for guarantee_id=1:\n";
$stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ? ORDER BY created_at DESC LIMIT 5');
$stmt->execute([1]);
$notes = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (count($notes) > 0) {
    echo "✅ Found " . count($notes) . " notes:\n";
    foreach ($notes as $note) {
        echo "  - ID: {$note['id']}, Content: " . substr($note['content'], 0, 50) . "...\n";
        echo "    Created: {$note['created_at']}, By: {$note['created_by']}\n";
    }
} else {
    echo "⚠️  No notes found for guarantee_id=1\n";
}
echo "\n";

// 5. Check total notes count
echo "5. Total notes in database:\n";
$stmt = $db->query('SELECT COUNT(*) as total FROM guarantee_notes');
$total = $stmt->fetch(PDO::FETCH_ASSOC);
echo "Total: {$total['total']} notes\n\n";

// 6. Check if notes are being loaded in index.php
echo "6. Simulating index.php data loading:\n";
$guaranteeId = 1;
$stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ? ORDER BY created_at DESC');
$stmt->execute([$guaranteeId]);
$mockNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Would load " . count($mockNotes) . " notes for guarantee_id={$guaranteeId}\n";
echo "JSON output: " . json_encode($mockNotes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";

echo "=== VERIFICATION COMPLETE ===\n";
