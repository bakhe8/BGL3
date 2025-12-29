<?php
try {
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Add english_name if not exists
    $columns = $pdo->query("PRAGMA table_info(suppliers)")->fetchAll(PDO::FETCH_COLUMN, 1);
    
    if (!in_array('english_name', $columns)) {
        $pdo->exec("ALTER TABLE suppliers ADD COLUMN english_name TEXT DEFAULT NULL");
        echo "Added english_name column.\n";
    }

    // Rename display_name to unified_name if desired, or justalias it in display
    // Current schema has display_name. The user wants "الاسم الموحد". 
    // I will use display_name as Unified Name in the UI. 
    
    echo "Schema check/update completed.\n";

} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
