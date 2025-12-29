<?php
try {
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    $stmt = $pdo->prepare("SELECT * FROM banks WHERE arabic_name LIKE '%الفرنسي%'");
    $stmt->execute();
    $banks = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($banks)) {
        echo "No banks found matching 'الفرنسي'\n";
    } else {
        foreach ($banks as $bank) {
            echo "ID: " . $bank['id'] . "\n";
            echo "Name: " . $bank['arabic_name'] . "\n";
            echo "Department: " . ($bank['department'] ?? 'NULL') . "\n";
            echo "PO Box: " . ($bank['address_line1'] ?? 'NULL') . "\n";
            echo "Email: " . ($bank['contact_email'] ?? 'NULL') . "\n";
            echo "-------------------\n";
        }
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
