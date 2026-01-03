-- ============================================
-- Phase 6: Database Schema Improvements
-- Migration: Drop supplier_learning_cache (deprecated)
-- Date: 2026-01-03
-- ============================================

-- This table acted as "cache-as-authority" (Charter violation)
-- It has been replaced by UnifiedLearningAuthority
-- Safe to drop - no data loss

DROP TABLE IF EXISTS supplier_learning_cache;

-- Remove related indexes (if any)
-- DROP INDEX IF EXISTS idx_supplier_learning_cache_xxx;
