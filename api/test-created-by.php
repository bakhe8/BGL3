<?php
// Quick test: Check actual created_by data
require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

// Check if created_by has NULLs
$result = $db->query("
    SELECT 
        COUNT(*) as total,
        SUM(CASE WHEN created_by IS NULL THEN 1 ELSE 0 END) as null_count,
        SUM(CASE WHEN created_by IS NOT NULL THEN 1 ELSE 0 END) as not_null_count
    FROM guarantee_history
")->fetch(PDO::FETCH_ASSOC);

echo json_encode($result, JSON_PRETTY_PRINT);
