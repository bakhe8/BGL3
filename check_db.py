import sqlite3
from pathlib import Path

db_path = Path(".bgl_core/brain/knowledge.db")
if not db_path.exists():
    print("Database not found.")
else:
    conn = sqlite3.connect(db_path)
    cur = conn.cursor()
    cur.execute("SELECT COUNT(*) FROM routes;")
    count = cur.fetchone()[0]
    print(f"Total routes in DB: {count}")

    cur.execute("SELECT uri FROM routes LIMIT 20;")
    routes = cur.fetchall()
    print("Sample routes:")
    for r in routes:
        print(f"  - {r[0]}")
    conn.close()
