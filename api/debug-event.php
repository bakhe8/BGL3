<?php
// Debug: Check what's happening in save-and-next.php
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

// Get the LATEST event for guarantee 350
$stmt = $db->prepare("SELECT * FROM guarantee_history WHERE guarantee_id = 350 ORDER BY created_at DESC LIMIT 1");
$stmt->execute();
$latest = $stmt->fetch(PDO::FETCH_ASSOC);

echo "Latest event for guarantee 350:\n";
echo "ID: " . $latest['id'] . "\n";
echo "Event Type: " . $latest['event_type'] . "\n";
echo "Created At: " . $latest['created_at'] . "\n";
echo "event_details (raw): " . $latest['event_details'] . "\n\n";

$details = json_decode($latest['event_details'], true);
echo "event_details (decoded):\n";
print_r($details);

echo "\nChanges array:\n";
print_r($details['changes'] ?? []);
