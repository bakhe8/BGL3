<?php
try {
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $stmt = $pdo->query("PRAGMA table_info(banks)");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['name'] . "\n";
    }
} catch (PDOException $e) { echo $e->getMessage(); }
