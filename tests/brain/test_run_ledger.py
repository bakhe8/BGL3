from pathlib import Path
import sqlite3
import sys
import time


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".bgl_core" / "brain"))

from run_ledger import start_run, finish_run  # type: ignore


def test_run_ledger_counts_runtime_events(tmp_path: Path):
    db = tmp_path / "knowledge.db"
    conn = sqlite3.connect(str(db))
    conn.execute(
        """
        CREATE TABLE runtime_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp REAL NOT NULL,
            session TEXT,
            event_type TEXT NOT NULL,
            route TEXT,
            method TEXT,
            target TEXT,
            payload TEXT,
            status INTEGER,
            latency_ms REAL,
            error TEXT
        )
        """
    )
    conn.commit()
    run_id = "run_123"
    now = time.time()
    conn.execute(
        "INSERT INTO runtime_events (timestamp, session, event_type) VALUES (?, ?, ?)",
        (now, run_id + "|agent_run", "agent_run_start"),
    )
    conn.execute(
        "INSERT INTO runtime_events (timestamp, session, event_type) VALUES (?, ?, ?)",
        (now + 1, run_id + "|click", "ui_click"),
    )
    conn.commit()
    conn.close()

    start_run(db, run_id=run_id, mode="scenario_runner", started_at=now)
    finish_run(db, run_id=run_id, ended_at=now + 2)

    conn = sqlite3.connect(str(db))
    row = conn.execute(
        "SELECT runtime_events_count FROM agent_runs WHERE run_id = ?",
        (run_id,),
    ).fetchone()
    conn.close()
    assert row is not None
    assert int(row[0]) == 2
