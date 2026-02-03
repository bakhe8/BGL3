import json
import argparse
from pathlib import Path
import sqlite3
import time

try:
    from .authority import Authority  # type: ignore
    from .brain_types import ActionRequest, ActionKind  # type: ignore
except Exception:
    from authority import Authority
    from brain_types import ActionRequest, ActionKind

ROOT = Path(__file__).resolve().parent.parent.parent
KNOWLEDGE_DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"
LOG_FILE = ROOT / ".bgl_core" / "logs" / "proposal_actions.log"


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

    auth = Authority(ROOT)

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

    # Gate + record decision/outcome
    kind = ActionKind.WRITE_PROD if args.force else ActionKind.PROPOSE
    req = ActionRequest(
        kind=kind,
        operation=f"proposal.apply|{args.proposal}" + ("|force" if args.force else ""),
        command=f"apply_proposal --proposal {args.proposal}" + (" --force" if args.force else ""),
        scope=[str(target.get("scope") or "")],
        reason=str(target.get("recommendation", "apply proposal")),
        confidence=0.9,
        metadata={"proposal": target},
    )
    gate = auth.gate(req, source="apply_proposal")
    decision_id = int(gate.decision_id or 0)
    if not gate.allowed:
        print(f"[!] BLOCKED: {gate.message}")
        return

    if args.force:
        # REAL APPLICATION LOGIC WOULD GO HERE (e.g. patching files)
        # For now, we mark it as a definitive direct action.
        auth.record_outcome(
            decision_id, "success_direct", "Proposal APPLIED DIRECTLY to production (simulated)"
        )
        print(f"⚠️ FORCE APPLIED proposal {target.get('id')} to PRODUCTION.")
    else:
        auth.record_outcome(decision_id, "success", "Proposal applied in sandbox (logged)")
        print(f"Applied proposal {target.get('id')} in SANDBOX.")


if __name__ == "__main__":
    main()
