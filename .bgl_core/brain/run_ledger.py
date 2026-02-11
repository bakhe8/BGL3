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


def _has_column(conn: sqlite3.Connection, table: str, column: str) -> bool:
    try:
        cols = {r[1] for r in conn.execute(f"PRAGMA table_info({table})").fetchall()}
        return column in cols
    except Exception:
        return False


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
        if _has_column(conn, "runtime_events", "run_id"):
            row = conn.execute(
                "SELECT COUNT(*) FROM runtime_events WHERE run_id = ?",
                (run_id,),
            ).fetchone()
            count = int(row[0] or 0) if row else 0
            if count > 0:
                return count
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


def _log_runtime_event(conn: sqlite3.Connection, event: Dict[str, Any]) -> None:
    try:
        conn.execute(
            """
            CREATE TABLE IF NOT EXISTS runtime_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp REAL NOT NULL,
                session TEXT,
                run_id TEXT,
                scenario_id TEXT,
                goal_id TEXT,
                source TEXT,
                event_type TEXT NOT NULL,
                route TEXT,
                method TEXT,
                target TEXT,
                step_id TEXT,
                payload TEXT,
                status INTEGER,
                latency_ms REAL,
                error TEXT
            )
            """
        )
        payload = event.get("payload")
        if isinstance(payload, dict):
            payload = json.dumps(payload, ensure_ascii=False)
        conn.execute(
            """
            INSERT INTO runtime_events (timestamp, session, run_id, scenario_id, goal_id, source, event_type, route, method, target, step_id, payload, status, latency_ms, error)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                event.get("timestamp", time.time()),
                event.get("session", ""),
                event.get("run_id", ""),
                event.get("scenario_id", ""),
                event.get("goal_id", ""),
                event.get("source", "run_ledger"),
                event.get("event_type"),
                event.get("route"),
                event.get("method"),
                event.get("target"),
                event.get("step_id"),
                payload,
                event.get("status"),
                event.get("latency_ms"),
                event.get("error"),
            ),
        )
    except Exception:
        pass


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
        window_events = None
        try:
            row = conn.execute(
                "SELECT COUNT(*) FROM runtime_events WHERE timestamp >= ? AND timestamp <= ?",
                (float(start_ts), float(end_ts)),
            ).fetchone()
            window_events = int(row[0] or 0) if row else 0
        except Exception:
            window_events = None
        decisions_count = _count_by_time(conn, "decisions", "created_at", start_ts, end_ts)
        outcomes_count = _count_by_time(conn, "outcomes", "timestamp", start_ts, end_ts)

        attr = _load_attribution(conn, run_id, start_ts, end_ts)
        attr_class = str(attr.get("classification") or "") if isinstance(attr, dict) else ""
        attr_conf = float(attr.get("confidence") or 0) if isinstance(attr, dict) else None

        notes = None
        try:
            row_notes = conn.execute(
                "SELECT notes FROM agent_runs WHERE run_id = ?",
                (run_id,),
            ).fetchone()
            notes = str(row_notes[0] or "") if row_notes else ""
        except Exception:
            notes = ""
        if window_events is not None and runtime_count == 0 and window_events > 0:
            mismatch_note = f"run_id_mismatch:events_in_window={window_events}"
            if mismatch_note not in (notes or ""):
                notes = (notes + " | " if notes else "") + mismatch_note
            try:
                _log_runtime_event(
                    conn,
                    {
                        "timestamp": end_ts,
                        "run_id": run_id,
                        "event_type": "run_id_mismatch",
                        "source": "run_ledger",
                        "payload": {
                            "run_id": run_id,
                            "events_in_window": window_events,
                            "start_ts": start_ts,
                            "end_ts": end_ts,
                        },
                    },
                )
            except Exception:
                pass

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
        if notes is not None:
            try:
                conn.execute(
                    "UPDATE agent_runs SET notes=? WHERE run_id=?",
                    (notes, run_id),
                )
            except Exception:
                pass
        conn.commit()
