<?php
/**
 * Clean Timeline Events - Remove FALSE amount/expiry detections
 * User confirmed ALL amount/expiry in 'modified' events are false detections
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
        
        // SIMPLE LOGIC: Keep only supplier/bank, remove everything else
        foreach ($details['changes'] as $change) {
            $field = $change['field'] ?? '';
            
            // ONLY keep supplier_id and bank_id
            if ($field === 'supplier_id' || $field === 'bank_id') {
                $cleanedChanges[] = $change;
            }
            // Skip amount, expiry_date, and any other fields
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
        'removed_fields' => 'amount, expiry_date, and any non-supplier/bank fields',
        'message' => "Cleaned $cleaned events out of $totalProcessed total"
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
