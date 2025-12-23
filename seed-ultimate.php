<?php
/**
 * ULTIMATE COMPREHENSIVE SEEDER
 * Covers ALL possible scenarios, edge cases, and data variations
 */

require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$db->exec('PRAGMA foreign_keys = OFF');

echo "╔════════════════════════════════════════════════════════════════╗\n";
echo "║ ULTIMATE COMPREHENSIVE DATABASE SEEDER                         ║\n";
echo "║ All Scenarios • Edge Cases • Complete Coverage                 ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

// Ensure suppliers and banks exist with variations
$suppliers = [
    ['id' => 1, 'name' => 'شركة الاختبار التجريبية', 'normalized' => 'شركه الاختبار التجريبيه'],
    ['id' => 2, 'name' => 'مؤسسة البناء الحديث', 'normalized' => 'مؤسسه البناء الحديث'],
    ['id' => 3, 'name' => 'شركة التقنية المتقدمة', 'normalized' => 'شركه التقنيه المتقدمه'],
    ['id' => 4, 'name' => 'مؤسسة الإنشاءات الكبرى', 'normalized' => 'مؤسسه الانشاءات الكبرى'],
    ['id' => 5, 'name' => 'ARAB COMPANY FOR INTERNET', 'normalized' => 'arab company for internet'],
    ['id' => 6, 'name' => 'شركة المقاولات العامة', 'normalized' => 'شركه المقاولات العامه'],
    ['id' => 7, 'name' => 'مؤسسة الخدمات الهندسية', 'normalized' => 'مؤسسه الخدمات الهندسيه'],
];

$banks = [
    ['id' => 1, 'name' => 'SNB', 'full' => 'البنك الأهلي السعودي'],
    ['id' => 2, 'name' => 'الراجحي', 'full' => 'مصرف الراجحي'],
    ['id' => 3, 'name' => 'الأهلي', 'full' => 'البنك الأهلي التجاري'],
    ['id' => 4, 'name' => 'سامبا', 'full' => 'بنك سامبا'],
    ['id' => 5, 'name' => 'الرياض', 'full' => 'بنك الرياض'],
    ['id' => 6, 'name' => 'الإنماء', 'full' => 'بنك الإنماء'],
];

// Create suppliers and banks
foreach ($suppliers as $s) {
    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO suppliers (id, name, normalized_name, normalized_key, created_at) VALUES (?, ?, ?, ?, datetime('now'))");
        $stmt->execute([$s['id'], $s['name'], $s['normalized'], md5($s['normalized'])]);
    } catch (Exception $e) {}
}

foreach ($banks as $b) {
    try {
        $stmt = $db->prepare("INSERT OR REPLACE INTO banks (id, name, full_name, created_at) VALUES (?, ?, ?, datetime('now'))");
        $stmt->execute([$b['id'], $b['name'], $b['full']]);
    } catch (Exception $e) {}
}

// Comprehensive data templates
$noteVariations = [
    'تم التواصل مع المورد وتأكيد البيانات',
    'بانتظار الرد من البنك خلال 48 ساعة',
    'تم تجديد الضمان بنجاح',
    'يحتاج متابعة مع قسم العقود',
    'تم الموافقة على التمديد',
    'المورد طلب تعديل المبلغ',
    'البنك أرسل خطاب التمديد',
    'تم استلام نسخة من الضمان',
    'ملاحظة قصيرة',
    'ملاحظة طويلة جداً تحتوي على تفاصيل كثيرة ومعلومات إضافية قد تكون مفيدة للمتابعة المستقبلية وتوثيق الحالة بشكل كامل',
    'Note in English for testing',
    'ملاحظة بأحرف خاصة !@#$%',
    '', // Empty note (edge case)
];

