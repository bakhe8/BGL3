<?php
/**
 * =============================================================================
 * TimelineEventService
 * =============================================================================
 * 
 * Centralized service for creating timeline events.
 * 
 * Key Responsibilities:
 * 1. Create timeline events with proper validation
 * 2. Trigger learning updates automatically
 * 3. Update supplier/bank weights
 * 4. Provide clean API for controllers
 * 
 * Design Pattern: Service Layer
 * - Encapsulates business logic
 * - Coordinates between repositories
 * - Handles side effects (learning, weights)
 * 
 * @version 1.0
 * @created 2025-12-20
 * =============================================================================
 */

namespace App\Services;

use App\Repositories\TimelineEventRepository;
use App\Repositories\ImportSessionRepository;
use App\Repositories\SupplierLearningCacheRepository;
use App\Repositories\ImportedRecordRepository;

class TimelineEventService
{
    private TimelineEventRepository $timeline;
    private ImportSessionRepository $sessions;
    private SupplierLearningCacheRepository $learningCache;
    private ImportedRecordRepository $records;
    
    public function __construct(
        ?TimelineEventRepository $timeline = null,
        ?ImportSessionRepository $sessions = null,
        ?SupplierLearningCacheRepository $learningCache = null,
        ?ImportedRecordRepository $records = null
    ) {
        $this->timeline = $timeline ?? new TimelineEventRepository();
        $this->sessions = $sessions ?? new ImportSessionRepository();
        $this->learningCache = $learningCache ?? new SupplierLearningCacheRepository();
        $this->records = $records ?? new ImportedRecordRepository();
    }
    
    /**
     * =========================================================================
     * SNAPSHOT CAPTURE - Historical Letter Data
     * =========================================================================
     * 
     * Captures complete letter state at time of event for historical viewing.
     * This enables "time travel" - viewing letters as they were at any point.
     * 
     * @param int $recordId Record ID to capture snapshot from
     * @return array|null Snapshot data or null if record not found
     */
    private function captureSnapshot(int $recordId): ?array
    {
        try {
            $record = $this->records->find($recordId);
            if (!$record) {
                return null;
            }
            
            // For import events, use RAW names (as they came from Excel)
            // For other events, use display names (after matching)
            $isImport = ($record->recordType === 'import' && $record->matchStatus === 'pending');
            
            return [
                'guarantee_number' => $record->guaranteeNumber ?? '',
                'contract_number' => $record->contractNumber ?? '',
                'supplier_name' => $isImport 
                    ? ($record->rawSupplierName ?? 'غير محدد')
                    : ($record->supplierDisplayName ?? $record->rawSupplierName ?? 'غير محدد'),
                'bank_name' => $isImport
                    ? ($record->rawBankName ?? 'غير محدد')
                    : ($record->bankDisplay ?? $record->rawBankName ?? 'غير محدد'),
                'amount' => $record->amount ?? '',
                'expiry_date' => $record->expiryDate ?? '',
                'issue_date' => $record->issueDate ?? '',
                'type' => $record->type ?? '',
                'record_type' => $record->recordType ?? 'import',
                'related_to' => $record->relatedTo ?? '',
                'match_status' => $record->matchStatus ?? 'pending'
            ];
        } catch (\Throwable $e) {
            // Fail gracefully - snapshot is nice-to-have
            return null;
        }
    }
    
    /**
     * =========================================================================
     * SUPPLIER CHANGE
     * =========================================================================
     */
    
