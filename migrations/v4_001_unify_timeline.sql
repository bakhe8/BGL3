-- ============================================================================
-- Migration v4.001: Unify Timeline System
-- Purpose: Merge guarantee_actions into guarantee_history for unified timeline
-- Date: 2025-12-27
-- ============================================================================

-- 1. ADD NEW COLUMNS TO guarantee_history
-- ============================================================================

ALTER TABLE guarantee_history ADD COLUMN event_subtype TEXT;
ALTER TABLE guarantee_history ADD COLUMN action_status TEXT DEFAULT 'completed';
ALTER TABLE guarantee_history ADD COLUMN action_date DATE;
ALTER TABLE guarantee_history ADD COLUMN letter_generated BOOLEAN DEFAULT 0;
ALTER TABLE guarantee_history ADD COLUMN letter_issued_at DATETIME;
ALTER TABLE guarantee_history ADD COLUMN notes TEXT;

-- 2. UPDATE EXISTING guarantee_history RECORDS WITH event_subtype
-- ============================================================================

UPDATE guarantee_history
SET event_subtype = CASE
    -- Import events
    WHEN event_type = 'import' THEN 
        COALESCE(json_extract(event_details, '$.source'), 'excel')
    
    -- Modified events - detect specific subtypes
    WHEN event_type = 'modified' THEN
        CASE
            -- Extension
            WHEN json_extract(event_details, '$.changes[0].trigger') LIKE '%extension%' THEN 'extension'
            -- Reduction
            WHEN json_extract(event_details, '$.changes[0].trigger') LIKE '%reduction%' THEN 'reduction'
            -- Supplier or Bank changes
            WHEN json_extract(event_details, '$.changes[0].field') IN ('supplier_id', 'bank_id') THEN 
                CASE 
                    WHEN json_extract(event_details, '$.changes[0].trigger') LIKE '%ai%' THEN 'ai_match'
                    ELSE 'manual_edit'
                END
            ELSE 'general'
        END
    
    -- Status change events
    WHEN event_type = 'status_change' THEN 'status_change'
    
    -- Release events
    WHEN event_type = 'release' THEN 'release'
    
    ELSE 'unknown'
END
WHERE event_subtype IS NULL;

-- 3. MIGRATE DATA FROM guarantee_actions TO guarantee_history
-- ============================================================================

INSERT INTO guarantee_history (
    guarantee_id,
    event_type,
    event_subtype,
    snapshot_data,
    event_details,
    action_status,
    action_date,
    letter_generated,
    letter_issued_at,
    notes,
    created_at,
    created_by
)
SELECT 
    ga.guarantee_id,
    
    -- event_type mapping
    CASE 
        WHEN ga.action_type = 'release' THEN 'release'
        ELSE 'modified'
    END as event_type,
    
    -- event_subtype is the action_type itself
    ga.action_type as event_subtype,
    
    -- Build snapshot (current state before the action)
    -- This is a best-effort reconstruction
    json_object(
        'amount', COALESCE(ga.previous_amount, 0),
        'expiry_date', COALESCE(ga.previous_expiry_date, ''),
        'status', CASE WHEN ga.action_type = 'release' THEN 'active' ELSE 'approved' END
    ) as snapshot_data,
    
    -- Build event_details
    json_object(
        'changes', json_array(
            json_object(
                'field', CASE ga.action_type
                    WHEN 'extension' THEN 'expiry_date'
                    WHEN 'reduction' THEN 'amount'
                    WHEN 'release' THEN 'status'
                END,
                'old_value', CASE ga.action_type
                    WHEN 'extension' THEN ga.previous_expiry_date
                    WHEN 'reduction' THEN CAST(ga.previous_amount AS TEXT)
                    ELSE 'active'
                END,
                'new_value', CASE ga.action_type
                    WHEN 'extension' THEN ga.new_expiry_date
                    WHEN 'reduction' THEN CAST(ga.new_amount AS TEXT)
                    ELSE 'released'
                END,
                'trigger', ga.action_type || '_action'
            )
        ),
        'reason', ga.release_reason,
        'notes', ga.notes
    ) as event_details,
    
    -- Action management fields
    ga.action_status,
    ga.action_date,
    ga.letter_generated,
    ga.letter_issued_at,
    ga.notes,
    
    -- Audit fields
    ga.created_at,
    COALESCE(ga.created_by, 'النظام') as created_by

FROM guarantee_actions ga
WHERE NOT EXISTS (
    -- Avoid duplicates: don't migrate if already exists
    SELECT 1 FROM guarantee_history gh
    WHERE gh.guarantee_id = ga.guarantee_id
    AND gh.event_subtype = ga.action_type
    AND gh.action_date = ga.action_date
);

-- 4. CREATE INDEXES FOR NEW COLUMNS
-- ============================================================================

CREATE INDEX IF NOT EXISTS idx_history_subtype ON guarantee_history(event_subtype);
CREATE INDEX IF NOT EXISTS idx_history_action_status ON guarantee_history(action_status);
CREATE INDEX IF NOT EXISTS idx_history_action_date ON guarantee_history(action_date DESC);
CREATE INDEX IF NOT EXISTS idx_history_guarantee_subtype ON guarantee_history(guarantee_id, event_subtype);

-- 5. BACKUP guarantee_actions AND RENAME
-- ============================================================================

ALTER TABLE guarantee_actions RENAME TO guarantee_actions_backup;

-- 6. VERIFICATION QUERIES
-- ============================================================================

-- Verify new columns exist
SELECT '=== NEW COLUMNS ===' as status;
PRAGMA table_info(guarantee_history);

-- Count migrated records
SELECT '=== MIGRATION STATS ===' as status;
SELECT 
    'Actions in backup' as metric,
    COUNT(*) as count 
FROM guarantee_actions_backup;

SELECT 
    'Events in history (extension/reduction/release)' as metric,
    COUNT(*) as count 
FROM guarantee_history 
WHERE event_subtype IN ('extension', 'reduction', 'release');

-- Check for duplicates
SELECT '=== DUPLICATE CHECK ===' as status;
SELECT 
    guarantee_id, 
    event_subtype, 
    DATE(created_at) as event_date, 
    COUNT(*) as cnt
FROM guarantee_history
WHERE event_subtype IN ('extension', 'reduction', 'release')
GROUP BY guarantee_id, event_subtype, event_date
HAVING cnt > 1;

SELECT '=== MIGRATION COMPLETE ===' as status;
