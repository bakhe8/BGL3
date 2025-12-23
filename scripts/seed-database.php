<?php
/**
 * Seed Database for V3 Testing
 * Direct PDO approach - no dependencies
 */

echo "=== V3 Database Seed Script ===\n\n";

try {
    // Connect directly to database
    $dbPath = __DIR__ . '/../storage/database/app.sqlite';
    
    if (!file_exists($dbPath)) {
        throw new Exception("Database not found at: {$dbPath}");
    }
    
    $db = new PDO('sqlite:' . $dbPath);
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    // Check existing data
    $stmt = $db->query('SELECT COUNT(*) FROM guarantees');
    $existing = $stmt->fetchColumn();
    
    if ($existing > 0) {
        echo "⚠️  Database already has {$existing} records.\n";
        echo "Adding 20 more...\n\n";
    }
    
    echo "Creating 20 sample guarantees...\n\n";
    
    // Sample data
    $suppliers = [
        'شركة الاختبار التجريبية',
        'ARAB COMPANY FOR INTERNET AND COMMUNICATIONS',
        'مؤسسة البناء الحديث',
        'شركة التقنية المتقدمة',
        'مؤسسة الإنشاءات الكبرى'
    ];
    
    $banks = ['SNB', 'الراجحي', 'الأهلي', 'سامبا', 'الرياض'];
    $types = ['ابتدائي', 'نهائي'];
    
    $insertStmt = $db->prepare('
        INSERT INTO guarantees (
            guarantee_number, raw_data, import_source, imported_at, imported_by
        ) VALUES (?, ?, ?, ?, ?)
    ');
    
    $created = 0;
    
    for ($i = 1; $i <= 20; $i++) {
        $supplierIdx = ($i - 1) % count($suppliers);
        $bankIdx = ($i - 1) % count($banks);
        
        $amount = rand(100000, 1000000);
        $issueDate = date('Y-m-d', strtotime("-" . rand(1, 365) . " days"));
        $expiryDate = date('Y-m-d', strtotime("+6 months", strtotime($issueDate)));
        
        $guaranteeNumber = 'C' . str_pad($i + $existing, 6, '0', STR_PAD_LEFT);
        
        $rawData = [
            'session_id' => 1,
            'supplier' => $suppliers[$supplierIdx],
            'guarantee_number' => $guaranteeNumber,
            'bank' => $banks[$bankIdx],
            'amount' => $amount,
            'issue_date' => $issueDate,
            'expiry_date' => $expiryDate,
            'type' => $types[$i % 2],
            'contract_number' => 'CNT-2024-' . str_pad($i, 4, '0', STR_PAD_LEFT),
        ];
        
        $insertStmt->execute([
            $guaranteeNumber,
            json_encode($rawData, JSON_UNESCAPED_UNICODE),
            'seed_script',
            date('Y-m-d H:i:s'),
            'system'
        ]);
        
        $created++;
        echo "✓ Created #{$i}: {$guaranteeNumber} - {$suppliers[$supplierIdx]}\n";
    }
    
    // Final count
    $stmt = $db->query('SELECT COUNT(*) FROM guarantees');
    $total = $stmt->fetchColumn();
    
    echo "\n✅ Successfully created {$created} guarantees!\n";
    echo "\n=== Summary ===\n";
    echo "Total guarantees in DB: {$total}\n";
    echo "\n🎉 Database is ready for testing!\n";
    echo "\nNext: Test with http://localhost:8000/V3/\n";
    
} catch (\Throwable $e) {
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Trace: " . $e->getTraceAsString() . "\n";
    exit(1);
}
