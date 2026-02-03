"""
Apply DB fixes in sandbox: indexes and FKs based on patch_templates.
Assumes SQLite sandbox DB at storage/database/app.sqlite (or as configured).
"""
import argparse
import sqlite3
from pathlib import Path

try:
    from .authority import Authority  # type: ignore
    from .brain_types import ActionRequest, ActionKind  # type: ignore
except Exception:
    from authority import Authority
    from brain_types import ActionRequest, ActionKind


def run_sqlite(sql_file: Path, db_path: Path):
    sql = sql_file.read_text(encoding="utf-8")
    conn = sqlite3.connect(db_path)
    try:
        conn.executescript(sql)
        conn.commit()
    finally:
        conn.close()


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--db", default="storage/database/app.sqlite", help="Path to sandbox sqlite DB")
    args = parser.parse_args()

    root = Path(__file__).resolve().parents[2]
    auth = Authority(root)
    db = root / args.db
    if not db.exists():
        # log failure (best-effort)
        req = ActionRequest(
            kind=ActionKind.WRITE_SANDBOX,
            operation=f"db.apply_fixes|{args.db}",
            command=f"apply_db_fixes --db {args.db}",
            scope=[str(db)],
            reason="Apply sqlite indexes/FKs",
            confidence=0.7,
            metadata={"db": str(db)},
        )
        gate = auth.gate(req, source="apply_db_fixes")
        auth.record_outcome(int(gate.decision_id or 0), "fail", f"DB not found: {db}")
        raise SystemExit(f"DB not found: {db}")

    req = ActionRequest(
        kind=ActionKind.WRITE_SANDBOX,
        operation=f"db.apply_fixes|{args.db}",
        command=f"apply_db_fixes --db {args.db}",
        scope=[str(db)],
        reason="Apply sqlite indexes/FKs",
        confidence=0.7,
        metadata={"db": str(db)},
    )
    gate = auth.gate(req, source="apply_db_fixes")
    decision_id = int(gate.decision_id or 0)
    if not gate.allowed:
        print(f"[!] BLOCKED: {gate.message}")
        raise SystemExit(2)

    tmpl_dir = Path(__file__).parent / "patch_templates"
    idx = tmpl_dir / "db_add_indexes.sql"
    fk = tmpl_dir / "db_add_foreign_keys.sql"

    try:
        print(f"[+] Applying indexes from {idx.name}")
        run_sqlite(idx, db)
        print(f"[+] Applying foreign keys from {fk.name} (placeholder - no-op if no FK columns)")
        try:
            run_sqlite(fk, db)
        except Exception as e:
            print(f"[!] FK script skipped: {e}")
        print("[✓] DB fixes applied where possible")
        auth.record_outcome(decision_id, "success", "DB fixes applied")
    except Exception as e:
        auth.record_outcome(decision_id, "fail", f"DB fixes failed: {e}")
        raise


if __name__ == "__main__":
    main()
