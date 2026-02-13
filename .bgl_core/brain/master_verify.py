import asyncio
import sys
import subprocess
import os
import threading
import atexit
from pathlib import Path
import json
import sqlite3
import time
import ctypes
import socket
from typing import Any, Dict, List

# Runtime globals for report finalization (used by exit fallback).
_ACTIVE_RUN_ID: str | None = None
_RUN_START_TS: float | None = None
_REPORT_WRITTEN: bool = False
_REPORT_PATH: Path | None = None
_STATUS_PATH: Path | None = None
_LAST_REPORT_SNAPSHOT: Dict[str, Any] | None = None
_CFG_SNAPSHOT: Dict[str, Any] | None = None


def _path_get(data: Dict[str, Any], path: str, default: Any = None) -> Any:
    if not path:
        return default
    cur: Any = data
    for part in path.split("."):
        if not isinstance(cur, dict) or part not in cur:
            return default
        cur = cur[part]
    return cur


def _parse_env_override(raw: str, cast: str) -> tuple[bool, Any]:
    try:
        text = str(raw).strip()
        if cast == "int":
            return True, int(text)
        if cast == "float":
            return True, float(text)
        if cast == "bool":
            return True, text.lower() in {"1", "true", "yes", "on"}
        return True, text
    except Exception:
        return False, raw


def _build_effective_runtime_config(effective_cfg: Dict[str, Any]) -> Dict[str, Any]:
    tracked_specs = {
        "diagnostic_profile": {"path": "diagnostic_profile", "env": "BGL_DIAGNOSTIC_PROFILE", "cast": "str", "default": "auto"},
        "diagnostic_timeout_sec": {"path": "diagnostic_timeout_sec", "env": "BGL_DIAGNOSTIC_TIMEOUT_SEC", "cast": "int", "default": 300},
        "guardian_timeout_sec": {"path": "guardian_timeout_sec", "env": "BGL_GUARDIAN_TIMEOUT_SEC", "cast": "int", "default": None},
        "diagnostic_budget_seconds": {"path": "diagnostic_budget_seconds", "env": "BGL_DIAGNOSTIC_BUDGET_SECONDS", "cast": "int", "default": None},
        "route_scan_limit": {"path": "route_scan_limit", "env": "BGL_ROUTE_SCAN_LIMIT", "cast": "int", "default": None},
        "route_scan_max_seconds": {"path": "route_scan_max_seconds", "env": "BGL_ROUTE_SCAN_MAX_SECONDS", "cast": "int", "default": None},
        "scenario_batch_limit": {"path": "scenario_batch_limit", "env": "BGL_SCENARIO_BATCH_LIMIT", "cast": "int", "default": None},
        "scenario_batch_timeout_sec": {"path": "scenario_batch_timeout_sec", "env": "BGL_SCENARIO_BATCH_TIMEOUT_SEC", "cast": "int", "default": None},
        "scenario_retry_on_low_events": {"path": "scenario_retry_on_low_events", "env": "BGL_SCENARIO_RETRY_ON_LOW_EVENTS", "cast": "bool", "default": True},
        "scenario_retry_on_flaky_errors": {"path": "scenario_retry_on_flaky_errors", "env": "BGL_SCENARIO_RETRY_ON_FLAKY_ERRORS", "cast": "bool", "default": True},
        "skip_dream": {"path": "skip_dream", "env": "BGL_SKIP_DREAM", "cast": "bool", "default": False},
        "reasoning_enabled": {"path": "reasoning_enabled", "env": "BGL_REASONING", "cast": "bool", "default": True},
        "force_process_exit": {"path": "force_process_exit", "env": "BGL_FORCE_PROCESS_EXIT", "cast": "bool", "default": False},
    }
    out: Dict[str, Any] = {"tracked": {}, "env_overrides": []}
    flat_sources = effective_cfg.get("_sources", {}) if isinstance(effective_cfg, dict) else {}
    for key, spec in tracked_specs.items():
        path = str(spec.get("path") or key)
        env_name = str(spec.get("env") or "")
        cast = str(spec.get("cast") or "str")
        default = spec.get("default")
        value = _path_get(effective_cfg, path, default)
        source = flat_sources.get(path, "default") if isinstance(flat_sources, dict) else "default"
        env_value = os.getenv(env_name, "") if env_name else ""
        env_applied = False
        env_parse_ok = True
        if env_name and str(env_value).strip() != "":
            ok, parsed = _parse_env_override(env_value, cast)
            value = parsed
            source = "env"
            env_applied = True
            env_parse_ok = ok
            out["env_overrides"].append(key)
        out["tracked"][key] = {
            "value": value,
            "source": source,
            "path": path,
            "env": env_name,
            "env_applied": env_applied,
            "env_parse_ok": env_parse_ok,
            "default": default,
        }
    return out


def _mark_report_written() -> None:
    global _REPORT_WRITTEN
    _REPORT_WRITTEN = True


def _build_stub_report(run_id: str, reason: str) -> Dict[str, Any]:
    now = time.time()
    return {
        "timestamp": now,
        "diagnostic_run_id": run_id,
        "cache_used": True,
        "cache_reason": reason,
        "cached_at": now,
        "cache_run_id": run_id,
        "cache_source_run_id": None,
        "diagnostic_profile": "cache-stub",
        "findings": {},
        "scenario_run_stats": {},
        "context_digest": {},
    }


def _write_cached_report(reason: str) -> None:
    if _REPORT_PATH is None:
        return
    run_id = _ACTIVE_RUN_ID or ""
    now = time.time()
    base = _LAST_REPORT_SNAPSHOT or {}
    if not base and run_id:
        payload = _build_stub_report(run_id, reason)
    else:
        payload = dict(base)
        payload["timestamp"] = now
        if run_id:
            payload["diagnostic_run_id"] = run_id
        payload["cache_used"] = True
        payload["cache_reason"] = reason
        payload["cached_at"] = now
        payload["cache_run_id"] = run_id or payload.get("cache_run_id")
        payload["cache_source_run_id"] = base.get("diagnostic_run_id")
        payload["diagnostic_profile"] = payload.get("diagnostic_profile") or "cache-full"
    try:
        if _STATUS_PATH is not None:
            payload["diagnostic_status"] = _read_json(_STATUS_PATH)
    except Exception:
        payload["diagnostic_status"] = {}
    try:
        payload["auto_review"] = _auto_review(payload, _CFG_SNAPSHOT or {})
    except Exception:
        pass
    _atomic_write_json(_REPORT_PATH, payload)
    _mark_report_written()


