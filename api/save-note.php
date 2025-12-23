<?php
/**
 * API: Save Note
 */
require_once __DIR__ . '/../app/Support/autoload.php';

use App\Repositories\NoteRepository;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

// Get JSON Input
$input = json_decode(file_get_contents('php://input'), true);

$guaranteeId = $input['guarantee_id'] ?? null;
$content = $input['content'] ?? null;

if (!$guaranteeId || !$content) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Missing guarantee_id or content']);
    exit;
}

try {
    $repo = new NoteRepository();
    $id = $repo->create([
        'guarantee_id' => $guaranteeId,
        'content' => $content,
        'created_by' => 'User' // Should come from session
    ]);
    
    echo json_encode([
        'success' => true, 
        'note' => [
            'id' => $id,
            'content' => $content,
            'created_at' => date('Y-m-d H:i:s'),
            'created_by' => 'User'
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
