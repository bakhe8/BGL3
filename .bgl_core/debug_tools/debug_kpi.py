import sqlite3
import os

db_path = ".bgl_core/brain/knowledge.db"

if not os.path.exists(db_path):
    print(f"❌ Database not found at {db_path}")
    exit(1)

conn = sqlite3.connect(db_path)
cursor = conn.cursor()

# 1. Check total rows
cursor.execute("SELECT count(*) FROM runtime_events")
total = cursor.fetchone()[0]
print(f"Total rows in runtime_events: {total}")

# 2. Check distinct routes
cursor.execute("SELECT DISTINCT route FROM runtime_events")
routes = cursor.fetchall()
print("\nDistinct routes found:")
for r in routes:
    print(f" - {r[0]}")

# 3. Check the specific query used in bootstrap.php
# Routes expected by KPI logic:
target_routes = [
    "/api/create-guarantee.php",
    "/api/update_bank.php",
    "/api/update_supplier.php",
    "/api/import_suppliers.php",
    "/api/import_banks.php",
    "/api/create-bank.php",
    "/api/create-supplier.php",
]
placeholders = ",".join(["?"] * len(target_routes))
query = f"SELECT COUNT(*) FROM runtime_events WHERE route IN ({placeholders})"

try:
    cursor.execute(query, target_routes)
    count_matches = cursor.fetchone()[0]
    print(f"\nRows matching KPI routes: {count_matches}")
except Exception as e:
    print(f"\n❌ Query failed: {e}")

conn.close()
