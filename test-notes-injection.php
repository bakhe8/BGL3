<?php
// Quick test to verify notes are being injected correctly
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Repositories\GuaranteeRepository;

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);

// Get guarantee ID 1
$allGuarantees = $guaranteeRepo->getAll([], 100, 0);
$currentRecord = null;

foreach ($allGuarantees as $guarantee) {
    if ($guarantee->id === 1) {
        $currentRecord = $guarantee;
        break;
    }
}

if ($currentRecord) {
    // Load notes
    $stmt = $db->prepare('SELECT * FROM guarantee_notes WHERE guarantee_id = ? ORDER BY created_at DESC');
    $stmt->execute([$currentRecord->id]);
    $mockNotes = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Notes for guarantee_id=1:\n";
    echo "Count: " . count($mockNotes) . "\n\n";
    
    echo "JSON that will be injected:\n";
    echo json_encode($mockNotes, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n\n";
    
    echo "First 3 notes:\n";
    foreach (array_slice($mockNotes, 0, 3) as $note) {
        echo "- [{$note['id']}] {$note['content']} (by {$note['created_by']} at {$note['created_at']})\n";
    }
}
