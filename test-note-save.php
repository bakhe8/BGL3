<?php
// Test save-note API
require_once __DIR__ . '/app/Support/autoload.php';

use App\Repositories\NoteRepository;
use App\Support\Database;

echo "Testing Note Save Functionality\n\n";

// Test 1: Direct database insert
echo "Test 1: Direct Database Insert\n";
try {
    $db = Database::connect();
    $stmt = $db->prepare('INSERT INTO guarantee_notes (guarantee_id, content, created_by, created_at) VALUES (?, ?, ?, datetime("now"))');
    $stmt->execute([1, 'Test Note Direct', 'System']);
    $noteId = $db->lastInsertId();
    echo "✅ Success! Note ID: $noteId\n";
} catch (Exception $e) {
    echo "❌ Failed: " . $e->getMessage() . "\n";
}

// Test 2: Via Repository
echo "\nTest 2: Via NoteRepository\n";
try {
    $db = Database::connect();
    $repo = new NoteRepository($db);
    $id = $repo->create([
        'guarantee_id' => 1,
        'content' => 'Test via Repository',
        'created_by' => 'System'
    ]);
    echo "✅ Success! Note ID: $id\n";
} catch (Exception $e) {
    echo "❌ Failed: " . $e->getMessage() . "\n";
}

// Test 3: Retrieve notes
echo "\nTest 3: Retrieve Notes for Guarantee 1\n";
try {
    $db = Database::connect();
    $repo = new NoteRepository($db);
    $notes = $repo->getByGuaranteeId(1);
    echo "✅ Found " . count($notes) . " notes\n";
    foreach ($notes as $note) {
        echo "  - {$note['content']} (by {$note['created_by']})\n";
    }
} catch (Exception $e) {
    echo "❌ Failed: " . $e->getMessage() . "\n";
}
