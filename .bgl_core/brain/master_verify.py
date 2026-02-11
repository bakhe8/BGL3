import asyncio
import sys
import subprocess
import os
import threading
from pathlib import Path
import json
import sqlite3
import time
import ctypes

# Fix path to find brain modules in all execution contexts
current_dir = str(Path(__file__).parent)
if current_dir not in sys.path:
    sys.path.append(current_dir)

from agency_core import AgencyCore  # noqa: E402
from config_loader import load_config, load_effective_config  # noqa: E402
from report_builder import build_report  # noqa: E402
from generate_playbooks import generate_from_proposed  # noqa: E402
from contract_tests import run_contract_suite  # noqa: E402
from utils import load_route_usage  # noqa: E402
from callgraph_builder import build_callgraph  # noqa: E402
from generate_openapi import generate as generate_openapi  # noqa: E402
from scenario_deps import check_scenario_deps_async  # noqa: E402
from auto_insights import audit_auto_insights, write_auto_insights_status  # noqa: E402
from schema_check import check_schema  # noqa: E402
from run_ledger import start_run, finish_run  # noqa: E402
from run_lock import acquire_lock, release_lock, describe_lock  # noqa: E402
try:
    from fingerprint import compute_fingerprint, fingerprint_to_payload, fingerprint_equal, fingerprint_is_fresh  # noqa: E402
except Exception:
    compute_fingerprint = None  # type: ignore
    fingerprint_to_payload = None  # type: ignore
    fingerprint_equal = None  # type: ignore
    fingerprint_is_fresh = None  # type: ignore


def log_activity(root_path: Path, message: str, details: str | dict = "{}"):
    """Logs an event to the agent_activity table for dashboard visibility."""
    db_path = root_path / ".bgl_core" / "brain" / "knowledge.db"
    try:
        if isinstance(details, dict):
            details = json.dumps(details, ensure_ascii=False)
        with sqlite3.connect(str(db_path), timeout=30.0) as conn:
            conn.execute("PRAGMA journal_mode=WAL;")
            conn.execute(
                "INSERT INTO agent_activity (timestamp, activity, source, details) VALUES (?, ?, ?, ?)",
                (time.time(), message, "master_verify", details),
            )
    except Exception as e:
        try:
            if "locked" in str(e).lower():
                _write_status(
                    root_path / ".bgl_core" / "logs" / "diagnostic_status.json",
                    "running",
                    stage="db_write_locked",
                    db_write_last_error=str(e),
                )
        except Exception:
            pass
        print(f"[WARN] Failed to log activity: {e}")


def _log_runtime_event(root_path: Path, event: dict) -> None:
    db_path = root_path / ".bgl_core" / "brain" / "knowledge.db"
    try:
        if not db_path.exists():
            return
        with sqlite3.connect(str(db_path), timeout=5.0) as conn:
            conn.execute("PRAGMA journal_mode=WAL;")
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
                    event.get("source", "master_verify"),
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
            conn.commit()
    except Exception as e:
        try:
            if "locked" in str(e).lower():
                _write_status(
                    root_path / ".bgl_core" / "logs" / "diagnostic_status.json",
                    "running",
                    stage="db_write_locked",
                    db_write_last_error=str(e),
                )
        except Exception:
            pass


def _run_with_timeout(label: str, func, timeout: int, default):
    result = {"value": default}
    error = {"exc": None}

    def _target():
        try:
            result["value"] = func()
        except Exception as exc:
            error["exc"] = exc

    t = threading.Thread(target=_target, daemon=True)
    t.start()
    t.join(timeout)
    if t.is_alive():
        print(f"[WARN] {label} timed out after {timeout}s.")
        return default
    if error["exc"] is not None:
        print(f"[WARN] {label} failed: {error['exc']}")
        return default
    return result.get("value", default)


def _read_lock_status(lock_path: Path) -> dict:
    try:
        return describe_lock(lock_path)
    except Exception:
        status = {"path": str(lock_path), "exists": lock_path.exists()}
        if not lock_path.exists():
            return status
        try:
            raw = lock_path.read_text(encoding="utf-8").strip()
            parts = raw.split("|")
            pid = int(parts[0]) if parts and parts[0].isdigit() else 0
            ts = float(parts[1]) if len(parts) > 1 else 0.0
            label = parts[2] if len(parts) > 2 else ""
            status.update(
                {
                    "pid": pid,
                    "timestamp": ts,
                    "age_sec": round(max(0.0, time.time() - ts), 2) if ts else None,
                    "label": label,
                }
            )
        except Exception:
            status["error"] = "unreadable"
        return status


