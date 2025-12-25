<?php
// Quick check: Verify latest event for guarantee 350
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

// Get latest events for guarantee 350
$stmt = $db->prepare("
    SELECT id, event_type, snapshot_data, event_details, created_at, created_by
    FROM guarantee_history 
    WHERE guarantee_id = 350 
    ORDER BY created_at DESC 
    LIMIT 5
");
$stmt->execute();
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);

header('Content-Type: application/json');
echo json_encode($events, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
