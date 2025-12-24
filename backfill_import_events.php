<?php
/**
 * Backfill import events for existing guarantees
 * Run once to add import events to guarantee_history
 */

require_once __DIR__ . '/app/Support/autoload.php';

use App\Support\Database;

$db = Database::connect();

// Find guarantees without import event
$stmt = $db->query("
    SELECT g.id, g.guarantee_number, g.raw_data, g.import_source, g.imported_at, g.imported_by 
    FROM guarantees g 
    WHERE NOT EXISTS (
        SELECT 1 FROM guarantee_history h 
        WHERE h.guarantee_id = g.id AND h.action = 'imported'
    )
");

$guarantees = $stmt->fetchAll(PDO::FETCH_ASSOC);
echo "Found " . count($guarantees) . " guarantees without import event\n";

// Insert import events
$insertStmt = $db->prepare("
    INSERT INTO guarantee_history (
        guarantee_id, 
        action, 
        change_reason, 
        snapshot_data, 
        created_at, 
        created_by
    ) VALUES (?, ?, ?, ?, ?, ?)
");

$count = 0;
foreach ($guarantees as $g) {
    $insertStmt->execute([
        $g['id'],
        'imported',
        'تم استيراد الضمان من ' . $g['import_source'],
        $g['raw_data'],
        $g['imported_at'],
        $g['imported_by']
    ]);
    $count++;
    
    if ($count % 50 == 0) {
        echo "Processed $count...\n";
    }
}

echo "✅ Created $count import events successfully!\n";

// Verify
$verifyStmt = $db->query("SELECT COUNT(*) FROM guarantee_history WHERE action = 'imported'");
echo "Total import events in database: " . $verifyStmt->fetchColumn() . "\n";
