import subprocess
import sys
import shutil
from pathlib import Path

# Paths
ROOT_DIR = Path(__file__).parent.parent
def _preferred_python() -> str:
    candidates = [
        ROOT_DIR / ".bgl_core" / ".venv312" / "Scripts" / "python.exe",
        ROOT_DIR / ".bgl_core" / ".venv" / "Scripts" / "python.exe",
        ROOT_DIR / ".bgl_core" / ".venv312" / "bin" / "python",
        ROOT_DIR / ".bgl_core" / ".venv" / "bin" / "python",
    ]
    for cand in candidates:
        if cand.exists():
            return str(cand)
    return sys.executable


PYTHON_EXE = _preferred_python()
ANALYSIS_SCRIPT = ROOT_DIR / "analysis" / "analyze_trace.py"
METRICS_GUARD = ROOT_DIR / ".bgl_core" / "brain" / "metrics_guard.py"
SCENARIO_RUNNER = ROOT_DIR / ".bgl_core" / "brain" / "scenario_runner.py"
TRACE_LOG = ROOT_DIR / "storage" / "logs" / "traces.jsonl"
RUNTIME_DB = ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db"
LOCK_PATH = ROOT_DIR / ".bgl_core" / "logs" / "verification_cycle.lock"

try:
    sys.path.append(str(ROOT_DIR / ".bgl_core" / "brain"))
    from run_lock import acquire_lock, release_lock  # type: ignore
except Exception:
    acquire_lock = None  # type: ignore
    release_lock = None  # type: ignore


def run_command(cmd, description):
    print(f"--- {description} ---")
    try:
        subprocess.check_call(cmd, cwd=ROOT_DIR)
        print("Success ✅\n")
    except subprocess.CalledProcessError as e:
        print(f"Failed ❌ (Exit Code: {e.returncode})\n")
        sys.exit(e.returncode)


def main():
    print("🚀 Starting Continuous Verification Cycle...\n")
    if acquire_lock is not None:
        ok, reason = acquire_lock(LOCK_PATH, ttl_sec=7200, label="verification_cycle")
        if not ok:
            print(f"[!] Verification cycle already running ({reason}); skipping.")
            return

    # 1. Clean previous artifacts
    if TRACE_LOG.exists():
        TRACE_LOG.unlink()
    # We don't delete knowledge.db to keep learned events, but for a fresh verification cycle
    # we might want to isolate runtime_events. For now, we keep it additive.

    # 2. Run UI Scenarios (Headless)
    # Using 'basic_pages' as baseline
    cmd_ui = [
        PYTHON_EXE,
        str(SCENARIO_RUNNER),
        "--headless",
        "1",
        "--keep-open",
        "0",
        "--max-pages",
        "1",
        "--idle-timeout",
        "300",
        "--base-url",
        "http://localhost:8000",
        "--include",
        "basic_pages",
    ]
    # Set env to enable exploration
    # os.environ["BGL_EXPLORATION"] = "1"
    run_command(cmd_ui, "Running UI Scenarios")

    # 3. Verify Mouse Layer
    cmd_mouse = [
        PYTHON_EXE,
        str(ROOT_DIR / ".bgl_core" / "brain" / "check_mouse_layer.py"),
    ]
    run_command(cmd_mouse, "Verifying Mouse Layer Governance")

    # 4. Analyze Traces (UI + Backend)
    cmd_analysis = [PYTHON_EXE, str(ANALYSIS_SCRIPT)]
    run_command(cmd_analysis, "Analyzing Logic & Performance Traces")

    # 5. Check Metrics Guard
    # Should read analysis/metrics_summary.json if updated
    cmd_guard = [PYTHON_EXE, str(METRICS_GUARD)]
    run_command(cmd_guard, "Checking Performance Guardrails")

    # 6. Shadow Run (Phase 3)
    run_shadow_phase()

    # 7. Concurrency Stress Test (Phase 4)
    cmd_stress = [PYTHON_EXE, str(ROOT_DIR / "scripts" / "stress_test.py")]
    run_command(cmd_stress, "Running Concurrency Stress Test (Phase 4)")

    print("🏁 Verification Cycle Completed Successfully!")
    if release_lock is not None:
        release_lock(LOCK_PATH)


def run_shadow_phase():
    print("--- Running Shadow Verification (Phase 3) ---")

    # Setup Shadow DB
    main_db = ROOT_DIR / "storage" / "database" / "app.sqlite"
    shadow_db = ROOT_DIR / "storage" / "database" / "shadow.sqlite"

    if not main_db.exists():
        print("Main DB not found, skipping shadow run.")
        return

    try:
        shutil.copy2(main_db, shadow_db)
        print("Shadow DB created 📦")
    except Exception as e:
        print(f"Failed to copy DB: {e}")
        return

    # Run Shadow Scenario
    cmd_shadow = [
        PYTHON_EXE,
        str(SCENARIO_RUNNER),
        "--headless",
        "1",
        "--keep-open",
        "0",
        "--max-pages",
        "1",
        "--include",
        "shadow_read",
        "--shadow-mode",
        "1",
    ]

    run_command(cmd_shadow, "Running Shadow Mode Scenarios")


if __name__ == "__main__":
    main()
