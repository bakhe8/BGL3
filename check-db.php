<?php
$db = new PDO('sqlite:V3/storage/database/app.sqlite');
$count = $db->query('SELECT COUNT(*) FROM guarantees')->fetchColumn();
echo "V3 DB has $count records\n";

$db2 = new PDO('sqlite:storage/database.sqlite');
$count2 = $db2->query('SELECT COUNT(*) FROM guarantees')->fetchColumn();
echo "Original DB has $count2 records\n";
