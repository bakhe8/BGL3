import sqlite3
import json

conn = sqlite3.connect(".bgl_core/brain/knowledge.db")
conn.row_factory = sqlite3.Row
query = """
    SELECT f.path, e.name as entity, m.name as method, c.target_entity, c.type 
    FROM calls c 
    JOIN methods m ON c.source_method_id = m.id 
    JOIN entities e ON m.entity_id = e.id 
    JOIN files f ON e.file_id = f.id 
    WHERE f.path LIKE '%api%create-guarantee.php'
"""
res = conn.execute(query).fetchall()
print(json.dumps([dict(r) for r in res], indent=2))
conn.close()
