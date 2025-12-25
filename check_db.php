<?php
/**
 * Check SQLite Database - guarantee_history snapshots
 */

$dbPath = __DIR__ . '/storage/database/app.sqlite';
$outputFile = __DIR__ . '/snapshot_check_result.txt';

if (!file_exists($dbPath)) {
    die("Database not found at: $dbPath\n");
}

ob_start(); // Capture output

try {
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    
    echo "=== Database connected: $dbPath ===\n\n";
    
    // Get latest 10 events
    $stmt = $pdo->query("
        SELECT 
            id,
            guarantee_id,
            event_type,
            CASE 
                WHEN snapshot_data IS NULL THEN 'NULL'
                WHEN snapshot_data = '' THEN 'EMPTY'
                WHEN snapshot_data = '{}' THEN 'EMPTY_JSON'
                ELSE 'HAS_DATA'
            END as status,
            LENGTH(snapshot_data) as len,
            SUBSTR(snapshot_data, 1, 60) as preview,
            created_at
        FROM guarantee_history
        ORDER BY id DESC
        LIMIT 10
    ");
    
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Latest 10 events:\n";
    echo str_repeat('-', 130) . "\n";
    printf("%-5s | %-4s | %-15s | %-12s | %-6s | %-40s | %s\n", 
        'ID', 'GID', 'Type', 'Status', 'Len', 'Preview', 'Created');
    echo str_repeat('-', 130) . "\n";
    
    foreach ($events as $e) {
        printf("%-5s | %-4s | %-15s | %-12s | %-6s | %-40s | %s\n",
            $e['id'],
            $e['guarantee_id'],
            $e['event_type'] ?? 'N/A',
            $e['status'],
            $e['len'] ?? '0',
            substr($e['preview'] ?? '', 0, 40),
            substr($e['created_at'] ?? '', 0, 19)
        );
    }
    
    echo "\n\n=== Summary ===\n";
    $summary = $pdo->query("
        SELECT 
            CASE 
                WHEN snapshot_data IS NULL THEN 'NULL'
                WHEN snapshot_data = '' THEN 'EMPTY'
                WHEN snapshot_data = '{}' THEN 'EMPTY_JSON'
                ELSE 'HAS_DATA'
            END as status,
            COUNT(*) as count
        FROM guarantee_history
        GROUP BY status
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($summary as $s) {
        printf("%-15s: %d\n", $s['status'], $s['count']);
    }
    
    echo "\n=== Sample Event with Data ===\n";
    $sample = $pdo->query("
        SELECT id, guarantee_id, event_type, snapshot_data
        FROM guarantee_history
        WHERE snapshot_data IS NOT NULL 
          AND snapshot_data != '' 
          AND snapshot_data != '{}'
        ORDER BY id DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
    
    if ($sample) {
        echo "Event ID: " . $sample['id'] . "\n";
        echo "Snapshot Data:\n" . $sample['snapshot_data'] . "\n";
    } else {
        echo "No events with snapshot data found!\n";
    }
    
} catch (PDOException $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}

// Save to file
$output = ob_get_clean();
file_put_contents($outputFile, $output);
echo $output; // Also print
echo "\n\nResults saved to: $outputFile\n";

