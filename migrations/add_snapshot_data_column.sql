-- Migration: Add snapshot_data column to guarantee_timeline_events
-- This stores a complete JSON snapshot of the letter data at the time of each event

ALTER TABLE guarantee_timeline_events 
ADD COLUMN snapshot_data TEXT;

-- Add comment for documentation
-- snapshot_data stores JSON with structure:
-- {
--   "guarantee_number": "...",
--   "contract_number": "...",
--   "supplier_name": "...",
--   "bank_name": "...",
--   "amount": "...",
--   "expiry_date": "...",
--   "issue_date": "...",
--   "type": "...",
--   "record_type": "import|modification|extension|release"
-- }