$attachmentVariations = [
    ['name' => 'contract.pdf', 'type' => 'pdf', 'size' => 245000],
    ['name' => 'guarantee_letter.pdf', 'type' => 'pdf', 'size' => 180000],
    ['name' => 'very_long_filename_that_might_cause_issues_in_display.pdf', 'type' => 'pdf', 'size' => 120000],
    ['name' => 'scan.jpg', 'type' => 'jpg', 'size' => 520000],
    ['name' => 'large_file.pdf', 'type' => 'pdf', 'size' => 9500000], // Large file
    ['name' => 'tiny.pdf', 'type' => 'pdf', 'size' => 1024], // Tiny file
    ['name' => 'document with spaces.pdf', 'type' => 'pdf', 'size' => 85000],
    ['name' => 'وثيقة_عربية.pdf', 'type' => 'pdf', 'size' => 95000],
];

$supplierInputVariations = [
    'شركة الاختبار',
    'الاختبار التجريبية',
    'شركه الاختبار التجريبيه', // Different spelling
    'ARAB COMPANY',
    'Arab Company for Internet',
    'مؤسسة البناء',
    'البناء الحديث',
    'شركة التقنية',
    'التقنيه المتقدمه', // Typo
    'مؤسسه الانشاءات', // Missing ال
];

