CREATE TABLE IF NOT EXISTS guarantee_history (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_id INTEGER NOT NULL,
    action TEXT NOT NULL, -- 'create', 'update', 'decision', 'extension', 'release'
    snapshot_data TEXT NOT NULL, -- JSON dump of guarantee + decision state
    change_reason TEXT DEFAULT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    created_by TEXT DEFAULT 'system',
    FOREIGN KEY(guarantee_id) REFERENCES guarantees(id)
);

CREATE INDEX idx_history_guarantee ON guarantee_history(guarantee_id);
