from __future__ import annotations

import hashlib
import json
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, List, Tuple

try:
    from .self_policy import load_self_policy, save_self_policy  # type: ignore
except Exception:
    from self_policy import load_self_policy, save_self_policy


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
        CREATE TABLE IF NOT EXISTS learning_feedback (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at REAL NOT NULL,
            source TEXT,
            signal TEXT,
            delta REAL,
            confidence REAL,
            details_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_learning_feedback_time ON learning_feedback(created_at DESC)"
    )


def _fingerprint(*parts: str) -> str:
    base = "|".join([str(p or "").strip().lower() for p in parts])
    return hashlib.sha1(base.encode("utf-8")).hexdigest()


def _safe_json(obj: Any) -> str:
    try:
        return json.dumps(obj, ensure_ascii=False)
    except Exception:
        return "{}"


def _parse_context_snapshot(raw: str) -> Dict[str, Any]:
    try:
        return json.loads(raw or "{}") if raw else {}
    except Exception:
        return {}


def _classify_result(result: str) -> str:
    res = (result or "").lower().strip()
    if res in ("success", "success_with_override", "partial", "prevented_regression", "confirmed_issue"):
        return "success"
    if res in ("fail", "blocked", "false_positive", "skipped", "error"):
        return "fail"
    if res in ("deferred", "no_op"):
        return "neutral"
    return "neutral"


def _clamp(val: float, lo: float, hi: float) -> float:
    return max(lo, min(hi, val))


def _collect_hint_outcomes(conn: sqlite3.Connection, lookback_days: int) -> Dict[str, Dict[str, int]]:
    stats: Dict[str, Dict[str, int]] = {}
    rows = conn.execute(
        """
        SELECT o.result, i.context_snapshot, i.source
        FROM outcomes o
        JOIN decisions d ON o.decision_id = d.id
        JOIN intents i ON d.intent_id = i.id
        WHERE o.timestamp >= datetime('now', ?)
        ORDER BY o.id DESC
        LIMIT 500
        """,
        (f"-{int(lookback_days)} days",),
    ).fetchall()
    for r in rows:
        src = str(r[2] or "")
        if src != "agency_core":
            continue
        context = _parse_context_snapshot(str(r[1] or ""))
        hint_source = str(context.get("hint_source") or "").strip()
        if not hint_source:
            continue
        bucket = stats.setdefault(
            hint_source,
            {"success": 0, "fail": 0, "neutral": 0, "total": 0},
        )
        cls = _classify_result(str(r[0] or ""))
        bucket[cls] += 1
        bucket["total"] += 1
    return stats


def apply_learning_feedback(
    root_dir: Path,
    db_path: Path,
    *,
    lookback_days: int = 14,
    min_samples: int = 3,
) -> Dict[str, Any]:
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}

    try:
        conn = _connect(db_path)
    except Exception:
        return {"ok": False, "error": "db_connect"}

    _ensure_tables(conn)
    stats = _collect_hint_outcomes(conn, lookback_days)

    policy = load_self_policy(root_dir)
    bias = policy.get("intent_bias") or {}
    changes: List[str] = []

    for hint_source, data in stats.items():
        total = int(data.get("total") or 0)
        if total < min_samples:
            continue
        success = int(data.get("success") or 0)
        fail = int(data.get("fail") or 0)
        success_rate = success / max(1, total)
        fail_rate = fail / max(1, total)
        delta = 0.0
        if success_rate >= 0.65 and fail_rate <= 0.25:
            delta = 0.05
        elif success_rate <= 0.4 or fail_rate >= 0.5:
            delta = -0.05
        if delta == 0.0:
            continue
        try:
            current = float(bias.get(hint_source, 1.0))
        except Exception:
            current = 1.0
        new_val = _clamp(current + delta, 0.3, 1.5)
        if abs(new_val - current) < 0.001:
            continue
        bias[hint_source] = round(new_val, 3)
        changes.append(
            f"{hint_source}:{round(current,3)}->{round(new_val,3)} (rate={round(success_rate,2)})"
        )
        conn.execute(
            """
            INSERT INTO learning_feedback (created_at, source, signal, delta, confidence, details_json)
            VALUES (?, ?, ?, ?, ?, ?)
            """,
            (
                time.time(),
                "intent_bias",
                hint_source,
                float(delta),
                float(success_rate),
                _safe_json({"stats": data, "lookback_days": lookback_days}),
            ),
        )

    if changes:
        policy["intent_bias"] = bias
        history = policy.get("history") or []
        if not isinstance(history, list):
            history = []
        entry = {
            "ts": time.time(),
            "changes": changes,
            "context": {"lookback_days": lookback_days, "stats": stats},
            "source": "learning_feedback",
        }
        history.append(entry)
        policy["history"] = history[-30:]
        policy["last_updated"] = entry["ts"]
        save_self_policy(root_dir, policy)

        # Also log into learning_events for visibility
        try:
            fp = _fingerprint("intent_bias_update", str(int(entry["ts"])), "|".join(changes))
            conn.execute(
                """
                INSERT OR IGNORE INTO learning_events
                (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    fp,
                    float(entry["ts"]),
                    "learning_feedback",
                    "intent_bias_update",
                    "intent_bias",
                    "applied",
                    None,
                    _safe_json(entry),
                ),
            )
        except Exception:
            pass

    conn.commit()
    conn.close()

    return {
        "ok": True,
        "changes": changes,
        "stats": stats,
        "policy": policy,
        "lookback_days": lookback_days,
    }
