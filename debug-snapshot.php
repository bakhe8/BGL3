<?php
$db = new PDO('sqlite:V3/storage/database/app.sqlite');
$stmt = $db->query("SELECT snapshot_data FROM guarantee_history ORDER BY id DESC LIMIT 1");
echo $stmt->fetchColumn();