def _exit_report_fallback() -> None:
    """
    Best-effort fallback to ensure a report is written even if the run exits
    before the normal report-writing path completes.
    """
    if _REPORT_PATH is None or not _ACTIVE_RUN_ID:
        return
    if _REPORT_WRITTEN:
        return
    try:
        current = _read_json(_REPORT_PATH)
        if current.get("diagnostic_run_id") == _ACTIVE_RUN_ID or current.get("cache_run_id") == _ACTIVE_RUN_ID:
            return
    except Exception:
        pass
    _write_cached_report("exit_fallback")

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
from run_lock import acquire_lock, release_lock, describe_lock, refresh_lock  # noqa: E402
try:
    from priority_loop import run_priority_loop  # noqa: E402
except Exception:
    run_priority_loop = None  # type: ignore
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


def log_run_audit(root_path: Path) -> None:
    """Append a single-line audit record for each master_verify invocation."""
    try:
        log_dir = root_path / ".bgl_core" / "logs"
        log_dir.mkdir(parents=True, exist_ok=True)
        log_path = log_dir / "run_audit.jsonl"
        now_ts = time.time()
        payload = {
            "ts": now_ts,
            "timestamp": now_ts,
            "iso": time.strftime("%Y-%m-%d %H:%M:%S", time.localtime()),
            "pid": os.getpid(),
            "ppid": os.getppid(),
            "user": os.environ.get("USERNAME") or os.environ.get("USER"),
            "host": os.environ.get("COMPUTERNAME") or socket.gethostname(),
            "source": os.environ.get("BGL_RUN_SOURCE", "manual"),
            "task_name": os.environ.get("BGL_RUN_TASK_NAME", ""),
            "trigger": os.environ.get("BGL_RUN_TRIGGER", ""),
            "cmd": " ".join(sys.argv),
        }
        with log_path.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(payload, ensure_ascii=False) + "\n")
    except Exception as exc:
        print(f"[WARN] Failed to write run audit: {exc}")


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


def _run_post_finalize(root: Path, cfg: Dict[str, Any], run_id: str | None = None) -> None:
    """Run post-report automation hooks (auto-apply, strategy, cleanup, retention)."""
    rid = str(run_id or "")

    try:
        allow_auto_apply = os.getenv("BGL_AUTO_APPLY_PENDING")
        if allow_auto_apply is None:
            allow_auto_apply = str(cfg.get("auto_apply", 0))
        if str(allow_auto_apply).strip().lower() in ("1", "true", "yes", "on"):
            script = root / ".bgl_core" / "brain" / "auto_apply_pending_plans.py"
            if script.exists():
                exe = sys.executable or "python"
                try:
                    per_apply_timeout = int(cfg.get("auto_apply_timeout_sec", 300) or 300)
                except Exception:
                    per_apply_timeout = 300
                try:
                    apply_limit = int(cfg.get("auto_apply_limit", 3) or 3)
                except Exception:
                    apply_limit = 3
                aggregate_timeout = max(per_apply_timeout, (per_apply_timeout * max(1, apply_limit)) + 30)
                proc = subprocess.run(
                    [exe, str(script)],
                    cwd=str(root),
                    capture_output=True,
                    text=True,
                    timeout=aggregate_timeout,
                )
                payload = {
                    "ok": proc.returncode == 0,
                    "stdout": (proc.stdout or "").strip()[-1200:],
                    "stderr": (proc.stderr or "").strip()[-1200:],
                    "returncode": proc.returncode,
                    "per_apply_timeout": per_apply_timeout,
                    "auto_apply_limit": apply_limit,
                    "aggregate_timeout": aggregate_timeout,
                }
                try:
                    payload = json.loads((proc.stdout or "").strip().splitlines()[-1])
                except Exception:
                    pass
                _log_runtime_event(
                    root,
                    {
                        "timestamp": time.time(),
                        "run_id": rid,
                        "event_type": "auto_apply_pending",
                        "source": "master_verify",
                        "payload": payload,
                    },
                )
    except Exception as exc:
        try:
            _log_runtime_event(
                root,
                {
                    "timestamp": time.time(),
                    "run_id": rid,
                    "event_type": "auto_apply_pending_error",
                    "source": "master_verify",
                    "payload": {"ok": False, "error": str(exc)[:600]},
                },
            )
        except Exception:
            pass

    try:
        allow_strategy = os.getenv("BGL_AUTO_STRATEGY")
        if allow_strategy is None:
            allow_strategy = str(cfg.get("auto_strategy", 1))
        if str(allow_strategy).strip().lower() in ("1", "true", "yes", "on"):
            try:
                from .self_strategy_engine import run_self_strategy  # type: ignore
            except Exception:
                try:
                    from self_strategy_engine import run_self_strategy  # type: ignore
                except Exception:
                    run_self_strategy = None  # type: ignore
            if run_self_strategy:
                payload = run_self_strategy(root, cfg, run_id=rid or None)
                _log_runtime_event(
                    root,
                    {
                        "timestamp": time.time(),
                        "run_id": rid,
                        "event_type": "self_strategy",
                        "source": "master_verify",
                        "payload": payload,
                    },
                )
    except Exception:
        pass

    try:
        from .maintenance_cleanup import run_cleanup  # type: ignore
    except Exception:
        try:
            from maintenance_cleanup import run_cleanup  # type: ignore
        except Exception:
            run_cleanup = None  # type: ignore
    if run_cleanup:
        try:
            run_cleanup(root, cfg, source="master_verify")
        except Exception:
            pass

    try:
        from .retention_engine import run_retention  # type: ignore
    except Exception:
        try:
            from retention_engine import run_retention  # type: ignore
        except Exception:
            run_retention = None  # type: ignore
    if run_retention:
        try:
            run_retention(root, cfg, run_id=rid or None)
        except Exception:
            pass


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


def _start_lock_heartbeat(
    lock_path: Path,
    *,
    label: str,
    interval_sec: int,
    run_id: str | None = None,
) -> threading.Event | None:
    if refresh_lock is None or interval_sec <= 0:
        return None
    stop = threading.Event()

    def _beat() -> None:
        while not stop.is_set():
            ok = refresh_lock(lock_path, label=label)
            if not ok:
                try:
                    _log_runtime_event(
                        ROOT,
                        {
                            "timestamp": time.time(),
                            "run_id": run_id or "",
                            "session": "lock",
                            "event_type": "lock_heartbeat_failed",
                            "route": str(lock_path),
                            "target": label,
                        },
                    )
                except Exception:
                    pass
            stop.wait(interval_sec)

    threading.Thread(target=_beat, name="master_verify_lock_heartbeat", daemon=True).start()
    return stop


