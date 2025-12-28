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
     * Create snapshot from Database (Server = Source Of Truth)
     * 
     * If $decisionData provided, use it. Else, fetch from DB.
     * This snapshot represents the "current state" at the time of calling.
     */
    public static function createSnapshot($guaranteeId, $decisionData = null) {
        global $db;
        
        if (!$decisionData) {
            // Fetch latest decision + raw data
            $stmt = $db->prepare("
                SELECT 
                    g.raw_data, 
                    d.supplier_id, 
                    d.bank_id, 
                    d.status,
                    s.official_name as supplier_name,
                    b.arabic_name as bank_name
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
        
        // 🔥 FIX: Fallback to raw_data if decision fields are null
        // This ensures snapshots ALWAYS have bank/supplier names
        $supplierName = $data['supplier_name'] ?? $rawData['supplier'] ?? '';
        $bankName = $data['bank_name'] ?? $rawData['bank'] ?? '';
        
        return [
            'guarantee_number' => $rawData['guarantee_number'] ?? '',
            'contract_number' => $rawData['document_reference'] ?? '',
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'issue_date' => $rawData['issue_date'] ?? '',
            'type' => $rawData['type'] ?? '',
            'supplier_id' => $data['supplier_id'],
            'supplier_name' => $supplierName,  // ← Always filled
            'bank_id' => $data['bank_id'],
            'bank_name' => $bankName,          // ← Always filled
            'status' => $data['status'] ?? 'pending'
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

        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, 'User', [], 'extension');
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

        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, 'User', [], 'reduction');
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

        return self::recordEvent($guaranteeId, 'release', $oldSnapshot, $changes, 'User', $extraDetails, 'release');
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

        $subtype = $isAuto ? 'ai_match' : 'manual_edit';
        return self::recordEvent($guaranteeId, 'modified', $oldSnapshot, $changes, $creator, $extra, $subtype);
    }

    /**
     * Core Private Recording Method
     * Enforces Closed Event Contract
     */
    private static function recordEvent(
        $guaranteeId, 
        $type, 
        $snapshot, 
        $changes, 
        $creator, 
        $extraDetails = [],
        $subtype = null  // 🆕 event_subtype
    ) {
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
            (guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $guaranteeId,
            $type,
            $subtype,
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
     * 
     * DEPRECATED: Delegates to StatusEvaluator for authority
     * Kept for backward compatibility with existing calls
     * 
     * @param int $guaranteeId Guarantee ID
     * @return string Status: 'approved' or 'pending'
     */
    public static function calculateStatus($guaranteeId) {
        return \App\Services\StatusEvaluator::evaluateFromDatabase($guaranteeId);
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
        
        // Check if import event already exists (prevent duplicates)
        $stmt = $db->prepare("SELECT id FROM guarantee_history WHERE guarantee_id = ? AND event_type = 'import' LIMIT 1");
        $stmt->execute([$guaranteeId]);
        if ($stmt->fetch()) {
             return false; // Already has import event
        }

        //  🔥 FIX: Fetch raw_data from guarantees to create proper snapshot
        $stmt = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
        $stmt->execute([$guaranteeId]);
        $rawDataJson = $stmt->fetchColumn();
        
        if (!$rawDataJson) {
            // Fallback if no raw_data
            $snapshot = [];
        } else {
            $rawData = json_decode($rawDataJson, true) ?? [];
            
            // Create snapshot from RAW Excel/manual data (before any AI matching)
            $snapshot = [
                'supplier_name' => $rawData['supplier'] ?? '',
                'bank_name' => $rawData['bank'] ?? '',
                'supplier_id' => null,  // Not matched yet
                'bank_id' => null,      // Not matched yet
                'amount' => $rawData['amount'] ?? 0,
                'expiry_date' => $rawData['expiry_date'] ?? '',
                'issue_date' => $rawData['issue_date'] ?? '',
                'contract_number' => $rawData['contract_number'] ?? $rawData['document_reference'] ?? '',
                'guarantee_number' => $rawData['guarantee_number'] ?? '',
                'type' => $rawData['type'] ?? '',
                'status' => 'pending'
            ];
        }

        $eventDetails = ['source' => $source];
        
        // Use recordEvent with subtype
        return self::recordEvent(
            $guaranteeId,
            'import',
            $snapshot,
            [],  // no changes for import
            'النظام',
            $eventDetails,
            $source  // event_subtype (excel/manual/smart_paste)
        );
    }

    /**
     * Record Duplicate Import Event (RE-00)
     * Called when user attempts to import/paste a guarantee that already exists
     * Creates a timeline event for transparency without modifying guarantee data
     */
    public static function recordDuplicateImportEvent($guaranteeId, $source = 'excel') {
        global $db;
        
        // Fetch current raw_data for snapshot
        $stmt = $db->prepare("SELECT raw_data FROM guarantees WHERE id = ?");
        $stmt->execute([$guaranteeId]);
        $rawDataJson = $stmt->fetchColumn();
        
        if (!$rawDataJson) {
            return false;
        }
        
        $rawData = json_decode($rawDataJson, true) ?? [];
        
        // Create snapshot from current data
        $snapshot = [
            'supplier_name' => $rawData['supplier'] ?? '',
            'bank_name' => $rawData['bank'] ?? '',
            'amount' => $rawData['amount'] ?? 0,
            'expiry_date' => $rawData['expiry_date'] ?? '',
            'guarantee_number' => $rawData['guarantee_number'] ?? '',
        ];

        $eventDetails = [
            'source' => $source,
            'message' => 'محاولة استيراد مكرر - الضمان موجود بالفعل',
            'action' => 'duplicate_detected'
        ];
        
        // Use 'import' type with 'duplicate' subtype
        return self::recordEvent(
            $guaranteeId,
            'import',
            $snapshot,
            [],  // no changes
            'النظام',
            $eventDetails,
            'duplicate_' . $source  // event_subtype (duplicate_excel/duplicate_smart_paste)
        );
    }

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
        return self::recordEvent($guaranteeId, 'status_change', $oldSnapshot, $changes, 'System', $extra, 'status_change');
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
        $subtype = $event['event_subtype'] ?? '';
        $type = $event['event_type'] ?? '';
        
        // 🆕 Prioritize event_subtype if available (unified timeline)
        if ($subtype) {
            return match ($subtype) {
                'excel', 'manual', 'smart_paste', 'smart_paste_multi' => 'استيراد',
                'extension' => 'تمديد',
                'reduction' => 'تخفيض',
                'release' => 'إفراج',
                'supplier_change', 'bank_change', 'manual_edit' => 'تطابق يدوي',
                'ai_match' => 'تطابق تلقائي',
                'status_change' => 'تغيير حالة',
                default => 'تحديث'
            };
        }
        
        // Fallback to old logic for legacy records without subtype
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

        if ($type === 'import') return 'استيراد';
        if ($type === 'reimport') return 'استيراد مكرر';
        if ($type === 'auto_matched') return 'تطابق تلقائي';
        if ($type === 'approved') return 'اعتماد';

        if ($type === 'modified') {
            if ($hasField('expiry_date') || $hasTrigger('extension_action')) return 'تمديد';
            if ($hasField('amount') || $hasTrigger('reduction_action')) return 'تخفيض';
            if ($hasField('supplier_id') || $hasField('bank_id')) return 'تطابق يدوي';
            return 'تحديث'; 
        }

        if ($type === 'released' || $type === 'release') return 'إفراج';

        if ($type === 'status_change') {
             return 'تغيير حالة';
        }

        return 'تحديث';
    }

    public static function getEventIcon(array $event): string
    {
        $label = self::getEventDisplayLabel($event);
        return match ($label) {
            'استيراد' => '📥',
            'استيراد مكرر' => '🔁',
            'تطابق تلقائي' => '🤖',
            'تطابق يدوي' => '✍️',
            'اعتماد' => '✔️',
            'تمديد' => '⏱️',
            'تخفيض' => '💰',
            'إفراج' => '🔓',
            'تغيير حالة' => '🔄',
            default => '📝'
        };
    }

    private static function getCurrentUser(): string { return 'النظام'; }
}
