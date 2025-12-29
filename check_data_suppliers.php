<?php
try {
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $stmt = $pdo->query("SELECT * FROM suppliers LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (PDOException $e) { echo $e->getMessage(); }
