-- ============================================================================
-- Migration: Drop old V3 tables if they exist
-- Created: 2025-12-23
-- Description: Clean slate for new V3 schema
-- ============================================================================

PRAGMA foreign_keys = OFF;

-- Drop all V3 tables
DROP TABLE IF EXISTS guarantee_actions;
DROP TABLE IF EXISTS guarantee_decisions;
DROP TABLE IF EXISTS guarantees;
DROP TABLE IF EXISTS supplier_learning_cache;
DROP TABLE IF EXISTS supplier_decisions_log;

PRAGMA foreign_keys = ON;

SELECT 'Old tables dropped successfully' as result;
