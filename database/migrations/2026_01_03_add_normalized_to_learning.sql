-- ============================================
-- Phase 6: Database Schema Improvements
-- Migration: Add normalized_supplier_name to learning_confirmations
-- Date: 2026-01-03
-- ============================================

-- Add normalized column to learning_confirmations
ALTER TABLE learning_confirmations 
ADD COLUMN normalized_supplier_name TEXT;

-- Create index for performance
CREATE INDEX IF NOT EXISTS idx_learning_confirmations_normalized 
ON learning_confirmations(normalized_supplier_name);

-- Backfill existing data (will be done via PHP script)
-- See: backfill_normalized_names.php
