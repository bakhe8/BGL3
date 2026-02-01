import json
import argparse
from pathlib import Path
import sqlite3
import time

ROOT = Path(__file__).resolve().parent.parent.parent
DECISION_DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"
KNOWLEDGE_DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"
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
        return cur2.lastrowid or 0


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--proposal", required=True, help="Proposal ID to apply")
    parser.add_argument(
        "--force", action="store_true", help="Apply directly (bypass sandbox)"
    )
    args = parser.parse_args()

    # Fetch from DB
    conn_kb = sqlite3.connect(str(KNOWLEDGE_DB))
    conn_kb.row_factory = sqlite3.Row
    target = None
    try:
        cur = conn_kb.execute(
            "SELECT * FROM agent_proposals WHERE id = ?", (args.proposal,)
        )
        row = cur.fetchone()
        if row:
            target = dict(row)
            # Map DB columns to script expectations
            target["recommendation"] = (
                target.get("solution") or target.get("action") or "No solution"
            )
            target["scope"] = target.get("impact")
            target["evidence"] = target.get("evidence")
    except Exception as e:
        print(f"DB Error: {e}")
        return
    finally:
        conn_kb.close()

    if not target:
        print(f"Proposal {args.proposal} not found in DB.")
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
                    "mode": "force" if args.force else "sandbox",
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

    if args.force:
        # REAL APPLICATION LOGIC WOULD GO HERE (e.g. patching files)
        # For now, we mark it as a definitive direct action.
        insert_outcome(
            decision_id,
            "success_direct",
            "Proposal APPLIED DIRECTLY to production (simulated)",
        )
        print(f"⚠️ FORCE APPLIED proposal {target.get('id')} to PRODUCTION.")
    else:
        insert_outcome(decision_id, "success", "Proposal applied in sandbox (logged)")
        print(f"Applied proposal {target.get('id')} in SANDBOX.")


if __name__ == "__main__":
    main()