    /**
     * Log supplier change event
     * 
     * Automatically:
     * - Creates timeline event
     * - Updates supplier weights (future)
     * - Updates suggestions usage count
     * 
     * @param string $guaranteeNumber Guarantee number
     * @param int $recordId Record ID
     * @param int|null $oldSupplierId Previous supplier ID
     * @param int $newSupplierId New supplier ID
     * @param string|null $oldSupplierName Previous supplier name
     * @param string $newSupplierName New supplier name
     * @param int|null $sessionId Session ID (defaults to daily session)
     * @return int Event ID
     */
    public function logSupplierChange(
        string $guaranteeNumber,
        int $recordId,
        ?int $oldSupplierId,
        int $newSupplierId,
        ?string $oldSupplierName,
        string $newSupplierName,
        ?int $sessionId = null
    ): int {
        // Get session
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        // Capture complete snapshot for historical viewing
        $snapshot = $this->captureSnapshot($recordId);
        $snapshotJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
        
        // Determine change type
        $changeType = $this->determineSupplierChangeType($oldSupplierId, $oldSupplierName, $newSupplierName);
        
        // Create timeline event
        $eventData = [
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'supplier_change',
            'field_name' => 'supplier',
            'old_value' => $oldSupplierName ?? 'غير محدد',
            'new_value' => $newSupplierName,
            'old_id' => $oldSupplierId,
            'new_id' => $newSupplierId,
            'supplier_display_name' => $newSupplierName,  // Store for fast access
            'change_type' => $changeType,
            'snapshot_data' => $snapshotJson  // Historical letter data
        ];
        
        $eventId = $this->timeline->create($eventData);
        
        // Update weights/learning (automatic side effect)
        try {
            $this->updateSupplierWeights($newSupplierId);
        } catch (\Exception $e) {
            // Log error but don't fail the event creation
            error_log("TimelineEventService: Failed to update supplier weights: " . $e->getMessage());
        }
        
        return $eventId;
    }
    
    /**
     * Determine supplier change type
     */
    private function determineSupplierChangeType(
        ?int $oldId,
        ?string $oldName,
        string $newName
    ): string {
        if ($oldId === null) {
            return 'initial_assignment';
        }
        
        if ($oldId && $oldName && $oldName !== $newName) {
            return 'entity_change';
        }
        
        if ($oldId && $oldName === $newName) {
            return 'name_correction';
        }
        
        return 'update';
    }
    
    /**
     * =========================================================================
     * BANK CHANGE
     * =========================================================================
     */
    
