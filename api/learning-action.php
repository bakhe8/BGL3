<?php
require __DIR__ . '/../app/Support/autoload.php';

use App\Support\Database;

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

try {
    $data = json_decode(file_get_contents('php://input'), true);
    $id = $data['id'] ?? null;
    $action = $data['action'] ?? null; // 'delete'

    if (!$id || !$action) {
        throw new Exception('Missing parameters');
    }

    $db = Database::connect();

    if ($action === 'delete') {
        $stmt = $db->prepare("DELETE FROM learning_confirmations WHERE id = ?");
        $stmt->execute([$id]);
        
        echo json_encode(['success' => true, 'message' => 'Item deleted']);
    } else {
        throw new Exception('Invalid action');
    }

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
