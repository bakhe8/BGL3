-- Cleanup Script: Remove unnecessary columns and backup table

-- 1. Drop backup table
DROP TABLE IF EXISTS guarantee_actions_backup;

-- 2. Remove letter_* columns (not needed - viewing is the "letter")
ALTER TABLE guarantee_history DROP COLUMN letter_generated;
ALTER TABLE guarantee_history DROP COLUMN letter_issued_at;

-- 3. Keep notes column (may be useful for future manual notes on events)
-- Notes: Can store manual notes added by user about specific timeline events

SELECT 'Cleanup complete' as status;
