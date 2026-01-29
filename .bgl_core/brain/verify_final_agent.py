import json
import os
from pathlib import Path
from orchestrator import BGLOrchestrator

ROOT = Path(__file__).parent.parent.parent
orchestrator = BGLOrchestrator(ROOT)


def test_specialized_programming():
    # Target file
    rel_path = "app/Services/AuthManager.php"
    target_file = ROOT / rel_path

    # Ensure file exists
    if not target_file.exists():
        os.makedirs(target_file.parent, exist_ok=True)
        with open(target_file, "w") as f:
            f.write(
                r"<?php"
                + "\n\n"
                + r"namespace App\Services;"
                + "\n\n"
                + r"class AuthManager"
                + "\n"
                + r"{"
                + "\n"
                + r"    // Original format preserved"
                + "\n"
                + r"    public function login() {}"
                + "\n"
                + r"}"
            )

    # Task Spec
    spec = {
        "task": "rename_class",
        "target": {"path": rel_path},
        "params": {
            "old_name": "AuthManager",
            "new_name": "AuthManagerService",
            "dry_run": False,
        },
    }

    res = orchestrator.execute_task(spec)
    print(json.dumps(res, indent=2))


if __name__ == "__main__":
    test_specialized_programming()
