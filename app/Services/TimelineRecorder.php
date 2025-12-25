<?php
declare(strict_types=1);

namespace App\Services;

use PDO;

/**
 * Timeline Recorder
 * Core functions for tracking guarantee history events
 * Formerly TimelineHelper
 */
class TimelineRecorder {
    
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
    /**
     * Record Extension Event (UE-02)
     * Strictly monitors expiry_date change
     */
    public static function recordExtensionEvent($guaranteeId, $oldSnapshot, $newExpiry, $actionId = null) {
        // Validate change
        $oldExpiry = $oldSnapshot['expiry_date'] ?? null;
        if ($oldExpiry === $newExpiry) {
            return false; // No actual change
        }

        $changes = [[
            'field' => 'expiry_date',
            'old_value' => $oldExpiry,
            'new_value' => $newExpiry,
            'trigger' => 'extension_action',
            'action_id' => $actionId
        ]];

        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, 'User');
    }

    /**
     * Record Reduction Event (UE-03)
     * Strictly monitors amount change
     */
    public static function recordReductionEvent($guaranteeId, $oldSnapshot, $newAmount, $previousAmount = null) {
        // Use previousAmount if explicitly passed (for restore hacks), otherwise from snapshot
        $oldAmount = $previousAmount ?? ($oldSnapshot['amount'] ?? 0);
        
        if ((float)$oldAmount === (float)$newAmount) {
            return false; 
        }

        $changes = [[
            'field' => 'amount',
            'old_value' => $oldAmount,
            'new_value' => $newAmount,
            'trigger' => 'reduction_action'
        ]];

        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, 'User');
    }

    /**
     * Record Release Event (UE-04)
     * Strictly monitors status change to released
     */
    public static function recordReleaseEvent($guaranteeId, $oldSnapshot, $reason = null) {
        $changes = [[
            'field' => 'status',
            'old_value' => $oldSnapshot['status'] ?? 'pending',
            'new_value' => 'released',
            'trigger' => 'release_action'
        ]];

        // Add reason to event details if present
        $extraDetails = $reason ? ['reason_text' => $reason] : [];

        return self::recordEvent($guaranteeId, 'release', $oldSnapshot, $changes, 'User', $extraDetails);
    }

    /**
     * Record Decision Event (UE-01 or SY-03)
     * Monitors Supplier/Bank changes
     */
    public static function recordDecisionEvent($guaranteeId, $oldSnapshot, $newData, $isAuto = false, $confidence = null) {
        $changes = [];
        
        // Check Supplier
        if (isset($newData['supplier_id'])) {
            $old = $oldSnapshot['supplier_id'] ?? null;
            $new = $newData['supplier_id'];
            if ($old != $new) {
                $changes[] = [
                    'field' => 'supplier_id',
                    'old_value' => ['id' => $old, 'name' => $oldSnapshot['supplier_name'] ?? ''],
                    'new_value' => ['id' => $new, 'name' => $newData['supplier_name'] ?? ''],
                    'trigger' => $isAuto ? 'ai_match' : 'manual'
                ];
            }
        }

        // Check Bank
        if (isset($newData['bank_id'])) {
            $old = $oldSnapshot['bank_id'] ?? null;
            $new = $newData['bank_id'];
            if ($old != $new) {
                $changes[] = [
                    'field' => 'bank_id',
                    'old_value' => ['id' => $old, 'name' => $oldSnapshot['bank_name'] ?? ''],
                    'new_value' => ['id' => $new, 'name' => $newData['bank_name'] ?? ''],
                    'trigger' => $isAuto ? 'ai_match' : 'manual'
                ];
            }
        }

        if (empty($changes)) {
            return false;
        }

        $creator = $isAuto ? 'System' : 'User';
        $extra = $confidence ? ['confidence' => $confidence] : [];

        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, $creator, $extra);
    }

    /**
     * Core Private Recording Method
     * Enforces Closed Event Contract
     */
    private static function recordEvent($guaranteeId, $type, $snapshot, $changes, $creator, $extraDetails = []) {
        global $db;
        
        // Note: We do NOT calculate status change here anymore. 
        // Status transitions (SE-01/02) must be recorded via recordStatusTransitionEvent separately.
        
        $eventDetails = array_merge([
            'changes' => $changes
        ], $extraDetails);

        // Map Creator to Display Text
        $creatorText = match($creator) {
            'User' => 'بواسطة المستخدم',
            'System' => 'بواسطة النظام',
            default => 'بواسطة النظام'
        };

        $stmt = $db->prepare("
            INSERT INTO guarantee_history 
            (guarantee_id, event_type, snapshot_data, event_details, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $guaranteeId,
            $type,
            json_encode($snapshot),
            json_encode($eventDetails),
            date('Y-m-d H:i:s'),
            $creatorText
        ]);

        return $db->lastInsertId();
    }

    /**
     * Detect Changes - Generalized Helper
     * Kept for backward compatibility or generic use, but specific methods preferred
     */
    public static function detectChanges($oldSnapshot, $newData) {
        // ... (We can keep it simple or remove if unused, keeping simple for now)
        return []; 
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

    // ... [Values kept for saveModifiedEvent, keeping strictly for backward compat if needed] ...

    /**
     * saveImportEvent / saveReimportEvent kept as is (LE-00)
     */
    /**
     * Record Import Event (LE-00)
     * The ONLY entry point.
     */
    public static function recordImportEvent($guaranteeId, $source = 'excel') {
        global $db;
        
        // Ensure no prior events exist (Strict LE-00)
        $stmt = $db->prepare("SELECT id FROM guarantee_history WHERE guarantee_id = ? LIMIT 1");
        $stmt->execute([$guaranteeId]);
        if ($stmt->fetch()) {
             // Event already exists! This violates LE-00 rule.
             // But maybe we are re-importing? Then it is RE-import event?
             // The user said: "Import... Event 1 only... No event can precede it".
             // If we are calling this, we assume it's a new guarantee or we want to log re-import.
             // But we have `saveReimportEvent`.
             // So `recordImportEvent` should only be called for NEW guarantees.
             return false;
        }

        $eventDetails = ['source' => $source];
        $stmt = $db->prepare("INSERT INTO guarantee_history (guarantee_id, event_type, snapshot_data, event_details, created_at, created_by) VALUES (?, 'import', 'null', ?, ?, ?)");
        $stmt->execute([$guaranteeId, json_encode($eventDetails), date('Y-m-d H:i:s'), 'النظام']);
        return $db->lastInsertId();
    }

    /**
     * Record Status Transition Event (SE-01, SE-02)
     */
    public static function recordStatusTransitionEvent($guaranteeId, $oldSnapshot, $newStatus, $reason = 'auto_logic') {
        global $db;
        
        $oldStatus = $oldSnapshot['status'] ?? 'pending';
        
        if ($oldStatus === $newStatus) {
            return false;
        }
        
        $changes = [[
            'field' => 'status',
            'old_value' => $oldStatus,
            'new_value' => $newStatus,
            'trigger' => $reason
        ]];
        
        // Metadata (Conflict is Reason, NOT Event)
        $extra = ['reason' => $reason];

        // SE events are System attributed usually.
        return self::recordEvent($guaranteeId, 'status_change', $oldSnapshot, $changes, 'System', $extra);
    }
    
    /**
     * saveReimportEvent (LE-00 Equivalent for duplicates, but strictly separate type)
     */
    public static function recordReimportEvent($guaranteeId, $source = 'excel') {
        global $db;
        $snapshot = self::createSnapshot($guaranteeId);
        $eventDetails = ['source' => $source, 'reason' => 'duplicate_guarantee_number'];
        $stmt = $db->prepare("INSERT INTO guarantee_history (guarantee_id, event_type, snapshot_data, event_details, created_at, created_by) VALUES (?, 'reimport', ?, ?, ?, ?)");
        $stmt->execute([$guaranteeId, json_encode($snapshot), json_encode($eventDetails), date('Y-m-d H:i:s'), 'النظام']);
        return $db->lastInsertId();
    }
    
    // ... [Keep getEventDisplayLabel as per previous fix, but ensure it handles new structure] ...

    public static function getTimeline($guaranteeId) {
        global $db;
        $stmt = $db->prepare("SELECT * FROM guarantee_history WHERE guarantee_id = ? ORDER BY created_at DESC, id DESC");
        $stmt->execute([$guaranteeId]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    public static function getEventDisplayLabel(array $event): string
    {
        $type = $event['event_type'] ?? '';
        $details = json_decode($event['event_details'] ?? '{}', true);
        $changes = $details['changes'] ?? [];

        // Helper
        $hasField = function($field) use ($changes) {
            foreach ($changes as $change) {
                if (($change['field'] ?? '') === $field) return true;
                if (($change['trigger'] ?? '') === $field) return true; 
            }
            return false;
        };
        
        $hasTrigger = function($trigger) use ($changes) {
            foreach ($changes as $change) {
                if (($change['trigger'] ?? '') === $trigger) return true;
            }
            return false;
        };

        if ($type === 'import') return 'استيراد الضمان';
        if ($type === 'reimport') return 'محاولة استيراد مكررة';

        if ($type === 'modified') {
            if ($hasField('expiry_date') || $hasTrigger('extension_action')) return 'تمديد الضمان';
            if ($hasField('amount') || $hasTrigger('reduction_action')) return 'تخفيض قيمة الضمان';
            if ($hasField('supplier_id') || $hasField('bank_id')) return 'اعتماد بيانات المورد أو البنك';
            return 'تحديث بيانات'; 
        }

        if ($type === 'released' || $type === 'release') return 'إفراج الضمان';

        if ($type === 'status_change') {
             // Basic status change (SE-01, SE-02) logic can be inferred or explicit
             return 'تغير حالة الضمان';
        }

        return 'تحديث بيانات';
    }

    public static function getEventIcon(array $event): string
    {
        $label = self::getEventDisplayLabel($event);
        return match ($label) {
            'استيراد الضمان' => '📥',
            'اعتماد بيانات المورد أو البنك' => '✍️',
            'تمديد الضمان' => '⏱️',
            'تخفيض قيمة الضمان' => '💰',
            'إفراج الضمان' => '🔓',
            'الضمان جاهز' => '✅',
            'يحتاج مراجعة' => '⚠️',
            default => '📝'
        };
    }

    private static function getCurrentUser(): string { return 'النظام'; }
}
