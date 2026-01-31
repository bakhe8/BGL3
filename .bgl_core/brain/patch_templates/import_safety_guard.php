<?php
// Template: Import safety guard
// Placeholders: {{max_size_mb}}, {{allowed_mimes}}

$maxSize = {{max_size_mb}} * 1024 * 1024; // bytes
$allowed = {{allowed_mimes}}; // e.g. ['text/csv','application/vnd.ms-excel']

if ($_FILES['file']['size'] > $maxSize) {
    http_response_code(400);
    echo json_encode(['error' => 'File too large']);
    exit;
}

if (!in_array($_FILES['file']['type'], $allowed, true)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid file type']);
    exit;
}
