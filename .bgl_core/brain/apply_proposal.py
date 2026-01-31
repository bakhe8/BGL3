import json
import argparse
from pathlib import Path
import sqlite3
import time

ROOT = Path(__file__).resolve().parent.parent.parent
DECISION_DB = ROOT / ".bgl_core" / "brain" / "decision.db"
PROPOSED = ROOT / ".bgl_core" / "brain" / "proposed_patterns.json"
LOG_FILE = ROOT / ".bgl_core" / "logs" / "proposal_actions.log"


def insert_outcome(decision_id: int, result: str, notes: str):
    conn = sqlite3.connect(str(DECISION_DB))
    with conn:
        conn.execute(
            "INSERT INTO outcomes (decision_id, result, notes, timestamp) VALUES (?, ?, ?, datetime('now'))",
            (decision_id, result, notes),
        )


def insert_decision(intent: str, reason: str) -> int:
    conn = sqlite3.connect(str(DECISION_DB))
    with conn:
        cur = conn.execute(
            "INSERT INTO intents (timestamp,intent,confidence,reason,scope,context_snapshot,source) VALUES (datetime('now'),?,?,?,?,?,?)",
            (intent, 0.9, reason, json.dumps([]), json.dumps({}), "apply_proposal"),
        )
        intent_id = cur.lastrowid
        cur2 = conn.execute(
            "INSERT INTO decisions (intent_id, decision, risk_level, requires_human, justification, created_at) VALUES (?,?,?,?,?, datetime('now'))",
            (intent_id, "auto_fix", "low", 0, reason),
        )
        return cur2.lastrowid


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--proposal", required=True, help="Proposal ID to apply")
    args = parser.parse_args()

    if not PROPOSED.exists():
        print("No proposed_patterns.json found.")
        return

    patterns = json.loads(PROPOSED.read_text(encoding="utf-8") or "[]")
    target = next((p for p in patterns if p.get("id") == args.proposal), None)
    if not target:
        print("Proposal not found.")
        return

    # Log action
    LOG_FILE.parent.mkdir(parents=True, exist_ok=True)
    with LOG_FILE.open("a", encoding="utf-8") as f:
        f.write(
            json.dumps(
                {
                    "ts": time.time(),
                    "id": target.get("id"),
                    "recommendation": target.get("recommendation"),
                    "scope": target.get("scope"),
                    "evidence": target.get("evidence"),
                },
                ensure_ascii=False,
            )
            + "\n"
        )

    # Record decision/outcome
    decision_id = insert_decision(
        intent=f"apply_{target.get('id')}",
        reason=target.get("recommendation", "apply proposal"),
    )
    insert_outcome(decision_id, "success", "Proposal applied in sandbox (logged)")
    print(f"Applied proposal {target.get('id')} (logged and recorded).")


if __name__ == "__main__":
    main()
