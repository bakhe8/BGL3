-- Learning Merge Schema Migration
-- Date: 2026-01-04
-- Purpose: Add normalized_supplier_name columns and indexes for safe querying
-- Replaces: Fragile JSON LIKE queries

-- ============================================================
-- STEP 1: Add normalized_supplier_name to guarantees
-- ============================================================

ALTER TABLE guarantees 
ADD COLUMN normalized_supplier_name TEXT;

-- ============================================================
-- STEP 2: Add normalized_supplier_name to learning_confirmations
-- ============================================================

ALTER TABLE learning_confirmations
ADD COLUMN normalized_supplier_name TEXT;

-- ============================================================
-- STEP 3: Create indexes for fast lookup
-- ============================================================

-- Index for guarantees (historical queries)
CREATE INDEX idx_guarantees_normalized_supplier 
ON guarantees(normalized_supplier_name);

-- Index for learning_confirmations (existing column, currently unindexed)
CREATE INDEX idx_learning_confirmations_raw_supplier 
ON learning_confirmations(raw_supplier_name);

-- Composite index for learning_confirmations (normalized + action)
CREATE INDEX idx_learning_confirmations_normalized 
ON learning_confirmations(normalized_supplier_name, action);

-- ============================================================
-- STEP 4: Index for guarantee_decisions (optional performance boost)
-- ============================================================

-- Helps with GROUP BY in historical selections query
CREATE INDEX idx_guarantee_decisions_supplier
ON guarantee_decisions(supplier_id) 
WHERE supplier_id IS NOT NULL;

-- ============================================================
-- Verification: Check schema changes applied
-- ============================================================

-- To verify after migration, run:
-- PRAGMA table_info(guarantees);  -- Should show normalized_supplier_name
-- PRAGMA table_info(learning_confirmations);  -- Should show normalized_supplier_name
-- PRAGMA index_list(guarantees);  -- Should show idx_guarantees_normalized_supplier
-- PRAGMA index_list(learning_confirmations);  -- Should show both indexes

-- ============================================================
-- Notes:
-- ============================================================
-- - All changes are ADDITIVE (no columns dropped)
-- - raw_data remains untouched (Timeline compatibility)
-- - population must run AFTER this migration
-- - If migration fails, rollback using backup
-- ============================================================
