<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    
    // 1. Analyze ID 1363
    $stmt = $db->query("SELECT * FROM guarantee_history WHERE id = 1363");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    
    echo "ID 1363 BEFORE UPDATE:\n";
    echo "Type: [" . $row['event_type'] . "]\n";
    echo "Subtype: [" . $row['event_subtype'] . "]\n";
    echo "Created By: [" . $row['created_by'] . "]\n";
    
    // 2. Try Update
    $update = $db->prepare("UPDATE guarantee_history SET created_by = 'بواسطة المستخدم' WHERE id = 1363");
    $update->execute();
    echo "Update executed. Rows impacted: " . $update->rowCount() . "\n";
    
    // 3. Verify
    $stmt = $db->query("SELECT * FROM guarantee_history WHERE id = 1363");
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    echo "ID 1363 AFTER UPDATE:\n";
    echo "Created By: [" . $row['created_by'] . "]\n";
    
    // 4. Check potential remaining 'System' manual edits
    $check = $db->query("
        SELECT id, event_subtype FROM guarantee_history 
        WHERE event_subtype = 'manual_edit' 
        AND created_by LIKE '%النظام%'
    ");
    $remaining = $check->fetchAll(PDO::FETCH_ASSOC);
    echo "Remaining System Manual Edits: " . count($remaining) . "\n";
    if (count($remaining) > 0) {
        print_r($remaining);
    }

} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
