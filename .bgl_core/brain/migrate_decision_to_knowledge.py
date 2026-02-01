"""
One-time migration: copy intents/decisions/outcomes from decision.db to knowledge.db.
Safe to re-run; skips if source missing.
"""
from pathlib import Path
import sqlite3

ROOT = Path(__file__).resolve().parents[2]
SRC = ROOT / ".bgl_core" / "brain" / "decision.db"
DST = ROOT / ".bgl_core" / "brain" / "knowledge.db"
SCHEMA = ROOT / ".bgl_core" / "brain" / "decision_schema.sql"


def ensure_schema(db: Path):
    if not SCHEMA.exists():
        return
    conn = sqlite3.connect(db)
    with conn:
        conn.executescript(SCHEMA.read_text(encoding="utf-8"))
    conn.close()


def migrate():
    if not SRC.exists():
        print("No decision.db found; nothing to migrate.")
        return
    ensure_schema(DST)
    src = sqlite3.connect(SRC)
    dst = sqlite3.connect(DST)
    with src, dst:
        for table in ("intents", "decisions", "outcomes"):
            # create table if missing
            cur = src.execute(f"SELECT name FROM sqlite_master WHERE type='table' AND name='{table}'")
            if not cur.fetchone():
                continue
            dst.execute(f"CREATE TABLE IF NOT EXISTS {table} AS SELECT * FROM {table} WHERE 0")
            # copy rows not already present (by id)
            rows = src.execute(f"SELECT * FROM {table}").fetchall()
            if not rows:
                continue
            cols_info = src.execute(f"PRAGMA table_info({table})").fetchall()
            cols = [str(d[1]) for d in cols_info]
            placeholders = ",".join(["?"] * len(cols))
            collist = ",".join(cols)
            for r in rows:
                # skip if id exists
                rid = r[0]
                exists = dst.execute(f"SELECT 1 FROM {table} WHERE id=?", (rid,)).fetchone()
                if exists:
                    continue
                dst.execute(f"INSERT INTO {table} ({collist}) VALUES ({placeholders})", r)
        dst.commit()
    src.close()
    dst.close()
    print("Migration completed.")


if __name__ == "__main__":
    migrate()
