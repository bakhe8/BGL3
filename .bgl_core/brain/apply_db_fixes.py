"""
Apply DB fixes in sandbox: indexes and FKs based on patch_templates.
Assumes SQLite sandbox DB at storage/database/app.sqlite (or as configured).
"""
import argparse
import sqlite3
from pathlib import Path


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
    db = root / args.db
    if not db.exists():
        raise SystemExit(f"DB not found: {db}")

    tmpl_dir = Path(__file__).parent / "patch_templates"
    idx = tmpl_dir / "db_add_indexes.sql"
    fk = tmpl_dir / "db_add_foreign_keys.sql"

    print(f"[+] Applying indexes from {idx.name}")
    run_sqlite(idx, db)
    print(f"[+] Applying foreign keys from {fk.name} (placeholder - no-op if no FK columns)")
    try:
        run_sqlite(fk, db)
    except Exception as e:
        print(f"[!] FK script skipped: {e}")
    print("[✓] DB fixes applied where possible")


if __name__ == "__main__":
    main()