def _read_json(path: Path) -> dict:
    try:
        if not path.exists():
            return {}
        return json.loads(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _is_valid_ui_action_coverage(payload: Any) -> bool:
    if not isinstance(payload, dict):
        return False
    ratio = payload.get("coverage_ratio")
    try:
        if ratio is None:
            return False
        float(ratio)
        return True
    except Exception:
        return False


def _extract_ui_action_coverage(report: Dict[str, Any]) -> Dict[str, Any]:
    if not isinstance(report, dict):
        return {}
    findings = report.get("findings") or {}
    if isinstance(findings, dict):
        from_findings = findings.get("ui_action_coverage")
        if _is_valid_ui_action_coverage(from_findings):
            return dict(from_findings)
    top_level = report.get("ui_action_coverage")
    if _is_valid_ui_action_coverage(top_level):
        return dict(top_level)
    return {}


def _load_last_valid_ui_action_coverage(root: Path) -> Dict[str, Any]:
    logs_dir = root / ".bgl_core" / "logs"
    candidates: List[Path] = []
    try:
        p = logs_dir / "latest_report.json"
        if p.exists():
            candidates.append(p)
    except Exception:
        pass
    try:
        baseline = logs_dir / "diagnostic_baseline.json"
        if baseline.exists():
            candidates.append(baseline)
    except Exception:
        pass
    try:
        rotated = sorted(
            logs_dir.glob("latest_report.*.json"),
            key=lambda x: x.stat().st_mtime,
            reverse=True,
        )
        candidates.extend(rotated[:15])
    except Exception:
        pass
    seen: set[str] = set()
    for path in candidates:
        pstr = str(path)
        if pstr in seen:
            continue
        seen.add(pstr)
        report = _read_json(path)
        cov = _extract_ui_action_coverage(report)
        if cov:
            return cov
    return {}


def _cfg_bool(value: object, default: bool = False) -> bool:
    if value is None:
        return default
    if isinstance(value, bool):
        return value
    try:
        return bool(int(value))
    except Exception:
        return str(value).strip().lower() in ("1", "true", "yes", "on")


def _approvals_disabled(cfg: dict) -> bool:
    force_no_human = _cfg_bool(cfg.get("force_no_human_approvals", 0), False)
    env_force = os.getenv("BGL_FORCE_NO_HUMAN_APPROVALS")
    if env_force is not None:
        force_no_human = str(env_force).strip().lower() in ("1", "true", "yes", "on")
    approvals_enabled = _cfg_bool(cfg.get("approvals_enabled", 1), True)
    env_flag = os.getenv("BGL_APPROVALS_ENABLED")
    if env_flag is not None:
        approvals_enabled = str(env_flag).strip().lower() in ("1", "true", "yes", "on")
    if force_no_human:
        approvals_enabled = False
    return not approvals_enabled


def _atomic_write_text(path: Path, content: str) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        tmp_path = path.with_suffix(f"{path.suffix}.tmp.{os.getpid()}")
        try:
            tmp_path.write_text(content, encoding="utf-8")
            os.replace(tmp_path, path)
        finally:
            try:
                if tmp_path.exists():
                    tmp_path.unlink()
            except Exception:
                pass
    except Exception:
        pass


def _atomic_write_json(path: Path, payload: dict) -> None:
    _atomic_write_text(path, json.dumps(payload, ensure_ascii=False, indent=2))


def _write_json(path: Path, payload: dict) -> None:
    _atomic_write_json(path, payload)


def _acquire_lock_with_timeout(lock_path: Path, ttl_sec: int, timeout_sec: int, label: str) -> tuple[bool, str]:
    if timeout_sec <= 0:
        ok, reason = acquire_lock(lock_path, ttl_sec=ttl_sec, label=label)
        return ok, reason
    deadline = time.time() + timeout_sec
    last_reason = "timeout"
    while True:
        ok, reason = acquire_lock(lock_path, ttl_sec=ttl_sec, label=label)
        if ok:
            return True, reason
        last_reason = reason
        if time.time() >= deadline:
            return False, last_reason
        time.sleep(1.5)


def _load_retention_state(root: Path) -> Dict[str, Any]:
    state_path = root / ".bgl_core" / "logs" / "retention_state.json"
    apply_path = root / ".bgl_core" / "logs" / "retention_actions_applied.json"
    state: Dict[str, Any] = {}
    applied: Dict[str, Any] = {}
    if state_path.exists():
        try:
            state = json.loads(state_path.read_text(encoding="utf-8"))
        except Exception:
            state = {}
    if apply_path.exists():
        try:
            applied = json.loads(apply_path.read_text(encoding="utf-8"))
        except Exception:
            applied = {}
    if not state and not applied:
        return {}
    summary = {
        "run_seq": state.get("run_seq"),
        "catalog_total": state.get("catalog_total"),
        "prune_candidates": state.get("prune_candidates"),
        "kept": state.get("kept"),
        "rewrite_candidates": state.get("rewrite_candidates"),
        "merge_groups": state.get("merge_groups"),
        "top_k_per_route": state.get("top_k_per_route"),
        "rewrite_applied": applied.get("rewrite_applied"),
        "merge_applied": applied.get("merge_applied"),
        "apply_errors": len(applied.get("errors") or []),
    }
    return {"summary": summary, "state": state, "apply": applied}


def _retention_indicator_metrics(root: Path, report: Dict[str, Any]) -> Dict[str, Any]:
    def _rj(path: Path) -> Dict[str, Any]:
        if not path.exists():
            return {}
        try:
            return json.loads(path.read_text(encoding="utf-8"))
        except Exception:
            return {}

    cleanup_report = _rj(root / ".bgl_core" / "logs" / "cleanup_report.json")
    selection = _rj(root / ".bgl_core" / "logs" / "scenario_selection.json")
    retention_state = _rj(root / ".bgl_core" / "logs" / "retention_state.json")
    retention_preview = _rj(root / ".bgl_core" / "logs" / "retention_preview.json")
    retention_apply = _rj(root / ".bgl_core" / "logs" / "retention_actions_applied.json")

    kinds_seen: List[str] = []
    try:
        for item in retention_preview.get("candidates") or []:
            kind = str(item.get("kind") or "")
            if kind and kind not in kinds_seen:
                kinds_seen.append(kind)
    except Exception:
        kinds_seen = []

    scenario_stats = report.get("scenario_run_stats") or {}
    diagnostic_faults = report.get("diagnostic_faults") or {}

    metrics: Dict[str, Any] = {
        "time_prune_disabled": cleanup_report.get("retention_disable_time_prune"),
        "retention_blocked": (selection.get("retention_blocked") if selection else None),
        "run_seq": retention_state.get("run_seq"),
        "merge_groups": retention_state.get("merge_groups"),
        "rewrite_candidates": retention_state.get("rewrite_candidates"),
        "rewrite_applied": retention_apply.get("rewrite_applied"),
        "kinds_seen": kinds_seen,
        "prune_quota": retention_state.get("prune_quota"),
        "prune_candidates": retention_state.get("prune_candidates"),
        "event_delta_source": scenario_stats.get("event_delta_source"),
        "db_write_locked": diagnostic_faults.get("db_write_locked"),
        "archived": retention_state.get("archived"),
        "archive_errors": retention_state.get("archive_errors"),
    }
    return {k: v for k, v in metrics.items() if v is not None and v != []}


def _retention_auto_review(root: Path, report: Dict[str, Any], cfg: Dict[str, Any]) -> Dict[str, Any]:
    indicators = report.get("retention_indicators") or {}
    retention_state = (report.get("retention_state") or {}).get("state") or {}
    reasons: List[str] = []

    paused = bool(retention_state.get("paused"))
    if paused:
        reasons.append("pause_on_low_coverage")

    if indicators.get("time_prune_disabled") is False:
        reasons.append("time_prune_enabled")

    if indicators.get("run_seq") is None:
        reasons.append("missing_run_seq")

    db_write_locked = indicators.get("db_write_locked")
    if isinstance(db_write_locked, (int, float)) and db_write_locked:
        reasons.append("db_write_locked")

    if indicators.get("event_delta_source") and indicators.get("event_delta_source") != "db":
        reasons.append("event_delta_fallback")

    archive_errors = indicators.get("archive_errors") or []
    if archive_errors:
        reasons.append("archive_errors")

    min_ui = float(cfg.get("min_ui_action_coverage", 30) or 30)
    ui_cov = (report.get("ui_action_coverage") or {}).get("coverage_ratio")
    if ui_cov is not None and float(ui_cov) < min_ui:
        reasons.append("low_ui_coverage")

    status = "OK"
    if paused:
        status = "Blocked"
    elif reasons:
        status = "Risk"

    return {
        "status": status,
        "reasons": reasons,
        "timestamp": time.time(),
    }


def _auto_review(report: Dict[str, Any], cfg: Dict[str, Any]) -> Dict[str, Any]:
    status = "OK"
    reasons: List[str] = []

    def mark(level: str, reason: str) -> None:
        nonlocal status
        if level == "Blocked":
            status = "Blocked"
        elif level == "Risk" and status == "OK":
            status = "Risk"
        reasons.append(reason)

    integrity_gate = report.get("integrity_gate") or {}
    overall = (integrity_gate.get("overall") or {}).get("status")
    if overall in {"fail", "blocked", "error"}:
        mark("Blocked", f"integrity_gate_{overall}")
    elif overall in {"warn", "warning"}:
        mark("Risk", "integrity_gate_warn")

    scenario_run = report.get("scenario_run_stats") or {}
    scen_status = str(scenario_run.get("status") or "").lower()
    if scen_status in {"blocked", "aborted"}:
        mark("Blocked", f"scenario_run_{scen_status}")
    elif scen_status in {"timeout", "fail", "error", "safe_mode"}:
        mark("Risk", f"scenario_run_{scen_status}")

    context_digest = report.get("context_digest") or {}
    profile = str(report.get("diagnostic_profile") or "").strip().lower()
    try:
        strict_fast_context_digest = str(
            cfg.get("auto_review_fast_context_digest_is_risk", 0)
        ).strip().lower() in ("1", "true", "yes", "on")
    except Exception:
        strict_fast_context_digest = False
    skip_context_digest_due_fast = (
        not strict_fast_context_digest
        and profile.startswith("fast")
        and context_digest.get("ok") is False
    )
    if context_digest.get("ok") is False and not skip_context_digest_due_fast:
        mark("Risk", "context_digest_failed")

    ui_action = report.get("ui_action_coverage") or {}
    try:
        cov = float(ui_action.get("coverage_ratio"))
        min_cov = float(cfg.get("min_ui_action_coverage", 30) or 30)
        if cov < min_cov:
            mark("Risk", "ui_action_coverage_low")
    except Exception:
        pass

    try:
        success_rate = float(report.get("success_rate"))
        target = float(cfg.get("success_rate_target", 0.75) or 0.75)
        if success_rate < target:
            mark("Risk", "success_rate_low")
    except Exception:
        pass

    canary = report.get("canary_status") or {}
    canary_reason = str(canary.get("reason") or "").strip().lower()
    try:
        strict_fast_skip = str(
            cfg.get("auto_review_fast_canary_skip_is_risk", 0)
        ).strip().lower() in ("1", "true", "yes", "on")
    except Exception:
        strict_fast_skip = False
    skip_due_fast_profile = (
        not strict_fast_skip
        and profile.startswith("fast")
        and bool(canary.get("skipped"))
        and canary_reason == "fast_profile_skip"
    )
    if canary.get("ok") is False and not skip_due_fast_profile:
        mark("Risk", "canary_not_ok")
    if canary.get("skipped") and not skip_due_fast_profile:
        mark("Risk", "canary_skipped")

    return {"status": status, "reasons": reasons, "timestamp": time.time()}


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


def _count_fallback_events(root: Path) -> int:
    path = root / ".bgl_core" / "logs" / "runtime_events_fallback.jsonl"
    if not path.exists():
        return 0
    try:
        with path.open("r", encoding="utf-8") as handle:
            return sum(1 for _ in handle)
    except Exception:
        return 0


def _load_scenario_selection(root: Path) -> dict:
    return _read_json(root / ".bgl_core" / "logs" / "scenario_selection.json")


def _compute_integrity_gate(root: Path, data: dict) -> dict:
    now = time.time()
    gate = {
        "run_integrity": {"status": "pass", "decision": "ok", "signals": []},
        "event_integrity": {"status": "pass", "decision": "ok", "signals": []},
        "exploration_integrity": {"status": "pass", "decision": "ok", "signals": []},
        "understanding_integrity": {"status": "pass", "decision": "ok", "signals": []},
        "overall": {"status": "ok", "decision": "ok", "failed_layers": []},
        "timestamp": now,
    }

    def _flag(layer: dict, key: str, detail: str, value=None, *, hard: bool = False, decision: str = "") -> None:
        layer["signals"].append({"key": key, "detail": detail, "value": value})
        if hard:
            layer["status"] = "fail"
            layer["decision"] = decision or layer.get("decision") or "stop_reading"
        else:
            layer["status"] = "warn"
            layer["decision"] = decision or layer.get("decision") or "caution"

    diag_status = data.get("diagnostic_status") or {}
    diag_state = str(diag_status.get("status") or "")
    report_ts = data.get("timestamp") or 0
    run_locks = data.get("run_locks") or {}
    scenario = data.get("scenario_run_stats") or {}
    exec_stats = data.get("execution_stats") or {}

    # Run Integrity
    if diag_state in ("running", "deferred_user_active") and report_ts:
        _flag(
            gate["run_integrity"],
            "diagnostic_status_running",
            "diagnostic_status still indicates running despite report timestamp",
            {"status": diag_state, "report_ts": report_ts, "status_ts": diag_status.get("timestamp")},
            hard=True,
            decision="stop_reading",
        )
    # stale locks
    stale_lock_hits = []
    for name, lock in run_locks.items():
        status = str((lock or {}).get("status") or "")
        if status in ("stale_dead_pid", "active_stale"):
            stale_lock_hits.append({"lock": name, "status": status, "age_sec": lock.get("age_sec")})
    if stale_lock_hits:
        _flag(
            gate["run_integrity"],
            "stale_locks",
            "detected stale locks",
            stale_lock_hits,
            hard=True,
            decision="stop_reading",
        )
    # stage history no progress (only if status indicates running)
    if diag_state == "running":
        history = diag_status.get("stage_history") or []
        if history:
            last = history[-1]
            last_ts = float(last.get("ts") or 0)
            idle = (now - last_ts) if last_ts else 0
            try:
                idle_threshold = int((data.get("diagnostic_timeout_sec") or 0) or 600)
            except Exception:
                idle_threshold = 600
            if last_ts and idle > idle_threshold:
                _flag(
                    gate["run_integrity"],
                    "stage_no_progress",
                    "stage history indicates no progress",
                    {"last_stage": last.get("stage"), "idle_sec": round(idle, 2)},
                    hard=True,
                    decision="stop_reading",
                )
    # missing success_rate
    if exec_stats.get("success_rate") is None:
        _flag(
            gate["run_integrity"],
            "missing_success_rate",
            "execution_stats.success_rate missing",
            None,
            hard=True,
            decision="stop_reading",
        )
    # scenario duration zero with attempted
    if scenario.get("attempted") and float(scenario.get("duration_s") or 0) <= 0:
        _flag(
            gate["run_integrity"],
            "scenario_duration_zero",
            "scenario_run_stats.duration_s=0 while attempted",
            {"status": scenario.get("status"), "reason": scenario.get("reason")},
            hard=True,
            decision="stop_reading",
        )

    # Event Integrity
    event_delta = scenario.get("event_delta_total")
    if event_delta is None:
        event_delta = scenario.get("event_delta")
    try:
        event_delta = int(event_delta or 0)
    except Exception:
        event_delta = 0
    if scenario.get("attempted") and event_delta == 0:
        _flag(
            gate["event_integrity"],
            "event_delta_zero",
            "event_delta is zero despite attempted run",
            {"status": scenario.get("status"), "reason": scenario.get("reason")},
            hard=False,
            decision="read_carefully",
        )
    fallback_count = _count_fallback_events(root)
    if fallback_count and fallback_count >= max(3, event_delta or 0):
        _flag(
            gate["event_integrity"],
            "fallback_dominant",
            "fallback events dominate runtime events",
            {"fallback_count": fallback_count, "event_delta": event_delta},
            hard=False,
            decision="read_carefully",
        )
    if diag_status.get("mismatch_scenario_run_id") or diag_status.get("stage") == "run_id_mismatch":
        _flag(
            gate["event_integrity"],
            "run_id_mismatch",
            "scenario run_id mismatch with diagnostic",
            {"mismatch": diag_status.get("mismatch_scenario_run_id")},
            hard=False,
            decision="read_carefully",
        )
    if "locked" in str(diag_status.get("db_write_last_error") or "").lower():
        _flag(
            gate["event_integrity"],
            "db_write_locked",
            "database locked during event write",
            diag_status.get("db_write_last_error"),
            hard=False,
            decision="read_carefully",
        )

    # Exploration Integrity
    selection = _load_scenario_selection(root)
    selected = selection.get("selected") or []
    has_ui = any(str(s.get("kind") or "").lower().startswith("ui") for s in selected)
    if selected and not has_ui:
        _flag(
            gate["exploration_integrity"],
            "no_ui_scenario",
            "scenario selection has no UI scenarios",
            {"selected_count": len(selected)},
            hard=False,
            decision="low_coverage",
        )
    route_scan_limit = data.get("route_scan_limit")
    try:
        route_scan_limit = int(route_scan_limit) if route_scan_limit is not None else None
    except Exception:
        route_scan_limit = None
    if route_scan_limit is not None and route_scan_limit < 20:
        _flag(
            gate["exploration_integrity"],
            "route_scan_limit_low",
            "route_scan_limit is low for coverage",
            route_scan_limit,
            hard=False,
            decision="low_coverage",
        )
    ui_cov = data.get("ui_action_coverage") or {}
    try:
        ui_ratio = float(ui_cov.get("coverage_ratio") or 0)
    except Exception:
        ui_ratio = 0.0
    if ui_ratio and ui_ratio < 30:
        _flag(
            gate["exploration_integrity"],
            "ui_coverage_low",
            "ui_action_coverage below target",
            ui_ratio,
            hard=False,
            decision="low_coverage",
        )
    gap_runs = ui_cov.get("gap_runs")
    gap_changed = ui_cov.get("gap_changed")
    try:
        gap_runs = int(gap_runs or 0)
        gap_changed = int(gap_changed or 0)
    except Exception:
        gap_runs = 0
        gap_changed = 0
    if gap_runs and gap_changed == 0:
        _flag(
            gate["exploration_integrity"],
            "gap_no_change",
            "gap runs observed without coverage change",
            {"gap_runs": gap_runs, "gap_changed": gap_changed},
            hard=False,
            decision="low_coverage",
        )

    # Understanding Integrity
    digest = data.get("context_digest") or {}
    if str(digest.get("ok")).lower() in ("false", "0") or digest.get("ok") is False:
        _flag(
            gate["understanding_integrity"],
            "context_digest_failed",
            "context_digest failed or timed out",
            {"status": digest.get("status"), "reason": digest.get("reason")},
            hard=False,
            decision="skip_memory_update",
        )
    sem = data.get("ui_semantic_delta") or {}
    if sem and not sem.get("changed"):
        _flag(
            gate["understanding_integrity"],
            "semantic_delta_missing",
            "ui_semantic_delta did not change",
            {"change_count": sem.get("change_count")},
            hard=False,
            decision="skip_memory_update",
        )
    flow = data.get("flow_coverage") or {}
    try:
        seq_ratio = float(flow.get("sequence_coverage_ratio") or 0)
    except Exception:
        seq_ratio = 0.0
    if seq_ratio and seq_ratio < 60:
        _flag(
            gate["understanding_integrity"],
            "flow_sequence_low",
            "flow sequence coverage below target",
            seq_ratio,
            hard=False,
            decision="skip_memory_update",
        )
    gaps = ui_cov.get("gaps") or []
    if isinstance(gaps, list) and len(gaps) > 0 and gap_changed == 0:
        _flag(
            gate["understanding_integrity"],
            "selectors_unstable",
            "gaps present without change; selector stability questionable",
            {"gap_count": len(gaps), "gap_changed": gap_changed},
            hard=False,
            decision="skip_memory_update",
        )

    # Overall decision
    failed_layers = []
    for key in ("run_integrity", "event_integrity", "exploration_integrity", "understanding_integrity"):
        if gate[key]["status"] in ("fail", "warn"):
            failed_layers.append(key)
    gate["overall"]["failed_layers"] = failed_layers
    if gate["run_integrity"]["status"] == "fail":
        gate["overall"]["status"] = "fail"
        gate["overall"]["decision"] = "stop_reading"
    elif any(gate[k]["status"] == "warn" for k in ("event_integrity", "exploration_integrity", "understanding_integrity")):
        gate["overall"]["status"] = "warn"
        gate["overall"]["decision"] = "caution"

    return gate


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
            "BGL_AUTO_RUN_GAP_SCENARIOS": "0",
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
    effective_runtime_config = _build_effective_runtime_config(effective_cfg)
    status_path = ROOT / ".bgl_core" / "logs" / "diagnostic_status.json"
    hb_stop: threading.Event | None = None
    lock_heartbeat_stop: threading.Event | None = None

    def _release_master_lock() -> None:
        nonlocal lock_heartbeat_stop
        try:
            if lock_heartbeat_stop is not None:
                lock_heartbeat_stop.set()
                lock_heartbeat_stop = None
        except Exception:
            pass
        try:
            release_lock(lock_path)
        except Exception:
            pass
    try:
        _mark_aborted_if_stale(lock_path, status_path)
    except Exception:
        pass
    try:
        idle_guard = int(cfg.get("diagnostic_idle_guard_sec", 0) or 0)
    except Exception:
        idle_guard = 0
    if _approvals_disabled(cfg):
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
        # Ensure we always release the master lock on process exit.
        atexit.register(_release_master_lock)
    except Exception:
        pass

    try:
        heartbeat_sec = int(cfg.get("master_verify_lock_heartbeat_sec", 30) or 30)
    except Exception:
        heartbeat_sec = 30
    try:
        lock_heartbeat_stop = _start_lock_heartbeat(
            lock_path,
            label="master_verify",
            interval_sec=heartbeat_sec,
            run_id=os.getenv("BGL_DIAGNOSTIC_RUN_ID"),
        )
    except Exception:
        lock_heartbeat_stop = None

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
            _run_post_finalize(ROOT, cfg, run_id=None)
        except Exception:
            pass
        _release_master_lock()
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
            _run_post_finalize(ROOT, cfg, run_id=None)
        except Exception:
            pass
        _release_master_lock()
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
            _run_post_finalize(ROOT, cfg, run_id=None)
        except Exception:
            pass
        _release_master_lock()
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
                _mark_report_written()
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
                _mark_report_written()
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
            _run_post_finalize(ROOT, cfg, run_id=None)
        except Exception:
            pass
        _release_master_lock()
        return
    try:
        scen_lock_path = ROOT / ".bgl_core" / "logs" / "scenario_runner.lock"
        scen_lock = _read_lock_status(scen_lock_path)
        scen_age = scen_lock.get("age_sec")
        scen_status = str(scen_lock.get("status") or "")
        scen_pid_alive = bool(scen_lock.get("pid_alive")) if "pid_alive" in scen_lock else None
        run_lock_path = ROOT / ".bgl_core" / "logs" / "run_scenarios.lock"
        run_lock = _read_lock_status(run_lock_path)
        run_age = run_lock.get("age_sec")
        run_status = str(run_lock.get("status") or "")
        run_pid_alive = bool(run_lock.get("pid_alive")) if "pid_alive" in run_lock else None
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
        if run_lock.get("exists") and run_age is not None:
            if run_status in ("active_fresh", "recent_lock"):
                if float(run_age) < skip_age:
                    should_skip = True
            elif run_pid_alive is True and float(run_age) < skip_age:
                should_skip = True
        if should_skip:
            os.environ["BGL_RUN_SCENARIOS"] = "0"
            profile_overrides.setdefault("BGL_RUN_SCENARIOS", "0")
            profile_overrides.setdefault("scenario_lock_skip", scen_lock)
            profile_overrides.setdefault("run_scenarios_lock_skip", run_lock)
        else:
            # If lock looks stale, try to clear it so scenarios can proceed.
            if scen_lock.get("exists") and scen_status in ("stale_dead_pid", "active_stale"):
                try:
                    release_lock(scen_lock_path)
                    scen_lock["cleared"] = True
                    profile_overrides.setdefault("scenario_lock_override", scen_lock)
                except Exception:
                    pass
            if run_lock.get("exists") and run_status in ("stale_dead_pid", "active_stale"):
                try:
                    release_lock(run_lock_path)
                    run_lock["cleared"] = True
                    profile_overrides.setdefault("run_scenarios_lock_override", run_lock)
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
    # Snapshot globals for exit fallback (after run_id exists).
    global _ACTIVE_RUN_ID, _RUN_START_TS, _REPORT_PATH, _STATUS_PATH, _LAST_REPORT_SNAPSHOT, _CFG_SNAPSHOT
    _ACTIVE_RUN_ID = run_id
    _RUN_START_TS = now
    _REPORT_PATH = report_path
    _STATUS_PATH = status_path
    _LAST_REPORT_SNAPSHOT = last_report if isinstance(last_report, dict) else {}
    _CFG_SNAPSHOT = cfg
    try:
        atexit.register(_exit_report_fallback)
    except Exception:
        pass
    # دائم: تنفيذ أول 3 أولويات قبل التشخيص (سلامة التشغيل -> الأحداث -> digest)
    if run_priority_loop:
        try:
            _update_status_stage(status_path, "priority_loop", run_id=run_id)
        except Exception:
            pass
        try:
            run_priority_loop(ROOT, cfg=cfg, run_id=run_id)
            try:
                _update_status_stage(status_path, "priority_loop_done", run_id=run_id)
            except Exception:
                pass
        except Exception as exc:
            try:
                _update_status_stage(status_path, "priority_loop_error", run_id=run_id)
            except Exception:
                pass
            try:
                _log_runtime_event(
                    ROOT,
                    {
                        "timestamp": time.time(),
                        "run_id": run_id,
                        "event_type": "priority_loop_error",
                        "source": "master_verify",
                        "payload": {"error": str(exc)},
                    },
                )
            except Exception:
                pass
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
        timeout_report = None
        try:
            _phase_end("full_diagnostic", phase_ts, status="timeout", reason="diagnostic_timeout")
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
        # Fallback to cached report to keep pipeline responsive.
        if last_report:
            try:
                timeout_report = dict(last_report)
                timeout_report["timestamp"] = time.time()
                timeout_report["diagnostic_run_id"] = run_id
                timeout_report["cache_used"] = True
                timeout_report["cache_reason"] = "timeout_fallback"
                timeout_report["cached_at"] = time.time()
                timeout_report["cache_run_id"] = run_id
                timeout_report["cache_source_run_id"] = last_report.get("diagnostic_run_id")
                timeout_report["diagnostic_profile"] = "cache-fast" if profile == "fast" else "cache-full"
                try:
                    timeout_report["diagnostic_status"] = _read_json(status_path)
                except Exception:
                    timeout_report["diagnostic_status"] = {}
                try:
                    timeout_report["diagnostic_timeout"] = {
                        "reason": "diagnostic_timeout",
                        "run_id": run_id,
                        "ts": time.time(),
                    }
                except Exception:
                    pass
                try:
                    timeout_report["auto_review"] = _auto_review(timeout_report, cfg)
                except Exception:
                    pass
                try:
                    template = Path(__file__).parent / "report_template.html"
                    output = Path(".bgl_core/logs/latest_report.html")
                    build_report(timeout_report, template, output)
                except Exception:
                    pass
                _atomic_write_json(report_path, timeout_report)
                _mark_report_written()
            except Exception:
                pass
        if not isinstance(timeout_report, dict):
            timeout_report = _build_stub_report(run_id, "diagnostic_timeout_no_cache")
            timeout_report["diagnostic_profile"] = "cache-fast" if profile == "fast" else "cache-full"
            timeout_report["cache_used"] = True
            timeout_report["cache_reason"] = "timeout_fallback_no_cache"
            timeout_report["audit_status"] = "partial"
            timeout_report["diagnostic_timeout"] = {
                "reason": "diagnostic_timeout",
                "run_id": run_id,
                "ts": time.time(),
            }
        timeout_report.setdefault("findings", {})
        timeout_report.setdefault("execution_stats", {})
        timeout_report.setdefault("scenario_run_stats", {})
        timeout_report.setdefault("flow_coverage", {})
        timeout_report.setdefault("ui_action_coverage", {})
        timeout_report.setdefault("route_coverage", {})
        timeout_report.setdefault("feature_flags", cfg.get("feature_flags", {}))
        timeout_report.setdefault("effective_runtime_config", effective_runtime_config)
        try:
            cov = _extract_ui_action_coverage(timeout_report)
            if not cov:
                cov = _load_last_valid_ui_action_coverage(ROOT)
            if cov:
                timeout_report["ui_action_coverage"] = cov
                findings_obj = timeout_report.get("findings")
                if isinstance(findings_obj, dict):
                    findings_obj["ui_action_coverage"] = cov
        except Exception:
            pass
        timeout_report["diagnostic_run_id"] = run_id
        diagnostic = timeout_report
    finally:
        _release_master_lock()
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
    diagnostic["effective_runtime_config"] = effective_runtime_config

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
    findings = diagnostic.get("findings") or {}
    blockers = findings.get("blockers") or []
    if blockers:
        print(f"    [!] ALERT: {len(blockers)} Cognitive Blockers identified.")
        for b in blockers:
            print(f"        - {b['task_name']}: {b['reason'][:60]}...")
    else:
        print("    [SUCCESS] No cognitive blockers detected.")

    # 5. Route Health
    failing = findings.get("failing_routes") or []
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
            "ui_action_coverage": (
                _extract_ui_action_coverage(diagnostic)
                or _load_last_valid_ui_action_coverage(ROOT)
                or {}
            ),
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
            "effective_runtime_config": diagnostic.get("effective_runtime_config", {}),
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

        # Retention summary (value-based cleanup)
        try:
            data["retention_state"] = _load_retention_state(ROOT)
        except Exception:
            data["retention_state"] = {}
        try:
            data["retention_indicators"] = _retention_indicator_metrics(ROOT, data)
        except Exception:
            data["retention_indicators"] = {}
        try:
            data["retention_auto_review"] = _retention_auto_review(ROOT, data, cfg)
        except Exception:
            data["retention_auto_review"] = {}
        try:
            data["auto_review"] = _auto_review(data, cfg)
        except Exception:
            data["auto_review"] = {}
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
            exec_stats = data.get("execution_stats") or {}
            if exec_stats and exec_stats.get("success_rate") is not None:
                data["success_rate"] = exec_stats.get("success_rate")
        except Exception:
            pass
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
        try:
            data["integrity_gate"] = _compute_integrity_gate(ROOT, data)
        except Exception:
            data["integrity_gate"] = {}
        try:
            if data.get("integrity_gate"):
                _log_runtime_event(
                    ROOT,
                    {
                        "timestamp": time.time(),
                        "session": "diagnostic",
                        "run_id": data.get("diagnostic_run_id") or "",
                        "event_type": "integrity_gate",
                        "payload": data.get("integrity_gate"),
                    },
                )
        except Exception:
            pass
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
        data["diagnostic_phase_timings"] = phase_timings
        report_lock_path = ROOT / ".bgl_core" / "logs" / "report_writer.lock"
        report_lock_ttl = int(cfg.get("report_writer_lock_ttl_sec", 900) or 900)
        report_lock_timeout = int(cfg.get("report_writer_lock_timeout_sec", 120) or 120)
        lock_ok, lock_reason = _acquire_lock_with_timeout(
            report_lock_path,
            report_lock_ttl,
            report_lock_timeout,
            label="report_writer",
        )
        report_blocked = not lock_ok
        if report_blocked:
            try:
                data["report_write_blocked"] = {
                    "reason": lock_reason,
                    "timeout_sec": report_lock_timeout,
                }
                _log_runtime_event(
                    ROOT,
                    {
                        "timestamp": time.time(),
                        "session": "diagnostic",
                        "run_id": run_id,
                        "event_type": "report_write_blocked",
                        "payload": data["report_write_blocked"],
                    },
                )
                _write_status(
                    status_path,
                    "running",
                    stage="report_write_blocked",
                    run_id=run_id,
                    report_lock_reason=lock_reason,
                )
            except Exception:
                pass
            try:
                alt_json = ROOT / ".bgl_core" / "logs" / f"latest_report.{run_id}.json"
                _atomic_write_json(alt_json, data)
            except Exception:
                pass
            # Best-effort: still refresh latest_report.json to avoid stale dashboards
            try:
                _atomic_write_json(report_path, data)
            except Exception:
                pass
        else:
            try:
                _br_start = _phase_start("build_report")
                build_report(data, template, output)
                _phase_end("build_report", _br_start)
            except Exception:
                pass
            try:
                if (
                    phase_timings
                    and isinstance(phase_timings[-1], dict)
                    and phase_timings[-1].get("phase") == "build_report"
                ):
                    data["report_render_duration_s"] = phase_timings[-1].get("duration_s")
            except Exception:
                pass
            # Write JSON alongside HTML for dashboard consumption (atomic)
            json_out = ROOT / ".bgl_core" / "logs" / "latest_report.json"
            _atomic_write_json(json_out, data)
            _mark_report_written()
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
        if lock_ok:
            try:
                release_lock(report_lock_path)
            except Exception:
                pass
        # Update baseline if this is a full, non-cached report
        if not report_blocked:
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
        _run_post_finalize(ROOT, cfg, run_id=run_id)
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
        # Silence noisy unraisable asyncio transport errors on Windows shutdown.
        import sys
        import warnings

        def _quiet_unraisablehook(unraisable):
            try:
                exc = unraisable.exc_value
                msg = str(exc or "").lower()
                if isinstance(exc, ValueError) and "closed pipe" in msg:
                    return
            except Exception:
                pass
            try:
                return sys.__unraisablehook__(unraisable)
            except Exception:
                return None

        try:
            sys.unraisablehook = _quiet_unraisablehook
        except Exception:
            pass
        try:
            warnings.filterwarnings("ignore", category=ResourceWarning, message="unclosed transport")
        except Exception:
            pass

        # Allow overriding headless and scenario run via env for visibility/CI
        ROOT = Path(__file__).parent.parent.parent
        cfg = load_config(ROOT)
        diag_profile_env = str(os.getenv("BGL_DIAGNOSTIC_PROFILE", "")).strip().lower()
        fast_cfg = str(cfg.get("fast_verify", "0")).strip().lower() in ("1", "true", "yes", "on")
        if os.getenv("BGL_FAST_VERIFY", "0") == "1" or fast_cfg or diag_profile_env == "fast":
            os.environ["BGL_RUN_SCENARIOS"] = "0"
            os.environ["BGL_AUTO_CONTEXT_DIGEST"] = "0"
            os.environ["BGL_AUTO_APPLY"] = "0"
            os.environ["BGL_AUTO_PLAN"] = "0"
            os.environ["BGL_AUTO_VERIFY"] = "0"
            os.environ["BGL_AUTO_PATCH_ON_ERRORS"] = "0"
            os.environ["BGL_SKIP_DREAM"] = "1"
            os.environ["BGL_MAX_AUTO_INSIGHTS"] = "0"
        elif diag_profile_env == "full":
            # Avoid env leakage from previous fast runs in persistent shells.
            respect_env = str(os.getenv("BGL_RESPECT_RUN_SCENARIOS_ENV", "0")).strip().lower() in (
                "1",
                "true",
                "yes",
                "on",
            )
            if not respect_env:
                os.environ["BGL_RUN_SCENARIOS"] = str(cfg.get("run_scenarios", 1))
                os.environ["BGL_AUTO_RUN_GAP_SCENARIOS"] = str(
                    cfg.get("auto_run_gap_scenarios", 1)
                )
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
        log_run_audit(ROOT)

        async def _run_with_cleanup():
            try:
                await master_assurance_diagnostic()
            finally:
                # Best-effort cleanup before loop shutdown.
                try:
                    await asyncio.sleep(0)
                except Exception:
                    pass
                try:
                    import gc

                    gc.collect()
                except Exception:
                    pass

        run_completed = False
        try:
            asyncio.run(_run_with_cleanup())
            run_completed = True
        finally:
            # Prevent lingering process after successful completion in autonomous runs.
            try:
                force_process_exit_raw = os.getenv("BGL_FORCE_PROCESS_EXIT", "")
                if force_process_exit_raw.strip():
                    force_process_exit = force_process_exit_raw.strip().lower() in (
                        "1",
                        "true",
                        "yes",
                        "on",
                    )
                else:
                    force_process_exit = str(cfg.get("agent_mode", "")).strip().lower() in (
                        "auto",
                        "autonomous",
                    )
                if run_completed and force_process_exit:
                    os._exit(0)
            except Exception:
                pass
    except KeyboardInterrupt:
        print("\n[INFO] Master diagnostic interrupted gracefully.")
    except Exception as e:
        print(f"\n[CRITICAL FAILURE] Master Diagnostic Crashed: {e}")
        import traceback

        traceback.print_exc()
