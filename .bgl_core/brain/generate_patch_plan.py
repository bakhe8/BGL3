import argparse
import json
import sqlite3
import time
from pathlib import Path

try:
    from .plan_generator import generate_plan_from_proposal, PlanGenerationError  # type: ignore
    from .patch_plan import PlanError  # type: ignore
except Exception:
    from plan_generator import generate_plan_from_proposal, PlanGenerationError
    from patch_plan import PlanError

ROOT = Path(__file__).resolve().parent.parent.parent
DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--proposal", required=True, help="Proposal ID")
    parser.add_argument("--output", help="Optional output path (relative)")
    args = parser.parse_args()

    if not DB.exists():
        print("knowledge.db not found.")
        return

    conn = sqlite3.connect(str(DB))
    conn.row_factory = sqlite3.Row
    row = None
    try:
        cur = conn.execute("SELECT * FROM agent_proposals WHERE id = ?", (args.proposal,))
        row = cur.fetchone()
    except Exception as e:
        print(f"DB Error: {e}")
        return
    finally:
        conn.close()

    if not row:
        print(f"Proposal {args.proposal} not found.")
        return

    proposal = dict(row)
    plan = None
    try:
        plan = generate_plan_from_proposal(proposal, ROOT)
    except (PlanGenerationError, PlanError) as e:
        print(f"Plan generation failed: {e}")
        return

    out_dir = ROOT / ".bgl_core" / "patch_plans"
    out_dir.mkdir(parents=True, exist_ok=True)
    if args.output:
        out_path = Path(args.output)
        if not out_path.is_absolute():
            out_path = ROOT / out_path
    else:
        out_path = out_dir / f"auto_{proposal.get('id')}_{int(time.time())}.json"

    out_path.write_text(json.dumps({
        "version": plan.version,
        "id": plan.plan_id,
        "description": plan.description,
        "created_at": plan.created_at,
        "metadata": plan.metadata,
        "operations": [op.__dict__ for op in plan.operations],
    }, ensure_ascii=False, indent=2), encoding="utf-8")

    # Update proposal with plan path
    try:
        conn = sqlite3.connect(str(DB))
        rel = out_path.relative_to(ROOT).as_posix()
        conn.execute("UPDATE agent_proposals SET solution = ? WHERE id = ?", (rel, args.proposal))
        conn.commit()
        conn.close()
    except Exception:
        pass

    print(json.dumps({
        "ok": True,
        "proposal": proposal.get("id"),
        "plan_path": out_path.as_posix(),
    }, ensure_ascii=False))


if __name__ == "__main__":
    main()
