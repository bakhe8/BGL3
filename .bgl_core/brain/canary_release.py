from __future__ import annotations

import json
import os
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple


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
        CREATE TABLE IF NOT EXISTS canary_releases (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id TEXT UNIQUE,
            plan_id TEXT,
            source TEXT,
            status TEXT,
            created_at REAL,
            updated_at REAL,
            baseline_json TEXT,
            current_json TEXT,
            backup_dir TEXT,
            change_scope_json TEXT,
            notes TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_canary_releases_status ON canary_releases(status, created_at DESC)"
    )
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS canary_release_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            release_id TEXT,
            created_at REAL,
            event_type TEXT,
            detail_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_canary_release_events_time ON canary_release_events(created_at DESC)"
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


def _collect_route_health(conn: sqlite3.Connection, days: int = 7) -> Dict[str, Any]:
    try:
        cutoff = time.time() - (days * 86400)
        rows = conn.execute(
            "SELECT status_score FROM routes WHERE last_validated >= ?",
            (float(cutoff),),
        ).fetchall()
    except Exception:
        rows = []
    scores = [int(r[0] or 0) for r in rows]
    failing = len([s for s in scores if s <= 0])
    health = round(sum(scores) / max(1, len(scores)), 2) if scores else None
    return {"health_score": health, "failing_routes": failing, "routes_count": len(scores)}


def _collect_runtime_errors(conn: sqlite3.Connection, minutes: int = 120) -> Dict[str, Any]:
    cutoff = time.time() - (minutes * 60)
    try:
        rows = conn.execute(
            """
            SELECT event_type, COUNT(*) c FROM runtime_events
            WHERE timestamp >= ? AND event_type IN ('http_error','network_fail','dom_no_change','search_no_change')
            GROUP BY event_type
            """,
            (float(cutoff),),
        ).fetchall()
    except Exception:
        rows = []
    stats = {"http_error": 0, "network_fail": 0, "dom_no_change": 0, "search_no_change": 0}
    for row in rows:
        stats[str(row[0])] = int(row[1] or 0)
    total = stats["http_error"] + stats["network_fail"]
    return {"errors": stats, "error_total": total, "window_min": minutes}


def _collect_outcomes(conn: sqlite3.Connection, minutes: int = 120) -> Dict[str, Any]:
    cutoff = time.time() - (minutes * 60)
    try:
        rows = conn.execute(
            "SELECT result, COUNT(*) c FROM outcomes WHERE strftime('%s', timestamp) >= ? GROUP BY result",
            (int(cutoff),),
        ).fetchall()
    except Exception:
        rows = []
    counts: Dict[str, int] = {}
    total = 0
    for row in rows:
        res = str(row[0] or "")
        cnt = int(row[1] or 0)
        counts[res] = cnt
        total += cnt
    return {"counts": counts, "total": total, "window_min": minutes}


def _collect_metrics(db_path: Path) -> Dict[str, Any]:
    if not db_path.exists():
        return {}
    conn = _connect(db_path)
    _ensure_tables(conn)
    metrics = {
        "routes": _collect_route_health(conn),
        "runtime": _collect_runtime_errors(conn),
        "outcomes": _collect_outcomes(conn),
        "ts": time.time(),
    }
    conn.close()
    return metrics


