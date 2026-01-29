import sqlite3
from pathlib import Path

db_path = Path(".bgl_core/brain/knowledge.db")
conn = sqlite3.connect(db_path)
conn.row_factory = sqlite3.Row
cursor = conn.cursor()

print("--- Confidence Evidence Summary ---")
try:
    results = cursor.execute("""
        SELECT confidence, evidence, COUNT(*) as count 
        FROM calls 
        GROUP BY confidence, evidence
    """).fetchall()

    for row in results:
        print(
            f"Confidence: {row['confidence']} | Evidence: {row['evidence']} | Count: {row['count']}"
        )

    print("\n--- Sample HIGH Confidence Calls (Dependency Injection) ---")
    high_conf = cursor.execute("""
        SELECT e.name as source, c.target_entity, c.evidence 
        FROM calls c
        JOIN methods m ON c.source_method_id = m.id
        JOIN entities e ON m.entity_id = e.id
        WHERE c.confidence = 'HIGH'
        LIMIT 5
    """).fetchall()
    for row in high_conf:
        print(
            f"{row['source']} -> {row['target_entity']} (Evidence: {row['evidence']})"
        )

    print("\n--- Sample app() helper calls ---")
    app_calls = cursor.execute("""
        SELECT e.name as source, c.target_entity, c.evidence 
        FROM calls c
        JOIN methods m ON c.source_method_id = m.id
        JOIN entities e ON m.entity_id = e.id
        WHERE c.evidence = 'app_helper_call'
        LIMIT 5
    """).fetchall()
    for row in app_calls:
        print(
            f"{row['source']} -> {row['target_entity']} (Evidence: {row['evidence']})"
        )

except Exception as e:
    print(f"Error: {e}")

conn.close()
