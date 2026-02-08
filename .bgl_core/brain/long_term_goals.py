from __future__ import annotations

import json
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, List, Optional


def _connect(db_path: Path) -> sqlite3.Connection:
    conn = sqlite3.connect(str(db_path), timeout=30.0)
    conn.row_factory = sqlite3.Row
    try:
        conn.execute("PRAGMA journal_mode=WAL;")
    except Exception:
        pass
    return conn


def _ensure_tables(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS long_term_goals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            goal_key TEXT UNIQUE,
            title TEXT,
            goal TEXT,
            payload_json TEXT,
            source TEXT,
            status TEXT,
            priority REAL,
            created_at REAL,
            updated_at REAL,
            last_scheduled_at REAL,
            next_due_at REAL,
            success_count INTEGER DEFAULT 0,
            fail_count INTEGER DEFAULT 0,
            last_outcome TEXT,
            last_outcome_at REAL,
            notes TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_long_term_goals_status ON long_term_goals(status, priority DESC)"
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_long_term_goals_due ON long_term_goals(next_due_at, priority DESC)"
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS long_term_goal_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            goal_key TEXT,
            created_at REAL,
            event_type TEXT,
            delta REAL,
            confidence REAL,
            details_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_long_term_goal_events_time ON long_term_goal_events(created_at DESC)"
    )


def _safe_json(obj: Any) -> str:
    try:
        return json.dumps(obj, ensure_ascii=False)
    except Exception:
        return "{}"


def _safe_float(val: Any, default: float = 0.0) -> float:
    try:
        return float(val)
    except Exception:
        return default


def _clamp(val: float, lo: float, hi: float) -> float:
    return max(lo, min(hi, val))


def _interval_hours(priority: float) -> float:
    # Higher priority goals should resurface faster.
    return max(6.0, 48.0 - (priority * 36.0))


