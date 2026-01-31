-- Patch Template: Audit Trigger
-- كيفية الاستخدام:
-- 1) استبدل {TABLE_NAME} و {AUDIT_TABLE} وحقول المفتاح الأساسية.
-- 2) شغّل هذا الـSQL على قاعدة البيانات.

CREATE TABLE IF NOT EXISTS {AUDIT_TABLE} (
  id INTEGER PRIMARY KEY AUTOINCREMENT,
  table_name TEXT,
  row_id INTEGER,
  action TEXT,
  payload TEXT,
  changed_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

DROP TRIGGER IF EXISTS trg_{TABLE_NAME}_audit;
CREATE TRIGGER trg_{TABLE_NAME}_audit
AFTER INSERT OR UPDATE OR DELETE ON {TABLE_NAME}
BEGIN
  INSERT INTO {AUDIT_TABLE}(table_name, row_id, action, payload)
  VALUES (
    '{TABLE_NAME}',
    COALESCE(NEW.id, OLD.id),
    CASE
      WHEN NEW.id IS NOT NULL AND OLD.id IS NULL THEN 'INSERT'
      WHEN NEW.id IS NOT NULL AND OLD.id IS NOT NULL THEN 'UPDATE'
      ELSE 'DELETE'
    END,
    json_object(
      'old', json(OLD),
      'new', json(NEW)
    )
  );
END;
