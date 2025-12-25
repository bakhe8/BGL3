<?php
$dbPath = __DIR__ . '/storage/database/app.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

ob_start();

echo "=== CURRENT guarantee_history Schema ===\n\n";

$cols = $pdo->query("PRAGMA table_info(guarantee_history)")->fetchAll(PDO::FETCH_ASSOC);

printf("%-20s | %-15s | %-10s | %-10s\n", 'Column Name', 'Type', 'NotNull', 'Default');
echo str_repeat('-', 70) . "\n";
foreach ($cols as $col) {
    printf("%-20s | %-15s | %-10s | %-10s\n",
        $col['name'],
        $col['type'],
        $col['notnull'] ? 'YES' : 'NO',
        $col['dflt_value'] ?? 'NULL'
    );
}

echo "\n\n=== Sample Data (Latest 3 Records) ===\n";
$samples = $pdo->query("SELECT * FROM guarantee_history ORDER BY id DESC LIMIT 3")->fetchAll(PDO::FETCH_ASSOC);
foreach ($samples as $i => $s) {
    echo "\n--- Record " . ($i+1) . " (ID: {$s['id']}) ---\n";
    foreach ($s as $key => $val) {
        printf("%-20s: %s\n", $key, substr($val ?? 'NULL', 0, 100));
    }
}

echo "\n\n=== Total Records ===\n";
$count = $pdo->query("SELECT COUNT(*) FROM guarantee_history")->fetchColumn();
echo "Total events: $count\n";

$output = ob_get_clean();
file_put_contents(__DIR__ . '/schema_analysis.txt', $output);
echo $output;
