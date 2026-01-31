-- Template: add recommended indexes for current schema
-- Adjust names if they clash with existing indexes.

-- guarantees (الفهرسة حسب الواقع الحالي)
CREATE INDEX IF NOT EXISTS idx_guarantees_import_source ON guarantees(import_source);
CREATE INDEX IF NOT EXISTS idx_guarantees_imported_at ON guarantees(imported_at);
CREATE INDEX IF NOT EXISTS idx_guarantees_normalized_supplier ON guarantees(normalized_supplier_name);
CREATE INDEX IF NOT EXISTS idx_guarantees_test_batch ON guarantees(test_batch_id);
CREATE INDEX IF NOT EXISTS idx_guarantees_is_test_data ON guarantees(is_test_data);

-- suppliers
CREATE INDEX IF NOT EXISTS idx_suppliers_normalized_name ON suppliers(normalized_name);
CREATE INDEX IF NOT EXISTS idx_suppliers_official_name ON suppliers(official_name);

-- banks
CREATE INDEX IF NOT EXISTS idx_banks_normalized_name ON banks(normalized_name);
CREATE INDEX IF NOT EXISTS idx_banks_contact_email ON banks(contact_email);
