from __future__ import annotations

import json
import os
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

try:
    from .decision_db import record_decision_trace  # type: ignore
except Exception:
    try:
        from decision_db import record_decision_trace  # type: ignore
    except Exception:
        record_decision_trace = None  # type: ignore


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


def _root_from_db(db_path: Path) -> Path:
    try:
        return db_path.parent.parent.parent
    except Exception:
        return Path(".").resolve()


def _load_code_contracts(root_dir: Path) -> Dict[str, Any]:
    path = root_dir / "analysis" / "code_contracts.json"
    if not path.exists():
        try:
            from .code_contracts import build_code_contracts  # type: ignore
        except Exception:
            try:
                from code_contracts import build_code_contracts  # type: ignore
            except Exception:
                build_code_contracts = None  # type: ignore
        if build_code_contracts:
            try:
                build_code_contracts(root_dir)
            except Exception:
                pass
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {}


def _normalize_path(path: str) -> str:
    return str(path or "").replace("\\", "/").lstrip("./")


def _matches_scope(file_path: str, scope: List[str]) -> bool:
    if not scope:
        return True
    file_norm = _normalize_path(file_path)
    for s in scope:
        if not s:
            continue
        s_norm = _normalize_path(str(s))
        if not s_norm:
            continue
        if file_norm.endswith(s_norm) or s_norm.endswith(file_norm) or file_norm == s_norm:
            return True
    return False


