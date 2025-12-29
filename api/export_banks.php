<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

header('Content-Type: application/json');
header('Content-Disposition: attachment; filename="banks.json"');

try {
    $db = Database::connect();
    // Only select displayed fields
    $result = $db->query('
        SELECT id, arabic_name, english_name, short_name, department, address_line1, contact_email 
        FROM banks
    ');
    
    $banks = $result->fetchAll();
    echo json_encode($banks, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
