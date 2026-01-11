<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

try {
    $db = Database::connect();
    echo "Connected for updates (RETRY).\n";

    // backup again
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $backupPath = __DIR__ . '/storage/database/app_backup_retry_' . date('Ymd_His') . '.sqlite';
    copy($dbPath, $backupPath);
    echo "Backup: $backupPath\n";

    // 1. Fix EXTENSIONS / REDUCTIONS (User)
    // Stored as event_type='modified', event_subtype='extension'/'reduction'
    $stmt1 = $db->prepare("
        UPDATE guarantee_history 
        SET created_by = 'بواسطة المستخدم' 
        WHERE event_type = 'modified' 
        AND event_subtype IN ('extension', 'reduction')
    ");
    $stmt1->execute();
    echo "Updated Extensions/Reductions (User): " . $stmt1->rowCount() . " rows\n";

    // 2. Fix MANUAL MATCHES (User)
    // Stored as event_type='modified', event_subtype='manual_edit'/'supplier_change'
    // Also cover legacy 'manual_match' type if any
    $stmt2 = $db->prepare("
        UPDATE guarantee_history 
        SET created_by = 'بواسطة المستخدم' 
        WHERE (event_type = 'modified' AND event_subtype IN ('manual_edit', 'supplier_change'))
           OR event_type = 'manual_match'
           OR event_subtype = 'manual_match'
    ");
    $stmt2->execute();
    echo "Updated Manual Matches (User): " . $stmt2->rowCount() . " rows\n";

    // 3. Fix RELEASES (User)
    $stmt3 = $db->prepare("
        UPDATE guarantee_history 
        SET created_by = 'بواسطة المستخدم' 
        WHERE event_type IN ('release', 'released') 
           OR event_subtype = 'release'
    ");
    $stmt3->execute();
    echo "Updated Releases (User): " . $stmt3->rowCount() . " rows\n";

    // 4. Fix IMPORTS (User) - Optional but recommended as user usually initiates import
    $stmt4 = $db->prepare("
        UPDATE guarantee_history 
        SET created_by = 'بواسطة المستخدم' 
        WHERE event_type = 'import' 
        AND event_subtype IN ('excel', 'manual', 'smart_paste')
    ");
    $stmt4->execute();
    echo "Updated Imports (User): " . $stmt4->rowCount() . " rows\n";
    
    // 5. Verify distribution
    $stmt5 = $db->query("SELECT created_by, count(*) as c FROM guarantee_history GROUP BY created_by");
    $rows = $stmt5->fetchAll(PDO::FETCH_ASSOC);
    echo "Final Distribution:\n" . json_encode($rows, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
