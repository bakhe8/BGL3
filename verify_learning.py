import sqlite3
from pathlib import Path

DB = Path("c:/Users/Bakheet/Documents/Projects/BGL3/.bgl_core/brain/knowledge.db")


def verify():
    if not DB.exists():
        print(f"Error: DB not found at {DB}")
        return

    conn = sqlite3.connect(DB)
    cursor = conn.cursor()

    print("--- Checking Schema ---")
    cursor.execute("PRAGMA table_info(embeddings)")
    cols = cursor.fetchall()
    for col in cols:
        print(f"Col: {col[1]} (type: {col[2]}, pk: {col[5]})")

    print("\n--- Checking New Entries ---")
    cursor.execute(
        "SELECT label, SUBSTR(text, 1, 100) FROM embeddings WHERE label LIKE '[Insight]%' OR label LIKE '[Experience]%'"
    )
    rows = cursor.fetchall()

    print(f"Found {len(rows)} tagged entries.")
    for r in rows:
        print(f" - {r[0]}: {r[1]}...")

    conn.close()


if __name__ == "__main__":
    verify()
