<?php
try {
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    // Fetch a few rows with keys to see columns clearly
    $stmt = $pdo->query("SELECT id, official_name, display_name FROM suppliers LIMIT 5");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($rows);
} catch (PDOException $e) { echo $e->getMessage(); }
