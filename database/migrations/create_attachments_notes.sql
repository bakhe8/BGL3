-- Create Attachments Table
CREATE TABLE IF NOT EXISTS guarantee_attachments (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    file_name TEXT NOT NULL,
    file_path TEXT NOT NULL,
    file_size INTEGER,
    file_type TEXT,
    uploaded_by TEXT DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);

-- Create Notes Table
CREATE TABLE IF NOT EXISTS guarantee_notes (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    content TEXT NOT NULL,
    created_by TEXT DEFAULT 'system',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP,
    FOREIGN KEY (guarantee_id) REFERENCES guarantees(id) ON DELETE CASCADE
);

-- Indexes for performance
CREATE INDEX IF NOT EXISTS idx_attachments_guarantee ON guarantee_attachments(guarantee_id);
CREATE INDEX IF NOT EXISTS idx_notes_guarantee ON guarantee_notes(guarantee_id);
