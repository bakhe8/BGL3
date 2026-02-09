import sqlite3
from pathlib import Path

db = Path(".bgl_core/brain/knowledge.db")
if not db.exists():
    print(f"DB not found: {db}")
    exit(1)

conn = sqlite3.connect(str(db))
cur = conn.cursor()

# Check tables
print("=== Tables ===")
tables = cur.execute("SELECT name FROM sqlite_master WHERE type='table'").fetchall()
for t in tables:
    print(f"  - {t[0]}")

print("\n=== Counts ===")
for table in [
    "exploration_novelty",
    "ui_action_snapshots",
    "runtime_events",
    "experiences",
    "agent_proposals",
]:
    try:
        count = cur.execute(f"SELECT COUNT(*) FROM {table}").fetchone()[0]
        print(f"  - {table}: {count}")
    except:
        print(f"  - {table}: NOT EXISTS")

conn.close()
