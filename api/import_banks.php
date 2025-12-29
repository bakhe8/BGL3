<?php
require_once __DIR__ . '/../app/Support/Database.php';
use App\Support\Database;

header('Content-Type: application/json');

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Invalid method');
    }

    if (!isset($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        throw new Exception('File upload failed');
    }

    $json = file_get_contents($_FILES['file']['tmp_name']);
    $data = json_decode($json, true);

    if (!is_array($data)) {
        throw new Exception('Invalid JSON format');
    }

    $db = Database::connect();
    $updates = 0;
    $inserts = 0;

    foreach ($data as $item) {
        // Safe Update Logic
        if (isset($item['id'])) {
            // Check if exists
            $stmt = $db->prepare('SELECT * FROM banks WHERE id = ?');
            $stmt->execute([$item['id']]);
            $current = $stmt->fetch();

            if ($current) {
                // Update only non-empty fields
                $arabic = !empty($item['arabic_name']) ? $item['arabic_name'] : $current['arabic_name'];
                $english = !empty($item['english_name']) ? $item['english_name'] : $current['english_name'];
                $short = !empty($item['short_name']) ? $item['short_name'] : $current['short_name'];
                $dept = !empty($item['department']) ? $item['department'] : $current['department'];
                $addr = !empty($item['address_line1']) ? $item['address_line1'] : $current['address_line1'];
                $email = !empty($item['contact_email']) ? $item['contact_email'] : $current['contact_email'];

                $updateHelp = $db->prepare('UPDATE banks SET arabic_name=?, english_name=?, short_name=?, department=?, address_line1=?, contact_email=? WHERE id=?');
                $updateHelp->execute([$arabic, $english, $short, $dept, $addr, $email, $item['id']]);
                $updates++;
            } else {
                // Insert with ID (or skip ID to auto-inc? usually import preserves ID for restore purposes)
                // Let's preserve ID if user wants full restore capability, BUT manual inserts might conflict.
                // Generally, if ID is provided and not finding match, we assume it's a restore or sync.
                $insert = $db->prepare('INSERT INTO banks (id, arabic_name, english_name, short_name, department, address_line1, contact_email) VALUES (?, ?, ?, ?, ?, ?, ?)');
                $insert->execute([
                    $item['id'],
                    $item['arabic_name'] ?? '',
                    $item['english_name'] ?? '',
                    $item['short_name'] ?? '',
                    $item['department'] ?? '',
                    $item['address_line1'] ?? '',
                    $item['contact_email'] ?? ''
                ]);
                $inserts++;
            }
        } else {
            // New record without ID
            $insert = $db->prepare('INSERT INTO banks (arabic_name, english_name, short_name, department, address_line1, contact_email) VALUES (?, ?, ?, ?, ?, ?)');
            $insert->execute([
                $item['arabic_name'] ?? '',
                $item['english_name'] ?? '',
                $item['short_name'] ?? '',
                $item['department'] ?? '',
                $item['address_line1'] ?? '',
                $item['contact_email'] ?? ''
            ]);
            $inserts++;
        }
    }

    echo json_encode(['success' => true, 'message' => "تم الاستيراد: $inserts إضافة، $updates تحديث"]);

} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
