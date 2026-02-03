import sqlite3
import time
import os
import sys
from pathlib import Path

try:
    from .authority import Authority  # type: ignore
    from .brain_types import ActionRequest, ActionKind  # type: ignore
except Exception:
    from authority import Authority
    from brain_types import ActionRequest, ActionKind


def simulate_agent_task():
    root = Path(__file__).resolve().parents[2]
    auth = Authority(root)
    db_path = str(root / ".bgl_core" / "brain" / "knowledge.db")

    req = ActionRequest(
        kind=ActionKind.PROPOSE,
        operation="task.blocker_log|security_cert_renewal",
        command="simulate_agent_task Security Cert Renewal",
        scope=["knowledge.db:agent_blockers"],
        reason="Log a blocker that requires human intervention.",
        confidence=0.6,
        metadata={"task": "Security Cert Renewal"},
    )
    gate = auth.gate(req, source="agent_tasks")
    decision_id = int(gate.decision_id or 0)

    print("[*] Agent: Starting Task [Sync_Global_Security_Certs]...")
    time.sleep(1)

    print("    - Step 1: Connecting to Secure Vault... OK")
    time.sleep(1)

    print("    - Step 2: Validating Current Certificates... [EXPIRED]")

    print("[!] Agent: Attempting autonomous certificate renewal...")
    time.sleep(2)

    # Simulate a Permission Denial
    error_reason = "Permission Denied: Agent lacks OS-level 'Administrator' rights to modify System Trust Store (SSL/TSL). Manual sudo/run-as-admin required."

    print(f"    [FAILURE] {error_reason}")

    # Log the blocker into the dashboard memory
    try:
        conn = sqlite3.connect(db_path)
        conn.execute(
            """
            INSERT INTO agent_blockers (timestamp, task_name, reason, complexity_level) 
            VALUES (?, ?, ?, ?)
        """,
            (time.time(), "Security Cert Renewal", error_reason, 4),
        )
        conn.commit()
        conn.close()
        print(
            "[+] Agent: Blocker logged to Intelligence Dashboard. Requesting Human Intervention."
        )
        auth.record_outcome(decision_id, "success", "Blocker logged")
    except Exception as e:
        print(f"[ERROR] Failed to log blocker: {e}")
        auth.record_outcome(decision_id, "fail", f"Failed to log blocker: {e}")


if __name__ == "__main__":
    simulate_agent_task()
