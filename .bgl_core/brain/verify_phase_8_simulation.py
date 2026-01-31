import sqlite3
import time
from pathlib import Path


def simulate_recurring_blockers():
    db_path = ".bgl_core/brain/knowledge.db"
    conn = sqlite3.connect(db_path)

    # Clean old ones for clear test
    conn.execute("DELETE FROM agent_blockers WHERE reason LIKE '%Permission%'")
    conn.execute("DELETE FROM agent_proposals")

    # Inject 3 similar stressors
    stressors = [
        ("Task A", "Permission Denied: Cannot write to storage/logs/test.log"),
        ("Task B", "Permission Denied: OS blocked write to app/Config/agent.json"),
        ("Task C", "Permission Denied: Lacks admin rights for folder creation"),
    ]

    for task, reason in stressors:
        conn.execute(
            """
            INSERT INTO agent_blockers (timestamp, task_name, reason, complexity_level) 
            VALUES (?, ?, ?, ?)
        """,
            (time.time(), task, reason, 2),
        )

    conn.commit()
    conn.close()
    print("[+] Phase 8 Simulation: Injected 3 Permission stress patterns.")


if __name__ == "__main__":
    simulate_recurring_blockers()