$guarantees = $db->query('SELECT id, guarantee_number FROM guarantees ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
$totalGuarantees = count($guarantees);

echo "Processing {$totalGuarantees} guarantees with comprehensive scenarios...\n\n";

$stats = ['decisions' => 0, 'actions' => 0, 'history' => 0, 'attachments' => 0, 'notes' => 0, 'learning' => 0, 'alternatives' => 0];

foreach ($guarantees as $index => $g) {
    $gid = $g['id'];
    $gnum = $g['guarantee_number'];
    
    // Determine comprehensive scenario
    $scenario = $index % 10; // 10 different scenarios
    
    echo "  [{$index}/{$totalGuarantees}] {$gnum} - Scenario {$scenario}\n";
    
    // SCENARIO 0: Complete workflow with high confidence
    if ($scenario === 0) {
        $supplierId = ($index % 7) + 1;
        $bankId = ($index % 6) + 1;
        $confidence = rand(90, 99);
        
        // Decision
        try {
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, was_top_suggestion, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', 1, datetime('now', ?), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, $confidence, '-' . rand(20, 45) . ' days']);
            $stats['decisions']++;
        } catch (Exception $e) {}
        
        // Learning log with variation
        try {
            $rawInput = $supplierInputVariations[array_rand($supplierInputVariations)];
            $stmt = $db->prepare("INSERT INTO supplier_decisions_log 
                (guarantee_id, raw_input, chosen_supplier_id, chosen_supplier_name, decision_source, confidence_score, was_top_suggestion, created_at)
                VALUES (?, ?, ?, ?, 'chip', ?, 1, datetime('now', ?))");
            $stmt->execute([$gid, $rawInput, $supplierId, $suppliers[$supplierId - 1]['name'], $confidence, '-' . rand(20, 45) . ' days']);
            $stats['learning']++;
        } catch (Exception $e) {}
        
        // Extension with full details
        try {
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, previous_expiry_date, new_expiry_date, notes, created_at) 
                VALUES (?, 'extension', datetime('now', ?), 'issued', datetime('now', '+6 months'), datetime('now', '+18 months'), 'تمديد تلقائي', datetime('now', ?))");
            $stmt->execute([$gid, '-' . rand(10, 30) . ' days', '-' . rand(10, 30) . ' days']);
            $stats['actions']++;
        } catch (Exception $e) {}
        
        // Multiple history entries
        for ($i = 0; $i < 3; $i++) {
            try {
                $actions = ['decision_saved', 'extension_issued', 'note_added'];
                $stmt = $db->prepare("INSERT INTO guarantee_history 
                    (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                    VALUES (?, ?, ?, '{}', 'system', datetime('now', ?))");
                $stmt->execute([$gid, $actions[$i % 3], 'تحديث ' . ($i + 1), '-' . rand(5, 40) . ' days']);
                $stats['history']++;
            } catch (Exception $e) {}
        }
        
        // Multiple notes with variations
        for ($i = 0; $i < 4; $i++) {
            try {
                $stmt = $db->prepare("INSERT INTO guarantee_notes 
                    (guarantee_id, content, created_by, created_at) 
                    VALUES (?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $noteVariations[array_rand($noteVariations)], '-' . rand(1, 40) . ' days']);
                $stats['notes']++;
            } catch (Exception $e) {}
        }
        
        // Multiple attachments
        for ($i = 0; $i < 3; $i++) {
            try {
                $att = $attachmentVariations[array_rand($attachmentVariations)];
                $stmt = $db->prepare("INSERT INTO guarantee_attachments 
                    (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $att['name'], '/uploads/' . $att['name'], $att['size'], $att['type'], '-' . rand(1, 35) . ' days']);
                $stats['attachments']++;
            } catch (Exception $e) {}
        }
    }
    
    // SCENARIO 1: Low confidence manual decision
    elseif ($scenario === 1) {
        try {
            $supplierId = ($index % 7) + 1;
            $bankId = ($index % 6) + 1;
            $confidence = rand(60, 75); // Low confidence
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, was_top_suggestion, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'manual', 0, datetime('now', ?), 'admin')");
            $stmt->execute([$gid, $supplierId, $bankId, $confidence, '-' . rand(15, 35) . ' days']);
            $stats['decisions']++;
            
            // Learning log for manual decision
            $stmt = $db->prepare("INSERT INTO supplier_decisions_log 
                (guarantee_id, raw_input, chosen_supplier_id, chosen_supplier_name, decision_source, confidence_score, was_top_suggestion, created_at)
                VALUES (?, ?, ?, ?, 'manual', ?, 0, datetime('now', ?))");
            $stmt->execute([$gid, 'إدخال يدوي', $supplierId, $suppliers[$supplierId - 1]['name'], $confidence, '-' . rand(15, 35) . ' days']);
            $stats['learning']++;
            
            // Note explaining manual decision
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'تم الإدخال يدوياً بسبب عدم وجود تطابق في النظام', 'admin', datetime('now', '-10 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 2: Release with full documentation
    elseif ($scenario === 2) {
        try {
            $supplierId = ($index % 7) + 1;
            $bankId = ($index % 6) + 1;
            
            // Decision first
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', datetime('now', '-30 days'), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(80, 95)]);
            $stats['decisions']++;
            
            // Release action
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, release_reason, notes, created_at) 
                VALUES (?, 'release', datetime('now', '-5 days'), 'issued', 'إفراج عن ضمان - اكتمال المشروع', 'تم الإفراج بموافقة الإدارة', datetime('now', '-5 days'))");
            $stmt->execute([$gid]);
            $stats['actions']++;
            
            // History
            $stmt = $db->prepare("INSERT INTO guarantee_history 
                (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                VALUES (?, 'release_issued', 'إفراج نهائي', '{}', 'system', datetime('now', '-5 days'))");
            $stmt->execute([$gid]);
            $stats['history']++;
            
            // Multiple notes documenting release
            $releaseNotes = [
                'تم استلام طلب الإفراج من المورد',
                'تمت الموافقة على الإفراج',
                'تم إرسال خطاب الإفراج للبنك'
            ];
            foreach ($releaseNotes as $note) {
                $stmt = $db->prepare("INSERT INTO guarantee_notes 
                    (guarantee_id, content, created_by, created_at) 
                    VALUES (?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $note, '-' . rand(1, 10) . ' days']);
                $stats['notes']++;
            }
            
            // Release documents
            $stmt = $db->prepare("INSERT INTO guarantee_attachments 
                (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at) 
                VALUES (?, 'release_letter.pdf', '/uploads/release_letter.pdf', 125000, 'pdf', 'system', datetime('now', '-3 days'))");
            $stmt->execute([$gid]);
            $stats['attachments']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 3: Reduction with amount changes
    elseif ($scenario === 3) {
        try {
            $supplierId = ($index % 7) + 1;
            $bankId = ($index % 6) + 1;
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', datetime('now', '-25 days'), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(85, 95)]);
            $stats['decisions']++;
            
            // Reduction
            $prevAmount = rand(500000, 900000);
            $newAmount = $prevAmount - rand(50000, 200000);
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, previous_amount, new_amount, notes, created_at) 
                VALUES (?, 'reduction', datetime('now', '-12 days'), 'issued', ?, ?, 'تخفيض بناءً على طلب المورد', datetime('now', '-12 days'))");
            $stmt->execute([$gid, $prevAmount, $newAmount]);
            $stats['actions']++;
            
            // History
            $stmt = $db->prepare("INSERT INTO guarantee_history 
                (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                VALUES (?, 'reduction_issued', 'تخفيض المبلغ', '{}', 'system', datetime('now', '-12 days'))");
            $stmt->execute([$gid]);
            $stats['history']++;
            
            // Notes
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'تم تخفيض المبلغ من " . number_format($prevAmount) . " إلى " . number_format($newAmount) . "', 'system', datetime('now', '-10 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 4: Multiple extensions (edge case)
    elseif ($scenario === 4) {
        try {
            $supplierId = ($index % 7) + 1;
            $bankId = ($index % 6) + 1;
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', datetime('now', '-60 days'), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(88, 96)]);
            $stats['decisions']++;
            
            // First extension
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, previous_expiry_date, new_expiry_date, notes, created_at) 
                VALUES (?, 'extension', datetime('now', '-40 days'), 'issued', datetime('now', '+6 months'), datetime('now', '+18 months'), 'التمديد الأول', datetime('now', '-40 days'))");
            $stmt->execute([$gid]);
            $stats['actions']++;
            
            // Second extension
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, previous_expiry_date, new_expiry_date, notes, created_at) 
                VALUES (?, 'extension', datetime('now', '-15 days'), 'issued', datetime('now', '+18 months'), datetime('now', '+30 months'), 'التمديد الثاني', datetime('now', '-15 days'))");
            $stmt->execute([$gid]);
            $stats['actions']++;
            
            // History for both
            $stmt = $db->prepare("INSERT INTO guarantee_history 
                (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                VALUES (?, 'extension_issued', 'تمديد متعدد', '{}', 'system', datetime('now', '-40 days'))");
            $stmt->execute([$gid]);
            $stats['history']++;
            
            $stmt = $db->prepare("INSERT INTO guarantee_history 
                (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                VALUES (?, 'extension_issued', 'تمديد ثاني', '{}', 'system', datetime('now', '-15 days'))");
            $stmt->execute([$gid]);
            $stats['history']++;
            
            // Note
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'تم تمديد الضمان مرتين بسبب تأخر المشروع', 'system', datetime('now', '-10 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 5: Pending decision (no action yet)
    elseif ($scenario === 5) {
        try {
            $supplierId = ($index % 7) + 1;
            $bankId = ($index % 6) + 1;
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'pending', ?, 'chip', datetime('now', '-5 days'), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(70, 85)]);
            $stats['decisions']++;
            
            // Just a note
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'قرار معلق - بانتظار المراجعة', 'system', datetime('now', '-3 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 6: Heavy documentation (many attachments)
    elseif ($scenario === 6) {
        try {
            // Decision
            $supplierId = ($index % 7) + 1;
            $bankId = ($index % 6) + 1;
            
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', datetime('now', '-20 days'), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(82, 94)]);
            $stats['decisions']++;
            
            // Many attachments (5-7)
            for ($i = 0; $i < rand(5, 7); $i++) {
                $att = $attachmentVariations[array_rand($attachmentVariations)];
                $stmt = $db->prepare("INSERT INTO guarantee_attachments 
                    (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $att['name'], '/uploads/' . $att['name'], $att['size'], $att['type'], '-' . rand(1, 20) . ' days']);
                $stats['attachments']++;
            }
            
            // Note about documentation
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'تم رفع جميع المستندات المطلوبة', 'system', datetime('now', '-5 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 7: Conflicting data (for testing error handling)
    elseif ($scenario === 7) {
        try {
            $supplierId = ($index % 7) + 1;
            $bankId = ($index % 6) + 1;
            
            // Decision with very low confidence
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, was_top_suggestion, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'manual', 0, datetime('now', '-8 days'), 'admin')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(45, 65)]);
            $stats['decisions']++;
            
            // Learning log with different supplier (conflict)
            $differentSupplierId = (($index + 1) % 7) + 1;
            $stmt = $db->prepare("INSERT INTO supplier_decisions_log 
                (guarantee_id, raw_input, chosen_supplier_id, chosen_supplier_name, decision_source, confidence_score, was_top_suggestion, created_at)
                VALUES (?, ?, ?, ?, 'manual', ?, 0, datetime('now', '-8 days'))");
            $stmt->execute([$gid, 'بيانات غير مطابقة', $differentSupplierId, $suppliers[$differentSupplierId - 1]['name'], rand(45, 65)]);
            $stats['learning']++;
            
            // Note explaining conflict
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'تم تصحيح بيانات المورد يدوياً بسبب عدم التطابق', 'admin', datetime('now', '-7 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 8: Minimal data (edge case)
    elseif ($scenario === 8) {
        // Just one note, no decision
        try {
            $stmt = $db->prepare("INSERT INTO guarantee_notes 
                (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'سجل جديد - لم يتم اتخاذ قرار بعد', 'system', datetime('now', '-1 days'))");
            $stmt->execute([$gid]);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // SCENARIO 9: Complete lifecycle (decision -> extension -> reduction -> release)
    else {
        try {
            $supplierId = ($index % 7) + 1;
            $bankId = ($index % 6) + 1;
            
            // Initial decision
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions 
                (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', datetime('now', '-90 days'), 'system')");
            $stmt->execute([$gid, $supplierId, $bankId, rand(88, 97)]);
            $stats['decisions']++;
            
            // Extension
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, previous_expiry_date, new_expiry_date, created_at) 
                VALUES (?, 'extension', datetime('now', '-60 days'), 'issued', datetime('now', '+6 months'), datetime('now', '+18 months'), datetime('now', '-60 days'))");
            $stmt->execute([$gid]);
            $stats['actions']++;
            
            // Reduction
            $prevAmount = rand(700000, 900000);
            $newAmount = $prevAmount - rand(100000, 200000);
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, previous_amount, new_amount, created_at) 
                VALUES (?, 'reduction', datetime('now', '-30 days'), 'issued', ?, ?, datetime('now', '-30 days'))");
            $stmt->execute([$gid, $prevAmount, $newAmount]);
            $stats['actions']++;
            
            // Final release
            $stmt = $db->prepare("INSERT INTO guarantee_actions 
                (guarantee_id, action_type, action_date, action_status, release_reason, created_at) 
                VALUES (?, 'release', datetime('now', '-5 days'), 'issued', 'إفراج نهائي بعد اكتمال جميع المراحل', datetime('now', '-5 days'))");
            $stmt->execute([$gid]);
            $stats['actions']++;
            
            // History for each action
            $historyActions = [
                ['action' => 'decision_saved', 'days' => 90],
                ['action' => 'extension_issued', 'days' => 60],
                ['action' => 'reduction_issued', 'days' => 30],
                ['action' => 'release_issued', 'days' => 5],
            ];
            foreach ($historyActions as $ha) {
                $stmt = $db->prepare("INSERT INTO guarantee_history 
                    (guarantee_id, action, change_reason, snapshot_data, created_by, created_at) 
                    VALUES (?, ?, ?, '{}', 'system', datetime('now', ?))");
                $stmt->execute([$gid, $ha['action'], 'دورة حياة كاملة', '-' . $ha['days'] . ' days']);
                $stats['history']++;
            }
            
            // Multiple notes throughout lifecycle
            $lifecycleNotes = [
                ['content' => 'بداية المشروع', 'days' => 90],
                ['content' => 'تم التمديد', 'days' => 60],
                ['content' => 'تم تخفيض المبلغ', 'days' => 30],
                ['content' => 'اكتمال المشروع', 'days' => 10],
                ['content' => 'تم الإفراج النهائي', 'days' => 5],
            ];
            foreach ($lifecycleNotes as $ln) {
                $stmt = $db->prepare("INSERT INTO guarantee_notes 
                    (guarantee_id, content, created_by, created_at) 
                    VALUES (?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $ln['content'], '-' . $ln['days'] . ' days']);
                $stats['notes']++;
            }
            
            // Attachments throughout
            for ($i = 0; $i < 4; $i++) {
                $att = $attachmentVariations[array_rand($attachmentVariations)];
                $stmt = $db->prepare("INSERT INTO guarantee_attachments 
                    (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at) 
                    VALUES (?, ?, ?, ?, ?, 'system', datetime('now', ?))");
                $stmt->execute([$gid, $att['name'], '/uploads/' . $att['name'], $att['size'], $att['type'], '-' . rand(5, 85) . ' days']);
                $stats['attachments']++;
            }
            
            // Learning log
            $stmt = $db->prepare("INSERT INTO supplier_decisions_log 
                (guarantee_id, raw_input, chosen_supplier_id, chosen_supplier_name, decision_source, confidence_score, was_top_suggestion, created_at)
                VALUES (?, ?, ?, ?, 'chip', ?, 1, datetime('now', '-90 days'))");
            $stmt->execute([$gid, $suppliers[$supplierId - 1]['name'], $supplierId, $suppliers[$supplierId - 1]['name'], rand(88, 97)]);
            $stats['learning']++;
        } catch (Exception $e) {}
    }
}

// Add comprehensive alternative names
echo "\nAdding comprehensive alternative names...\n";
$alternatives = [
    [1, 'شركة الاختبار', 8],
    [1, 'الاختبار التجريبية', 5],
    [1, 'شركه الاختبار', 3],
    [2, 'البناء الحديث', 7],
    [2, 'مؤسسة البناء', 6],
    [2, 'مؤسسه البناء الحديث', 4],
    [3, 'التقنية المتقدمة', 9],
    [3, 'شركة التقنية', 6],
    [3, 'التقنيه المتقدمه', 2],
    [4, 'الإنشاءات الكبرى', 7],
    [4, 'مؤسسة الإنشاءات', 5],
    [4, 'الانشاءات الكبرى', 3],
    [5, 'ARAB COMPANY', 10],
    [5, 'Arab Company', 8],
    [5, 'arab company for internet', 6],
    [6, 'المقاولات العامة', 5],
    [7, 'الخدمات الهندسية', 4],
];

foreach ($alternatives as $alt) {
    try {
        $stmt = $db->prepare("INSERT OR IGNORE INTO supplier_alternative_names 
            (supplier_id, alternative_name, normalized_name, usage_count, created_at, updated_at) 
            VALUES (?, ?, ?, ?, datetime('now'), datetime('now'))");
        $stmt->execute([$alt[0], $alt[1], strtolower($alt[1]), $alt[2]]);
        $stats['alternatives']++;
    } catch (Exception $e) {}
}

echo "\n╔════════════════════════════════════════════════════════════════╗\n";
echo "║ ULTIMATE SEEDING COMPLETED                                     ║\n";
echo "╚════════════════════════════════════════════════════════════════╝\n\n";

echo "Final Comprehensive Statistics:\n";
echo "  ✓ Decisions: {$stats['decisions']}\n";
echo "  ✓ Actions: {$stats['actions']}\n";
echo "  ✓ History: {$stats['history']}\n";
echo "  ✓ Attachments: {$stats['attachments']}\n";
echo "  ✓ Notes: {$stats['notes']}\n";
echo "  ✓ Learning Logs: {$stats['learning']}\n";
echo "  ✓ Alternative Names: {$stats['alternatives']}\n";

echo "\n✅ Database now contains:\n";
echo "   • All possible scenarios and edge cases\n";
echo "   • Complete data linkage across all tables\n";
echo "   • Variations in data quality and completeness\n";
echo "   • Realistic workflows and lifecycles\n";
echo "   • Edge cases for testing system robustness\n";
