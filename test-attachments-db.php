<?php
require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Check attachments for guarantee_id=1
$stmt = $db->prepare('SELECT COUNT(*) as count FROM guarantee_attachments WHERE guarantee_id = ?');
$stmt->execute([1]);
$count = $stmt->fetch(PDO::FETCH_ASSOC)['count'];

echo "Attachments for guarantee_id=1: $count\n\n";

if ($count > 0) {
    $stmt = $db->prepare('SELECT * FROM guarantee_attachments WHERE guarantee_id = ? LIMIT 5');
    $stmt->execute([1]);
    $attachments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "First 5 attachments:\n";
    foreach ($attachments as $att) {
        echo "- [{$att['id']}] {$att['filename']} ({$att['file_type']}) - {$att['file_size']} bytes\n";
        echo "  Path: {$att['file_path']}\n";
        echo "  Uploaded by: {$att['created_by']} at {$att['created_at']}\n\n";
    }
} else {
    echo "No attachments found.\n";
}