def _collect_runtime_contract_hotspots(
    root_dir: Path,
    *,
    change_scope: Optional[List[str]] = None,
    min_events: int = 5,
    error_rate_threshold: float = 0.3,
    latency_ms_threshold: float = 2000,
) -> Dict[str, Any]:
    data = _load_code_contracts(root_dir)
    contracts = data.get("contracts") or []
    if not isinstance(contracts, list):
        return {"hotspots": 0, "worst": []}
    scope = change_scope or []
    hotspots = []
    for c in contracts:
        if not isinstance(c, dict):
            continue
        runtime = c.get("runtime") or {}
        if not runtime:
            continue
        try:
            event_count = int(runtime.get("event_count") or 0)
        except Exception:
            event_count = 0
        if event_count < min_events:
            continue
        try:
            error_rate = float(runtime.get("error_rate") or 0.0)
        except Exception:
            error_rate = 0.0
        try:
            avg_latency = float(runtime.get("avg_latency_ms") or 0.0)
        except Exception:
            avg_latency = 0.0
        last_error = str(runtime.get("last_error") or "").strip()
        file_path = _normalize_path(str(c.get("file") or ""))
        if scope and file_path and not _matches_scope(file_path, scope):
            continue
        if error_rate >= error_rate_threshold or avg_latency >= latency_ms_threshold or last_error:
            target = str(c.get("route") or c.get("file") or "")
            hotspots.append(
                {
                    "target": target,
                    "file": file_path,
                    "error_rate": round(error_rate, 3),
                    "avg_latency_ms": round(avg_latency, 2),
                    "last_error": last_error[:120],
                    "event_count": event_count,
                }
            )
    hotspots = sorted(hotspots, key=lambda x: (x["error_rate"], x["avg_latency_ms"]), reverse=True)
    return {"hotspots": len(hotspots), "worst": hotspots[:6]}


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
    # Runtime contract hotspots: fail fast if changed scope shows high error/latency.
    try:
        if failure_signal:
            rc = failure_signal.get("runtime_contracts") or {}
            hotspots = int(rc.get("hotspots") or 0)
            threshold = int(failure_signal.get("runtime_contracts_threshold") or 2)
            if hotspots >= max(1, threshold):
                return True, "runtime_contract_hotspots"
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
    # Adjust thresholds for higher-risk patches
    health_drop_thr = 8
    fail_delta_thr = 3
    err_spike_thr = 0.5
    try:
        pr = (failure_signal or {}).get("patch_risk") or {}
        risk_level = str(pr.get("risk_level") or "").lower()
        if risk_level == "high":
            health_drop_thr = 5
            fail_delta_thr = 2
            err_spike_thr = 0.3
        elif risk_level == "medium":
            health_drop_thr = 6
            fail_delta_thr = 2
            err_spike_thr = 0.4
    except Exception:
        pass
    if health_drop >= health_drop_thr:
        return True, "health_drop"
    if fail_delta >= fail_delta_thr:
        return True, "fail_delta"
    if err_spike >= err_spike_thr:
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
    try:
        if record_decision_trace is not None:
            record_decision_trace(
                root_dir / ".bgl_core" / "brain" / "knowledge.db",
                kind="rollback",
                decision_id=0,
                outcome_id=None,
                operation=f"canary_rollback:{release_id}",
                result="rolled_back",
                source="canary_release",
                details={"release_id": release_id, "restored": restored},
            )
    except Exception:
        pass
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
    root_from_db = _root_from_db(db_path)
    runtime_contract_enabled = True
    try:
        flag = os.getenv("BGL_CANARY_RUNTIME_CONTRACTS")
        if flag is not None:
            runtime_contract_enabled = str(flag).strip() in ("1", "true", "yes", "on")
    except Exception:
        runtime_contract_enabled = True
    try:
        runtime_contract_hotspots_thr = int(os.getenv("BGL_CANARY_RUNTIME_HOTSPOTS", "2") or "2")
    except Exception:
        runtime_contract_hotspots_thr = 2
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
        release_notes: Dict[str, Any] = {}
        try:
            release_notes = json.loads(row["notes"] or "{}")
            if not isinstance(release_notes, dict):
                release_notes = {}
        except Exception:
            release_notes = {}
        current = _collect_metrics(db_path)
        change_scope = []
        try:
            change_scope = json.loads(row["change_scope_json"] or "[]")
            if not isinstance(change_scope, list):
                change_scope = []
        except Exception:
            change_scope = []
        runtime_contracts = {}
        if runtime_contract_enabled:
            try:
                runtime_contracts = _collect_runtime_contract_hotspots(
                    root_from_db,
                    change_scope=change_scope,
                    min_events=5,
                    error_rate_threshold=0.3,
                    latency_ms_threshold=2000,
                )
            except Exception:
                runtime_contracts = {}
            if runtime_contracts:
                current["runtime_contracts"] = runtime_contracts
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
        if runtime_contracts:
            try:
                if isinstance(failure_signal, dict):
                    failure_signal = dict(failure_signal)
                    failure_signal["runtime_contracts"] = runtime_contracts
                    failure_signal["runtime_contracts_threshold"] = runtime_contract_hotspots_thr
            except Exception:
                pass
        if release_notes:
            try:
                if isinstance(failure_signal, dict):
                    failure_signal = dict(failure_signal)
                    if release_notes.get("patch_risk"):
                        failure_signal["patch_risk"] = release_notes.get("patch_risk")
                    if release_notes.get("mode"):
                        failure_signal["apply_mode"] = release_notes.get("mode")
            except Exception:
                pass
        # High-risk patches require minimum runtime data before promotion.
        hold_for_risk = False
        try:
            pr = release_notes.get("patch_risk") or {}
            risk_level = str(pr.get("risk_level") or "").lower()
        except Exception:
            risk_level = ""
        if risk_level in {"high", "medium"} and row["status"] == "monitoring":
            routes_count = int((current.get("routes") or {}).get("routes_count") or 0)
            outcomes_total = int((current.get("outcomes") or {}).get("total") or 0)
            try:
                min_routes = int(os.getenv("BGL_CANARY_MIN_ROUTES", "5") or "5")
            except Exception:
                min_routes = 5
            try:
                min_outcomes = int(os.getenv("BGL_CANARY_MIN_OUTCOMES", "8") or "8")
            except Exception:
                min_outcomes = 8
            if risk_level == "high":
                min_routes = max(min_routes, 8)
                min_outcomes = max(min_outcomes, 12)
            if routes_count < min_routes or outcomes_total < min_outcomes:
                hold_for_risk = True
        if hold_for_risk:
            conn.execute(
                "UPDATE canary_releases SET status=?, updated_at=?, current_json=? WHERE release_id=?",
                ("monitoring", float(now), _safe_json(current), row["release_id"]),
            )
            conn.execute(
                "INSERT INTO canary_release_events (release_id, created_at, event_type, detail_json) VALUES (?, ?, ?, ?)",
                (
                    row["release_id"],
                    float(now),
                    "evaluated",
                    _safe_json(
                        {
                            "reason": "risk_data_insufficient",
                            "status": "monitoring",
                            "risk_level": risk_level,
                        }
                    ),
                ),
            )
            evaluated += 1
            continue
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
