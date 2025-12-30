<?php
$db = new PDO('sqlite:storage/database/app.sqlite');
$stmt = $db->query("PRAGMA table_info(guarantee_history)");
echo "Columns:\n";
while ($col = $stmt->fetch()) {
    echo $col['name'] . " - " . $col['type'] . "\n";
}

echo "\nTotal rows:\n";
$stmt = $db->query("SELECT COUNT(*) FROM guarantee_history");
echo $stmt->fetchColumn() . "\n";

echo "\nSample row:\n";
$stmt = $db->query("SELECT * FROM guarantee_history LIMIT 1");
$row = $stmt->fetch(PDO::FETCH_ASSOC);
print_r($row);
