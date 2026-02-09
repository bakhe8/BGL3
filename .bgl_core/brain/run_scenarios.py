import asyncio
import json
import os
import sqlite3
import time
from pathlib import Path

from config_loader import load_config
from scenario_deps import check_scenario_deps
from run_lock import acquire_lock, release_lock


ROOT = Path(__file__).parent.parent.parent
DB_PATH = ROOT / ".bgl_core" / "brain" / "knowledge.db"
LOCK_PATH = ROOT / ".bgl_core" / "logs" / "run_scenarios.lock"


def _log_activity(message: str, details: str = "{}"):
    if not DB_PATH.exists():
        return
    try:
        with sqlite3.connect(str(DB_PATH)) as conn:
            conn.execute(
                "INSERT INTO agent_activity (timestamp, activity, source, details) VALUES (?, ?, ?, ?)",
                (time.time(), message, "run_scenarios", details),
            )
    except Exception:
        pass


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
                    json.dumps({"ok": bool(release.get("ok")), "release_id": release.get("release_id")}),
                )
            except Exception:
                _log_activity("scenario_shadow_canary_failed")


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
    lock_ttl = int(cfg.get("run_scenarios_lock_ttl_sec", 7200))
    ok, reason = acquire_lock(LOCK_PATH, ttl_sec=lock_ttl, label="run_scenarios")
    if not ok:
        print(f"[!] Scenario run already in progress ({reason}); skipping.")
        return

    if os.getenv("BGL_SIMULATE_SCENARIOS", "0") == "1":
        try:
            simulate_traffic()
        finally:
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

    _log_activity("scenario_run_started")
    try:
        asyncio.run(_run_real_scenarios())
        _log_activity("scenario_run_completed")
    finally:
        release_lock(LOCK_PATH)


if __name__ == "__main__":
    main()
