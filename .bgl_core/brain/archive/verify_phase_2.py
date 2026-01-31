import json
import time
from pathlib import Path
from orchestrator import BGLOrchestrator

ROOT = Path(__file__).parent.parent.parent


def test_unified_perception():
    """
    Simulates a task execution where both backend and (simulated) frontend errors occur.
    """
    print("[*] Testing Unified Contextual Perception...")

    # 1. Seed a backend log entry
    log_path = ROOT / "storage" / "logs" / "app.log"
    log_path.parent.mkdir(parents=True, exist_ok=True)

    timestamp = time.strftime("%Y-%m-%d %H:%M:%S")
    log_entry = f'[{timestamp}] ERROR: Database Connection Timeout | Context: {{"db":"mysql_main"}}\n'

    with open(log_path, "a") as f:
        f.write(log_entry)

    print(f"    - Seeded backend log: {log_entry.strip()}")

    # 2. Run a task (using a dry run on a valid file)
    orchestrator = BGLOrchestrator(ROOT)
    spec = {
        "task": "add_method",
        "target": {"path": "app/Services/MatchEngine.php"},
        "params": {
            "target_class": "MatchEngine",
            "method_name": "unifiedTest",
            "dry_run": True,
        },
    }

    print("    - Executing task via orchestrator...")
    report = orchestrator.execute_task(spec)

    # 3. Verify unified logs
    logs = report.get("unified_logs", [])
    print(f"    - Captured Logs Count: {len(logs)}")

    backend_found = any(log["source"] == "backend_laravel" for log in logs)
    # Browser audit might fail on port 8000 if nothing is running, adding a network log
    frontend_found = any(log["source"].startswith("frontend") for log in logs)

    if backend_found:
        print("    [SUCCESS] Backend log correctly captured and correlated.")
    else:
        print("    [FAILURE] Backend log missing from report.")

    if frontend_found:
        print("    [SUCCESS] Frontend observability data present.")

    print("\n--- Unified Report Snippet ---")
    print(json.dumps(logs, indent=2))


if __name__ == "__main__":
    test_unified_perception()
