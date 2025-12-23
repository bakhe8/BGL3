<?php
/**
 * Comprehensive Database Seeder for V3
 * Populates all tables with diverse sample data
 */

require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║ V3 Database Comprehensive Seeder                               ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Get all guarantees
$stmt = $db->query('SELECT id, guarantee_number FROM guarantees ORDER BY id LIMIT 28');
$guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($guarantees) . " guarantees to populate\n\n";

// Sample suppliers and banks for learning
$suppliers = [
    ['id' => 1, 'name' => 'شركة الاختبار التجريبية', 'normalized' => 'شركه الاختبار التجريبيه'],
    ['id' => 2, 'name' => 'مؤسسة البناء الحديث', 'normalized' => 'مؤسسه البناء الحديث'],
    ['id' => 3, 'name' => 'شركة التقنية المتقدمة', 'normalized' => 'شركه التقنيه المتقدمه'],
    ['id' => 4, 'name' => 'مؤسسة الإنشاءات الكبرى', 'normalized' => 'مؤسسه الانشاءات الكبرى'],
    ['id' => 5, 'name' => 'ARAB COMPANY FOR INTERNET', 'normalized' => 'arab company for internet'],
];

$banks = [
    ['id' => 1, 'name' => 'SNB', 'full_name' => 'البنك الأهلي السعودي'],
    ['id' => 2, 'name' => 'الراجحي', 'full_name' => 'مصرف الراجحي'],
    ['id' => 3, 'name' => 'الأهلي', 'full_name' => 'البنك الأهلي التجاري'],
    ['id' => 4, 'name' => 'سامبا', 'full_name' => 'بنك سامبا'],
    ['id' => 5, 'name' => 'الرياض', 'full_name' => 'بنك الرياض'],
];

// Ensure suppliers exist
echo "1. Creating Suppliers...\n";
foreach ($suppliers as $supplier) {
    $stmt = $db->prepare('INSERT OR REPLACE INTO suppliers (id, name, normalized_name, normalized_key) VALUES (?, ?, ?, ?)');
    $stmt->execute([
        $supplier['id'],
        $supplier['name'],
        $supplier['normalized'],
        md5($supplier['normalized'])
    ]);
}
echo "   ✓ Suppliers created\n\n";

// Ensure banks exist
echo "2. Creating Banks...\n";
foreach ($banks as $bank) {
    $stmt = $db->prepare('INSERT OR REPLACE INTO banks (id, name, full_name) VALUES (?, ?, ?)');
    $stmt->execute([$bank['id'], $bank['name'], $bank['full_name']]);
}
echo "   ✓ Banks created\n\n";

// Counter for statistics
$stats = [
    'decisions' => 0,
    'actions' => 0,
    'history' => 0,
    'attachments' => 0,
    'notes' => 0,
    'learning_logs' => 0,
    'alternative_names' => 0
];

echo "3. Populating Data for Each Guarantee...\n";