def _upsert_goal(
    conn: sqlite3.Connection,
    *,
    goal_key: str,
    title: str,
    goal_type: Optional[str] = None,
    goal: Optional[str] = None,
    payload: Dict[str, Any],
    source: str,
    priority: float,
    status: str = "active",
    notes: str = "",
) -> str:
    now = time.time()
    priority = _clamp(_safe_float(priority, 0.0), 0.0, 1.0)
    payload_json = _safe_json(payload or {})
    if not goal_type:
        goal_type = str(goal or "")
    if not goal_type:
        goal_type = "unspecified"
    row = conn.execute(
        "SELECT id, priority, status FROM long_term_goals WHERE goal_key=?",
        (goal_key,),
    ).fetchone()
    if row:
        current_pri = _safe_float(row["priority"], 0.0)
        # allow priority to decay slightly unless a higher signal arrives
        new_pri = max(current_pri * 0.9, priority)
        status_val = row["status"] or status
        conn.execute(
            """
            UPDATE long_term_goals
            SET title=?, goal=?, payload_json=?, source=?, status=?, priority=?, updated_at=?, notes=?
            WHERE id=?
            """,
            (
                title,
                goal_type,
                payload_json,
                source,
                status_val,
                float(new_pri),
                now,
                notes,
                int(row["id"]),
            ),
        )
        return "updated"
    conn.execute(
        """
        INSERT INTO long_term_goals
        (goal_key, title, goal, payload_json, source, status, priority, created_at, updated_at, notes)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
        (
            goal_key,
            title,
            goal_type,
            payload_json,
            source,
            status,
            float(priority),
            now,
            now,
            notes,
        ),
    )
    return "added"


def _outcome_stats(conn: sqlite3.Connection, lookback_days: int) -> Dict[str, Any]:
    try:
        rows = conn.execute(
            "SELECT result, COUNT(*) c FROM outcomes WHERE timestamp >= datetime('now', ?) GROUP BY result",
            (f"-{int(lookback_days)} days",),
        ).fetchall()
    except Exception:
        return {"total": 0, "fail": 0, "success": 0, "fail_rate": 0.0}
    total = 0
    fail = 0
    success = 0
    fail_set = {"fail", "blocked", "error"}
    success_set = {"success", "success_with_override", "partial", "prevented_regression", "confirmed_issue"}
    for row in rows:
        result = str(row["result"] or "").lower()
        count = int(row["c"] or 0)
        total += count
        if result in fail_set:
            fail += count
        elif result in success_set:
            success += count
    fail_rate = fail / max(1, total)
    return {"total": total, "fail": fail, "success": success, "fail_rate": fail_rate}


def refresh_long_term_goals(
    db_path: Path,
    *,
    lookback_days: int = 30,
    max_candidates: int = 12,
) -> Dict[str, Any]:
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}
    conn = _connect(db_path)
    _ensure_tables(conn)

    candidates: Dict[str, Dict[str, Any]] = {}

    def add_candidate(payload: Dict[str, Any]) -> None:
        key = str(payload.get("goal_key") or "").strip()
        if not key:
            return
        existing = candidates.get(key)
        if existing and _safe_float(existing.get("priority"), 0.0) >= _safe_float(payload.get("priority"), 0.0):
            return
        candidates[key] = payload

    # Hypothesis-driven goals
    try:
        rows = conn.execute(
            """
            SELECT id, title, statement, priority, confidence
            FROM hypotheses
            WHERE status='open'
            ORDER BY priority DESC, updated_at DESC
            LIMIT ?
            """,
            (int(max_candidates),),
        ).fetchall()
    except Exception:
        rows = []
    for row in rows:
        hid = int(row["id"])
        pri = 0.4 + 0.5 * _safe_float(row["priority"], 0.0) + 0.1 * _safe_float(row["confidence"], 0.0)
        add_candidate(
            {
                "goal_key": f"hypothesis:{hid}",
                "title": str(row["title"] or "Hypothesis"),
                "goal": "hypothesis_validate",
                "payload": {"hypothesis_id": hid, "statement": row["statement"], "title": row["title"]},
                "source": "hypothesis",
                "priority": _clamp(pri, 0.2, 1.0),
                "status": "active",
                "notes": "open_hypothesis",
            }
        )

    # Knowledge conflicts -> review goal
    try:
        conflicts = conn.execute(
            "SELECT key, winner_path, reason FROM knowledge_conflicts ORDER BY created_at DESC LIMIT 6"
        ).fetchall()
    except Exception:
        conflicts = []
    if conflicts:
        keys = [str(r["key"] or "") for r in conflicts if r["key"]]
        pri = 0.7 + min(0.2, 0.04 * len(keys))
        add_candidate(
            {
                "goal_key": "knowledge_conflicts",
                "title": "Knowledge conflict review",
                "goal": "knowledge_conflict_review",
                "payload": {"keys": keys},
                "source": "knowledge",
                "priority": _clamp(pri, 0.2, 1.0),
                "status": "active",
                "notes": "conflict_keys",
            }
        )

    # Outcome stability -> raise priority when failures dominate
    stats = _outcome_stats(conn, lookback_days)
    if stats.get("total", 0) >= 5 and stats.get("fail_rate", 0.0) >= 0.35:
        pri = 0.5 + min(0.4, float(stats.get("fail_rate", 0.0)))
        add_candidate(
            {
                "goal_key": "stability_review",
                "title": "Stability review",
                "goal": "stability_review",
                "payload": {"stats": stats},
                "source": "outcomes",
                "priority": _clamp(pri, 0.2, 1.0),
                "status": "active",
                "notes": "fail_rate_trigger",
            }
        )

    # Learning feedback negative deltas -> review intent bias
    try:
        cutoff = time.time() - (lookback_days * 86400)
        row = conn.execute(
            "SELECT COUNT(*) c FROM learning_feedback WHERE delta < 0 AND created_at >= ?",
            (float(cutoff),),
        ).fetchone()
        neg_count = int(row["c"] or 0) if row else 0
    except Exception:
        neg_count = 0
    if neg_count >= 2:
        pri = 0.55 + min(0.25, 0.05 * neg_count)
        add_candidate(
            {
                "goal_key": "intent_bias_review",
                "title": "Intent bias review",
                "goal": "intent_bias_review",
                "payload": {"negative_updates": neg_count},
                "source": "learning_feedback",
                "priority": _clamp(pri, 0.2, 1.0),
                "status": "active",
                "notes": "negative_bias_updates",
            }
        )

    # Recent volition -> ensure purpose focus stays in long-term view
    try:
        row = conn.execute(
            "SELECT volition, confidence FROM volitions ORDER BY created_at DESC LIMIT 1"
        ).fetchone()
    except Exception:
        row = None
    if row and row["volition"]:
        pri = 0.35 + min(0.25, _safe_float(row["confidence"], 0.0) * 0.4)
        add_candidate(
            {
                "goal_key": "purpose_focus",
                "title": "Purpose focus",
                "goal": "purpose_focus",
                "payload": {"term": row["volition"], "purpose": row["volition"]},
                "source": "volition",
                "priority": _clamp(pri, 0.2, 1.0),
                "status": "active",
                "notes": "volition",
            }
        )

    added = 0
    updated = 0
    for payload in candidates.values():
        result = _upsert_goal(conn, **payload)
        if result == "added":
            added += 1
        elif result == "updated":
            updated += 1

    # Pause very low-priority goals to keep queue focused
    try:
        conn.execute(
            "UPDATE long_term_goals SET status='paused' WHERE priority < 0.2 AND status='active'"
        )
    except Exception:
        pass

    conn.commit()
    summary = summarize_long_term_goals(db_path, limit=6, conn=conn)
    conn.close()

    return {
        "ok": True,
        "added": added,
        "updated": updated,
        "candidates": len(candidates),
        "summary": summary,
    }


def pick_long_term_goals(
    db_path: Path,
    *,
    limit: int = 3,
    min_priority: float = 0.25,
) -> List[Dict[str, Any]]:
    if not db_path.exists():
        return []
    conn = _connect(db_path)
    _ensure_tables(conn)
    now = time.time()
    rows = conn.execute(
        """
        SELECT id, goal_key, title, goal, payload_json, source, priority, last_scheduled_at, next_due_at
        FROM long_term_goals
        WHERE status='active' AND priority >= ? AND (next_due_at IS NULL OR next_due_at <= ?)
        ORDER BY priority DESC, COALESCE(last_scheduled_at, 0) ASC
        LIMIT ?
        """,
        (float(min_priority), float(now), int(limit)),
    ).fetchall()

    picked: List[Dict[str, Any]] = []
    for row in rows:
        try:
            payload = json.loads(row["payload_json"] or "{}")
        except Exception:
            payload = {}
        interval_h = _interval_hours(_safe_float(row["priority"], 0.0))
        next_due = now + interval_h * 3600.0
        conn.execute(
            "UPDATE long_term_goals SET last_scheduled_at=?, next_due_at=?, updated_at=? WHERE id=?",
            (float(now), float(next_due), float(now), int(row["id"])),
        )
        picked.append(
            {
                "goal_key": row["goal_key"],
                "title": row["title"],
                "goal": row["goal"],
                "payload": payload,
                "source": row["source"],
                "priority": row["priority"],
            }
        )

    conn.commit()
    conn.close()
    return picked


def record_long_term_goal_result(
    db_path: Path,
    goal_key: str,
    *,
    ok: bool,
    details: Optional[Dict[str, Any]] = None,
) -> None:
    if not db_path.exists() or not goal_key:
        return
    conn = _connect(db_path)
    _ensure_tables(conn)
    row = conn.execute(
        "SELECT id, priority, success_count, fail_count FROM long_term_goals WHERE goal_key=?",
        (goal_key,),
    ).fetchone()
    if not row:
        conn.close()
        return
    success = int(row["success_count"] or 0)
    fail = int(row["fail_count"] or 0)
    delta = 0.0
    outcome = "success" if ok else "fail"
    if ok:
        success += 1
        delta = -0.05
    else:
        fail += 1
        delta = 0.07
    new_priority = _clamp(_safe_float(row["priority"], 0.0) + delta, 0.1, 1.0)
    now = time.time()
    conn.execute(
        """
        UPDATE long_term_goals
        SET success_count=?, fail_count=?, last_outcome=?, last_outcome_at=?, priority=?, updated_at=?
        WHERE id=?
        """,
        (
            success,
            fail,
            outcome,
            float(now),
            float(new_priority),
            float(now),
            int(row["id"]),
        ),
    )
    try:
        conn.execute(
            """
            INSERT INTO long_term_goal_events
            (goal_key, created_at, event_type, delta, confidence, details_json)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                goal_key,
                float(now),
                "outcome",
                float(delta),
                None,
                _safe_json(details or {}),
            ),
        )
    except Exception:
        pass
    conn.commit()
    conn.close()


