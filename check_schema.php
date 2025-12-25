<?php
$dbPath = __DIR__ . '/storage/database/app.sqlite';

$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== Table Structure for guarantee_history ===\n\n";

// Get table info
$stmt = $pdo->query("PRAGMA table_info(guarantee_history)");
$columns = $stmt->fetchAll(PDO::FETCH_ASSOC);

printf("%-5s | %-25s | %-15s | %-10s | %-10s\n", 'CID', 'Name', 'Type', 'NotNull', 'Default');
echo str_repeat('-', 80) . "\n";

foreach ($columns as $col) {
    printf("%-5s | %-25s | %-15s | %-10s | %-10s\n",
        $col['cid'],
        $col['name'],
        $col['type'],
        $col['notnull'] ? 'YES' : 'NO',
        $col['dflt_value'] ?? 'NULL'
    );
}

echo "\n\n=== Sample Row ===\n";
$sample = $pdo->query("SELECT * FROM guarantee_history LIMIT 1")->fetch(PDO::FETCH_ASSOC);
if ($sample) {
    print_r($sample);
}
