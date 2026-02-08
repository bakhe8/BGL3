from __future__ import annotations

import json
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, Optional


def _ensure_table(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS agent_runs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            run_id TEXT UNIQUE,
            mode TEXT,
            started_at REAL,
            ended_at REAL,
            duration_s REAL,
            runtime_events_count INTEGER,
            decisions_count INTEGER,
            outcomes_count INTEGER,
            attribution_class TEXT,
            attribution_conf REAL,
            notes TEXT
        )
        """
    )
    conn.commit()


def start_run(
    db_path: Path, *, run_id: str, mode: str, started_at: Optional[float] = None, notes: str = ""
) -> None:
    if not db_path.exists():
        return
    ts = float(started_at or time.time())
    with sqlite3.connect(str(db_path), timeout=30.0) as conn:
        _ensure_table(conn)
        conn.execute(
            """
            INSERT INTO agent_runs (run_id, mode, started_at, notes)
            VALUES (?, ?, ?, ?)
            ON CONFLICT(run_id) DO UPDATE SET mode=excluded.mode, started_at=excluded.started_at, notes=excluded.notes
            """,
            (run_id, mode, ts, notes),
        )
        conn.commit()


def _count_runtime_events(conn: sqlite3.Connection, run_id: str, start_ts: float, end_ts: float) -> int:
    try:
        row = conn.execute(
            "SELECT COUNT(*) FROM runtime_events WHERE session LIKE ?",
            (f"{run_id}%",),
        ).fetchone()
        count = int(row[0] or 0) if row else 0
        if count > 0:
            return count
    except Exception:
        pass
    try:
        row = conn.execute(
            "SELECT COUNT(*) FROM runtime_events WHERE timestamp >= ? AND timestamp <= ?",
            (float(start_ts), float(end_ts)),
        ).fetchone()
        return int(row[0] or 0) if row else 0
    except Exception:
        return 0


def _count_by_time(conn: sqlite3.Connection, table: str, time_col: str, start_ts: float, end_ts: float) -> Optional[int]:
    try:
        row = conn.execute(
            f"SELECT COUNT(*) FROM {table} WHERE strftime('%s', {time_col}) >= ? AND strftime('%s', {time_col}) <= ?",
            (int(start_ts), int(end_ts)),
        ).fetchone()
        return int(row[0] or 0) if row else 0
    except Exception:
        return None


def _load_attribution(conn: sqlite3.Connection, run_id: str, start_ts: float, end_ts: float) -> Dict[str, Any]:
    try:
        row = conn.execute(
            """
            SELECT payload_json FROM env_snapshots
            WHERE kind='diagnostic_attribution' AND run_id=?
            ORDER BY created_at DESC LIMIT 1
            """,
            (run_id,),
        ).fetchone()
        if row and row[0]:
            return json.loads(row[0])
    except Exception:
        pass
    try:
        row = conn.execute(
            """
            SELECT payload_json FROM env_snapshots
            WHERE kind='diagnostic_attribution' AND created_at >= ? AND created_at <= ?
            ORDER BY created_at DESC LIMIT 1
            """,
            (float(start_ts), float(end_ts)),
        ).fetchone()
        if row and row[0]:
            return json.loads(row[0])
    except Exception:
        pass
    return {}


def finish_run(db_path: Path, *, run_id: str, ended_at: Optional[float] = None) -> None:
    if not db_path.exists():
        return
    end_ts = float(ended_at or time.time())
    with sqlite3.connect(str(db_path), timeout=30.0) as conn:
        conn.row_factory = sqlite3.Row
        _ensure_table(conn)
        row = conn.execute(
            "SELECT started_at FROM agent_runs WHERE run_id = ?",
            (run_id,),
        ).fetchone()
        start_ts = float(row["started_at"] or end_ts) if row else end_ts
        duration_s = max(0.0, end_ts - start_ts)

        runtime_count = _count_runtime_events(conn, run_id, start_ts, end_ts)
        decisions_count = _count_by_time(conn, "decisions", "created_at", start_ts, end_ts)
        outcomes_count = _count_by_time(conn, "outcomes", "timestamp", start_ts, end_ts)

        attr = _load_attribution(conn, run_id, start_ts, end_ts)
        attr_class = str(attr.get("classification") or "") if isinstance(attr, dict) else ""
        attr_conf = float(attr.get("confidence") or 0) if isinstance(attr, dict) else None

        conn.execute(
            """
            UPDATE agent_runs
            SET ended_at=?, duration_s=?, runtime_events_count=?, decisions_count=?, outcomes_count=?,
                attribution_class=?, attribution_conf=?
            WHERE run_id=?
            """,
            (
                end_ts,
                duration_s,
                runtime_count,
                decisions_count,
                outcomes_count,
                attr_class,
                attr_conf,
                run_id,
            ),
        )
        conn.commit()