def register_canary_release(
    root_dir: Path,
    db_path: Path,
    *,
    plan_id: str,
    change_scope: List[str],
    source: str,
    backup_dir: Optional[Path] = None,
    notes: str = "",
) -> Dict[str, Any]:
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}
    conn = _connect(db_path)
    _ensure_tables(conn)
    now = time.time()
    release_id = f"canary_{plan_id}_{int(now)}"
    baseline = _collect_metrics(db_path)
    try:
        conn.execute(
            """
            INSERT INTO canary_releases
            (release_id, plan_id, source, status, created_at, updated_at, baseline_json, backup_dir, change_scope_json, notes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                release_id,
                plan_id,
                source,
                "monitoring",
                float(now),
                float(now),
                _safe_json(baseline),
                str(backup_dir) if backup_dir else "",
                _safe_json(change_scope),
                notes,
            ),
        )
        conn.execute(
            """
            INSERT INTO canary_release_events (release_id, created_at, event_type, detail_json)
            VALUES (?, ?, ?, ?)
            """,
            (release_id, float(now), "started", _safe_json({"scope": change_scope})),
        )
        conn.commit()
    except Exception as exc:
        conn.close()
        return {"ok": False, "error": str(exc)}
    conn.close()
    return {"ok": True, "release_id": release_id, "baseline": baseline}


def _should_rollback(
    baseline: Dict[str, Any],
    current: Dict[str, Any],
    failure_signal: Optional[Dict[str, Any]] = None,
) -> Tuple[bool, str]:
    if not baseline or not current:
        return False, "insufficient_data"
    base_routes = baseline.get("routes") or {}
    cur_routes = current.get("routes") or {}
    base_health = _safe_float(base_routes.get("health_score"), 0.0)
    cur_health = _safe_float(cur_routes.get("health_score"), 0.0)
    base_fail = int(base_routes.get("failing_routes") or 0)
    cur_fail = int(cur_routes.get("failing_routes") or 0)

    base_err = int((baseline.get("runtime") or {}).get("error_total") or 0)
    cur_err = int((current.get("runtime") or {}).get("error_total") or 0)

    health_drop = base_health - cur_health
    fail_delta = cur_fail - base_fail
    err_spike = 0.0
    if base_err > 0:
        err_spike = (cur_err - base_err) / max(1, base_err)
    elif cur_err > 3:
        err_spike = 1.0

    # Thresholds (can be tuned via env in future)
    # Failure-class guardrail: severe failures in recent outcomes can force rollback.
    try:
        if failure_signal and failure_signal.get("ok"):
            by_class = failure_signal.get("by_class") or {}
            severe_classes = {"write_engine", "validation", "permission", "schema"}
            severe_count = 0
            for cls in severe_classes:
                try:
                    severe_count += int(by_class.get(cls, 0) or 0)
                except Exception:
                    continue
            try:
                threshold = int(os.getenv("BGL_CANARY_SEVERE_FAILURES", "2") or "2")
            except Exception:
                threshold = 2
            if severe_count >= max(1, threshold):
                top_cls = ""
                try:
                    top_cls = max(by_class.items(), key=lambda kv: kv[1])[0]
                except Exception:
                    top_cls = ""
                reason = f"failure_class:{top_cls}" if top_cls else "failure_class_severe"
                return True, reason
    except Exception:
        pass
    # External dependency guardrail: DB/network outages should block promotion/trigger rollback.
    try:
        ext = (failure_signal or {}).get("external_dependency") or {}
        ext_active = bool(ext.get("active") or int(ext.get("count") or 0) > 0)
        if ext_active:
            env_flag = os.getenv("BGL_CANARY_EXTERNAL_DEPENDENCY_ROLLBACK")
            if env_flag is None:
                env_flag = "1"
            if str(env_flag).strip().lower() in ("1", "true", "yes", "on"):
                return True, "external_dependency"
    except Exception:
        pass
    if health_drop >= 8:
        return True, "health_drop"
    if fail_delta >= 3:
        return True, "fail_delta"
    if err_spike >= 0.5:
        return True, "error_spike"
    return False, "ok"


def rollback_release(root_dir: Path, release_id: str, conn: Optional[sqlite3.Connection] = None) -> bool:
    close_conn = False
    if conn is None:
        conn = _connect(root_dir / ".bgl_core" / "brain" / "knowledge.db")
        _ensure_tables(conn)
        close_conn = True
    row = conn.execute(
        "SELECT id, backup_dir FROM canary_releases WHERE release_id=?",
        (release_id,),
    ).fetchone()
    if not row:
        if close_conn:
            conn.close()
        return False
    backup_dir = Path(row["backup_dir"] or "")
    if not backup_dir.exists():
        if close_conn:
            conn.close()
        return False
    restored = 0
    for path in backup_dir.rglob("*"):
        if not path.is_file():
            continue
        rel = path.relative_to(backup_dir)
        target = root_dir / rel
        target.parent.mkdir(parents=True, exist_ok=True)
        try:
            target.write_bytes(path.read_bytes())
            restored += 1
        except Exception:
            continue
    now = time.time()
    conn.execute(
        "UPDATE canary_releases SET status=?, updated_at=? WHERE release_id=?",
        ("rolled_back", float(now), release_id),
    )
    conn.execute(
        "INSERT INTO canary_release_events (release_id, created_at, event_type, detail_json) VALUES (?, ?, ?, ?)",
        (release_id, float(now), "rollback", _safe_json({"restored": restored})),
    )
    conn.commit()
    if close_conn:
        conn.close()
    return restored > 0


def evaluate_canary_releases(
    root_dir: Path,
    db_path: Path,
    *,
    min_age_sec: int = 300,
    auto_rollback: bool = False,
    recheck_sec: int = 0,
    external_dependency: Optional[Dict[str, Any]] = None,
) -> Dict[str, Any]:
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}
    conn = _connect(db_path)
    _ensure_tables(conn)
    now = time.time()
    rows = conn.execute(
        "SELECT * FROM canary_releases WHERE status IN ('monitoring','promoted') ORDER BY created_at DESC",
    ).fetchall()
    evaluated = 0
    rollbacks = 0
    promoted = 0
    for row in rows:
        created_at = _safe_float(row["created_at"], now)
        # Respect minimum age for first evaluation
        if now - created_at < min_age_sec and row["status"] == "monitoring":
            continue
        # Recheck promoted releases only if interval elapsed
        if row["status"] == "promoted" and recheck_sec:
            last_update = _safe_float(row["updated_at"], created_at)
            if now - last_update < recheck_sec:
                continue
        baseline = {}
        try:
            baseline = json.loads(row["baseline_json"] or "{}")
        except Exception:
            baseline = {}
        current = _collect_metrics(db_path)
        failure_signal = {}
        try:
            from .failure_classifier import summarize_failure_taxonomy  # type: ignore
        except Exception:
            try:
                from failure_classifier import summarize_failure_taxonomy  # type: ignore
            except Exception:
                summarize_failure_taxonomy = None  # type: ignore
        if summarize_failure_taxonomy:
            try:
                lookback_hours = max(1, int((now - created_at) / 3600) or 1)
                failure_signal = summarize_failure_taxonomy(
                    db_path,
                    lookback_hours=lookback_hours,
                    limit=200,
                )
                current["failure_taxonomy"] = failure_signal
            except Exception:
                failure_signal = {}
        if external_dependency:
            try:
                current["external_dependency"] = external_dependency
                if isinstance(failure_signal, dict):
                    failure_signal = dict(failure_signal)
                    failure_signal["external_dependency"] = external_dependency
            except Exception:
                pass
        should_rb, reason = _should_rollback(baseline, current, failure_signal)
        status = "rollback_required" if should_rb else "promoted"
        conn.execute(
            "UPDATE canary_releases SET status=?, updated_at=?, current_json=? WHERE release_id=?",
            (status, float(now), _safe_json(current), row["release_id"]),
        )
        conn.execute(
            "INSERT INTO canary_release_events (release_id, created_at, event_type, detail_json) VALUES (?, ?, ?, ?)",
            (row["release_id"], float(now), "evaluated", _safe_json({"reason": reason, "status": status})),
        )
        evaluated += 1
        if status == "promoted":
            promoted += 1
        else:
            if auto_rollback:
                if rollback_release(root_dir, row["release_id"], conn=conn):
                    rollbacks += 1
    conn.commit()
    conn.close()
    return {
        "ok": True,
        "evaluated": evaluated,
        "promoted": promoted,
        "rollbacks": rollbacks,
    }


def summarize_canary_status(db_path: Path, limit: int = 4) -> Dict[str, Any]:
    if not db_path.exists():
        return {"total": 0, "active": 0, "recent": []}
    conn = _connect(db_path)
    _ensure_tables(conn)
    total = conn.execute("SELECT COUNT(*) FROM canary_releases").fetchone()[0]
    active = conn.execute(
        "SELECT COUNT(*) FROM canary_releases WHERE status IN ('monitoring','rollback_required')"
    ).fetchone()[0]
    rows = conn.execute(
        "SELECT release_id, plan_id, status, created_at, notes FROM canary_releases ORDER BY created_at DESC LIMIT ?",
        (int(limit),),
    ).fetchall()
    recent = [dict(r) for r in rows]
    conn.close()
    return {"total": int(total or 0), "active": int(active or 0), "recent": recent}
