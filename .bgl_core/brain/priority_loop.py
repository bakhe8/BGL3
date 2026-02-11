from __future__ import annotations

import json
import os
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, Optional

from run_lock import describe_lock, release_lock

try:
    from .config_loader import load_config  # type: ignore
except Exception:
    from config_loader import load_config  # type: ignore

try:
    from .config_tuner import apply_safe_config_tuning  # type: ignore
except Exception:
    try:
        from config_tuner import apply_safe_config_tuning  # type: ignore
    except Exception:
        apply_safe_config_tuning = None  # type: ignore


def _read_json(path: Path) -> Dict[str, Any]:
    try:
        if not path.exists():
            return {}
        return json.loads(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _write_json(path: Path, payload: Dict[str, Any]) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass


def _log_runtime_event(root_dir: Path, event: Dict[str, Any]) -> None:
    db_path = root_dir / ".bgl_core" / "brain" / "knowledge.db"
    if not db_path.exists():
        return
    try:
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
                    event.get("session", "priority_loop"),
                    event.get("run_id", ""),
                    event.get("scenario_id", ""),
                    event.get("goal_id", ""),
                    event.get("source", "priority_loop"),
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
    except Exception:
        return


def _update_diagnostic_status(status_path: Path, updates: Dict[str, Any]) -> None:
    payload = _read_json(status_path)
    if not isinstance(payload, dict):
        payload = {}
    # Preserve stage history if present.
    if "stage_history" in payload and "stage_history" not in updates:
        updates["stage_history"] = payload.get("stage_history")
    payload.update(updates)
    payload["timestamp"] = time.time()
    _write_json(status_path, payload)


def _cleanup_lock(lock_path: Path, *, ttl_sec: int, label: str) -> Dict[str, Any]:
    info = describe_lock(lock_path, ttl_sec=ttl_sec)
    status = info.get("status")
    cleaned = False
    if status in ("stale_dead_pid", "active_stale"):
        try:
            release_lock(lock_path)
            cleaned = True
        except Exception:
            cleaned = False
    info["cleaned"] = cleaned
    info["label"] = label
    return info


def _run_integrity(root_dir: Path, cfg: Dict[str, Any], run_id: Optional[str]) -> Dict[str, Any]:
    status_path = root_dir / ".bgl_core" / "logs" / "diagnostic_status.json"
    report_path = root_dir / ".bgl_core" / "logs" / "latest_report.json"
    status = _read_json(status_path)
    report = _read_json(report_path)
    report_ts = report.get("timestamp") if isinstance(report, dict) else None
    status_ts = status.get("timestamp") if isinstance(status, dict) else None
    status_state = str(status.get("status") or "")
    status_fixed = False
    if status_state == "running" and report_ts:
        # If a report exists after a run, mark status as complete to avoid stale "running".
        _update_diagnostic_status(
            status_path,
            {
                "status": "complete",
                "reason": "priority_loop_report_present",
                "finished_at": float(report_ts),
            },
        )
        status_fixed = True

    run_scenarios_ttl = int(cfg.get("run_scenarios_lock_ttl_sec", 7200) or 7200)
    scenario_runner_ttl = int(cfg.get("scenario_lock_ttl_sec", 7200) or 7200)
    locks = [
        _cleanup_lock(
            root_dir / ".bgl_core" / "logs" / "run_scenarios.lock",
            ttl_sec=run_scenarios_ttl,
            label="run_scenarios",
        ),
        _cleanup_lock(
            root_dir / ".bgl_core" / "logs" / "scenario_runner.lock",
            ttl_sec=scenario_runner_ttl,
            label="scenario_runner",
        ),
    ]
    _log_runtime_event(
        root_dir,
        {
            "timestamp": time.time(),
            "run_id": run_id or "",
            "event_type": "priority_run_integrity",
            "payload": {
                "status_fixed": status_fixed,
                "report_ts": report_ts,
                "status_ts": status_ts,
                "locks": locks,
            },
        },
    )
    return {"status_fixed": status_fixed, "locks": locks}


def _event_integrity(root_dir: Path, run_id: Optional[str]) -> Dict[str, Any]:
    report = _read_json(root_dir / ".bgl_core" / "logs" / "latest_report.json")
    stats = (report or {}).get("scenario_run_stats") or {}
    attempted = bool(stats.get("attempted")) if isinstance(stats, dict) else False
    event_delta = stats.get("event_delta") if isinstance(stats, dict) else None
    duration_s = stats.get("duration_s") if isinstance(stats, dict) else None
    fallback_path = root_dir / ".bgl_core" / "logs" / "runtime_events_fallback.jsonl"
    fallback_bytes = fallback_path.stat().st_size if fallback_path.exists() else 0
    degraded = bool(attempted and (event_delta == 0))
    summary = {
        "attempted": attempted,
        "event_delta": event_delta,
        "duration_s": duration_s,
        "fallback_bytes": fallback_bytes,
        "degraded": degraded,
    }
    _log_runtime_event(
        root_dir,
        {
            "timestamp": time.time(),
            "run_id": run_id or "",
            "event_type": "priority_event_integrity",
            "payload": summary,
        },
    )
    return summary


def _digest_integrity(root_dir: Path, cfg: Dict[str, Any], run_id: Optional[str]) -> Dict[str, Any]:
    report = _read_json(root_dir / ".bgl_core" / "logs" / "latest_report.json")
    ctx = (report or {}).get("context_digest") or {}
    ctx_ok = bool(ctx.get("ok", True)) if isinstance(ctx, dict) else True
    tuning = None
    if not ctx_ok and apply_safe_config_tuning:
        try:
            diagnostic_map = {
                "findings": {"context_digest": ctx},
                "route_scan_stats": (report or {}).get("route_scan_stats") or {},
            }
            tuning = apply_safe_config_tuning(root_dir, diagnostic_map)
        except Exception:
            tuning = {"status": "error", "reason": "apply_safe_config_tuning_failed"}
    payload = {
        "context_digest_ok": ctx_ok,
        "context_digest_error": ctx.get("error") if isinstance(ctx, dict) else None,
        "tuning": tuning,
    }
    _log_runtime_event(
        root_dir,
        {
            "timestamp": time.time(),
            "run_id": run_id or "",
            "event_type": "priority_context_digest",
            "payload": payload,
        },
    )
    return payload


def run_priority_loop(root_dir: Path, cfg: Optional[Dict[str, Any]] = None, run_id: Optional[str] = None) -> Dict[str, Any]:
    """
    دائم: تنفيذ أول 3 أولويات بالتسلسل (سلامة التشغيل -> سلامة الأحداث -> سلامة الـ digest)
    مع تسجيل النتائج في .bgl_core/logs/priority_loop_state.json.
    """
    cfg = cfg or (load_config(root_dir) or {})
    started = time.time()
    results: Dict[str, Any] = {
        "started_at": started,
        "run_id": run_id or "",
        "steps": [],
    }
    try:
        run_res = _run_integrity(root_dir, cfg, run_id)
        results["steps"].append({"step": "run_integrity", "result": run_res})
    except Exception as exc:
        results["steps"].append({"step": "run_integrity", "error": str(exc)})
    try:
        event_res = _event_integrity(root_dir, run_id)
        results["steps"].append({"step": "event_integrity", "result": event_res})
    except Exception as exc:
        results["steps"].append({"step": "event_integrity", "error": str(exc)})
    try:
        digest_res = _digest_integrity(root_dir, cfg, run_id)
        results["steps"].append({"step": "digest_integrity", "result": digest_res})
    except Exception as exc:
        results["steps"].append({"step": "digest_integrity", "error": str(exc)})
    results["finished_at"] = time.time()
    _write_json(root_dir / ".bgl_core" / "logs" / "priority_loop_state.json", results)
    return results
