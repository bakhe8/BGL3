<?php
// Seed Suppliers & Banks from Original DB to V3
echo "=== Seeding Suppliers & Banks to V3 ===\n\n";

try {
    // Original DB
    $dbOriginal = new PDO('sqlite:storage/database/app.sqlite');
    $dbOriginal->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // V3 DB
    $dbV3 = new PDO('sqlite:storage/database/app.sqlite');
    $dbV3->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // 1. Copy Suppliers
    echo "📋 Copying Suppliers...\n";
    $suppliers = $dbOriginal->query('SELECT * FROM suppliers LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $dbV3->prepare('
        INSERT INTO suppliers (official_name, display_name, normalized_name, supplier_normalized_key, is_confirmed, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    
    $count = 0;
    foreach ($suppliers as $supplier) {
        $stmt->execute([
            $supplier['official_name'],
            $supplier['display_name'] ?? null,
            $supplier['normalized_name'],
            $supplier['supplier_normalized_key'] ?? null,
            $supplier['is_confirmed'] ?? 0,
            $supplier['created_at'] ?? date('Y-m-d H:i:s')
        ]);
        $count++;
    }
    
    echo "✅ Copied $count suppliers\n\n";
    
    // 2. Copy Banks
    echo "📋 Copying Banks...\n";
    $banks = $dbOriginal->query('SELECT * FROM banks LIMIT 50')->fetchAll(PDO::FETCH_ASSOC);
    
    $stmt = $dbV3->prepare('
        INSERT INTO banks (official_name, official_name_en, short_code, normalized_name, is_confirmed, created_at)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    
    $count = 0;
    foreach ($banks as $bank) {
        // Use normalized_key as normalized_name if it exists
        $normalizedName = $bank['normalized_key'] ?? $bank['normalized_name'] ?? $bank['short_code'];
        
        $stmt->execute([
            $bank['official_name'],
            $bank['official_name_en'] ?? null,
            $bank['short_code'],
            $normalizedName,
            $bank['is_confirmed'] ?? 0,
            $bank['created_at'] ?? date('Y-m-d H:i:s')
        ]);
        $count++;
    }
    
    echo "✅ Copied $count banks\n\n";
    
    // 3. Verify
    echo "=== Verification ===\n";
    $supplierCount = $dbV3->query('SELECT COUNT(*) FROM suppliers')->fetchColumn();
    $bankCount = $dbV3->query('SELECT COUNT(*) FROM banks')->fetchColumn();
    
    echo "Suppliers in V3: $supplierCount\n";
    echo "Banks in V3: $bankCount\n";
    
    echo "\n🎉 Seeding complete!\n";
    
} catch (Exception $e) {
    echo "❌ Error: " . $e->getMessage() . "\n";
    exit(1);
}