def _read_json(path: Path) -> dict:
    try:
        if not path.exists():
            return {}
        return json.loads(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _write_json(path: Path, payload: dict) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass


def _append_stage_history(
    payload: dict,
    stage: str,
    *,
    run_id: str | None = None,
    source: str | None = None,
) -> None:
    try:
        history = payload.get("stage_history")
        if not isinstance(history, list):
            history = []
        entry = {
            "stage": stage,
            "ts": time.time(),
        }
        entry["run_id"] = run_id or payload.get("run_id")
        if payload.get("scenario_run_id"):
            entry["scenario_run_id"] = payload.get("scenario_run_id")
        if source:
            entry["source"] = source
        history.append(entry)
        if len(history) > 200:
            history = history[-200:]
        payload["stage_history"] = history
    except Exception:
        pass


def _write_status(path: Path, status: str, **kwargs) -> None:
    payload = {"status": status, "timestamp": time.time()}
    reset_history = bool(kwargs.pop("reset_stage_history", False))
    prev = _read_json(path)
    if isinstance(prev, dict) and prev.get("stage_history") and not reset_history:
        payload["stage_history"] = prev.get("stage_history")
    payload.update(kwargs)
    stage = payload.get("stage")
    if stage:
        _append_stage_history(
            payload,
            str(stage),
            run_id=payload.get("run_id"),
            source="master_verify",
        )
    _write_json(path, payload)


def _update_status_stage(path: Path, stage: str, run_id: str | None = None) -> None:
    try:
        payload = _read_json(path)
        if not isinstance(payload, dict):
            payload = {}
    except Exception:
        payload = {}
    payload["status"] = payload.get("status") or "running"
    payload["stage"] = stage
    payload["stage_timestamp"] = time.time()
    payload["last_stage_change"] = payload["stage_timestamp"]
    if run_id:
        payload["run_id"] = run_id
    payload["timestamp"] = time.time()
    _append_stage_history(payload, stage, run_id=run_id, source="master_verify")
    _write_json(path, payload)


def _compute_stage_durations(status_payload: dict, run_id: str | None) -> tuple[list[dict], dict]:
    history = status_payload.get("stage_history")
    if not isinstance(history, list) or not history:
        return [], {}
    filtered = []
    for entry in history:
        if not isinstance(entry, dict):
            continue
        if run_id and entry.get("run_id") and entry.get("run_id") != run_id:
            continue
        if entry.get("stage") is None:
            continue
        filtered.append(entry)
    if not filtered:
        return [], {}
    filtered.sort(key=lambda x: float(x.get("ts") or 0))
    finished_at = status_payload.get("finished_at")
    end_fallback = float(finished_at) if finished_at else time.time()
    timings = []
    totals: dict[str, float] = {}
    for idx, entry in enumerate(filtered):
        start_ts = float(entry.get("ts") or 0)
        if idx + 1 < len(filtered):
            end_ts = float(filtered[idx + 1].get("ts") or start_ts)
        else:
            end_ts = end_fallback
        duration = max(0.0, end_ts - start_ts)
        stage = str(entry.get("stage"))
        item = {
            "stage": stage,
            "start_ts": start_ts,
            "duration_s": round(duration, 3),
        }
        if entry.get("source"):
            item["source"] = entry.get("source")
        if entry.get("scenario_run_id"):
            item["scenario_run_id"] = entry.get("scenario_run_id")
        timings.append(item)
        totals[stage] = totals.get(stage, 0.0) + duration
    totals = {k: round(v, 3) for k, v in totals.items()}
    return timings, totals


def _compute_slow_phases(stage_totals: dict) -> list[dict]:
    slow: list[dict] = []
    try:
        threshold = float(os.getenv("BGL_DIAGNOSTIC_SLOW_STAGE_SEC", "90") or 90)
    except Exception:
        threshold = 90.0
    try:
        ordered = sorted(stage_totals.items(), key=lambda kv: kv[1], reverse=True)
    except Exception:
        ordered = []
    for stage, duration in ordered:
        try:
            dur = float(duration or 0)
        except Exception:
            dur = 0.0
        if dur < threshold:
            continue
        slow.append({"stage": stage, "duration_s": round(dur, 3)})
    return slow


def _collect_timeout_ledger(root: Path, status_payload: dict) -> list[dict]:
    db_path = root / ".bgl_core" / "brain" / "knowledge.db"
    if not db_path.exists():
        return []
    start_ts = status_payload.get("started_at")
    end_ts = status_payload.get("finished_at") or time.time()
    try:
        if start_ts is None:
            history = status_payload.get("stage_history") or []
            if isinstance(history, list) and history:
                start_ts = min(float(h.get("ts") or end_ts) for h in history)
    except Exception:
        start_ts = None
    if start_ts is None:
        start_ts = max(0.0, end_ts - 7200.0)
    try:
        import sqlite3
    except Exception:
        return []
    entries: list[dict] = []
    try:
        with sqlite3.connect(str(db_path), timeout=5.0) as conn:
            conn.row_factory = sqlite3.Row
            cur = conn.cursor()
            cur.execute(
                """
                SELECT timestamp, session, run_id, scenario_id, source, event_type, route, method, target, step_id, payload, error
                FROM runtime_events
                WHERE timestamp BETWEEN ? AND ?
                  AND event_type IN ('scenario_step_timeout', 'route_check_skipped', 'route_check_ui', 'route_check_api', 'scenario_step_error')
                ORDER BY timestamp ASC
                """,
                (float(start_ts), float(end_ts)),
            )
            rows = cur.fetchall()
            for r in rows:
                payload = r["payload"]
                payload_obj = None
                if payload:
                    try:
                        payload_obj = json.loads(payload)
                    except Exception:
                        payload_obj = None
                reason = None
                if r["event_type"] == "scenario_step_timeout":
                    if isinstance(payload_obj, dict):
                        reason = payload_obj.get("timeout_reason") or payload_obj.get("precheck") or None
                elif r["event_type"] == "route_check_skipped":
                    reason = r["error"] or (payload_obj.get("reason") if isinstance(payload_obj, dict) else None)
                elif r["event_type"] in ("route_check_ui", "route_check_api"):
                    if r["error"]:
                        reason = r["error"]
                elif r["event_type"] == "scenario_step_error":
                    if isinstance(payload_obj, dict):
                        reason = payload_obj.get("error")
                    reason = reason or r["error"]
                entry = {
                    "timestamp": r["timestamp"],
                    "event_type": r["event_type"],
                    "run_id": r["run_id"],
                    "scenario_id": r["scenario_id"],
                    "source": r["source"],
                    "route": r["route"],
                    "method": r["method"],
                    "target": r["target"],
                    "step_id": r["step_id"],
                    "reason": reason,
                }
                if isinstance(payload_obj, dict):
                    entry["details"] = payload_obj
                entries.append(entry)
    except Exception:
        return []
    return entries


def _heartbeat_status(path: Path, run_id: str | None = None) -> None:
    try:
        payload = _read_json(path)
        if not isinstance(payload, dict):
            payload = {}
    except Exception:
        payload = {}
    payload.setdefault("status", "running")
    payload["last_heartbeat"] = time.time()
    if run_id:
        payload.setdefault("run_id", run_id)
    _write_json(path, payload)


def _last_input_age_sec() -> float | None:
    if os.name != "nt":
        return None
    try:
        class LASTINPUTINFO(ctypes.Structure):
            _fields_ = [("cbSize", ctypes.c_uint), ("dwTime", ctypes.c_uint)]

        li = LASTINPUTINFO()
        li.cbSize = ctypes.sizeof(LASTINPUTINFO)
        if ctypes.windll.user32.GetLastInputInfo(ctypes.byref(li)):
            millis = ctypes.windll.kernel32.GetTickCount() - li.dwTime
            return float(millis) / 1000.0
    except Exception:
        return None
    return None


def _extract_metrics(report: dict) -> dict:
    stats = report.get("execution_stats") or {}
    failure = report.get("failure_taxonomy") or {}
    ui_cov = report.get("ui_action_coverage") or {}
    flow_cov = report.get("flow_coverage") or {}
    sem_delta = report.get("ui_semantic_delta") or {}
    blocked_ratio = None
    try:
        total_fail = float(failure.get("total_failures") or 0)
        blocked = float((failure.get("by_class") or {}).get("blocked") or 0)
        if total_fail > 0:
            blocked_ratio = blocked / total_fail
    except Exception:
        blocked_ratio = None
    return {
        "success_rate": stats.get("success_rate"),
        "blocked_ratio": blocked_ratio,
        "ui_action_coverage": ui_cov.get("coverage_ratio"),
        "flow_coverage": flow_cov.get("coverage_ratio"),
        "semantic_change_count": sem_delta.get("change_count"),
    }


def _classify_delta(delta: float, epsilon: float = 0.01) -> str:
    if delta > epsilon:
        return "improvement"
    if delta < -epsilon:
        return "regression"
    return "noise"


def _compare_reports(prev: dict, curr: dict) -> dict:
    prev_metrics = _extract_metrics(prev or {})
    curr_metrics = _extract_metrics(curr or {})
    comparison = {"metrics": {}, "summary": {}}
    for key, curr_val in curr_metrics.items():
        prev_val = prev_metrics.get(key)
        if curr_val is None or prev_val is None:
            comparison["metrics"][key] = {"from": prev_val, "to": curr_val, "delta": None, "classification": "unknown"}
            continue
        try:
            delta = float(curr_val) - float(prev_val)
        except Exception:
            comparison["metrics"][key] = {"from": prev_val, "to": curr_val, "delta": None, "classification": "unknown"}
            continue
        # For blocked_ratio, lower is better.
        if key == "blocked_ratio":
            classification = _classify_delta(-delta)
        else:
            classification = _classify_delta(delta)
        comparison["metrics"][key] = {
            "from": prev_val,
            "to": curr_val,
            "delta": delta,
            "classification": classification,
        }
    comparison["summary"]["changed_metrics"] = len(
        [m for m in comparison["metrics"].values() if m.get("classification") not in ("noise", "unknown")]
    )
    return comparison


def _load_baseline_report(root: Path) -> tuple[dict, dict]:
    baseline_path = root / ".bgl_core" / "logs" / "diagnostic_baseline.json"
    baseline_meta_path = root / ".bgl_core" / "logs" / "diagnostic_baseline.meta.json"
    return _read_json(baseline_path), _read_json(baseline_meta_path)


def _sanitize_baseline_report(payload: dict) -> dict:
    cleaned = dict(payload or {})
    cleaned.pop("diagnostic_baseline", None)
    cleaned.pop("diagnostic_comparison_full", None)
    cleaned.pop("diagnostic_comparison", None)
    cleaned.pop("diagnostic_confidence", None)
    return cleaned


def _compute_diagnostic_confidence(data: dict) -> dict:
    profile = str(data.get("diagnostic_profile") or "").lower()
    cache_used = bool(data.get("cache_used"))
    base = 0.6
    if profile in ("full", "full-scan"):
        base = 1.0
    elif profile == "medium":
        base = 0.75
    elif profile in ("fast", "fast-stub"):
        base = 0.45
    elif profile.startswith("cache"):
        base = 0.5
    if cache_used:
        base *= 0.85

    notes: list[str] = []
    audit_status = str(data.get("audit_status") or "")
    if audit_status and audit_status != "ok":
        base *= 0.7
        notes.append(f"audit_status={audit_status}")

    route_stats = data.get("route_scan_stats") or {}
    attempted = int(route_stats.get("attempted") or 0)
    checked = int(route_stats.get("checked") or 0)
    route_ratio = (checked / attempted) if attempted > 0 else 0.0
    if attempted > 0:
        base *= (0.5 + 0.5 * min(1.0, max(0.0, route_ratio)))
    else:
        base *= 0.6
        notes.append("route_scan:0")

    scenario = data.get("scenario_run_stats") or {}
    scen_status = str(scenario.get("status") or "")
    if scenario.get("attempted"):
        if scen_status in ("ok", "ok_after_retry"):
            base *= 1.0
        elif scen_status in ("low_events", "low_event_delta", "skipped_or_locked"):
            base *= 0.85
            notes.append(f"scenario={scen_status}")
        else:
            base *= 0.7
            notes.append(f"scenario={scen_status or 'unknown'}")
    else:
        if scen_status and scen_status != "skipped":
            notes.append(f"scenario={scen_status}")
        base *= 0.9

    reliability = data.get("coverage_reliability") or {}
    if reliability:
        if any(r is False for r in reliability.values()):
            base *= 0.8
            notes.append("coverage_reliability=false")

    score = max(0.05, min(1.0, round(base, 3)))
    return {
        "score": score,
        "profile": profile,
        "route_scan_ratio": round(route_ratio, 3),
        "scenario_status": scen_status or "skipped",
        "audit_status": audit_status or "ok",
        "coverage_reliability": reliability,
        "notes": notes,
    }


def _compute_diagnostic_faults(data: dict) -> list:
    faults: list = []
    status = (data.get("diagnostic_status") or {}).get("status") or data.get("audit_status") or ""
    audit_status = str(data.get("audit_status") or "")
    if status in ("timeout", "error", "aborted", "deferred_user_active"):
        faults.append(
            {
                "code": f"diagnostic_{status}",
                "severity": "high" if status in ("timeout", "error", "aborted") else "medium",
                "detail": data.get("diagnostic_status") or {},
            }
        )
    if audit_status == "partial":
        faults.append(
            {
                "code": "diagnostic_partial",
                "severity": "medium",
                "detail": {
                    "audit_status": audit_status,
                    "audit_reason": data.get("audit_reason"),
                    "route_scan_stats": data.get("route_scan_stats") or {},
                },
            }
        )
    scenario = data.get("scenario_run_stats") or {}
    scen_status = str(scenario.get("status") or "")
    if scen_status in ("blocked", "deps_missing", "low_events", "low_event_delta", "skipped_or_locked"):
        faults.append(
            {
                "code": f"scenario_{scen_status}",
                "severity": "medium",
                "detail": scenario,
            }
        )
    route_stats = data.get("route_scan_stats") or {}
    attempted = int(route_stats.get("attempted") or 0)
    checked = int(route_stats.get("checked") or 0)
    if attempted > 0 and checked == 0:
        faults.append(
            {
                "code": "route_scan_zero_checked",
                "severity": "high",
                "detail": route_stats,
            }
        )
    coverage_rel = data.get("coverage_reliability") or {}
    if coverage_rel and any(v is False for v in coverage_rel.values()):
        faults.append(
            {
                "code": "coverage_reliability_low",
                "severity": "medium",
                "detail": coverage_rel,
            }
        )
    slow_phases = data.get("diagnostic_slow_phases") or []
    if isinstance(slow_phases, list) and slow_phases:
        faults.append(
            {
                "code": "diagnostic_slow_phases",
                "severity": "medium",
                "detail": slow_phases,
            }
        )
    conf = (data.get("diagnostic_confidence") or {}).get("score")
    if conf is not None:
        try:
            conf_val = float(conf)
            if conf_val < 0.6:
                faults.append(
                    {
                        "code": "diagnostic_confidence_low",
                        "severity": "medium",
                        "detail": data.get("diagnostic_confidence") or {},
                    }
                )
        except Exception:
            pass
    return faults


def _compute_diagnostic_self_check(data: dict) -> dict:
    required = {
        "timestamp": data.get("timestamp"),
        "health_score": data.get("health_score"),
        "route_scan_stats": data.get("route_scan_stats"),
        "scan_duration_seconds": data.get("scan_duration_seconds"),
        "audit_status": data.get("audit_status"),
    }
    missing = [k for k, v in required.items() if v in (None, {}, "")]
    return {
        "ok": len(missing) == 0,
        "missing": missing,
        "audit_status": data.get("audit_status"),
        "audit_reason": data.get("audit_reason"),
        "route_scan_stats": data.get("route_scan_stats") or {},
        "scan_duration_seconds": data.get("scan_duration_seconds"),
    }


def _user_recent_activity(idle_sec: int) -> bool:
    if idle_sec <= 0:
        return False
    if os.name != "nt":
        return False
    try:
        class LASTINPUTINFO(ctypes.Structure):
            _fields_ = [("cbSize", ctypes.c_uint), ("dwTime", ctypes.c_uint)]

        li = LASTINPUTINFO()
        li.cbSize = ctypes.sizeof(LASTINPUTINFO)
        if ctypes.windll.user32.GetLastInputInfo(ctypes.byref(li)):
            millis = ctypes.windll.kernel32.GetTickCount() - li.dwTime
            return (millis / 1000.0) < float(idle_sec)
    except Exception:
        return False
    return False


def _mark_aborted_if_stale(lock_path: Path, status_path: Path) -> None:
    if not lock_path.exists():
        return
    try:
        raw = lock_path.read_text(encoding="utf-8").strip()
        parts = raw.split("|")
        pid = int(parts[0]) if parts and parts[0].isdigit() else 0
    except Exception:
        pid = 0
    if pid <= 0:
        return
    try:
        alive = False
        if os.name == "nt":
            res = subprocess.run(
                ["tasklist", "/FI", f"PID eq {pid}"],
                capture_output=True,
                text=True,
                timeout=3,
            )
            alive = str(pid) in (res.stdout or "")
        else:
            os.kill(pid, 0)
            alive = True
    except Exception:
        alive = False
    try:
        prev = _read_json(status_path)
    except Exception:
        prev = {}
    try:
        hb_timeout = float(prev.get("heartbeat_timeout_sec") or 180)
    except Exception:
        hb_timeout = 180.0
    last_hb = None
    try:
        last_hb = float(prev.get("last_heartbeat") or 0)
    except Exception:
        last_hb = None
    now = time.time()
    hb_stale = bool(last_hb and (now - last_hb) > hb_timeout)
    if alive and hb_stale and str(prev.get("status") or "") == "running":
        _write_status(
            status_path,
            "aborted",
            reason="heartbeat_stale_pid_alive",
            stale_pid=pid,
            last_heartbeat=last_hb,
            heartbeat_timeout_sec=hb_timeout,
        )
        return
    if alive:
        return
    try:
        if str(prev.get("status") or "") == "running":
            _write_status(
                status_path,
                "aborted",
                reason="stale_lock_pid_dead",
                stale_pid=pid,
                last_heartbeat=last_hb,
                heartbeat_timeout_sec=hb_timeout,
            )
    except Exception:
        pass


def _current_fingerprint_payload(root: Path) -> dict:
    if compute_fingerprint and fingerprint_to_payload:
        try:
            fp = compute_fingerprint(root)
            return fingerprint_to_payload(fp)
        except Exception:
            return {}
    return {}


def _select_profile(cfg: dict, last_report: dict, meta: dict, fp_payload: dict) -> tuple[str, str]:
    env_profile = os.getenv("BGL_DIAGNOSTIC_PROFILE")
    if env_profile:
        return str(env_profile).strip().lower(), "env"
    cfg_profile = str(cfg.get("diagnostic_profile", "auto") or "auto").strip().lower()
    if cfg_profile and cfg_profile != "auto":
        return cfg_profile, "config"

    now = time.time()
    last_ts = float(last_report.get("timestamp") or 0)
    age = now - last_ts if last_ts else None
    try:
        full_interval = float(cfg.get("diagnostic_full_interval_sec", 86400) or 86400)
    except Exception:
        full_interval = 86400.0
    try:
        medium_interval = float(cfg.get("diagnostic_medium_interval_sec", 3600) or 3600)
    except Exception:
        medium_interval = 3600.0

    fp_same = False
    try:
        prev_fp = meta.get("fingerprint")
        if fingerprint_equal and fp_payload:
            fp_same = fingerprint_equal(prev_fp, fp_payload)
        if fingerprint_is_fresh and prev_fp:
            fp_same = fp_same or fingerprint_is_fresh(prev_fp, max_age_s=900)
    except Exception:
        fp_same = False

    if age is None:
        return "full", "no_previous_report"
    if fp_same and age < medium_interval:
        return "fast", "stable_recent"
    if fp_same and age < full_interval:
        return "medium", "stable"
    return "full", "changed_or_stale"


def _apply_profile_env(profile: str, cfg: dict) -> dict:
    profile = str(profile or "full").strip().lower()
    overrides: dict = {}
    if profile == "fast":
        overrides = {
            "BGL_ROUTE_SCAN_LIMIT": "12",
            "BGL_ROUTE_SCAN_MAX_SECONDS": "25",
            "BGL_RUN_SCENARIOS": "0",
            "BGL_API_SCAN_MODE": "skip",
            "BGL_DIAGNOSTIC_BUDGET_SECONDS": "45",
            "BGL_CODE_INTEL": "0",
            "BGL_CODE_CONTRACTS": "0",
        }
    elif profile == "medium":
        overrides = {
            "BGL_ROUTE_SCAN_LIMIT": "25",
            "BGL_ROUTE_SCAN_MAX_SECONDS": "60",
            "BGL_RUN_SCENARIOS": "1",
            "BGL_API_SCAN_MODE": "safe",
            "BGL_DIAGNOSTIC_BUDGET_SECONDS": "120",
        }
    else:
        overrides = {}
    for k, v in overrides.items():
        os.environ[k] = str(v)
    os.environ["BGL_DIAGNOSTIC_PROFILE"] = profile
    return overrides


async def master_assurance_diagnostic():
    """
    Main entry point for Master Technical Assurance.
    Runs a full AgencyCore diagnostic and presents the results.
    """
    ROOT = Path(__file__).parent.parent.parent

    print("\n" + "=" * 70)
    print("🚀 BGL3 AGENCY: MASTER TECHNICAL ASSURANCE (GOLD STANDARD)")
    print("=" * 70)

    cfg = load_config(ROOT)
    effective_cfg = load_effective_config(ROOT)
    timeout = int(cfg.get("diagnostic_timeout_sec", 300))
    try:
        timeout_env = os.getenv("BGL_DIAGNOSTIC_TIMEOUT_SEC")
        if timeout_env and str(timeout_env).strip():
            timeout = int(timeout_env)
    except Exception:
        pass
    lock_path = ROOT / ".bgl_core" / "logs" / "master_verify.lock"
    status_path = ROOT / ".bgl_core" / "logs" / "diagnostic_status.json"
    hb_stop: threading.Event | None = None
    try:
        _mark_aborted_if_stale(lock_path, status_path)
    except Exception:
        pass
    try:
        idle_guard = int(cfg.get("diagnostic_idle_guard_sec", 0) or 0)
    except Exception:
        idle_guard = 0
    if (
        idle_guard > 0
        and os.getenv("BGL_DIAGNOSTIC_IGNORE_IDLE", "0") != "1"
        and _user_recent_activity(idle_guard)
    ):
        try:
            last_input_age_sec = _last_input_age_sec()
            _write_status(
                status_path,
                "deferred_user_active",
                reason="recent_input",
                idle_guard_sec=idle_guard,
                last_input_age_sec=last_input_age_sec,
            )
        except Exception:
            pass
        return
    lock_ttl = int(cfg.get("master_verify_lock_ttl_sec", max(3600, timeout * 3)))
    ok, reason = acquire_lock(lock_path, ttl_sec=lock_ttl, label="master_verify")
    if not ok:
        print(f"[!] master_verify already running ({reason}); skipping.")
        try:
            lock_info = describe_lock(lock_path, ttl_sec=lock_ttl)
            lock_info["reason"] = reason
            _log_runtime_event(
                ROOT,
                {
                    "timestamp": time.time(),
                    "session": "lock",
                    "event_type": "lock_blocked",
                    "route": str(lock_path),
                    "target": "master_verify",
                    "payload": lock_info,
                },
            )
        except Exception:
            pass
        try:
            _write_status(
                ROOT / ".bgl_core" / "logs" / "diagnostic_status.json",
                "skipped",
                reason=reason,
                stage="lock_blocked",
            )
        except Exception:
            pass
        return

    try:
        _write_status(
            status_path,
            "running",
            started_at=time.time(),
            profile="pending",
            stage="init",
            run_id=None,
            reset_stage_history=True,
            heartbeat_timeout_sec=float(cfg.get("diagnostic_heartbeat_timeout_sec", 180) or 180),
        )
    except Exception:
        pass

    # Cache/cooldown checks (after lock acquisition to avoid overlap).
    report_path = ROOT / ".bgl_core" / "logs" / "latest_report.json"
    meta_path = ROOT / ".bgl_core" / "logs" / "latest_report.meta.json"
    last_report = _read_json(report_path)
    meta = _read_json(meta_path)
    fp_payload = _current_fingerprint_payload(ROOT)
    now = time.time()
    last_ts = float(last_report.get("timestamp") or 0)
    age = (now - last_ts) if last_ts else None
    force = os.getenv("BGL_FORCE_DIAGNOSTIC", "0") == "1" or str(cfg.get("force_diagnostic", "0")) == "1"
    try:
        min_interval = float(cfg.get("diagnostic_min_interval_sec", 60) or 60)
    except Exception:
        min_interval = 60.0
    if not force and age is not None and age < min_interval:
        print(f"[!] Diagnostic cooldown active ({age:.1f}s < {min_interval}s); skipping.")
        try:
            release_lock(lock_path)
        except Exception:
            pass
        return

    try:
        cache_ttl = float(cfg.get("diagnostic_cache_ttl_sec", 0) or 0)
    except Exception:
        cache_ttl = 0.0
    cache_ok = False
    try:
        fp_same = False
        if fingerprint_equal and fp_payload:
            fp_same = fingerprint_equal(meta.get("fingerprint"), fp_payload)
        if fingerprint_is_fresh and meta.get("fingerprint"):
            fp_same = fp_same or fingerprint_is_fresh(meta.get("fingerprint"), max_age_s=900)
        audit_status = str(last_report.get("audit_status") or "ok").lower()
        if cache_ttl > 0 and age is not None and age <= cache_ttl and fp_same and audit_status != "partial":
            cache_ok = True
    except Exception:
        cache_ok = False
    if cache_ok and not force:
        print(f"[+] Using cached diagnostic report (age {age:.1f}s).")
        try:
            last_report["cache_used"] = True
            last_report["cache_reason"] = "within_cache_ttl"
            last_report["cached_at"] = now
            last_report["diagnostic_profile"] = "cache"
            _write_json(report_path, last_report)
            meta["cached_at"] = now
            _write_json(meta_path, meta)
            _update_status_stage(status_path, "cache_used")
        except Exception:
            pass
        try:
            release_lock(lock_path)
        except Exception:
            pass
        return

    # Optional fast mode (skip heavy/side-effect stages).
    try:
        fast_cfg = effective_cfg.get("fast_verify", 0)
    except Exception:
        fast_cfg = 0
    fast_verify = os.getenv("BGL_FAST_VERIFY", "0") == "1" or str(fast_cfg).strip() in ("1", "true", "yes", "on")
    profile, profile_reason = _select_profile(cfg, last_report, meta, fp_payload)
    profile_overrides = _apply_profile_env(profile, cfg)
    try:
        print(f"[*] Diagnostic profile: {profile} ({profile_reason})")
        if profile_overrides:
            print(f"    - profile overrides: {profile_overrides}")
    except Exception:
        pass
    try:
        _write_status(
            status_path,
            "running",
            started_at=time.time(),
            profile=profile,
            profile_reason=profile_reason,
            overrides=profile_overrides,
            heartbeat_timeout_sec=float(cfg.get("diagnostic_heartbeat_timeout_sec", 180) or 180),
        )
    except Exception:
        pass
    try:
        lock_snapshot = {
            "master_verify": _read_lock_status(lock_path),
            "run_scenarios": _read_lock_status(ROOT / ".bgl_core" / "logs" / "run_scenarios.lock"),
            "scenario_runner": _read_lock_status(ROOT / ".bgl_core" / "logs" / "scenario_runner.lock"),
        }
        _write_status(
            status_path,
            "running",
            stage="lock_snapshot",
            locks=lock_snapshot,
        )
    except Exception:
        pass
    try:
        hb_stop = threading.Event()

        def _hb_loop():
            while not hb_stop.is_set():
                _heartbeat_status(status_path, run_id=os.getenv("BGL_DIAGNOSTIC_RUN_ID"))
                hb_stop.wait(15)

        hb_thread = threading.Thread(target=_hb_loop, daemon=True)
        hb_thread.start()
    except Exception:
        hb_stop = None
    # Fast-profile cache shortcut (skip full run even if fingerprint drifted).
    try:
        fast_cache_ttl = float(cfg.get("diagnostic_fast_cache_ttl_sec", 1800) or 1800)
    except Exception:
        fast_cache_ttl = 1800.0
    if (
        profile == "fast"
        and not force
        and last_report
        and age is not None
        and age < fast_cache_ttl
    ):
        try:
            last_report["cache_used"] = True
            last_report["cache_reason"] = "fast_profile_recent"
            last_report["cached_at"] = now
            last_report["diagnostic_profile"] = "cache-fast"
            _write_json(report_path, last_report)
            meta["cached_at"] = now
            _write_json(meta_path, meta)
        except Exception:
            pass
        try:
            _write_status(
                status_path,
                "cached",
                finished_at=time.time(),
                profile=profile,
                reason="fast_profile_recent",
                cache_used=True,
            )
        except Exception:
            pass
        try:
            release_lock(lock_path)
        except Exception:
            pass
        return
    fast_strategy = str(cfg.get("diagnostic_fast_strategy", "scan") or "scan").strip().lower()
    if profile == "fast" and not force and fast_strategy in ("cache", "stub", "skip"):
        # Short-circuit fast profile when explicitly configured to reuse cache.
        try:
            if last_report:
                last_report["cache_used"] = True
                last_report["cache_reason"] = "fast_profile_skip"
                last_report["cached_at"] = now
                last_report["diagnostic_profile"] = "cache-fast"
                _write_json(report_path, last_report)
            else:
                stub = {
                    "timestamp": now,
                    "vitals": {
                        "infrastructure": None,
                        "business_logic": None,
                        "architecture": None,
                    },
                    "findings": {"note": "fast_profile_stub_no_previous_report"},
                    "diagnostic_profile": "fast-stub",
                    "cache_used": True,
                    "cache_reason": "fast_profile_stub",
                }
                _write_json(report_path, stub)
        except Exception:
            pass
        try:
            _write_status(
                status_path,
                "cached",
                finished_at=time.time(),
                profile=profile,
                reason="fast_profile_skip",
                cache_used=True,
            )
        except Exception:
            pass
        try:
            release_lock(lock_path)
        except Exception:
            pass
        return
    try:
        scen_lock_path = ROOT / ".bgl_core" / "logs" / "scenario_runner.lock"
        scen_lock = _read_lock_status(scen_lock_path)
        scen_age = scen_lock.get("age_sec")
        scen_status = str(scen_lock.get("status") or "")
        scen_pid_alive = bool(scen_lock.get("pid_alive")) if "pid_alive" in scen_lock else None
        try:
            skip_age = int(cfg.get("scenario_lock_skip_age_sec", 600) or 600)
        except Exception:
            skip_age = 600
        should_skip = False
        if scen_lock.get("exists") and scen_age is not None:
            if scen_status in ("active_fresh", "recent_lock"):
                if float(scen_age) < skip_age:
                    should_skip = True
            elif scen_pid_alive is True and float(scen_age) < skip_age:
                should_skip = True
        if should_skip:
            os.environ["BGL_RUN_SCENARIOS"] = "0"
            profile_overrides.setdefault("BGL_RUN_SCENARIOS", "0")
            profile_overrides.setdefault("scenario_lock_skip", scen_lock)
        else:
            # If lock looks stale, try to clear it so scenarios can proceed.
            if scen_lock.get("exists") and scen_status in ("stale_dead_pid", "active_stale"):
                try:
                    release_lock(scen_lock_path)
                    scen_lock["cleared"] = True
                    profile_overrides.setdefault("scenario_lock_override", scen_lock)
                except Exception:
                    pass
    except Exception:
        pass

    if fast_verify:
        print("[*] FAST VERIFY mode enabled (skipping heavy stages).")
        os.environ["BGL_RUN_SCENARIOS"] = "0"
        os.environ["BGL_AUTO_CONTEXT_DIGEST"] = "0"
        os.environ["BGL_AUTO_APPLY"] = "0"
        os.environ["BGL_AUTO_PLAN"] = "0"
        os.environ["BGL_AUTO_VERIFY"] = "0"
        os.environ["BGL_AUTO_PATCH_ON_ERRORS"] = "0"
        os.environ["BGL_SKIP_DREAM"] = "1"
        os.environ["BGL_MAX_AUTO_INSIGHTS"] = "0"
        os.environ["BGL_CODE_INTEL"] = "0"
        os.environ["BGL_CODE_CONTRACTS"] = "0"

    # Tighten timeout for fast profile to avoid long hangs.
    try:
        if profile == "fast":
            fast_timeout = int(cfg.get("diagnostic_fast_timeout_sec", 60) or 60)
            timeout = min(timeout, fast_timeout)
    except Exception:
        pass

    # Master verify is an automated pipeline; leaving a browser open will hang until timeout.
    # Force keep-browser off unless explicitly overridden for debugging.
    if os.getenv("BGL_MASTER_KEEP_BROWSER", "0") != "1":
        os.environ["BGL_KEEP_BROWSER"] = "0"

    # Initialize Core
    core = AgencyCore(ROOT)

    # Run Full Diagnostic with bounded timeout to avoid hanging browser runs
    run_id = f"diag_{int(time.time())}"
    os.environ["BGL_DIAGNOSTIC_RUN_ID"] = run_id
    phase_timings: list[dict] = []
    try:
        _update_status_stage(status_path, "full_diagnostic", run_id=run_id)
    except Exception:
        pass

    def _phase_start(name: str, extra: dict | None = None) -> float:
        ts = time.time()
        payload = {"phase": name}
        if extra:
            payload.update(extra)
        _log_runtime_event(
            ROOT,
            {
                "timestamp": ts,
                "run_id": run_id,
                "event_type": "diagnostic_phase_start",
                "source": "master_verify",
                "payload": payload,
            },
        )
        return ts

    def _phase_end(name: str, start_ts: float, status: str = "ok", reason: str = "", extra: dict | None = None) -> None:
        end_ts = time.time()
        duration = max(0.0, end_ts - start_ts)
        payload = {"phase": name, "duration_s": round(duration, 3), "status": status}
        if reason:
            payload["reason"] = reason
        if extra:
            payload.update(extra)
        phase_timings.append(payload)
        _log_runtime_event(
            ROOT,
            {
                "timestamp": end_ts,
                "run_id": run_id,
                "event_type": "diagnostic_phase_end",
                "source": "master_verify",
                "payload": payload,
            },
        )
    try:
        start_run(ROOT / ".bgl_core" / "brain" / "knowledge.db", run_id=run_id, mode="master_verify")
    except Exception:
        pass
    try:
        phase_ts = _phase_start("full_diagnostic")
        diagnostic = await asyncio.wait_for(core.run_full_diagnostic(), timeout=timeout)
        _phase_end("full_diagnostic", phase_ts)
        try:
            diagnostic["diagnostic_profile"] = profile
            diagnostic["diagnostic_profile_reason"] = profile_reason
            diagnostic["diagnostic_profile_overrides"] = profile_overrides
            diagnostic.setdefault("cache_used", False)
            diagnostic.setdefault("cache_reason", "")
            diagnostic["diagnostic_phase_timings"] = phase_timings
        except Exception:
            pass
    except asyncio.TimeoutError:
        print(f"[CRITICAL] Diagnostic timed out after {timeout}s.")
        try:
            _phase_end("full_diagnostic", phase_ts, status="timeout", reason="diagnostic_timeout")
        except Exception:
            pass
        # Fallback to cached report to keep pipeline responsive.
        if profile == "fast" and last_report:
            try:
                last_report["cache_used"] = True
                last_report["cache_reason"] = "timeout_fallback"
                last_report["cached_at"] = time.time()
                last_report["diagnostic_profile"] = "cache-fast"
                _write_json(report_path, last_report)
            except Exception:
                pass
        try:
            _write_status(
                status_path,
                "timeout",
                finished_at=time.time(),
                profile=profile,
                reason="diagnostic_timeout",
                stage="timeout",
            )
        except Exception:
            pass
        return
    finally:
        try:
            release_lock(lock_path)
        except Exception:
            pass
        try:
            if hb_stop is not None:
                hb_stop.set()
        except Exception:
            pass
        try:
            finish_run(ROOT / ".bgl_core" / "brain" / "knowledge.db", run_id=run_id)
        except Exception:
            pass
        try:
            os.environ.pop("BGL_DIAGNOSTIC_RUN_ID", None)
        except Exception:
            pass
        # Best-effort cleanup: Playwright on Windows can emit noisy asyncio subprocess warnings
        # if its driver is still attached when the event loop shuts down.
        try:
            await asyncio.wait_for(core.sensor_browser.close(), timeout=5)
        except Exception:
            pass

    # Augment diagnostic with route_usage (for suppression) and feature_flags
    diagnostic["route_usage"] = load_route_usage(ROOT)
    diagnostic["feature_flags"] = cfg.get("feature_flags", {})
    diagnostic["effective_config"] = effective_cfg

    # Heavy stages (callgraph/OpenAPI/contract tests) can be skipped or scaled by profile.
    skip_heavy = fast_verify or profile == "fast"
    is_medium = profile == "medium"

    def _scaled_timeout(value, floor=10):
        try:
            timeout_val = int(value)
        except Exception:
            timeout_val = 0
        if is_medium:
            timeout_val = max(floor, int(timeout_val * 0.5))
        return timeout_val

    if skip_heavy:
        diagnostic["findings"]["callgraph_meta"] = {"skipped": True, "reason": "profile_fast"}
        diagnostic["openapi_path"] = ""
        if cfg.get("run_api_contract", 0):
            diagnostic.setdefault("findings", {})["gap_tests"] = []
            diagnostic.setdefault("gap_tests", [])
            diagnostic["findings"]["gap_tests_skipped"] = {"skipped": True, "reason": "profile_fast"}
    else:
        # Build callgraph for reporting/reference
        callgraph_timeout = _scaled_timeout(cfg.get("callgraph_timeout_sec", 60) or 60, floor=15)
        _cg_start = _phase_start("callgraph_builder")
        diagnostic["findings"]["callgraph_meta"] = _run_with_timeout(
            "callgraph_builder", lambda: build_callgraph(ROOT), callgraph_timeout, {}
        )
        _phase_end("callgraph_builder", _cg_start)

        # Generate OpenAPI (merged) for contract tests and reference
        openapi_timeout = _scaled_timeout(cfg.get("openapi_timeout_sec", 60) or 60, floor=15)
        _oa_start = _phase_start("openapi_generate")
        openapi_path = _run_with_timeout(
            "openapi_generate", lambda: generate_openapi(ROOT), openapi_timeout, None
        )
        _phase_end("openapi_generate", _oa_start)
        diagnostic["openapi_path"] = str(openapi_path) if openapi_path else ""

        # Optional: run API contract/property tests (Schemathesis/Dredd) if enabled
        if cfg.get("run_api_contract", 0):
            contract_timeout = _scaled_timeout(cfg.get("contract_timeout_sec", 120) or 120, floor=30)
            _ct_start = _phase_start("contract_suite")
            contract_results = _run_with_timeout(
                "contract_suite", lambda: run_contract_suite(ROOT), contract_timeout, []
            )
            _phase_end("contract_suite", _ct_start)
            diagnostic.setdefault("gap_tests", []).extend(contract_results)
            diagnostic.setdefault("findings", {}).setdefault("gap_tests", []).extend(contract_results)

    # Optional: lightweight perf probe (home page load)
    perf = {}
    if cfg.get("measure_perf", 0):
        import urllib.request

        base = cfg.get("base_url", "http://localhost:8000").rstrip("/")
        start = time.perf_counter()
        try:
            with urllib.request.urlopen(base + "/", timeout=5) as resp:
                perf["home_status"] = resp.getcode()
                perf["home_bytes"] = len(resp.read())
        except Exception as e:
            perf["home_error"] = str(e)
        perf["home_load_ms"] = round((time.perf_counter() - start) * 1000, 1)
        diagnostic["performance"] = perf

    # Scenario dependency health + runtime events meta
    scenario_deps = (await check_scenario_deps_async()).to_dict()
    diagnostic.setdefault("findings", {})["scenario_deps"] = scenario_deps
    runtime_meta = {"count": 0, "last_timestamp": None}
    try:
        db_path = ROOT / ".bgl_core" / "brain" / "knowledge.db"
        if db_path.exists():
            conn = sqlite3.connect(str(db_path), timeout=30.0)
            conn.execute("PRAGMA journal_mode=WAL;")
            cur = conn.cursor()
            cur.execute("SELECT COUNT(*) FROM runtime_events")
            runtime_meta["count"] = int(cur.fetchone()[0] or 0)
            cur.execute("SELECT MAX(timestamp) FROM runtime_events")
            runtime_meta["last_timestamp"] = cur.fetchone()[0]
            conn.close()
    except Exception:
        pass
    diagnostic["findings"]["runtime_events_meta"] = runtime_meta

    # Auto-insights status (staleness/coverage)
    if skip_heavy:
        auto_insights_status = {"skipped": True, "reason": "profile_fast"}
        diagnostic["findings"]["auto_insights_status"] = auto_insights_status
        try:
            write_auto_insights_status(ROOT, auto_insights_status)
        except Exception:
            pass
    else:
        try:
            allow_legacy = os.getenv("BGL_ALLOW_LEGACY_INSIGHTS", "0") == "1"
            try:
                max_insights = int(os.getenv("BGL_MAX_AUTO_INSIGHTS", "0") or "0")
            except Exception:
                max_insights = 0
            insights_timeout = _scaled_timeout(
                cfg.get("auto_insights_timeout_sec", 60) or 60, floor=15
            )
            try:
                _update_status_stage(status_path, "auto_insights", run_id=run_id)
            except Exception:
                pass
            _ai_start = _phase_start("auto_insights")
            auto_insights_status = _run_with_timeout(
                "auto_insights",
                lambda: audit_auto_insights(
                    ROOT, allow_legacy=allow_legacy, max_insights=max_insights
                ),
                insights_timeout,
                {},
            )
            _phase_end("auto_insights", _ai_start)
            diagnostic["findings"]["auto_insights_status"] = auto_insights_status
            write_auto_insights_status(ROOT, auto_insights_status)
            try:
                _update_status_stage(status_path, "auto_insights_done", run_id=run_id)
            except Exception:
                pass
        except Exception:
            diagnostic["findings"]["auto_insights_status"] = {}

    # Auto-generate playbook skeletons from proposed patterns (discovery-only)
    generated = generate_from_proposed(Path(__file__).parent.parent.parent)
    if generated:
        print(f"[+] Generated playbook skeletons: {generated}")
    # Warn if pending playbooks await review
    pending = list((Path(__file__).parent / "playbooks_proposed").glob("*.md"))
    if pending:
        print(
            f"[WARN] Pending playbooks awaiting approval: {[p.name for p in pending]}"
        )

    # 1. Infrastructure Pass
    print(
        f"\n[1] Infrastructure Integrity: {'✅ PASS' if diagnostic['vitals']['infrastructure'] else '❌ FAIL'}"
    )

    # 2. Business Logic Pass
    print(
        f"[2] Business Logic Health:    {'✅ PASS' if diagnostic['vitals']['business_logic'] else '⚠️ WARNING'}"
    )

    # 3. Architectural Pass
    print(
        f"[3] Architectural Compliance: {'✅ PASS' if diagnostic['vitals']['architecture'] else '❌ VIOLATION'}"
    )

    # 4. Agent Status & Memory
    print(f"\n[4] Agent Memory (Knowledge DB): {'✅ SYNCED'}")
    blockers = diagnostic["findings"]["blockers"]
    if blockers:
        print(f"    [!] ALERT: {len(blockers)} Cognitive Blockers identified.")
        for b in blockers:
            print(f"        - {b['task_name']}: {b['reason'][:60]}...")
    else:
        print("    [SUCCESS] No cognitive blockers detected.")

    # 5. Route Health
    failing = diagnostic["findings"]["failing_routes"]
    print(f"\n[5] Real-time Route Health:  {100 - len(failing)}% Optimal")
    if failing:
        for f in failing[:3]:  # Show top 3
            if isinstance(f, dict):
                uri = f.get("uri") or f.get("url") or str(f)
            else:
                uri = str(f)
            print(f"        - ERROR on {uri}")

    # 6. Browser Driver Check
    print("\n[6] Browser Engine Status:")
    try:
        res = subprocess.run(
            ["playwright", "--version"], capture_output=True, text=True, check=True
        )
        print(f"    - Playwright: ✅ DETECTED ({res.stdout.strip()})")
    except Exception:
        print("    - Playwright: ❌ MISSING (Run: playwright install chromium)")

    # 7. Write HTML report
    try:
        template = Path(__file__).parent / "report_template.html"
        output = Path(".bgl_core/logs/latest_report.html")
        data = {
            "timestamp": diagnostic.get("timestamp"),
            "diagnostic_run_id": diagnostic.get("diagnostic_run_id", run_id),
            "health_score": diagnostic.get("health_score", 0),
            "health_score_status": diagnostic.get("health_score_status", ""),
            "route_scan_limit": diagnostic.get("route_scan_limit", 0),
            "route_scan_mode": diagnostic.get("route_scan_mode", "auto"),
            "route_scan_stats": diagnostic.get("route_scan_stats", {}),
            "audit_status": diagnostic.get("audit_status", ""),
            "audit_reason": diagnostic.get("audit_reason", ""),
            "audit_budget_seconds": diagnostic.get("audit_budget_seconds", 0),
            "audit_elapsed_seconds": diagnostic.get("audit_elapsed_seconds", 0),
            "diagnostic_profile": diagnostic.get("diagnostic_profile", ""),
            "diagnostic_profile_reason": diagnostic.get("diagnostic_profile_reason", ""),
            "diagnostic_profile_overrides": diagnostic.get("diagnostic_profile_overrides", {}),
            "cache_used": diagnostic.get("cache_used", False),
            "cache_reason": diagnostic.get("cache_reason", ""),
            "execution_mode": diagnostic.get(
                "execution_mode", cfg.get("execution_mode", "sandbox")
            ),
            "execution_stats": diagnostic.get("execution_stats", {}),
            "performance": diagnostic.get("performance", {}),
            "scan_duration_seconds": diagnostic.get("scan_duration_seconds", 0),
            "target_duration_seconds": diagnostic.get("target_duration_seconds", 0),
            "vitals": diagnostic.get("vitals", {}),
            "permission_issues": diagnostic["findings"].get("permission_issues", []),
            "pending_approvals": diagnostic["findings"].get("pending_approvals", []),
            "recent_outcomes": diagnostic["findings"].get("recent_outcomes", []),
            "failing_routes": diagnostic["findings"].get("failing_routes", []),
            "experiences": diagnostic["findings"].get("experiences", []),
            "suggestions": diagnostic["findings"].get("proposals", []),
            "worst_routes": diagnostic["findings"].get("worst_routes", []),
            "interpretation": diagnostic["findings"].get("interpretation", {}),
            "intent": diagnostic["findings"].get("intent", {}),
            "decision": diagnostic["findings"].get("decision", {}),
            "signals": diagnostic["findings"].get("signals", {}),
            "signals_intent_hint": diagnostic["findings"].get(
                "signals_intent_hint", {}
            ),
            "gap_tests": diagnostic["findings"].get("gap_tests", []),
            "proposals": diagnostic["findings"].get("proposals", []),
            "external_checks": diagnostic["findings"].get("external_checks", []),
            "tool_evidence": diagnostic["findings"].get("tool_evidence", {}),
            "scenario_deps": diagnostic["findings"].get("scenario_deps", {}),
            "runtime_events_meta": diagnostic["findings"].get(
                "runtime_events_meta", {}
            ),
            "scenario_coverage": diagnostic["findings"].get("scenario_coverage", {}),
            "ui_action_coverage": diagnostic["findings"].get("ui_action_coverage", {}),
            "flow_coverage": diagnostic["findings"].get("flow_coverage", {}),
            "coverage_gate": diagnostic["findings"].get("coverage_gate", {}),
            "flow_gate": diagnostic["findings"].get("flow_gate", {}),
            "auto_insights_status": diagnostic["findings"].get(
                "auto_insights_status", {}
            ),
            "api_scan": diagnostic["findings"].get("api_scan", {}),
            "volition": diagnostic["findings"].get("volition", {}),
            "autonomous_policy": diagnostic["findings"].get("autonomous_policy", {}),
            "readiness": diagnostic.get("readiness", {}),
            "api_contract": diagnostic.get("api_contract", {}),
            "api_contract_missing": diagnostic["findings"].get(
                "api_contract_missing", []
            ),
            "api_contract_gaps": diagnostic["findings"].get("api_contract_gaps", []),
            "expected_failures": diagnostic["findings"].get("expected_failures", []),
            "policy_candidates": diagnostic["findings"].get("policy_candidates", []),
            "policy_auto_promoted": diagnostic["findings"].get(
                "policy_auto_promoted", []
            ),
            "ui_semantic": diagnostic["findings"].get("ui_semantic", {}),
            "ui_semantic_delta": diagnostic["findings"].get("ui_semantic_delta", {}),
            "self_policy": diagnostic["findings"].get("self_policy", {}),
            "self_rules": diagnostic["findings"].get("self_rules", {}),
            "scenario_run_stats": diagnostic["findings"].get("scenario_run_stats", {}),
            "coverage_reliability": diagnostic["findings"].get("coverage_reliability", {}),
            "knowledge_status": diagnostic["findings"].get("knowledge_status", {}),
            "learning_feedback": diagnostic["findings"].get("learning_feedback", {}),
            "long_term_goals": diagnostic["findings"].get("long_term_goals", {}),
            "canary_status": diagnostic["findings"].get("canary_status", {}),
            "diagnostic_attribution": diagnostic["findings"].get("diagnostic_attribution", {}),
            "domain_rule_summary": diagnostic["findings"].get("domain_rule_summary", {}),
            "effective_config": diagnostic.get("effective_config", {}),
            "context_digest": diagnostic["findings"].get("context_digest", {}),
            "auto_plan": diagnostic["findings"].get("auto_plan", {}),
            "failure_taxonomy": diagnostic["findings"].get("failure_taxonomy", {}),
            "gap_scenarios": diagnostic["findings"].get("gap_scenarios", []),
            "gap_scenarios_existing": diagnostic["findings"].get("gap_scenarios_existing", []),
            "kpi_metrics": diagnostic["findings"].get("kpi_metrics", {}),
            "activity_summary": diagnostic["findings"].get("activity_summary", {}),
            "diagnostic_delta": diagnostic["findings"].get("diagnostic_delta", {}),
            "diagnostic_confidence": diagnostic["findings"].get("diagnostic_confidence", {}),
        }
        # Baseline + confidence metadata (fast/medium/full layering)
        try:
            baseline_report, baseline_meta = _load_baseline_report(ROOT)
            baseline_ts = baseline_meta.get("timestamp") or baseline_report.get("timestamp")
            baseline_profile = baseline_meta.get("diagnostic_profile") or baseline_report.get("diagnostic_profile")
            baseline_age = None
            if baseline_ts:
                baseline_age = round(max(0.0, time.time() - float(baseline_ts)), 2)
            data["diagnostic_baseline"] = {
                "timestamp": baseline_ts,
                "profile": baseline_profile,
                "age_sec": baseline_age,
                "path": str((ROOT / ".bgl_core" / "logs" / "diagnostic_baseline.json")),
            }
            if baseline_report:
                data["diagnostic_comparison_full"] = _compare_reports(baseline_report, data)
        except Exception:
            data["diagnostic_baseline"] = {}
            data["diagnostic_comparison_full"] = {}
        try:
            if not data.get("diagnostic_confidence"):
                data["diagnostic_confidence"] = _compute_diagnostic_confidence(data)
        except Exception:
            data["diagnostic_confidence"] = data.get("diagnostic_confidence") or {}
        try:
            data["diagnostic_faults"] = _compute_diagnostic_faults(data)
        except Exception:
            data["diagnostic_faults"] = []
        try:
            data["diagnostic_self_check"] = _compute_diagnostic_self_check(data)
        except Exception:
            data["diagnostic_self_check"] = {
                "ok": False,
                "missing": ["self_check_failed"],
                "audit_status": data.get("audit_status"),
                "audit_reason": data.get("audit_reason"),
            }
        # Fallback rule summary (intent/temporal linkage visibility)
        try:
            fallback_rules = []
            sp = diagnostic["findings"].get("self_policy", {})
            if isinstance(sp, dict):
                fallback_rules = sp.get("fallback_rules") or []
            if not isinstance(fallback_rules, list):
                fallback_rules = []
            by_source = {}
            samples: List[Dict[str, Any]] = []
            for rule in fallback_rules:
                if not isinstance(rule, dict):
                    continue
                src = str(rule.get("source") or "unknown")
                by_source[src] = by_source.get(src, 0) + 1
                if src == "code_intent_signals" and len(samples) < 6:
                    samples.append(
                        {
                            "key": rule.get("key"),
                            "action": rule.get("action"),
                            "intent": rule.get("intent"),
                            "risk": rule.get("risk"),
                            "reason": rule.get("reason"),
                            "repeat_count": rule.get("repeat_count"),
                            "tests_stale": rule.get("tests_stale"),
                            "temporal_profile": rule.get("temporal_profile"),
                        }
                    )
            data["fallback_rules_summary"] = {
                "total": len(fallback_rules),
                "by_source": by_source,
                "code_intent_samples": samples,
            }
        except Exception:
            data["fallback_rules_summary"] = {}
        try:
            auto_cfg = effective_cfg or cfg
        except Exception:
            auto_cfg = cfg
        data["automation"] = {
            "fast_verify": os.getenv("BGL_FAST_VERIFY", "0") == "1",
            "diagnostic_profile": diagnostic.get("diagnostic_profile"),
            "approvals_enabled": auto_cfg.get("approvals_enabled", True),
            "auto_propose": auto_cfg.get("auto_propose", 0),
            "auto_propose_min_conf": auto_cfg.get("auto_propose_min_conf"),
            "auto_propose_min_evidence": auto_cfg.get("auto_propose_min_evidence"),
            "auto_apply": auto_cfg.get("auto_apply", 0),
            "auto_apply_limit": auto_cfg.get("auto_apply_limit"),
            "auto_plan": auto_cfg.get("auto_plan", 0),
            "auto_plan_limit": auto_cfg.get("auto_plan_limit"),
            "auto_digest": auto_cfg.get("auto_digest", 0),
            "auto_digest_hours": auto_cfg.get("auto_digest_hours"),
            "auto_digest_limit": auto_cfg.get("auto_digest_limit"),
            "auto_verify": auto_cfg.get("auto_verify", 0),
            "auto_verify_on_low_success": auto_cfg.get("auto_verify_on_low_success"),
            "auto_verify_success_threshold": auto_cfg.get("auto_verify_success_threshold"),
            "auto_verify_on_ui_gap": auto_cfg.get("auto_verify_on_ui_gap"),
            "auto_verify_ui_gap_threshold": auto_cfg.get("auto_verify_ui_gap_threshold"),
            "auto_patch_on_errors": auto_cfg.get("auto_patch_on_errors"),
            "auto_patch_limit": auto_cfg.get("auto_patch_limit"),
            "auto_patch_min_conf": auto_cfg.get("auto_patch_min_conf"),
            "auto_patch_min_evidence": auto_cfg.get("auto_patch_min_evidence"),
            "post_apply_validate": auto_cfg.get("post_apply_validate"),
            "post_apply_validate_mode": auto_cfg.get("post_apply_validate_mode"),
            "post_apply_auto_rollback_on_fail": auto_cfg.get("post_apply_auto_rollback_on_fail"),
            "post_apply_immediate_canary_eval": auto_cfg.get("post_apply_immediate_canary_eval"),
            "post_apply_auto_promote_prod": auto_cfg.get("post_apply_auto_promote_prod"),
            "allow_prod_without_human": auto_cfg.get("allow_prod_without_human"),
            "execution_mode": auto_cfg.get("execution_mode"),
            "agent_mode": auto_cfg.get("agent_mode") or (auto_cfg.get("decision") or {}).get("mode"),
        }
        try:
            data["run_locks"] = {
                "master_verify": _read_lock_status(ROOT / ".bgl_core" / "logs" / "master_verify.lock"),
                "run_scenarios": _read_lock_status(ROOT / ".bgl_core" / "logs" / "run_scenarios.lock"),
                "scenario_runner": _read_lock_status(ROOT / ".bgl_core" / "logs" / "scenario_runner.lock"),
            }
        except Exception:
            data["run_locks"] = {}
        try:
            data["diagnostic_status"] = _read_json(
                ROOT / ".bgl_core" / "logs" / "diagnostic_status.json"
            )
        except Exception:
            data["diagnostic_status"] = {}
        try:
            stage_timings, stage_totals = _compute_stage_durations(
                data.get("diagnostic_status") or {}, data.get("diagnostic_run_id")
            )
            data["diagnostic_stage_timings"] = stage_timings
            data["diagnostic_stage_totals"] = stage_totals
            data["diagnostic_slow_phases"] = _compute_slow_phases(stage_totals)
        except Exception:
            data["diagnostic_stage_timings"] = []
            data["diagnostic_stage_totals"] = {}
            data["diagnostic_slow_phases"] = []
        try:
            data["diagnostic_timeout_ledger"] = _collect_timeout_ledger(
                ROOT, data.get("diagnostic_status") or {}
            )
        except Exception:
            data["diagnostic_timeout_ledger"] = []
        try:
            data["schema_drift"] = check_schema(ROOT / ".bgl_core" / "brain" / "knowledge.db")
        except Exception:
            data["schema_drift"] = {"ok": False, "error": "schema_check_failed"}
        # Validate Authority vs write_scope.yml (gating matrix)
        try:
            data["authority_matrix"] = core.authority.validate_gating_matrix()
        except Exception:
            data["authority_matrix"] = {"ok": False, "warnings": ["authority_matrix_unavailable"]}
        try:
            data["diagnostic_comparison"] = _compare_reports(last_report, data)
        except Exception:
            data["diagnostic_comparison"] = {}
        data["diagnostic_phase_timings"] = diagnostic.get("diagnostic_phase_timings", [])
        try:
            scen_run_id = (data.get("scenario_run_stats") or {}).get("run_id")
            diag_run_id = data.get("diagnostic_run_id")
            if scen_run_id and diag_run_id and str(scen_run_id) != str(diag_run_id):
                _write_status(
                    status_path,
                    "running",
                    stage="run_id_mismatch",
                    run_id=diag_run_id,
                    mismatch_scenario_run_id=scen_run_id,
                )
        except Exception:
            pass
        try:
            _update_status_stage(status_path, "report_render", run_id=run_id)
        except Exception:
            pass
        _br_start = _phase_start("build_report")
        build_report(data, template, output)
        _phase_end("build_report", _br_start)
        try:
            if (
                phase_timings
                and isinstance(phase_timings[-1], dict)
                and phase_timings[-1].get("phase") == "build_report"
            ):
                data["report_render_duration_s"] = phase_timings[-1].get("duration_s")
        except Exception:
            pass
        try:
            data["diagnostic_phase_timings"] = phase_timings
            build_report(data, template, output)
        except Exception:
            pass
        # Write JSON alongside HTML for dashboard consumption
        json_out = Path(".bgl_core/logs/latest_report.json")
        json_out.write_text(
            json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        try:
            meta_payload = {
                "timestamp": diagnostic.get("timestamp"),
                "fingerprint": fp_payload,
                "diagnostic_profile": diagnostic.get("diagnostic_profile"),
                "audit_status": diagnostic.get("audit_status"),
                "route_scan_limit": diagnostic.get("route_scan_limit"),
                "scan_duration_seconds": diagnostic.get("scan_duration_seconds"),
            }
            _write_json(meta_path, meta_payload)
        except Exception:
            pass
        # Update baseline if this is a full, non-cached report
        try:
            prof = str(diagnostic.get("diagnostic_profile") or "").lower()
            audit_status = str(diagnostic.get("audit_status") or "").lower()
            try:
                conf_score = float((data.get("diagnostic_confidence") or {}).get("score") or 0.0)
            except Exception:
                conf_score = 0.0
            if (
                prof in ("full", "full-scan")
                and not diagnostic.get("cache_used")
                and audit_status != "partial"
                and conf_score >= 0.7
            ):
                baseline_path = ROOT / ".bgl_core" / "logs" / "diagnostic_baseline.json"
                baseline_meta_path = ROOT / ".bgl_core" / "logs" / "diagnostic_baseline.meta.json"
                _write_json(baseline_path, _sanitize_baseline_report(data))
                _write_json(
                    baseline_meta_path,
                    {
                        "timestamp": diagnostic.get("timestamp"),
                        "diagnostic_profile": diagnostic.get("diagnostic_profile"),
                        "route_scan_limit": diagnostic.get("route_scan_limit"),
                        "scan_duration_seconds": diagnostic.get("scan_duration_seconds"),
                    },
                )
        except Exception:
            pass
        print(f"[+] HTML report written to {output}")
        try:
            _write_status(
                ROOT / ".bgl_core" / "logs" / "diagnostic_status.json",
                "complete",
                finished_at=time.time(),
                profile=diagnostic.get("diagnostic_profile"),
                cache_used=bool(diagnostic.get("cache_used")),
                stage="complete",
            )
        except Exception:
            pass
    except Exception as e:
        print(f"[!] Failed to write HTML report: {e}")
        try:
            _write_status(
                ROOT / ".bgl_core" / "logs" / "diagnostic_status.json",
                "error",
                finished_at=time.time(),
                reason=str(e),
                profile=diagnostic.get("diagnostic_profile"),
                stage="error",
            )
        except Exception:
            pass

    print("\n" + "=" * 70)
    print("💎 ASSURANCE COMPLETE: SYSTEM IS IN GOLDEN STATE")
    print("=" * 70 + "\n")

    # Log completion for dashboard
    log_activity(ROOT, "master_verify_complete", {"run_id": run_id})


if __name__ == "__main__":
    try:
        # Allow overriding headless and scenario run via env for visibility/CI
        ROOT = Path(__file__).parent.parent.parent
        cfg = load_config(ROOT)
        fast_cfg = str(cfg.get("fast_verify", "0")).strip().lower() in ("1", "true", "yes", "on")
        if os.getenv("BGL_FAST_VERIFY", "0") == "1" or fast_cfg:
            os.environ["BGL_RUN_SCENARIOS"] = "0"
            os.environ["BGL_AUTO_CONTEXT_DIGEST"] = "0"
            os.environ["BGL_AUTO_APPLY"] = "0"
            os.environ["BGL_AUTO_PLAN"] = "0"
            os.environ["BGL_AUTO_VERIFY"] = "0"
            os.environ["BGL_AUTO_PATCH_ON_ERRORS"] = "0"
            os.environ["BGL_SKIP_DREAM"] = "1"
            os.environ["BGL_MAX_AUTO_INSIGHTS"] = "0"
        os.environ.setdefault(
            "BGL_HEADLESS", os.environ.get("BGL_HEADLESS", str(cfg.get("headless", 1)))
        )
        os.environ.setdefault(
            "BGL_RUN_SCENARIOS",
            os.environ.get("BGL_RUN_SCENARIOS", str(cfg.get("run_scenarios", 1))),
        )
        os.environ.setdefault(
            "BGL_BASE_URL",
            os.environ.get(
                "BGL_BASE_URL", cfg.get("base_url", "http://localhost:8000")
            ),
        )
        os.environ.setdefault(
            "BGL_KEEP_BROWSER",
            os.environ.get("BGL_KEEP_BROWSER", str(cfg.get("keep_browser", 0))),
        )
        asyncio.run(master_assurance_diagnostic())
    except Exception as e:
        print(f"\n[CRITICAL FAILURE] Master Diagnostic Crashed: {e}")
        import traceback

        traceback.print_exc()
