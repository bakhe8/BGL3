<?php
try {
    $dbPath = __DIR__ . '/storage/database/app.sqlite';
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Update Saudi Fransi
    $stmt = $pdo->prepare("
        UPDATE banks 
        SET 
            department = 'إدارة عمليات التجارة المركزية',
            address_line1 = '56006 الرياض 11554',
            contact_email = 'trade@alfransi.com.sa'
        WHERE id = 12
    ");
    $stmt->execute();
    
    echo "Updated Saudi Fransi details successfully.\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
