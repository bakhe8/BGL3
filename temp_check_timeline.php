<?php
$db = new PDO('sqlite:storage/database/app.sqlite');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "=== schema guarantee_history ===\n";
$stmt = $db->query("PRAGMA table_info(guarantee_history)");
while ($col = $stmt->fetch()) {
    echo sprintf("%-20s %s\n", $col['name'], $col['type']);
}

echo "\n=== عينة من البيانات ===\n";
$stmt = $db->query("SELECT * FROM guarantee_history LIMIT 3");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
foreach ($rows as $row) {
    echo json_encode($row, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n---\n";
}

echo "\n=== البحث عن أحداث البنك ===\n";
$stmt = $db->query("SELECT COUNT(*) as count FROM guarantee_history WHERE action LIKE '%بنك%' OR action LIKE '%bank%' OR change_reason LIKE '%بنك%' OR change_reason LIKE '%bank%'");
echo "الأحداث المحتملة للبنك: " . $stmt->fetchColumn() . "\n";

$stmt = $db->query("SELECT * FROM guarantee_history WHERE action LIKE '%بنك%' OR action LIKE '%bank%' OR change_reason LIKE '%بنك%' OR change_reason LIKE '%bank%' ORDER BY created_at DESC LIMIT 10");
$events = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "\nآخر 10 أحداث:\n";
foreach ($events as $event) {
    echo sprintf("[%d] %s - %s (%s)\n", $event['id'], $event['action'], $event['change_reason'], $event['created_at']);
}
