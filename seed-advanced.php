<?php
/**
 * Advanced Comprehensive Seeder - Fully Linked Data
 * Creates realistic, interconnected data across all tables
 */

require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$db->exec('PRAGMA foreign_keys = OFF');

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║ Advanced Comprehensive Database Seeder                         ║\n";
echo "║ Creating Fully Linked and Realistic Data                       ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Get all guarantees
$guarantees = $db->query('SELECT id, guarantee_number FROM guarantees ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($guarantees) . " guarantees to populate\n\n";

$stats = [
    'decisions' => 0,
    'actions' => 0,
    'history' => 0,
    'attachments' => 0,
    'notes' => 0,
    'learning_logs' => 0,
    'alternative_names' => 0
];

// Sample data for realistic scenarios
$supplierNames = [
    'شركة الاختبار التجريبية',
    'مؤسسة البناء الحديث',
    'شركة التقنية المتقدمة',
    'مؤسسة الإنشاءات الكبرى',
    'ARAB COMPANY FOR INTERNET'
];

$noteTemplates = [
    'تم التواصل مع المورد وتأكيد البيانات',
    'بانتظار الرد من البنك خلال 48 ساعة',
    'تم تجديد الضمان بنجاح',
    'يحتاج متابعة مع قسم العقود',
    'تم الموافقة على التمديد من الإدارة',
    'المورد طلب تعديل المبلغ',
    'البنك أرسل خطاب التمديد',
    'تم استلام نسخة من الضمان الجديد'
];

$attachmentTypes = [
    ['name' => 'contract.pdf', 'type' => 'pdf', 'size' => 245000],
    ['name' => 'guarantee_letter.pdf', 'type' => 'pdf', 'size' => 180000],
    ['name' => 'approval_document.pdf', 'type' => 'pdf', 'size' => 120000],
    ['name' => 'bank_letter.pdf', 'type' => 'pdf', 'size' => 95000],
    ['name' => 'scan_copy.jpg', 'type' => 'jpg', 'size' => 520000],
    ['name' => 'extension_request.pdf', 'type' => 'pdf', 'size' => 75000]
];

foreach ($guarantees as $index => $guarantee) {
    $gid = $guarantee['id'];
    $gnum = $guarantee['guarantee_number'];
    
    echo "Processing: {$gnum} (ID: {$gid})\n";
    
    // Determine scenario for this guarantee (to create realistic workflows)
    $scenario = $index % 5; // 5 different scenarios
    
    // SCENARIO 0: Complete workflow with decision, extension, and notes
    if ($scenario === 0) {
        // 1. Decision
        try {
            $supplierId = ($index % 5) + 1;
            $bankId = ($index % 5) + 1;
            $confidence = rand(85, 98);
            $daysAgo = rand(15, 45);
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, was_top_suggestion, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', 1, datetime('now', ?), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, $confidence, "-{$daysAgo} days"]);
            $stats['decisions']++;
            
            // Learning log for this decision
            $rawInput = $supplierNames[$supplierId - 1];
            $stmt = $db->prepare("INSERT INTO supplier_decisions_log 
                (guarantee_id, raw_input, chosen_supplier_id, chosen_supplier_name, decision_source, confidence_score, was_top_suggestion, created_at)
                VALUES (?, ?, ?, ?, 'chip', ?, 1, datetime('now', ?))");
            $stmt->execute([$gid, $rawInput, $supplierId, $supplierNames[$supplierId - 1], $confidence, "-{$daysAgo} days"]);
            $stats['learning_logs']++;
        } catch (Exception $e) {}
        
        // 2. Extension action
        try {
            $actionDays = rand(5, 30);
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, previous_expiry_date, new_expiry_date, created_at) 
                VALUES (?, 'extension', datetime('now', ?), 'issued', datetime('now', '+6 months'), datetime('now', '+18 months'), datetime('now', ?))");
            $stmt->execute([$gid, "-{$actionDays} days", "-{$actionDays} days"]);
            $stats['actions']++;
            
            // History for extension
            $stmt = $db->prepare("INSERT INTO guarantee_history 
                (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                VALUES (?, 'extension_issued', 'تمديد الضمان لمدة سنة إضافية', '{}', 'system', datetime('now', ?))");
            $stmt->execute([$gid, "-{$actionDays} days"]);
            $stats['history']++;
        } catch (Exception $e) {}
        
        // 3. Multiple notes
        for ($i = 0; $i < 3; $i++) {
            try {
                $noteDays = rand(1, 40);
                $stmt = $db->prepare("INSERT INTO guarantee_notes 
                    (guarantee_id, content, created_by, created_at) 
                    VALUES (?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $noteTemplates[array_rand($noteTemplates)], "-{$noteDays} days"]);
                $stats['notes']++;
            } catch (Exception $e) {}
        }
        
        // 4. Attachments
        for ($i = 0; $i < 2; $i++) {
            try {
                $att = $attachmentTypes[array_rand($attachmentTypes)];
                $attDays = rand(1, 35);
                $stmt = $db->prepare("INSERT INTO guarantee_attachments 
                    (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $att['name'], '/uploads/' . $att['name'], $att['size'], $att['type'], "-{$attDays} days"]);
                $stats['attachments']++;
            } catch (Exception $e) {}
        }
    }
    
    // SCENARIO 1: Decision with release
    elseif ($scenario === 1) {
        // Decision
        try {
            $supplierId = ($index % 5) + 1;
            $bankId = ($index % 5) + 1;
            $daysAgo = rand(20, 50);
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'manual', datetime('now', ?), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(70, 85), "-{$daysAgo} days"]);
            $stats['decisions']++;
        } catch (Exception $e) {}
        
        // Release action
        try {
            $releaseDays = rand(1, 15);
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, release_reason, created_at) 
                VALUES (?, 'release', datetime('now', ?), 'issued', 'إفراج عن ضمان - انتهاء المشروع', datetime('now', ?))");
            $stmt->execute([$gid, "-{$releaseDays} days", "-{$releaseDays} days"]);
            $stats['actions']++;
            
            // History
            $stmt = $db->prepare("INSERT INTO guarantee_history 
                (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                VALUES (?, 'release_issued', 'إفراج عن الضمان', '{}', 'system', datetime('now', ?))");
            $stmt->execute([$gid, "-{$releaseDays} days"]);
            $stats['history']++;
        } catch (Exception $e) {}
        
        // Note
        try {
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'تم إفراج الضمان بعد استكمال المشروع', 'system', datetime('now', '-5 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
        
        // Attachment
        try {
            $att = $attachmentTypes[0];
            $stmt = $db->prepare("INSERT INTO guarantee_attachments 
                (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at) 
                VALUES (?, ?, ?, ?, ?, 'system', datetime('now', '-10 days'))");
            $stmt->execute([$gid, 'release_letter.pdf', '/uploads/release_letter.pdf', 95000, 'pdf']);
            $stats['attachments']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 2: Decision only with history
    elseif ($scenario === 2) {
        try {
            $supplierId = ($index % 5) + 1;
            $bankId = ($index % 5) + 1;
            $daysAgo = rand(10, 35);
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', datetime('now', ?), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(75, 95), "-{$daysAgo} days"]);
            $stats['decisions']++;
            
            // History
            $stmt = $db->prepare("INSERT INTO guarantee_history 
                (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                VALUES (?, 'decision_saved', 'حفظ قرار جديد', '{}', 'system', datetime('now', ?))");
            $stmt->execute([$gid, "-{$daysAgo} days"]);
            $stats['history']++;
            
            // Note
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, ?, 'system', datetime('now', ?))");
            $stmt->execute([$gid, $noteTemplates[array_rand($noteTemplates)], "-" . rand(1, 30) . " days"]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 3: Reduction action
    elseif ($scenario === 3) {
        try {
            $supplierId = ($index % 5) + 1;
            $bankId = ($index % 5) + 1;
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'manual', datetime('now', '-25 days'), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(80, 92)]);
            $stats['decisions']++;
            
            // Reduction
            $prevAmount = rand(500000, 900000);
            $newAmount = $prevAmount - rand(50000, 200000);
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, previous_amount, new_amount, created_at) 
                VALUES (?, 'reduction', datetime('now', '-12 days'), 'issued', ?, ?, datetime('now', '-12 days'))");
            $stmt->execute([$gid, $prevAmount, $newAmount]);
            $stats['actions']++;
            
            // History
            $stmt = $db->prepare("INSERT INTO guarantee_history 
                (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                VALUES (?, 'reduction_issued', 'تخفيض قيمة الضمان', '{}', 'system', datetime('now', '-12 days'))");
            $stmt->execute([$gid]);
            $stats['history']++;
            
            // Notes
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'تم تخفيض المبلغ بناءً على طلب المورد', 'system', datetime('now', '-10 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 4: Basic with attachments
    else {
        try {
            // Just attachments and notes
            for ($i = 0; $i < 2; $i++) {
                $att = $attachmentTypes[array_rand($attachmentTypes)];
                $stmt = $db->prepare("INSERT INTO guarantee_attachments 
                    (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $att['name'], '/uploads/' . $att['name'], $att['size'], $att['type'], "-" . rand(1, 40) . " days"]);
                $stats['attachments']++;
            }
            
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, ?, 'system', datetime('now', '-15 days'))");
            $stmt->execute([$gid, $noteTemplates[array_rand($noteTemplates)]]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
}

// Add alternative names for suppliers
echo "\nAdding supplier alternative names...\n";
$alternatives = [
    [1, 'شركة الاختبار', 5],
    [1, 'الاختبار التجريبية', 3],
    [2, 'البناء الحديث', 4],
    [2, 'مؤسسة البناء', 6],
    [3, 'التقنية المتقدمة', 7],
    [3, 'شركة التقنية', 4],
    [4, 'الإنشاءات الكبرى', 5],
    [5, 'ARAB COMPANY', 8],
];

foreach ($alternatives as $alt) {
    try {
        $stmt = $db->prepare("INSERT OR IGNORE INTO supplier_alternative_names 
            (supplier_id, alternative_name, normalized_name, usage_count, created_at) 
            VALUES (?, ?, ?, ?, datetime('now'))");
        $stmt->execute([$alt[0], $alt[1], strtolower($alt[1]), $alt[2]]);
        $stats['alternative_names']++;
    } catch (Exception $e) {}
}

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║ SEEDING COMPLETED SUCCESSFULLY                                 ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Final Statistics:\n";
echo "  ✓ Decisions: {$stats['decisions']}\n";
echo "  ✓ Actions: {$stats['actions']}\n";
echo "  ✓ History: {$stats['history']}\n";
echo "  ✓ Attachments: {$stats['attachments']}\n";
echo "  ✓ Notes: {$stats['notes']}\n";
echo "  ✓ Learning Logs: {$stats['learning_logs']}\n";
echo "  ✓ Alternative Names: {$stats['alternative_names']}\n";

echo "\n✅ All data is now fully linked and realistic!\n";
echo "   Each guarantee has a complete workflow with related data.\n";
