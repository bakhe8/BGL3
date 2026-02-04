import asyncio
import os
import sqlite3
import time
from pathlib import Path

from config_loader import load_config
from scenario_deps import check_scenario_deps


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
        shadow_mode=False,
    )


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
            error = "Validation Failed: Invalid IBAN"
            latency = float(random.randint(20, 100))
        else:
            status = 500
            error = "Database Connection Timeout"
            latency = float(random.randint(1000, 3000))

        cursor.execute(
            """
            INSERT INTO runtime_events (
                timestamp, session, event_type, route, method, target, payload, status, latency_ms, error
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
            (
                time.time(),
                "sim_session_1",
                "import_banks" if "import" in route else "api_call",
                route,
                method,
                "target_system",
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
    # Prevent concurrent runs
    if LOCK_PATH.exists():
        try:
            data = LOCK_PATH.read_text(encoding="utf-8").strip().split("|")
            pid = int(data[0]) if data and data[0].isdigit() else None
            if pid:
                # Windows-safe liveness check
                try:
                    res = subprocess.run(
                        ["tasklist", "/FI", f"PID eq {pid}"],
                        capture_output=True,
                        text=True,
                        timeout=3,
                    )
                    if str(pid) in (res.stdout or ""):
                        print("[!] Scenario run already in progress; skipping.")
                        return
                except Exception:
                    # If unsure, avoid double-run
                    print("[!] Scenario run lock present; skipping.")
                    return
        except Exception:
            pass

    try:
        LOCK_PATH.parent.mkdir(parents=True, exist_ok=True)
        LOCK_PATH.write_text(f"{os.getpid()}|{time.time()}", encoding="utf-8")
    except Exception:
        # If we can't lock, proceed but warn (better than blocking)
        print("[!] Unable to write scenario lock; proceeding.")

    if os.getenv("BGL_SIMULATE_SCENARIOS", "0") == "1":
        simulate_traffic()
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
        try:
            if LOCK_PATH.exists():
                LOCK_PATH.unlink()
        except Exception:
            pass


if __name__ == "__main__":
    main()
