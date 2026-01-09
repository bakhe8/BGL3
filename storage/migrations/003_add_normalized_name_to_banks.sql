-- Migration 003: Add normalized_name column to banks table
-- Date: 2026-01-10
-- Purpose: Support normalized name searching for banks

-- Add normalized_name column
ALTER TABLE banks ADD COLUMN normalized_name TEXT;

-- Populate normalized_name from arabic_name
-- Remove 'بنك', 'مصرف', spaces, and lowercase
UPDATE banks 
SET normalized_name = LOWER(
    REPLACE(
        REPLACE(
            REPLACE(arabic_name, 'بنك', ''),
            'مصرف', ''
        ),
        ' ', ''
    )
);

-- Create index for performance
CREATE INDEX IF NOT EXISTS idx_banks_normalized_name ON banks(normalized_name);

-- Verify the migration
-- SELECT id, arabic_name, normalized_name FROM banks LIMIT 5;
