<?php
/**
 * Quick Test - 5 Guarantees Summary
 */

require_once __DIR__ . '/app/Support/autoload.php';
use App\Support\Database;

$db = Database::connect();
$stmt = $db->query('SELECT id FROM guarantees ORDER BY id LIMIT 5');
$guaranteeIds = $stmt->fetchAll(PDO::FETCH_COLUMN);

$results = [];

foreach ($guaranteeIds as $index => $gid) {
    $testNum = $index + 1;
    
    // Basic data
    $stmt = $db->prepare('SELECT * FROM guarantees WHERE id = ?');
    $stmt->execute([$gid]);
    $g = $stmt->fetch(PDO::FETCH_ASSOC);
    $raw = json_decode($g['raw_data'], true);
    
    // Decisions
    $stmt = $db->prepare('SELECT COUNT(*) FROM guarantee_decisions WHERE guarantee_id = ?');
    $stmt->execute([$gid]);
    $decisionsCount = $stmt->fetchColumn();
    
    // Actions
    $stmt = $db->prepare('SELECT COUNT(*) FROM guarantee_actions WHERE guarantee_id = ?');
    $stmt->execute([$gid]);
    $actionsCount = $stmt->fetchColumn();
    
    // History
    $stmt = $db->prepare('SELECT COUNT(*) FROM guarantee_history WHERE guarantee_id = ?');
    $stmt->execute([$gid]);
    $historyCount = $stmt->fetchColumn();
    
    // Attachments
    $stmt = $db->prepare('SELECT COUNT(*) FROM guarantee_attachments WHERE guarantee_id = ?');
    $stmt->execute([$gid]);
    $attachmentsCount = $stmt->fetchColumn();
    
    // Notes
    $stmt = $db->prepare('SELECT COUNT(*) FROM guarantee_notes WHERE guarantee_id = ?');
    $stmt->execute([$gid]);
    $notesCount = $stmt->fetchColumn();
    
    // Learning
    $stmt = $db->prepare('SELECT COUNT(*) FROM supplier_decisions_log WHERE guarantee_id = ?');
    $stmt->execute([$gid]);
    $learningCount = $stmt->fetchColumn();
    
    // API Test
    $apiUrl = "http://localhost:8000/V3/api/get-record.php?index={$testNum}";
    $apiResponse = @file_get_contents($apiUrl);
    $apiSuccess = false;
    $apiTimeline = 0;
    
    if ($apiResponse) {
        $apiData = json_decode($apiResponse, true);
        if ($apiData && $apiData['success']) {
            $apiSuccess = true;
            $apiTimeline = count($apiData['timeline'] ?? []);
        }
    }
    
    $results[] = [
        'test' => $testNum,
        'id' => $gid,
        'number' => $g['guarantee_number'],
        'supplier' => $raw['supplier'] ?? 'N/A',
        'bank' => $raw['bank'] ?? 'N/A',
        'amount' => $raw['amount'] ?? 0,
        'decisions' => $decisionsCount,
        'actions' => $actionsCount,
        'history' => $historyCount,
        'attachments' => $attachmentsCount,
        'notes' => $notesCount,
        'learning' => $learningCount,
        'api_success' => $apiSuccess,
        'api_timeline' => $apiTimeline
    ];
}

// Output as JSON
echo json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
