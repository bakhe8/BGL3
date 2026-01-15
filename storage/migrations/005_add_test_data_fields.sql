-- Migration 005: Add test data identification fields
-- Date: 2026-01-14
-- Purpose: Enable identification, filtering, and safe deletion of test data
--          without affecting auto-increment or production data

-- Add test data identification fields to guarantees table
ALTER TABLE guarantees ADD COLUMN is_test_data BOOLEAN DEFAULT 0 NOT NULL;
ALTER TABLE guarantees ADD COLUMN test_batch_id TEXT NULL;
ALTER TABLE guarantees ADD COLUMN test_note TEXT NULL;

-- Create indexes for performance
-- This ensures filtering test data doesn't slow down queries
CREATE INDEX idx_guarantees_is_test_data ON guarantees(is_test_data);
CREATE INDEX idx_guarantees_test_batch ON guarantees(test_batch_id) WHERE test_batch_id IS NOT NULL;

-- Verify migration
-- All existing records should have is_test_data = 0 (real data)
-- SELECT COUNT(*) as total_guarantees, 
--        SUM(CASE WHEN is_test_data = 1 THEN 1 ELSE 0 END) as test_data_count,
--        SUM(CASE WHEN is_test_data = 0 THEN 1 ELSE 0 END) as real_data_count
-- FROM guarantees;

-- Expected result: test_data_count = 0, real_data_count = total existing records
