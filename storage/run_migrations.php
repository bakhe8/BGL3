<?php
/**
 * Migration Runner
 * Executes database migrations
 */

$dbPath = __DIR__ . '/database/app.sqlite';

if (!file_exists($dbPath)) {
    die("Database not found at: $dbPath\n");
}

try {
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    echo "Connected to database\n";

    // Migration 003: Add normalized_name to banks
    echo "\n=== Running Migration 003: Add normalized_name to banks ===\n";

    $migration003 = file_get_contents(__DIR__ . '/migrations/003_add_normalized_name_to_banks.sql');
    $db->exec($migration003);

    echo "✓ Migration 003 completed successfully\n";

    // Verify
    $result = $db->query("SELECT id, arabic_name, normalized_name FROM banks LIMIT 3");
    echo "\nSample banks after migration:\n";
    while ($row = $result->fetch(PDO::FETCH_ASSOC)) {
        echo "  - {$row['arabic_name']} → {$row['normalized_name']}\n";
    }

    echo "\n=== Running Migration 004: Remove extra_columns ===\n";

    $migration004 = file_get_contents(__DIR__ . '/migrations/004_remove_extra_columns.sql');
    $db->exec($migration004);

    echo "✓ Migration 004 completed successfully\n";

    // Verify
    $count = $db->query("SELECT COUNT(*) FROM guarantee_decisions")->fetchColumn();
    echo "\nGuarantee decisions count: $count\n";

    echo "\n✓ All migrations completed successfully!\n";

} catch (Exception $e) {
    echo "✗ Error: " . $e->getMessage() . "\n";
    exit(1);
}
