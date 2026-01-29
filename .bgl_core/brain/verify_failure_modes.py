import time
import json
import os
import shutil
from pathlib import Path
from orchestrator import BGLOrchestrator

ROOT = Path(__file__).parent.parent.parent
orchestrator = BGLOrchestrator(ROOT)


def test_atomic_rollback():
    """
    Scenario: Apply a valid patch but force a failure during validation.
    Goal: Verify that the file is rolled back perfectly.
    """
    print("[*] Testing Atomic Rollback...")
    rel_path = "app/Services/MatchEngine.php"
    abs_path = ROOT / rel_path

    # Task: Add method with invalid name to trigger syntax failure
    spec = {
        "task": "add_method",
        "target": {"path": rel_path},
        "params": {
            "target_class": "MatchEngine",
            "method_name": "invalid-method-name",
            "dry_run": False,
        },
    }

    res = orchestrator.execute_task(spec)
    print(f"    - Status: {res['status']}")
    print(f"    - Rollback Performed: {res['rollback_performed']}")

    # Verify file content is still original
    with open(abs_path, "r") as f:
        content = f.read()

    if "invalid-method-name" not in content and "MatchEngine" in content:
        print("    [SUCCESS] File remains pristine after failure.")
    else:
        print("    [FAILURE] File was corrupted!")


def test_sandbox_isolation():
    """
    Scenario: Large change in sandbox.
    Goal: Verify no files are created in main project until final success.
    """
    print("[*] Testing Sandbox Isolation...")
    # We will look for the sandbox directory during execution
    # This is hard to "prove" without a hook, but we can verify the 'changes' list
    spec = {
        "task": "add_method",
        "target": {"path": "app/Services/AuthManager.php"},
        "params": {
            "target_class": "AuthManager",
            "method_name": "sandboxTest",
            "dry_run": True,  # Dry run should NEVER touch main
        },
    }
    res = orchestrator.execute_task(spec)

    # Check if AuthManager.php in main has 'sandboxTest'
    with open(ROOT / "app/Services/AuthManager.php", "r") as f:
        content = f.read()

    if "sandboxTest" not in content:
        print("    [SUCCESS] Dry run correctly isolated.")
    else:
        print("    [FAILURE] Dry run leaked to main project!")


def test_guardrail_barrier():
    """
    Scenario: Accessing forbidden files.
    """
    print("[*] Testing Guardrail Barrier...")
    spec = {
        "task": "rename_class",
        "target": {"path": ".bgl_core/brain/orchestrator.py"},
        "params": {
            "old_name": "BGLOrchestrator",
            "new_name": "HackedOrchestrator",
            "dry_run": False,
        },
    }
    res = orchestrator.execute_task(spec)
    print(f"    - Status: {res['status']}")
    print(f"    - Message: {res['message']}")

    if "Guardrail violation" in res["message"]:
        print("    [SUCCESS] Guardrail blocked self-modification.")
    else:
        print("    [FAILURE] Guardrail failed to block self-modification!")


if __name__ == "__main__":
    test_atomic_rollback()
    test_sandbox_isolation()
    test_guardrail_barrier()
