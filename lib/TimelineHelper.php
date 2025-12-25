<?php
/**
 * Timeline Helper
 * Core functions for tracking guarantee history events
 */

class TimelineHelper {
    
    /**
     * Create snapshot of current guarantee state (BEFORE event)
     */
    public static function createSnapshot($guaranteeId, $decisionData = null) {
        global $db;
        
        if (!$decisionData) {
            // Fetch current state from database
            $stmt = $db->prepare("
                SELECT 
                    g.raw_data, 
                    d.supplier_id, 
                    d.bank_id, 
                    d.status,
                    s.official_name as supplier_name,
                    b.official_name as bank_name
                FROM guarantees g
                LEFT JOIN guarantee_decisions d ON g.id = d.guarantee_id
                LEFT JOIN suppliers s ON d.supplier_id = s.id
                LEFT JOIN banks b ON d.bank_id = b.id
                WHERE g.id = ?
            ");
            $stmt->execute([$guaranteeId]);
            $data = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$data) {
                return null;
            }
        } else {
            $data = $decisionData;
        }
        
        $rawData = json_decode($data['raw_data'], true);
        
        return [
            'guarantee_number' => $rawData['guarantee_number'] ?? '',
            'contract_number' => $rawData['document_reference'] ?? '',
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'issue_date' => $rawData['issue_date'] ?? '',
            'type' => $rawData['type'] ?? '',
            'supplier_id' => $data['supplier_id'],
            'supplier_name' => $data['supplier_name'],
            'bank_id' => $data['bank_id'],
            'bank_name' => $data['bank_name'],
            'status' => $data['status']
        ];
    }
    
    /**
     * Detect changes between old and new data
     * Returns array of changes with field, old_value, new_value, trigger
     */
    public static function detectChanges($oldSnapshot, $newData) {
        $changes = [];
        
        // Check supplier change
        $oldSupplierId = $oldSnapshot['supplier_id'] ?? null;
        $newSupplierId = $newData['supplier_id'] ?? null;
        
        if ($oldSupplierId !== $newSupplierId) {
            $changes[] = [
                'field' => 'supplier_id',
                'old_value' => [
                    'id' => $oldSupplierId,
                    'name' => $oldSnapshot['supplier_name'] ?? null
                ],
                'new_value' => [
                    'id' => $newSupplierId,
                    'name' => $newData['supplier_name'] ?? null
                ],
                'trigger' => $newData['supplier_trigger'] ?? 'manual',
                'confidence' => $newData['supplier_confidence'] ?? null
            ];
        }
        
        // Check bank change
        $oldBankId = $oldSnapshot['bank_id'] ?? null;
        $newBankId = $newData['bank_id'] ?? null;
        
        if ($oldBankId !== $newBankId) {
            $changes[] = [
                'field' => 'bank_id',
                'old_value' => [
                    'id' => $oldBankId,
                    'name' => $oldSnapshot['bank_name'] ?? null
                ],
                'new_value' => [
                    'id' => $newBankId,
                    'name' => $newData['bank_name'] ?? null
                ],
                'trigger' => $newData['bank_trigger'] ?? 'manual',
                'confidence' => $newData['bank_confidence'] ?? null
            ];
        }
        
        // Check amount change (for reduction)
        $oldAmount = $oldSnapshot['amount'] ?? 0;
        $newAmount = $newData['amount'] ?? 0;
        
        if ($oldAmount != $newAmount) {
            $changes[] = [
                'field' => 'amount',
                'old_value' => $oldAmount,
                'new_value' => $newAmount,
                'trigger' => $newData['amount_trigger'] ?? 'reduction_action'
            ];
        }
        
        // Check expiry date change (for extension)
        $oldExpiry = $oldSnapshot['expiry_date'] ?? null;
        $newExpiry = $newData['expiry_date'] ?? null;
        
        if ($oldExpiry !== $newExpiry) {
            $changes[] = [
                'field' => 'expiry_date',
                'old_value' => $oldExpiry,
                'new_value' => $newExpiry,
                'trigger' => $newData['expiry_trigger'] ?? 'extension_action',
                'action_id' => $newData['action_id'] ?? null
            ];
        }
        
        return $changes;
    }
    
    /**
     * Calculate status based on supplier and bank presence
     * approved = both supplier_id and bank_id exist
     * pending = anything else
     */
    public static function calculateStatus($guaranteeId) {
        global $db;
        
        $stmt = $db->prepare("
            SELECT supplier_id, bank_id 
            FROM guarantee_decisions 
            WHERE guarantee_id = ?
        ");
        $stmt->execute([$guaranteeId]);
        $decision = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$decision) {
            return 'pending';
        }
        
        // approved only if both supplier and bank exist
        if ($decision['supplier_id'] && $decision['bank_id']) {
            return 'approved';
        }
        
        return 'pending';
    }
    
