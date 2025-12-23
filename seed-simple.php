<?php
/**
 * Simple Database Seeder for V3
 */

require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$db->exec('PRAGMA foreign_keys = OFF');

echo "Starting database seeding...\n\n";

// Get all guarantees
$guarantees = $db->query('SELECT id FROM guarantees ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
echo "Found " . count($guarantees) . " guarantees\n\n";

$stats = ['decisions' => 0, 'actions' => 0, 'history' => 0, 'attachments' => 0, 'notes' => 0, 'learning' => 0];

foreach ($guarantees as $gid) {
    // Add decision (70% chance)
    if (rand(1, 100) <= 70) {
        try {
            $db->exec("INSERT OR IGNORE INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES ({$gid}, " . rand(1, 5) . ", " . rand(1, 5) . ", 'ready', " . rand(75, 100) . ", 'chip', datetime('now', '-" . rand(1, 30) . " days'), 'system')");
            $stats['decisions']++;
        } catch (Exception $e) {}
    }
    
    // Add action (50% chance)
    if (rand(1, 100) <= 50) {
        $types = ['extension', 'release', 'reduction'];
        $type = $types[array_rand($types)];
        $daysAgo = rand(1, 60);
        try {
            if ($type === 'extension') {
                $db->exec("INSERT INTO guarantee_actions (guarantee_id, action_type, action_date, action_status, previous_expiry_date, new_expiry_date, performed_by, created_at)
                    VALUES ({$gid}, '{$type}', datetime('now', '-{$daysAgo} days'), 'issued', datetime('now', '+6 months'), datetime('now', '+18 months'), 'system', datetime('now', '-{$daysAgo} days'))");
            } else {
                $db->exec("INSERT INTO guarantee_actions (guarantee_id, action_type, action_date, action_status, performed_by, created_at)
                    VALUES ({$gid}, '{$type}', datetime('now', '-{$daysAgo} days'), 'issued', 'system', datetime('now', '-{$daysAgo} days'))");
            }
            $stats['actions']++;
        } catch (Exception $e) {}
    }
    
    // Add history (60% chance)
    if (rand(1, 100) <= 60) {
        $actions = ['decision_saved', 'extension_issued', 'note_added'];
        try {
            $db->exec("INSERT INTO guarantee_history (guarantee_id, action, change_reason, created_by, created_at)
                VALUES ({$gid}, '{$actions[array_rand($actions)]}', 'تحديث تلقائي', 'system', datetime('now', '-" . rand(1, 45) . " days'))");
            $stats['history']++;
        } catch (Exception $e) {}
    }
    
    // Add attachment (40% chance)
    if (rand(1, 100) <= 40) {
        $files = ['contract.pdf', 'letter.pdf', 'approval.pdf'];
        $file = $files[array_rand($files)];
        try {
            $db->exec("INSERT INTO guarantee_attachments (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at)
                VALUES ({$gid}, '{$file}', '/uploads/{$file}', " . rand(50000, 500000) . ", 'pdf', 'system', datetime('now', '-" . rand(1, 40) . " days'))");
            $stats['attachments']++;
        } catch (Exception $e) {}
    }
    
    // Add note (50% chance)
    if (rand(1, 100) <= 50) {
        $notes = ['تم التواصل مع المورد', 'بانتظار الرد', 'تم التجديد بنجاح', 'يحتاج متابعة'];
        $note = $notes[array_rand($notes)];
        try {
            $db->exec("INSERT INTO guarantee_notes (guarantee_id, content, created_by, created_at)
                VALUES ({$gid}, '{$note}', 'system', datetime('now', '-" . rand(1, 35) . " days'))");
            $stats['notes']++;
        } catch (Exception $e) {}
    }
    
    // Add learning log (30% chance)
    if (rand(1, 100) <= 30) {
        try {
            $db->exec("INSERT INTO supplier_decisions_log (guarantee_id, raw_input, chosen_supplier_id, chosen_supplier_name, decision_source, confidence_score, created_at)
                VALUES ({$gid}, 'شركة اختبار', " . rand(1, 5) . ", 'شركة الاختبار', 'chip', " . rand(75, 100) . ", datetime('now', '-" . rand(1, 30) . " days'))");
            $stats['learning']++;
        } catch (Exception $e) {}
    }
}

echo "\n✅ Seeding completed!\n\n";
echo "Statistics:\n";
foreach ($stats as $key => $value) {
    echo "  - " . ucfirst($key) . ": {$value}\n";
}
