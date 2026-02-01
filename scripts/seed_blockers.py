import sqlite3
import time
from pathlib import Path

DB_PATH = Path(".bgl_core/brain/knowledge.db")


def seed():
    conn = sqlite3.connect(str(DB_PATH))
    conn.execute("""
        CREATE TABLE IF NOT EXISTS agent_blockers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            task_name TEXT,
            reason TEXT,
            status TEXT,
            timestamp REAL
        )
    """)

    # Insert 2 vague errors that keyword matching won't catch (so they go to General -> LLM)
    blockers = [
        (
            "Quantum_Flux_Module",
            "Critical decoherence detected in the subspace emitter array during startup.",
            "PENDING",
            time.time(),
        ),
        (
            "Quantum_Flux_Module",
            "Subspace emitter failed to stabilize harmonic resonance frequency.",
            "PENDING",
            time.time(),
        ),
    ]

    conn.executemany(
        "INSERT INTO agent_blockers (task_name, reason, status, timestamp) VALUES (?, ?, ?, ?)",
        blockers,
    )
    conn.commit()
    print(f"Seeded {len(blockers)} complex blockers.")
    conn.close()


if __name__ == "__main__":
    seed()
