<?php
try {
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    // Add missing columns
    $commands = [
        "ALTER TABLE banks ADD COLUMN department TEXT DEFAULT NULL",
        "ALTER TABLE banks ADD COLUMN address_line1 TEXT DEFAULT NULL",
        "ALTER TABLE banks ADD COLUMN contact_email TEXT DEFAULT NULL"
    ];

    foreach ($commands as $cmd) {
        try {
            $pdo->exec($cmd);
            echo "Executed: $cmd\n";
        } catch (PDOException $e) {
            // Ignore if column exists (though we checked it doesn't)
            echo "Skipped/Error: " . $e->getMessage() . "\n";
        }
    }

    echo "Schema migration completed.\n";

} catch (PDOException $e) {
    echo "Fatal Error: " . $e->getMessage();
}
