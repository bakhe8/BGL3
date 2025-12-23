<?php
require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();

echo "Populating database with sample data...\n\n";

// Get guarantees
$guarantees = $db->query('SELECT id FROM guarantees ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);

$stats = ['decisions' => 0, 'actions' => 0, 'history' => 0, 'attachments' => 0, 'notes' => 0];

foreach ($guarantees as $gid) {
    // Decisions
    if (rand(1, 100) <= 70) {
        try {
            $stmt = $db->prepare("INSERT OR IGNORE INTO guarantee_decisions (guarantee_id, supplier_id, bank_id, status, confidence_score, decision_source, decided_at, decided_by) 
                VALUES (?, ?, ?, 'ready', ?, 'chip', datetime('now', ?), 'system')");
            $stmt->execute([$gid, rand(1, 5), rand(1, 5), rand(75, 100), '-' . rand(1, 30) . ' days']);
            $stats['decisions']++;
        } catch (Exception $e) {}
    }
    
    // Actions
    if (rand(1, 100) <= 50) {
        try {
            $stmt = $db->prepare("INSERT INTO guarantee_actions (guarantee_id, action_type, action_date, action_status, performed_by, created_at) 
                VALUES (?, 'extension', datetime('now', ?), 'issued', 'system', datetime('now', ?))");
            $stmt->execute([$gid, '-' . rand(1, 60) . ' days', '-' . rand(1, 60) . ' days']);
            $stats['actions']++;
        } catch (Exception $e) { echo "Action error: " . $e->getMessage() . "\n"; }
    }
    
    // History
    if (rand(1, 100) <= 60) {
        try {
            $stmt = $db->prepare("INSERT INTO guarantee_history (guarantee_id, action, change_reason, created_by, created_at) 
                VALUES (?, 'decision_saved', 'تحديث تلقائي', 'system', datetime('now', ?))");
            $stmt->execute([$gid, '-' . rand(1, 45) . ' days']);
            $stats['history']++;
        } catch (Exception $e) { echo "History error: " . $e->getMessage() . "\n"; }
    }
    
    // Attachments
    if (rand(1, 100) <= 40) {
        try {
            $stmt = $db->prepare("INSERT INTO guarantee_attachments (guarantee_id, file_name, file_path, file_size, file_type, uploaded_by, created_at) 
                VALUES (?, 'contract.pdf', '/uploads/contract.pdf', ?, 'pdf', 'system', datetime('now', ?))");
            $stmt->execute([$gid, rand(50000, 500000), '-' . rand(1, 40) . ' days']);
            $stats['attachments']++;
        } catch (Exception $e) {}
    }
    
    // Notes
    if (rand(1, 100) <= 50) {
        try {
            $stmt = $db->prepare("INSERT INTO guarantee_notes (guarantee_id, content, created_by, created_at) 
                VALUES (?, 'تم التواصل مع المورد', 'system', datetime('now', ?))");
            $stmt->execute([$gid, '-' . rand(1, 35) . ' days']);
            $stats['notes']++;
        } catch (Exception $e) {}
    }
}

echo "\n✅ Done!\n\n";
foreach ($stats as $key => $value) {
    echo ucfirst($key) . ": {$value}\n";
}
