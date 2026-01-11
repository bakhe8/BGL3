<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    echo "Connected for updates.\n";

    // backup first (again, safe side)
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $backupPath = __DIR__ . '/storage/database/app_backup_final_' . date('Ymd_His') . '.sqlite';
    copy($dbPath, $backupPath);
    echo "Backup: $backupPath\n";

    // 1. Set MANUAL actions to USER
    $stmt1 = $db->prepare("UPDATE guarantee_history SET created_by = 'بواسطة المستخدم' WHERE event_type IN ('manual_match', 'extension', 'reduction', 'release')");
    $stmt1->execute();
    echo "Updated Manual/Actions (User): " . $stmt1->rowCount() . " rows\n";

    // 2. Set MODIFIED (subtype manual/supplier) to USER
    $stmt2 = $db->prepare("UPDATE guarantee_history SET created_by = 'بواسطة المستخدم' WHERE event_type = 'modified' AND event_subtype IN ('manual_edit', 'supplier_change')");
    $stmt2->execute();
    echo "Updated Modified/Subtypes (User): " . $stmt2->rowCount() . " rows\n";

    // 3. Set AUTO/STATUS to SYSTEM
    $stmt3 = $db->prepare("UPDATE guarantee_history SET created_by = 'بواسطة النظام' WHERE event_type IN ('auto_matched', 'status_change')");
    $stmt3->execute();
    echo "Updated Auto/Status (System): " . $stmt3->rowCount() . " rows\n";
    
    // 4. Verify distribution
    $stmt4 = $db->query("SELECT created_by, count(*) as c FROM guarantee_history GROUP BY created_by");
    $rows = $stmt4->fetchAll(PDO::FETCH_ASSOC);
    echo "Final Distribution:\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