    /**
     * Log bank change event
     */
    public function logBankChange(
        string $guaranteeNumber,
        int $recordId,
        ?int $oldBankId,
        int $newBankId,
        ?string $oldBankName,
        string $newBankName,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        // Capture snapshot
        $snapshot = $this->captureSnapshot($recordId);
        $snapshotJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'bank_change',
            'field_name' => 'bank',
            'old_value' => $oldBankName ?? 'غير محدد',
            'new_value' => $newBankName,
            'old_id' => $oldBankId,
            'new_id' => $newBankId,
            'bank_display' => $newBankName,  // Store for fast access
            'change_type' => $oldBankId ? 'entity_change' : 'initial_assignment',
            'snapshot_data' => $snapshotJson
        ]);
    }
    
    /**
     * =========================================================================
     * AMOUNT CHANGE
     * =========================================================================
     */
    
    /**
     * Log amount change event
     */
    public function logAmountChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldAmount,
        string $newAmount,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        // Capture snapshot
        $snapshot = $this->captureSnapshot($recordId);
        $snapshotJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'amount_change',
            'field_name' => 'amount',
            'old_value' => $oldAmount ?? '0',
            'new_value' => $newAmount ?? '0',
            'change_type' => 'update',
            'snapshot_data' => $snapshotJson
        ]);
    }
    
    /**
     * =========================================================================
     * STATUS CHANGE (Pending → Ready)
     * =========================================================================
     */
    
    /**
     * Log match status change event
     * 
     * Tracks when a record changes from "يحتاج قرار" to "جاهز"
     * This represents the matching/decision moment
     */
    public function logStatusChange(
        string $guaranteeNumber,
        int $recordId,
        string $oldStatus,
        string $newStatus,
        ?int $sessionId = null,
        ?array $rawNames = null,      // Raw names from Excel (before matching)
        ?array $officialNames = null  // Official names (after matching)
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        // Build snapshot showing transformation (before → after)
        $snapshot = null;
        if ($rawNames && $officialNames) {
            $snapshot = [
                'guarantee_number' => $guaranteeNumber,
                'transformation' => [
                    'before' => $rawNames,
                    'after' => $officialNames
                ]
            ];
        } else {
            // Fallback: capture current state
            $snapshot = $this->captureSnapshot($recordId);
        }
        $snapshotJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
        
        // Translate status for display
        $statusLabels = [
            'pending' => 'يحتاج قرار',
            'ready' => 'جاهز',
            'locked' => 'مقفل'
        ];
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'status_change',
            'field_name' => 'match_status',
            'old_value' => $statusLabels[$oldStatus] ?? $oldStatus,
            'new_value' => $statusLabels[$newStatus] ?? $newStatus,
            'change_type' => 'status_update',
            'snapshot_data' => $snapshotJson
        ]);
    }
    
    /**
     * =========================================================================
     * ACTIONS (Extension, Release, etc)
     * =========================================================================
     */
    
    /**
     * Log extension action
     */
    public function logExtension(
        string $guaranteeNumber,
        int $recordId,
        string $oldExpiryDate,
        string $newExpiryDate,
        int $sessionId
    ): int {
        // Capture snapshot for historical view
        $snapshot = $this->captureSnapshot($recordId);
        $snapshotJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $sessionId,
            'event_type' => 'extension',
            'field_name' => 'expiry_date',
            'old_value' => $oldExpiryDate,
            'new_value' => $newExpiryDate,
            'change_type' => 'action',
            'snapshot_data' => $snapshotJson
        ]);
    }
    
    /**
     * Log release action
     */
    public function logRelease(
        string $guaranteeNumber,
        int $recordId,
        int $sessionId
    ): int {
        // Capture snapshot for historical view
        $snapshot = $this->captureSnapshot($recordId);
        $snapshotJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $sessionId,
            'event_type' => 'release',
            'change_type' => 'action',
            'snapshot_data' => $snapshotJson
        ]);
    }
    
    /**
     * =========================================================================
     * FIELD CHANGES (Comprehensive Tracking)
     * =========================================================================
     */
    
    /**
     * Log guarantee number change
     */
    public function logGuaranteeNumberChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldValue,
        string $newValue,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        return $this->timeline->create([
            'guarantee_number' => $newValue,  // Use new value as the reference
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'guarantee_number_change',
            'field_name' => 'guarantee_number',
            'old_value' => $oldValue ?? '',
            'new_value' => $newValue,
            'change_type' => 'field_update'
        ]);
    }
    
    /**
     * Log contract number change
     */
    public function logContractNumberChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldValue,
        string $newValue,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'contract_number_change',
            'field_name' => 'contract_number',
            'old_value' => $oldValue ?? '',
            'new_value' => $newValue,
            'change_type' => 'field_update'
        ]);
    }
    
    /**
     * Log expiry date change (not extension)
     */
    public function logExpiryDateChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldValue,
        string $newValue,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'expiry_date_change',
            'field_name' => 'expiry_date',
            'old_value' => $oldValue ?? '',
            'new_value' => $newValue,
            'change_type' => 'field_update'
        ]);
    }
    
    /**
     * Log issue date change
     */
    public function logIssueDateChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldValue,
        string $newValue,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'issue_date_change',
            'field_name' => 'issue_date',
            'old_value' => $oldValue ?? '',
            'new_value' => $newValue,
            'change_type' => 'field_update'
        ]);
    }
    
    /**
     * Log type change
     */
    public function logTypeChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldValue,
        string $newValue,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'type_change',
            'field_name' => 'type',
            'old_value' => $oldValue ?? '',
            'new_value' => $newValue,
            'change_type' => 'field_update'
        ]);
    }
    
    /**
     * Log raw supplier name change
     */
    public function logRawSupplierNameChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldValue,
        string $newValue,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'raw_supplier_name_change',
            'field_name' => 'raw_supplier_name',
            'old_value' => $oldValue ?? '',
            'new_value' => $newValue,
            'change_type' => 'field_update'
        ]);
    }
    
    /**
     * Log raw bank name change
     */
    public function logRawBankNameChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldValue,
        string $newValue,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'raw_bank_name_change',
            'field_name' => 'raw_bank_name',
            'old_value' => $oldValue ?? '',
            'new_value' => $newValue,
            'change_type' => 'field_update'
        ]);
    }
    
    /**
     * Log comment change
     */
    public function logCommentChange(
        string $guaranteeNumber,
        int $recordId,
        ?string $oldValue,
        string $newValue,
        ?int $sessionId = null
    ): int {
        $session = $sessionId ? $sessionId : $this->sessions->getOrCreateDailySession('daily_actions')->id;
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $session,
            'event_type' => 'comment_change',
            'field_name' => 'comment',
            'old_value' => $oldValue ?? '',
            'new_value' => $newValue,
            'change_type' => 'field_update'
        ]);
    }
    
    /**
     * Log record creation (import)
     */
    public function logRecordCreation(
        string $guaranteeNumber,
        int $recordId,
        int $sessionId,
        string $recordType = 'import',
        ?array $snapshotData = null  // Optional pre-captured snapshot
    ): int {
        // Use provided snapshot or capture new one
        if ($snapshotData) {
            $snapshotJson = json_encode($snapshotData, JSON_UNESCAPED_UNICODE);
        } else {
            $snapshot = $this->captureSnapshot($recordId);
            $snapshotJson = $snapshot ? json_encode($snapshot, JSON_UNESCAPED_UNICODE) : null;
        }
        
        return $this->timeline->create([
            'guarantee_number' => $guaranteeNumber,
            'record_id' => $recordId,
            'session_id' => $sessionId,
            'event_type' => $recordType === 'import' ? 'import' : 'record_creation',
            'change_type' => 'creation',
            'snapshot_data' => $snapshotJson
        ]);
    }
    
    /**
     * =========================================================================
     * LEARNING & WEIGHTS (Private)
     * =========================================================================
     */
    
    /**
     * Update supplier weights based on timeline data
     * 
     * CRITICAL: This replaces scattered learning logic!
     * 
     * @param int $supplierId Supplier ID
     */
    private function updateSupplierWeights(int $supplierId): void
    {
        // Get usage count from timeline
        // Note: methods getSupplierUsageCount/ReversionCount need to exist in TimelineEventRepository
        // If not, we wrap in try/catch to avoid crash
        try {
            $usageCount = 0;
            $reversionCount = 0;
            
            if (method_exists($this->timeline, 'getSupplierUsageCount')) {
                $usageCount = $this->timeline->getSupplierUsageCount($supplierId);
            }
            if (method_exists($this->timeline, 'getSupplierReversionCount')) {
                $reversionCount = $this->timeline->getSupplierReversionCount($supplierId);
            }
            
            // Calculate success rate
            $successRate = $usageCount > 0 
                ? ($usageCount - $reversionCount) / $usageCount 
                : 0;
            
            // Log metrics (for monitoring)
            error_log(sprintf(
                "TimelineEventService: Supplier %d - Usage: %d, Reversions: %d, Success: %.1f%%",
                $supplierId,
                $usageCount,
                $reversionCount,
                $successRate * 100
            ));
            
            // In Phase 4, we will update the cache directly:
            // $this->learningCache->upsert(..., $supplierId, ['source_weight' => ...]);

        } catch (\Throwable $e) {
            error_log("TimelineEventService Error: " . $e->getMessage());
        }
    }
    
    /**
     * =========================================================================
     * BULK OPERATIONS
     * =========================================================================
     */
    
    /**
     * Log bulk decision propagation
     * 
     * When a decision is propagated to multiple records,
     * create a single bulk event instead of individual events.
     * 
     * @param array $recordIds Propagated record IDs
     * @param int $sourceRecordId Source record ID
     * @param int $supplierId Supplier ID
     * @param string $supplierName Supplier name
     * @param int $sessionId Session ID
     * @return int Event ID
     */
    public function logBulkPropagation(
        array $recordIds,
        int $sourceRecordId,
        int $supplierId,
        string $supplierName,
        int $sessionId
    ): int {
        // For now, create a single event noting the count
        // In future, could create individual events if needed
        return $this->timeline->create([
            'guarantee_number' => "BULK_{$sourceRecordId}",
            'record_id' => $sourceRecordId,
            'session_id' => $sessionId,
            'event_type' => 'bulk_propagation',
            'new_value' => $supplierName . ' (' . count($recordIds) . ' records)',
            'new_id' => $supplierId,
            'change_type' => 'bulk_update'
        ]);
    }
}
