PRAGMA foreign_keys = OFF;
BEGIN TRANSACTION;

-- Rebuild guarantees table to add bank_id/supplier_id with FKs (idempotent-ish).
CREATE TABLE IF NOT EXISTS guarantees_new (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    guarantee_number TEXT NOT NULL,
    raw_data JSON NOT NULL,
    import_source TEXT NOT NULL,
    imported_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    imported_by TEXT,
    normalized_supplier_name TEXT,
    test_batch_id TEXT NULL,
    test_note TEXT NULL,
    is_test_data INTEGER DEFAULT 0 NOT NULL,
    bank_id INTEGER NULL,
    supplier_id INTEGER NULL,
    UNIQUE(guarantee_number),
    FOREIGN KEY(bank_id) REFERENCES banks(id),
    FOREIGN KEY(supplier_id) REFERENCES suppliers(id)
);

INSERT OR IGNORE INTO guarantees_new (id, guarantee_number, raw_data, import_source, imported_at, imported_by, normalized_supplier_name, test_batch_id, test_note, is_test_data, bank_id, supplier_id)
SELECT id, guarantee_number, raw_data, import_source, imported_at, imported_by, normalized_supplier_name, test_batch_id, test_note, is_test_data, NULL AS bank_id, NULL AS supplier_id
FROM guarantees;

DROP TABLE guarantees;
ALTER TABLE guarantees_new RENAME TO guarantees;

-- Recreate indexes (safe if already exist)
CREATE INDEX IF NOT EXISTS idx_guarantees_import_source ON guarantees(import_source);
CREATE INDEX IF NOT EXISTS idx_guarantees_imported_at ON guarantees(imported_at);
CREATE INDEX IF NOT EXISTS idx_guarantees_normalized_supplier ON guarantees(normalized_supplier_name);
CREATE INDEX IF NOT EXISTS idx_guarantees_test_batch ON guarantees(test_batch_id);
CREATE INDEX IF NOT EXISTS idx_guarantees_is_test_data ON guarantees(is_test_data);
CREATE INDEX IF NOT EXISTS idx_guarantees_bank ON guarantees(bank_id);
CREATE INDEX IF NOT EXISTS idx_guarantees_supplier ON guarantees(supplier_id);

COMMIT;
PRAGMA foreign_keys = ON;
