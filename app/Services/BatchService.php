<?php
declare(strict_types=1);

namespace App\Services;

use App\Support\Database;
use PDO;

/**
 * Batch Operations Service
 * Handles all batch-level operations on groups of guarantees
 * 
 * Decision #4: Reuse individual guarantee logic, don't create new business logic
 */
class BatchService
{
    private PDO $db;
    
    public function __construct(?PDO $db = null)
    {
        $this->db = $db ?? Database::connect();
    }
    
    /**
     * Check if batch is closed
     */
    public function isBatchClosed(string $importSource): bool
    {
        $stmt = $this->db->prepare("
            SELECT status FROM batch_metadata WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // If no metadata, batch is implicit (active)
        if (!$batch) {
            return false;
        }
        
        return $batch['status'] === 'completed';
    }
    
    /**
     * Get all guarantees in a batch
     */
    /**
     * @param array<int, int|string>|null $guaranteeIds
     */
    public function getBatchGuarantees(string $importSource, ?array $guaranteeIds = null): array
    {
        $sql = "
            SELECT g.*, d.status, d.supplier_id, d.bank_id, d.active_action, d.is_locked
            FROM guarantees g
            LEFT JOIN guarantee_decisions d ON d.guarantee_id = g.id
            WHERE g.import_source = ?
        ";
        $params = [$importSource];

        if (!empty($guaranteeIds)) {
            $placeholders = implode(',', array_fill(0, count($guaranteeIds), '?'));
            $sql .= " AND g.id IN ($placeholders)";
            $params = array_merge($params, $guaranteeIds);
        }

        $sql .= " ORDER BY g.id";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Extend all guarantees in batch
     * Decision #4: All-or-nothing policy
     * 
     * TODO: This needs the actual extend logic from existing code
     * Placeholder for now - will integrate with real extend service
     */
    public function extendBatch(string $importSource, string $newExpiryDate, string $userId = 'system', ?array $guaranteeIds = null): array
    {
        // Check if closed
        if ($this->isBatchClosed($importSource)) {
            return [
                'success' => false,
                'error' => 'الدفعة مغلقة - لا يمكن التمديد الجماعي'
            ];
        }

        $ids = $this->normalizeIds($guaranteeIds);
        $hasSelection = $guaranteeIds !== null;
        if ($hasSelection && empty($ids)) {
            return [
                'success' => false,
                'error' => 'لا توجد ضمانات محددة'
            ];
        }
        // Get all guarantees (optionally filtered)
        $guarantees = $this->getBatchGuarantees($importSource, $hasSelection ? $ids : null);
        
        if (empty($guarantees)) {
            return [
                'success' => false,
                'error' => 'الدفعة فارغة'
            ];
        }
        
        // All ready - extend using INDIVIDUAL logic from extend.php
        $extended = [];
        $errors = [];
        $blocked = [];
        
        $guaranteeRepo = new \App\Repositories\GuaranteeRepository($this->db);
        $decisionRepo = new \App\Repositories\GuaranteeDecisionRepository($this->db);
        
        foreach ($guarantees as $g) {
            try {
                if (!empty($g['is_locked'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'مقفل'
                    ];
                    continue;
                }
                if (!empty($g['active_action'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'تم تنفيذ إجراء سابق'
                    ];
                    continue;
                }
                if (($g['status'] ?? '') !== 'ready' || !$g['supplier_id']) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'غير جاهز'
                    ];
                    continue;
                }

                // Reuse extend.php logic (lines 53-77)
                // 1. Snapshot
                $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($g['id']);
                
                // 2. Update - Calculate new expiry (+1 year)
                $guarantee = $guaranteeRepo->find($g['id']);
                $raw = $guarantee->rawData;
                $oldExpiry = $raw['expiry_date'] ?? '';
                $newExpiry = $newExpiryDate; // Use the date passed from batch operation
                
                // Update raw_data
                $raw['expiry_date'] = $newExpiry;
                $guaranteeRepo->updateRawData($g['id'], json_encode($raw));
                
                // 3. Set Active Action
                $decisionRepo->setActiveAction($g['id'], 'extension');

                // 3.1 Track user-driven decision source
                $decisionUpdate = $this->db->prepare("
                    UPDATE guarantee_decisions
                    SET decision_source = 'manual',
                        decided_by = ?,
                        last_modified_by = ?,
                        last_modified_at = CURRENT_TIMESTAMP
                    WHERE guarantee_id = ?
                ");
                $decisionUpdate->execute([$userId, $userId, $g['id']]);
                
                // 4. Record in Timeline
                \App\Services\TimelineRecorder::recordExtensionEvent(
                    $g['id'],
                    $oldSnapshot,
                    $newExpiry
                );
                
                $extended[] = $g['id'];
                
            } catch (\Exception $e) {
                $errors[] = [
                    'guarantee_id' => $g['id'],
                    'guarantee_number' => $g['guarantee_number'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $success = count($extended) > 0;
        return [
            'success' => $success,
            'error' => $success ? null : 'لا توجد ضمانات مؤهلة للتمديد',
            'extended_count' => count($extended),
            'extended_ids' => $extended,
            'blocked_count' => count($blocked),
            'blocked' => $blocked,
            'errors' => $errors
        ];
    }
    
    /**
     * Release all guarantees in batch
     * Decision #4: All-or-nothing policy, reuse release.php logic
     */
    public function releaseBatch(string $importSource, ?string $reason = null, string $userId = 'system', ?array $guaranteeIds = null): array
    {
        // Check if closed
        if ($this->isBatchClosed($importSource)) {
            return [
                'success' => false,
                'error' => 'الدفعة مغلقة - لا يمكن الإفراج الجماعي'
            ];
        }
        
        $ids = $this->normalizeIds($guaranteeIds);
        $hasSelection = $guaranteeIds !== null;
        if ($hasSelection && empty($ids)) {
            return [
                'success' => false,
                'error' => 'لا توجد ضمانات محددة'
            ];
        }
        // Get all guarantees (optionally filtered)
        $guarantees = $this->getBatchGuarantees($importSource, $hasSelection ? $ids : null);
        
        if (empty($guarantees)) {
            return [
                'success' => false,
                'error' => 'الدفعة فارغة'
            ];
        }
        
        // All ready - release using INDIVIDUAL logic from release.php
        $released = [];
        $errors = [];
        $blocked = [];
        
        $decisionRepo = new \App\Repositories\GuaranteeDecisionRepository($this->db);
        
        foreach ($guarantees as $g) {
            try {
                if (!empty($g['is_locked'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'مقفل'
                    ];
                    continue;
                }
                if (!empty($g['active_action'])) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'تم تنفيذ إجراء سابق'
                    ];
                    continue;
                }
                if (($g['status'] ?? '') !== 'ready' || !$g['supplier_id'] || !$g['bank_id']) {
                    $blocked[] = [
                        'guarantee_id' => $g['id'],
                        'guarantee_number' => $g['guarantee_number'],
                        'reason' => 'غير جاهز'
                    ];
                    continue;
                }

                // Reuse release.php logic (lines 53-74)
                // 1. Snapshot
                $oldSnapshot = \App\Services\TimelineRecorder::createSnapshot($g['id']);
                
                // 2. Lock the guarantee
                $decisionRepo->lock($g['id'], 'released');
                
                // 3. Set Active Action
                $decisionRepo->setActiveAction($g['id'], 'release');

                // 3.1 Track user-driven decision source
                $decisionUpdate = $this->db->prepare("
                    UPDATE guarantee_decisions
                    SET decision_source = 'manual',
                        decided_by = ?,
                        last_modified_by = ?,
                        last_modified_at = CURRENT_TIMESTAMP
                    WHERE guarantee_id = ?
                ");
                $decisionUpdate->execute([$userId, $userId, $g['id']]);
                
                // 4. Record in Timeline
                \App\Services\TimelineRecorder::recordReleaseEvent($g['id'], $oldSnapshot, $reason);
                
                $released[] = $g['id'];
                
            } catch (\Exception $e) {
                $errors[] = [
                    'guarantee_id' => $g['id'],
                    'guarantee_number' => $g['guarantee_number'],
                    'error' => $e->getMessage()
                ];
            }
        }
        
        $success = count($released) > 0;
        return [
            'success' => $success,
            'error' => $success ? null : 'لا توجد ضمانات مؤهلة للإفراج',
            'released_count' => count($released),
            'released_ids' => $released,
            'blocked_count' => count($blocked),
            'blocked' => $blocked,
            'errors' => $errors
        ];
    }

    /**
     * Normalize and validate guarantee IDs from request payload.
     *
     * @param array<int, int|string>|null $guaranteeIds
     * @return array<int, int>
     */
    private function normalizeIds(?array $guaranteeIds): array
    {
        if (empty($guaranteeIds)) {
            return [];
        }

        $ids = array_filter($guaranteeIds, fn($id) => is_numeric($id));
        $ids = array_map('intval', $ids);
        $ids = array_values(array_unique($ids));

        return $ids;
    }
    
    /**
     * Update batch metadata
     * Decision #2: Allowed even on completed batches
     */
    public function updateMetadata(string $importSource, ?string $batchName, ?string $batchNotes): array
    {        // Get or create metadata
        $stmt = $this->db->prepare("
            SELECT id FROM batch_metadata WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            // Create new metadata record (Decision #12: manual creation only)
            $stmt = $this->db->prepare("
                INSERT INTO batch_metadata (import_source, batch_name, batch_notes) 
                VALUES (?, ?, ?)
            ");
            $stmt->execute([$importSource, $batchName, $batchNotes]);
        } else {
            // Update existing (only update non-null values)
            $updates = [];
            $params = [];
            
            if ($batchName !== null) {
                $updates[] = "batch_name = ?";
                $params[] = $batchName;
            }
            
            if ($batchNotes !== null) {
                $updates[] = "batch_notes = ?";
                $params[] = $batchNotes;
            }
            
            if (!empty($updates)) {
                $params[] = $importSource;
                $sql = "UPDATE batch_metadata SET " . implode(', ', $updates) . " WHERE import_source = ?";
                $stmt = $this->db->prepare($sql);
                $stmt->execute($params);
            }
        }
        
        return ['success' => true];
    }
    
    /**
     * Close batch
     * Decision #8: Disables group operations, allows individual work
     */
    public function closeBatch(string $importSource, string $closedBy = 'system'): array
    {
        // Get or create metadata
        $stmt = $this->db->prepare("
            SELECT id FROM batch_metadata WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $batch = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$batch) {
            // Create metadata with completed status
            $stmt = $this->db->prepare("
                INSERT INTO batch_metadata (import_source, status) VALUES (?, 'completed')
            ");
            $stmt->execute([$importSource]);
        } else {
            // Update status
            $stmt = $this->db->prepare("
                UPDATE batch_metadata SET status = 'completed' WHERE import_source = ?
            ");
            $stmt->execute([$importSource]);
        }
        
        return ['success' => true];
    }
    
    /**
     * Reopen batch
     * Decision #7: Allowed with explicit user action
     */
    public function reopenBatch(string $importSource, string $reopenedBy = 'system'): array
    {
        $stmt = $this->db->prepare("
            UPDATE batch_metadata SET status = 'active' 
            WHERE import_source = ? AND status = 'completed'
        ");
        $stmt->execute([$importSource]);
        
        if ($stmt->rowCount() === 0) {
            return [
                'success' => false,
                'error' => 'الدفعة غير موجودة أو غير مغلقة'
            ];
        }
        
        return ['success' => true];
    }
    
    /**
     * Get batch summary with derived data
     * Decision #2: Derive don't store
     */
    public function getBatchSummary(string $importSource): ?array
    {
        // Get metadata if exists
        $stmt = $this->db->prepare("
            SELECT * FROM batch_metadata WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $metadata = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Get derived data from guarantees
        $stmt = $this->db->prepare("
            SELECT 
                COUNT(*) as guarantee_count,
                MIN(imported_at) as created_at,
                GROUP_CONCAT(DISTINCT imported_by) as imported_by
            FROM guarantees 
            WHERE import_source = ?
        ");
        $stmt->execute([$importSource]);
        $derived = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($derived['guarantee_count'] == 0) {
            return null;  // Batch doesn't exist or is empty
        }
        
        // Parse source type
        $sourceType = 'unknown';
        if (strpos($importSource, 'excel_') === 0) {
            $sourceType = 'excel';
        } elseif (strpos($importSource, 'manual_paste_') === 0) {
            $sourceType = 'manual_paste';
        } elseif (strpos($importSource, 'manual_') === 0) {
            $sourceType = 'manual';
        }
        
        return [
            'import_source' => $importSource,
            'batch_name' => $metadata['batch_name'] ?? null,
            'batch_notes' => $metadata['batch_notes'] ?? null,
            'status' => $metadata['status'] ?? 'active',
            'guarantee_count' => (int)$derived['guarantee_count'],
            'created_at' => $derived['created_at'],
            'created_by' => $derived['imported_by'],
            'source_type' => $sourceType
        ];
    }
}