def summarize_long_term_goals(
    db_path: Path, *, limit: int = 6, conn: Optional[sqlite3.Connection] = None
) -> Dict[str, Any]:
    if not db_path.exists():
        return {"total": 0, "active": 0, "top": []}
    close_conn = False
    if conn is None:
        conn = _connect(db_path)
        _ensure_tables(conn)
        close_conn = True
    try:
        total = conn.execute("SELECT COUNT(*) FROM long_term_goals").fetchone()[0]
        active = conn.execute(
            "SELECT COUNT(*) FROM long_term_goals WHERE status='active'"
        ).fetchone()[0]
        rows = conn.execute(
            """
            SELECT goal_key, title, goal, priority, status, last_outcome, last_outcome_at
            FROM long_term_goals
            ORDER BY priority DESC, updated_at DESC
            LIMIT ?
            """,
            (int(limit),),
        ).fetchall()
        top = []
        for r in rows:
            top.append(
                {
                    "goal_key": r["goal_key"],
                    "title": r["title"],
                    "goal": r["goal"],
                    "priority": r["priority"],
                    "status": r["status"],
                    "last_outcome": r["last_outcome"],
                    "last_outcome_at": r["last_outcome_at"],
                }
            )
    except Exception:
        total = 0
        active = 0
        top = []
    if close_conn:
        conn.close()
    return {"total": int(total or 0), "active": int(active or 0), "top": top}
