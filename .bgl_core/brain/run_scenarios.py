import asyncio
import json
import os
import sqlite3
import threading
import time
from pathlib import Path

from config_loader import load_config
from scenario_deps import check_scenario_deps
from run_lock import acquire_lock, release_lock, describe_lock, refresh_lock


ROOT = Path(__file__).parent.parent.parent
DB_PATH = ROOT / ".bgl_core" / "brain" / "knowledge.db"
LOCK_PATH = ROOT / ".bgl_core" / "logs" / "run_scenarios.lock"


def _log_activity(message: str, details: str | dict = "{}"):
    if not DB_PATH.exists():
        return
    try:
        if isinstance(details, dict):
            details = json.dumps(details, ensure_ascii=False)
        with sqlite3.connect(str(DB_PATH)) as conn:
            conn.execute(
                "INSERT INTO agent_activity (timestamp, activity, source, details) VALUES (?, ?, ?, ?)",
                (time.time(), message, "run_scenarios", details),
            )
    except Exception as e:
        try:
            if "locked" in str(e).lower():
                _update_diagnostic_status_stage("db_write_locked")
        except Exception:
            pass


def _detect_trigger_source() -> str:
    explicit = os.getenv("BGL_TRIGGER_SOURCE")
    if explicit:
        return explicit
    if os.getenv("BGL_RUN_AFTER_APPLY") == "1":
        return "auto_apply"
    if os.getenv("BGL_DIAGNOSTIC_RUN_ID"):
        return "master_verify"
    if os.getenv("BGL_AUTONOMOUS_ONLY") == "1":
        return "autonomous_only"
    if os.getenv("BGL_AUTONOMOUS_SCENARIO") == "1":
        return "autonomous_scenario"
    return "manual"


def _update_diagnostic_status_stage(stage: str, run_id: str | None = None) -> None:
    if not os.getenv("BGL_DIAGNOSTIC_RUN_ID"):
        return
    try:
        status_path = ROOT / ".bgl_core" / "logs" / "diagnostic_status.json"
        payload = {}
        if status_path.exists():
            try:
                payload = json.loads(status_path.read_text(encoding="utf-8")) or {}
            except Exception:
                payload = {}
        payload["status"] = payload.get("status") or "running"
        payload["stage"] = stage
        payload["stage_timestamp"] = time.time()
        payload["last_stage_change"] = payload["stage_timestamp"]
        payload["run_id"] = os.getenv("BGL_DIAGNOSTIC_RUN_ID") or payload.get("run_id")
        if run_id:
            payload["scenario_run_id"] = run_id
        try:
            history = payload.get("stage_history")
            if not isinstance(history, list):
                history = []
            entry = {
                "stage": stage,
                "ts": payload["stage_timestamp"],
                "run_id": payload.get("run_id"),
                "source": "run_scenarios",
            }
            if payload.get("scenario_run_id"):
                entry["scenario_run_id"] = payload.get("scenario_run_id")
            history.append(entry)
            if len(history) > 200:
                history = history[-200:]
            payload["stage_history"] = history
        except Exception:
            pass
        payload["timestamp"] = time.time()
        status_path.parent.mkdir(parents=True, exist_ok=True)
        status_path.write_text(
            json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8"
        )
    except Exception:
        pass


def _log_runtime_event(event: dict) -> None:
    if not DB_PATH.exists():
        return
    try:
        with sqlite3.connect(str(DB_PATH), timeout=5.0) as conn:
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
                    event.get("source", "run_scenarios"),
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
                _update_diagnostic_status_stage("db_write_locked")
        except Exception:
            pass


def _start_lock_heartbeat(
    lock_path: Path, label: str, interval_sec: int, run_id: str
) -> threading.Event | None:
    """
    Periodically refresh the lock heartbeat so long-running runs don't go stale.
    Returns a stop Event to terminate the heartbeat, or None if disabled.
    """
    if interval_sec <= 0:
        return None
    stop_event = threading.Event()

    def _beat() -> None:
        while not stop_event.wait(interval_sec):
            ok = refresh_lock(lock_path, label=label)
            if not ok:
                _update_diagnostic_status_stage(
                    "run_scenarios_lock_refresh_failed", run_id=run_id
                )
                _log_runtime_event(
                    {
                        "timestamp": time.time(),
                        "session": "lock",
                        "run_id": run_id,
                        "event_type": "lock_refresh_failed",
                        "route": str(lock_path),
                        "target": "run_scenarios",
                        "payload": {
                            "label": label,
                            "interval_sec": interval_sec,
                        },
                    }
                )
                break

    thread = threading.Thread(
        target=_beat, name="run_scenarios_lock_heartbeat", daemon=True
    )
    thread.start()
    return stop_event


