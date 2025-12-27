-- Final Cleanup: Remove ALL unnecessary columns

-- SQLite workaround for dropping columns:
-- Create new table without unwanted columns

BEGIN TRANSACTION;

-- 1. Create new guarantee_history table (only needed columns)
CREATE TABLE guarantee_history_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    event_type TEXT NOT NULL,
    event_subtype TEXT,
    snapshot_data TEXT,
    event_details TEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by TEXT,
    
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);

-- 2. Copy data from old table to new (exclude unwanted columns)
INSERT INTO guarantee_history_new (
    id, guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by
)
SELECT 
    id, guarantee_id, event_type, event_subtype, snapshot_data, event_details, created_at, created_by
FROM guarantee_history;

-- 3. Drop old table
DROP TABLE guarantee_history;

-- 4. Rename new table
ALTER TABLE guarantee_history_new RENAME TO guarantee_history;

-- 5. Recreate indexes
CREATE INDEX IF NOT EXISTS idx_history_guarantee ON guarantee_history(guarantee_id);
CREATE INDEX IF NOT EXISTS idx_history_subtype ON guarantee_history(event_subtype);
CREATE INDEX IF NOT EXISTS idx_history_created_at ON guarantee_history(created_at DESC);

COMMIT;

SELECT 'Final cleanup complete - removed action_status, action_date, letter_*, notes' as status;