    /**
     * Save modified event to guarantee_history
     */
    public static function saveModifiedEvent($guaranteeId, $changes, $oldSnapshot) {
        global $db;
        
        // Don't save if no changes
        if (empty($changes)) {
            return false;
        }
        
        // Calculate status change if any
        $oldStatus = $oldSnapshot['status'] ?? 'pending';
        $newStatus = self::calculateStatus($guaranteeId);
        $statusChange = null;
        
        if ($oldStatus !== $newStatus) {
            $statusChange = "$oldStatus → $newStatus";
        }
        
        $eventDetails = [
            'changes' => $changes,
            'auto_status_change' => $statusChange
        ];
        
        $stmt = $db->prepare("
            INSERT INTO guarantee_history 
            (guarantee_id, event_type, snapshot_data, event_details, created_at, created_by)
            VALUES (?, 'modified', ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $guaranteeId,
            json_encode($oldSnapshot),
            json_encode($eventDetails),
            date('Y-m-d H:i:s'),
            self::getCurrentUser()
        ]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Save import event to guarantee_history
     */
    public static function saveImportEvent($guaranteeId, $source = 'excel') {
        global $db;
        
        $eventDetails = [
            'source' => $source,
            'imported_by' => self::getCurrentUser()
        ];
        
        $stmt = $db->prepare("
            INSERT INTO guarantee_history 
            (guarantee_id, event_type, snapshot_data, event_details, created_at, created_by)
            VALUES (?, 'import', 'null', ?, ?, ?)
        ");
        
        $stmt->execute([
            $guaranteeId,
            json_encode($eventDetails),
            date('Y-m-d H:i:s'),
            self::getCurrentUser()
        ]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Save reimport event (duplicate attempt - no data update)
     */
    public static function saveReimportEvent($guaranteeId, $source = 'excel') {
        global $db;
        
        // Get current state (no changes)
        $currentState = self::createSnapshot($guaranteeId);
        
        $eventDetails = [
            'source' => $source,
            'reason' => 'duplicate_guarantee_number',
            'attempted_at' => date('Y-m-d H:i:s')
        ];
        
        $stmt = $db->prepare("
            INSERT INTO guarantee_history 
            (guarantee_id, event_type, snapshot_data, event_details, created_at, created_by)
            VALUES (?, 'reimport', ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $guaranteeId,
            json_encode($currentState),
            json_encode($eventDetails),
            date('Y-m-d H:i:s'),
            self::getCurrentUser()
        ]);
        
        return $db->lastInsertId();
    }
    
    /**
     * Get timeline for a guarantee
     */
    public static function getTimeline($guaranteeId) {
        global $db;
        
        $stmt = $db->prepare("
            SELECT * FROM guarantee_history
            WHERE guarantee_id = ?
            ORDER BY created_at DESC
        ");
        $stmt->execute([$guaranteeId]);
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get display label for event based on changes
     * Used for UI display
     * Supports both legacy (action field) and new (event_type field) formats
     */
    public static function getEventDisplayLabel($event) {
        // Backward compatibility: handle legacy events with 'action' field
        if (!isset($event['event_type']) && isset($event['action'])) {
            // Map old action types to display labels
            $legacyMap = [
                'imported' => 'استيراد',
                'import' => 'استيراد',
                'extend' => 'تمديد',
                'extension' => 'تمديد',
                'reduce' => 'تخفيض',
                'reduction' => 'تخفيض',
                'release' => 'إفراج',
                'released' => 'إفراج',
                'update' => 'تعديل',
                'manual_match' => 'تعديل يدوي',
                'auto_matched' => 'تطابق تلقائي',
                'approved' => 'موافقة',
            ];
            
            return $legacyMap[$event['action']] ?? 'تعديل';
        }
        
        // New format handling
        if (isset($event['event_type']) && $event['event_type'] === 'import') {
            return 'استيراد';
        }
        
        if (isset($event['event_type']) && $event['event_type'] === 'reimport') {
            return 'محاولة استيراد مكرر';
        }
        
        // For 'modified' events, determine from changes
        $eventDetails = $event['event_details'] ?? null;
        if (!$eventDetails) {
            return 'تعديل';
        }
        
        $details = json_decode($eventDetails, true);
        if (!$details || !is_array($details)) {
            return 'تعديل';
        }
        
        $changes = $details['changes'] ?? [];
        
        if (empty($changes)) {
            return 'تعديل';
        }
        
        // Get primary trigger
        $trigger = $changes[0]['trigger'] ?? 'manual';
        
        switch ($trigger) {
            case 'ai_match':
                return 'تطابق تلقائي';
            case 'manual':
                return 'تعديل يدوي';
            case 'extension_action':
                return 'تمديد';
            case 'reduction_action':
                return 'تخفيض';
            case 'release_action':
                return 'إفراج';
            default:
                return 'تعديل';
        }
    }
    
    /**
     * Get event icon for UI
     */
    public static function getEventIcon($event) {
        $label = self::getEventDisplayLabel($event);
        
        $iconMap = [
            'استيراد' => '📥',
            'محاولة استيراد مكرر' => '⚠️',
            'تطابق تلقائي' => '🤖',
            'تعديل يدوي' => '✍️',
            'تمديد' => '⏱️',
            'تخفيض' => '💰',
            'إفراج' => '🔓',
            'تعديل' => '📝'
        ];
        
        return $iconMap[$label] ?? '📝';
    }
    
    /**
     * Get current user (placeholder - implement based on your auth system)
     */
    private static function getCurrentUser() {
        // TODO: Implement actual user authentication
        return 'النظام';
    }
}
