<?php
require __DIR__ . '/setup/SetupDatabase.php';

$db = SetupDatabase::connect();
$stmt = $db->query('SELECT bank_name, bank_info, occurrence_count FROM temp_banks LIMIT 5');

echo "=== Bank Data Check ===\n\n";

while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo "Bank: " . $row['bank_name'] . "\n";
    echo "Count: " . $row['occurrence_count'] . "\n";
    echo "Info: " . ($row['bank_info'] ?: 'NULL') . "\n";
    if ($row['bank_info']) {
        $info = json_decode($row['bank_info'], true);
        print_r($info);
    }
    echo "---\n";
}
