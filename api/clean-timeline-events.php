<?php
/**
 * Clean False Changes from Timeline Events
 * Removes amount/expiry_date changes that were incorrectly detected
 * during manual save operations (not from actual extend/reduce/release actions)
 */

require_once __DIR__ . '/../app/Support/autoload.php';
use App\Support\Database;

header('Content-Type: application/json');

try {
    $db = Database::connect();
    
    // Get all 'modified' events
    $stmt = $db->query("
        SELECT id, event_details 
        FROM guarantee_history 
        WHERE event_type = 'modified' 
        AND event_details IS NOT NULL
        AND event_details != ''
    ");
    
    $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $cleaned = 0;
    $totalProcessed = 0;
    
    foreach ($events as $event) {
        $totalProcessed++;
        $details = json_decode($event['event_details'], true);
        
        if (!$details || !isset($details['changes'])) {
            continue;
        }
        
        $originalCount = count($details['changes']);
        $cleanedChanges = [];
        
        foreach ($details['changes'] as $change) {
            $field = $change['field'] ?? '';
            $trigger = $change['trigger'] ?? 'manual';
            
            // Keep supplier/bank changes always
            if ($field === 'supplier_id' || $field === 'bank_id') {
                $cleanedChanges[] = $change;
                continue;
            }
            
            // For amount: only keep if from actual reduction action
            if ($field === 'amount') {
                // Only keep if it's from a real reduction/release action
                // Manual saves should NOT have amount changes
                if ($trigger === 'reduction_action' || $trigger === 'release_action') {
                    $cleanedChanges[] = $change;
                }
                // Skip if manual - this was false detection
                continue;
            }
            
            // For expiry_date: only keep if from actual extension action
            if ($field === 'expiry_date') {
                // Only keep if it's from a real extension action
                if ($trigger === 'extension_action') {
                    $cleanedChanges[] = $change;
                }
                // Skip if manual - this was false detection
                continue;
            }
            
            // Keep other fields
            $cleanedChanges[] = $change;
        }
        
        // If we removed any changes, update the event
        if (count($cleanedChanges) < $originalCount) {
            $details['changes'] = $cleanedChanges;
            
            $updateStmt = $db->prepare("
                UPDATE guarantee_history 
                SET event_details = ? 
                WHERE id = ?
            ");
            
            $updateStmt->execute([
                json_encode($details),
                $event['id']
            ]);
            
            $cleaned++;
        }
    }
    
    echo json_encode([
        'success' => true,
        'total_processed' => $totalProcessed,
        'events_cleaned' => $cleaned,
        'message' => "Cleaned $cleaned events out of $totalProcessed total"
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