def _ensure_env(cfg: dict):
    # Scenario density defaults: include API scenarios
    include_api = str(cfg.get("scenario_include_api", "1"))
    os.environ.setdefault("BGL_INCLUDE_API", include_api)
    os.environ.setdefault(
        "BGL_EXPLORATION", str(cfg.get("scenario_exploration", "1"))
    )


async def _run_real_scenarios():
    cfg = load_config(ROOT)
    _ensure_env(cfg)
    run_id = str(os.getenv("BGL_RUN_ID") or "")

    base_url = os.getenv("BGL_BASE_URL", cfg.get("base_url", "http://localhost:8000"))
    headless = bool(int(os.getenv("BGL_HEADLESS", str(cfg.get("headless", 1)))))
    keep_open = bool(int(os.getenv("BGL_KEEP_BROWSER", str(cfg.get("keep_browser", 0)))))
    max_pages = int(cfg.get("max_pages", 3))
    idle_timeout = int(cfg.get("page_idle_timeout", 120))
    shadow_mode = os.getenv("BGL_SHADOW_MODE", str(cfg.get("scenario_shadow_mode", 0)))
    shadow_mode = bool(int(shadow_mode))
    include = cfg.get("scenario_include", None)
    if isinstance(include, str) and not include.strip():
        include = None

    from scenario_runner import main as scenario_main

    await scenario_main(
        base_url,
        headless,
        keep_open,
        max_pages=max_pages,
        idle_timeout=idle_timeout,
        include=include,
        shadow_mode=shadow_mode,
    )
    if shadow_mode:
        register_canary_release = None
        try:
            from canary_release import register_canary_release
        except Exception:
            try:
                from .canary_release import register_canary_release  # type: ignore
            except Exception:
                register_canary_release = None
        if register_canary_release:
            try:
                release = register_canary_release(
                    ROOT,
                    DB_PATH,
                    plan_id="scenario_shadow",
                    change_scope=[],
                    source="scenario_shadow",
                    notes=f"shadow_mode=1 include={include or 'all'}",
                )
                _log_activity(
                    "scenario_shadow_canary",
                    {
                        "ok": bool(release.get("ok")),
                        "release_id": release.get("release_id"),
                        "run_id": run_id,
                    },
                )
            except Exception:
                _log_activity("scenario_shadow_canary_failed", {"run_id": run_id})