foreach ($guarantees as $index => $guarantee) {
    $gid = $guarantee['id'];
    $gnum = $guarantee['guarantee_number'];
    
    echo "   Processing: {$gnum} (ID: {$gid})\n";
    
    // Randomly decide what data to add (70% chance for each type)
    $addDecision = rand(1, 100) <= 70;
    $addAction = rand(1, 100) <= 50;
    $addHistory = rand(1, 100) <= 60;
    $addAttachment = rand(1, 100) <= 40;
    $addNote = rand(1, 100) <= 50;
    
    // 1. Add Decision
    if ($addDecision) {
        // Check if decision already exists
        $checkStmt = $db->prepare('SELECT COUNT(*) FROM guarantee_decisions WHERE guarantee_id = ?');
        $checkStmt->execute([$gid]);
        if ($checkStmt->fetchColumn() == 0) {
            $supplierId = $suppliers[array_rand($suppliers)]['id'];
            $bankId = $banks[array_rand($banks)]['id'];
            $confidence = rand(75, 100);
            
            $stmt = $db->prepare('
                INSERT INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, was_top_suggestion, decided_at, decided_by)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now", "-" || ? || " days"), ?)
            ');
            $stmt->execute([
                $gid, $supplierId, $bankId, 'ready', $confidence, 
                rand(0, 1) ? 'chip' : 'manual', 
                rand(0, 1), 
                rand(1, 30), 
                'system'
            ]);
            $stats['decisions']++;
            
            // Add learning log
            $rawInput = "شركة " . $suppliers[$supplierId - 1]['name'];
            $stmt = $db->prepare('
                INSERT INTO supplier_decisions_log
                (guarantee_id, raw_input, chosen_supplier_id, chosen_supplier_name, decision_source, confidence_score, was_top_suggestion, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now", "-" || ? || " days"))
            ');
            $stmt->execute([
                $gid, $rawInput, $supplierId, $suppliers[$supplierId - 1]['name'],
                rand(0, 1) ? 'chip' : 'manual', $confidence, rand(0, 1), rand(1, 30)
            ]);
            $stats['learning_logs']++;
        }
    }
    
    // 2. Add Actions (Extension, Release, or Reduction)
    if ($addAction) {
        $actionType = ['extension', 'release', 'reduction'][rand(0, 2)];
        $actionDate = date('Y-m-d', strtotime('-' . rand(1, 60) . ' days'));
        
        if ($actionType === 'extension') {
            $prevExpiry = date('Y-m-d', strtotime('+' . rand(1, 12) . ' months'));
            $newExpiry = date('Y-m-d', strtotime($prevExpiry . ' +1 year'));
            
            $stmt = $db->prepare('
                INSERT INTO guarantee_actions
                (guarantee_id, action_type, action_date, action_status, previous_expiry_date, new_expiry_date, performed_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now", "-" || ? || " days"))
            ');
            $stmt->execute([$gid, $actionType, $actionDate, 'issued', $prevExpiry, $newExpiry, 'system', rand(1, 60)]);
        } elseif ($actionType === 'release') {
            $stmt = $db->prepare('
                INSERT INTO guarantee_actions
                (guarantee_id, action_type, action_date, action_status, release_reason, performed_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, datetime("now", "-" || ? || " days"))
            ');
            $stmt->execute([$gid, $actionType, $actionDate, 'issued', 'إفراج عن ضمان', 'system', rand(1, 60)]);
        } else {
            $prevAmount = rand(100000, 900000);
            $newAmount = $prevAmount - rand(10000, 50000);
            
            $stmt = $db->prepare('
                INSERT INTO guarantee_actions
                (guarantee_id, action_type, action_date, action_status, previous_amount, new_amount, performed_by, created_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, datetime("now", "-" || ? || " days"))
            ');
            $stmt->execute([$gid, $actionType, $actionDate, 'issued', $prevAmount, $newAmount, 'system', rand(1, 60)]);
        }
        $stats['actions']++;
    }
    
    // 3. Add History
    if ($addHistory) {
        $actions = ['decision_saved', 'extension_issued', 'release_requested', 'note_added'];
        $action = $actions[array_rand($actions)];
        
        $stmt = $db->prepare('
            INSERT INTO guarantee_history
            (guarantee_id, action, change_reason, created_by, created_at)
            VALUES (?, ?, ?, ?, datetime("now", "-" || ? || " days"))
        ');
        $stmt->execute([$gid, $action, 'تحديث تلقائي', 'system', rand(1, 45)]);
        $stats['history']++;
    }
    
    // 4. Add Attachments
    if ($addAttachment) {
        $fileNames = ['contract.pdf', 'letter.pdf', 'approval.pdf', 'scan.jpg'];
        $fileName = $fileNames[array_rand($fileNames)];
        
        $stmt = $db->prepare('
            INSERT INTO guarantee_attachments
            (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, datetime("now", "-" || ? || " days"))
        ');
        $stmt->execute([
            $gid, $fileName, '/uploads/' . $fileName, rand(50000, 500000),
            pathinfo($fileName, PATHINFO_EXTENSION), 'system', rand(1, 40)
        ]);
        $stats['attachments']++;
    }
    
    // 5. Add Notes
    if ($addNote) {
        $noteContents = [
            'تم التواصل مع المورد',
            'بانتظار الرد من البنك',
            'تم تجديد الضمان بنجاح',
            'يحتاج متابعة',
            'تم الموافقة على التمديد'
        ];
        $content = $noteContents[array_rand($noteContents)];
        
        $stmt = $db->prepare('
            INSERT INTO guarantee_notes
            (guarantee_id, content, created_by, created_at)
            VALUES (?, ?, ?, datetime("now", "-" || ? || " days"))
        ');
        $stmt->execute([$gid, $content, 'system', rand(1, 35)]);
        $stats['notes']++;
    }
}

echo "\n4. Adding Alternative Names for Suppliers...\n";
// Add alternative names
$alternativeNames = [
    [1, 'شركة الاختبار'],
    [1, 'الاختبار التجريبية'],
    [2, 'البناء الحديث'],
    [2, 'مؤسسة البناء'],
    [3, 'التقنية المتقدمة'],
    [3, 'شركة التقنية'],
];

foreach ($alternativeNames as $alt) {
    $stmt = $db->prepare('
        INSERT OR IGNORE INTO supplier_alternative_names
        (supplier_id, alternative_name, normalized_name, usage_count, created_at)
        VALUES (?, ?, ?, ?, datetime("now"))
    ');
    $stmt->execute([$alt[0], $alt[1], strtolower($alt[1]), rand(1, 10)]);
    $stats['alternative_names']++;
}

echo "   ✓ Alternative names added\n\n";

// Summary
echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║ SEEDING COMPLETED                                              ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Statistics:\n";
echo "  - Decisions: {$stats['decisions']}\n";
echo "  - Actions: {$stats['actions']}\n";
echo "  - History: {$stats['history']}\n";
echo "  - Attachments: {$stats['attachments']}\n";
echo "  - Notes: {$stats['notes']}\n";
echo "  - Learning Logs: {$stats['learning_logs']}\n";
echo "  - Alternative Names: {$stats['alternative_names']}\n";
echo "\n✅ Database populated successfully!\n";
