<?php
$db = new PDO('sqlite:V3/storage/database/app.sqlite');
echo "--- Banks Table ---\n";
foreach($db->query("PRAGMA table_info(banks)") as $row) {
    echo $row['name'] . "\n";
}
echo "\n--- Guarantee Decisions Table ---\n";
foreach($db->query("PRAGMA table_info(guarantee_decisions)") as $row) {
    echo $row['name'] . "\n";
}