def simulate_traffic():
    """
    Legacy simulator (disabled by default).
    Enable via BGL_SIMULATE_SCENARIOS=1.
    """
    if not DB_PATH.exists():
        print(f"Error: {DB_PATH} not found.")
        return

    conn = sqlite3.connect(str(DB_PATH))
    cursor = conn.cursor()

    routes = [
        ("/api/create-guarantee.php", "POST"),
        ("/api/update_bank.php", "POST"),
        ("/api/import_suppliers.php", "POST"),
        ("/api/get_dashboard.php", "GET"),
    ]

    print("🚀 Generating 50 simulated requests...")

    import random

    for _ in range(50):
        route, method = random.choice(routes)
        rand = random.random()
        if rand > 0.1:
            status = 200
            error = None
            latency = float(random.randint(50, 300))
        elif rand > 0.02:
            status = 422
            error = "Validation Failed: Invalid contact data"
            latency = float(random.randint(20, 100))
        else:
            status = 500
            error = "Database Connection Timeout"
            latency = float(random.randint(1000, 3000))

        cursor.execute(
            """
            INSERT INTO runtime_events (
                timestamp, session, run_id, source, event_type, route, method, target, step_id, payload, status, latency_ms, error
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
            (
                time.time(),
                "run_sim_session_1",
                "sim_run",
                "simulation",
                "import_banks" if "import" in route else "api_call",
                route,
                method,
                "target_system",
                "sim",
                "{}",
                status,
                latency,
                error,
            ),
        )

    conn.commit()
    conn.close()
    print("✅ Traffic simulation complete. KPIs should now be populated.")


def main():
    cfg = load_config(ROOT)
    run_id = f"run_{int(time.time())}_{os.getpid()}"
    os.environ["BGL_RUN_ID"] = run_id
    trigger = _detect_trigger_source()
    trigger_payload = {
        "run_id": run_id,
        "trigger": trigger,
        "diagnostic_run_id": os.getenv("BGL_DIAGNOSTIC_RUN_ID"),
        "apply_proposal_id": os.getenv("BGL_APPLY_PROPOSAL_ID"),
        "source_env": os.getenv("BGL_TRIGGER_SOURCE"),
    }
    _update_diagnostic_status_stage("run_scenarios_start", run_id=run_id)
    lock_ttl = int(cfg.get("run_scenarios_lock_ttl_sec", 7200))
    try:
        max_age = cfg.get("run_scenarios_lock_max_age_sec")
        if max_age is not None:
            os.environ.setdefault("BGL_SCENARIO_LOCK_MAX_AGE_SEC", str(int(max_age)))
    except Exception:
        pass
    ok, reason = acquire_lock(LOCK_PATH, ttl_sec=lock_ttl, label="run_scenarios")
    lock_heartbeat_stop: threading.Event | None = None
    if not ok:
        lock_info: dict = {}
        try:
            lock_info = describe_lock(LOCK_PATH, ttl_sec=lock_ttl)
            lock_info["reason"] = reason
            lock_info["run_id"] = run_id
            _log_runtime_event(
                {
                    "timestamp": time.time(),
                    "session": "lock",
                    "run_id": run_id,
                    "event_type": "lock_blocked",
                    "route": str(LOCK_PATH),
                    "target": "run_scenarios",
                    "payload": lock_info,
                }
            )
        except Exception:
            pass
        # If the lock looks stale, attempt one safe takeover before skipping.
        try:
            lock_status = (lock_info or {}).get("status")
            if lock_status in ("stale_dead_pid", "active_stale"):
                if release_lock is not None:
                    release_lock(LOCK_PATH)
                ok, reason = acquire_lock(LOCK_PATH, ttl_sec=lock_ttl, label="run_scenarios")
                if ok:
                    _update_diagnostic_status_stage("run_scenarios_lock_recovered", run_id=run_id)
                else:
                    try:
                        lock_info = describe_lock(LOCK_PATH, ttl_sec=lock_ttl)
                    except Exception:
                        lock_info = lock_info or {}
        except Exception:
            pass
        if ok:
            pass
        else:
            print(f"[!] Scenario run already in progress ({reason}); skipping.")
            _update_diagnostic_status_stage("run_scenarios_blocked", run_id=run_id)
            return
    heartbeat_sec = int(cfg.get("run_scenarios_lock_heartbeat_sec", 30))
    lock_heartbeat_stop = _start_lock_heartbeat(
        LOCK_PATH, "run_scenarios", heartbeat_sec, run_id
    )

    if os.getenv("BGL_SIMULATE_SCENARIOS", "0") == "1":
        try:
            simulate_traffic()
        finally:
            if lock_heartbeat_stop:
                lock_heartbeat_stop.set()
            release_lock(LOCK_PATH)
        return

    deps = check_scenario_deps()
    if not deps.ok:
        print("[!] Scenario deps missing:")
        if deps.missing:
            print("    - " + ", ".join(deps.missing))
        if deps.notes:
            for note in deps.notes:
                print(f"    - {note}")
        _log_activity("scenario_deps_missing", str(deps.to_dict()))
        raise SystemExit(2)

    _log_activity("scenario_run_started", trigger_payload)
    _log_runtime_event(
        {
            "timestamp": time.time(),
            "session": "trigger",
            "run_id": run_id,
            "source": "run_scenarios",
            "event_type": "scenario_trigger",
            "target": trigger,
            "payload": trigger_payload,
        }
    )
    try:
        asyncio.run(_run_real_scenarios())
        _log_activity("scenario_run_completed", {"run_id": run_id})
    finally:
        _update_diagnostic_status_stage("run_scenarios_exit", run_id=run_id)
        if lock_heartbeat_stop:
            lock_heartbeat_stop.set()
        release_lock(LOCK_PATH)


if __name__ == "__main__":
    main()
