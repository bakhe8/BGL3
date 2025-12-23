<?php
/**
 * Test Attachments & Notes
 */
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;
use App\Models\Guarantee;
use App\Repositories\GuaranteeRepository;
use App\Repositories\AttachmentRepository;
use App\Repositories\NoteRepository;

echo "--- Starting Attachments & Notes Test ---\n";

$db = Database::connect();
$guaranteeRepo = new GuaranteeRepository($db);
$attachRepo = new AttachmentRepository($db);
$noteRepo = new NoteRepository($db);

// 1. Create Guarantee
$db->exec("DELETE FROM guarantees WHERE guarantee_number = 'ATT-001'");
$g = $guaranteeRepo->create(new Guarantee(
    null,
    'ATT-001',
    ['supplier' => 'Test Supplier', 'amount' => 5000],
    'test_attachments'
));
echo "Created Guarantee: " . $g->id . "\n";

// 2. Test Note
$noteId = $noteRepo->create([
    'guarantee_id' => $g->id,
    'content' => 'This is a test note.',
    'created_by' => 'Tester'
]);
echo "Created Note: $noteId\n";

$notes = $noteRepo->getByGuaranteeId($g->id);
if (count($notes) === 1 && $notes[0]['content'] === 'This is a test note.') {
    echo "SUCCESS: Note retrieved correctly.\n";
} else {
    echo "FAIL: Note retrieval failed.\n";
}

// 3. Test Attachment (DB Record only)
$attId = $attachRepo->create([
    'guarantee_id' => $g->id,
    'file_name' => 'test.pdf',
    'file_path' => 'attachments/test.pdf',
    'file_size' => 1024,
    'file_type' => 'application/pdf',
    'uploaded_by' => 'Tester'
]);
echo "Created Attachment Record: $attId\n";

$atts = $attachRepo->getByGuaranteeId($g->id);
if (count($atts) === 1 && $atts[0]['file_name'] === 'test.pdf') {
    echo "SUCCESS: Attachment record retrieved correctly.\n";
} else {
    echo "FAIL: Attachment retrieval failed.\n";
}

// Cleanup
$db->exec("DELETE FROM guarantees WHERE id = " . $g->id);
echo "Cleanup done.\n";
