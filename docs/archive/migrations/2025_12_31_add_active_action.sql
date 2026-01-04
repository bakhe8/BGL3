-- ============================================================================
-- Migration: Add Active Action State to Guarantee Decisions
-- ============================================================================
-- Date: 2025-12-31
-- Phase: 1 (Schema)
-- Purpose: Add explicit active_action field to separate from Status
--
-- IMPORTANT: Run Phase 2 (backfill script) AFTER this migration succeeds
-- ============================================================================

-- Add active_action column
ALTER TABLE guarantee_decisions 
ADD COLUMN active_action VARCHAR(20) NULL 
COMMENT 'Current active action: extension, reduction, release, or NULL';

-- Add timestamp for when action was set
ALTER TABLE guarantee_decisions 
ADD COLUMN active_action_set_at TIMESTAMP NULL
COMMENT 'When this action became active';

-- Add index for performance
CREATE INDEX idx_active_action 
ON guarantee_decisions(active_action);

-- Verify schema change
SELECT 
    'Schema migration completed successfully' AS status,
    COUNT(*) AS total_records,
    SUM(CASE WHEN active_action IS NULL THEN 1 ELSE 0 END) AS null_actions
FROM guarantee_decisions;

-- ============================================================================
-- NEXT STEP: Run Phase 2 backfill script
-- File: migrations/2025_12_31_backfill_active_action.sql
-- ============================================================================
