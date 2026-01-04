-- ============================================================================
-- Backfill Script: Populate active_action from Timeline History
-- ============================================================================
-- Date: 2025-12-31
-- Phase: 2 (One-time Backfill)
-- Purpose: Fill active_action based on existing data
--
-- PREREQUISITE: Phase 1 migration must be completed first
-- RUN ONCE ONLY: This is a one-time data migration
-- ============================================================================

-- Step 1: Set active_action = NULL for all PENDING guarantees
-- Reason: PENDING = unverified data, no action allowed
UPDATE guarantee_decisions
SET active_action = NULL,
    active_action_set_at = NULL
WHERE status = 'pending';

-- Verify Step 1
SELECT 'Step 1: PENDING guarantees' AS step,
       COUNT(*) AS affected_rows
FROM guarantee_decisions
WHERE status = 'pending' AND active_action IS NULL;

-- ============================================================================

-- Step 2: For READY guarantees, backfill from latest timeline event
-- Logic: Find latest legal action event (extension/reduction/release)

UPDATE guarantee_decisions gd
SET active_action = (
    SELECT 
        CASE 
            WHEN gh.event_subtype = 'extension' THEN 'extension'
            WHEN gh.event_subtype = 'reduction' THEN 'reduction'
            WHEN gh.event_subtype = 'release' THEN 'release'
            WHEN gh.event_type = 'extension' THEN 'extension'
            WHEN gh.event_type = 'reduction' THEN 'reduction'
            WHEN gh.event_type = 'release' THEN 'release'
            ELSE NULL
        END
    FROM guarantee_history gh
    WHERE gh.guarantee_id = gd.guarantee_id
      AND (
          gh.event_subtype IN ('extension', 'reduction', 'release')
          OR gh.event_type IN ('extension', 'reduction', 'release')
      )
    ORDER BY gh.created_at DESC, gh.id DESC
    LIMIT 1
),
active_action_set_at = (
    SELECT gh.created_at
    FROM guarantee_history gh
    WHERE gh.guarantee_id = gd.guarantee_id
      AND (
          gh.event_subtype IN ('extension', 'reduction', 'release')
          OR gh.event_type IN ('extension', 'reduction', 'release')
      )
    ORDER BY gh.created_at DESC, gh.id DESC
    LIMIT 1
)
WHERE gd.status IN ('approved', 'ready');

-- Verify Step 2
SELECT 'Step 2: READY guarantees' AS step,
       COUNT(*) AS total_ready,
       SUM(CASE WHEN active_action IS NOT NULL THEN 1 ELSE 0 END) AS with_action,
       SUM(CASE WHEN active_action IS NULL THEN 1 ELSE 0 END) AS without_action
FROM guarantee_decisions
WHERE status IN ('approved', 'ready');

-- ============================================================================

-- Step 3: Handle RELEASED guarantees
-- Assumption: Released guarantees always have release action
UPDATE guarantee_decisions
SET active_action = 'release',
    active_action_set_at = decided_at
WHERE status = 'released' AND active_action IS NULL;

-- Verify Step 3
SELECT 'Step 3: RELEASED guarantees' AS step,
       COUNT(*) AS affected_rows
FROM guarantee_decisions
WHERE status = 'released' AND active_action = 'release';

-- ============================================================================

-- Final Verification Report
SELECT 
    '=== BACKFILL COMPLETE ===' AS report,
    '' AS separator;

SELECT 
    status,
    active_action,
    COUNT(*) AS count
FROM guarantee_decisions
GROUP BY status, active_action
ORDER BY status, active_action;

SELECT 
    'Total Guarantees' AS metric,
    COUNT(*) AS value
FROM guarantee_decisions
UNION ALL
SELECT 
    'With Active Action',
    COUNT(*)
FROM guarantee_decisions
WHERE active_action IS NOT NULL
UNION ALL
SELECT 
    'Without Active Action',
    COUNT(*)
FROM guarantee_decisions
WHERE active_action IS NULL;

-- ============================================================================
-- BACKFILL COMPLETE
-- Next: Phase 3 - Update API endpoints
-- ============================================================================
