import json
import argparse
import hashlib
from pathlib import Path
import sqlite3
import time
import subprocess
import shutil
from typing import Any, Optional, Tuple

try:
    from .authority import Authority  # type: ignore
    from .brain_types import ActionRequest, ActionKind  # type: ignore
    from .patch_plan import PatchPlan, load_plan, PlanError  # type: ignore
    from .write_engine import WriteEngine  # type: ignore
    from .sandbox import BGLSandbox  # type: ignore
    from .plan_generator import generate_plan_from_proposal, PlanGenerationError  # type: ignore
except Exception:
    from authority import Authority
    from brain_types import ActionRequest, ActionKind
    from patch_plan import PatchPlan, load_plan, PlanError
    from write_engine import WriteEngine
    from sandbox import BGLSandbox
    from plan_generator import generate_plan_from_proposal, PlanGenerationError

ROOT = Path(__file__).resolve().parent.parent.parent
KNOWLEDGE_DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"
LOG_FILE = ROOT / ".bgl_core" / "logs" / "proposal_actions.log"
CHANGE_LOG = ROOT / ".bgl_core" / "logs" / "proposal_changes.jsonl"


def _ensure_proposal_links(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS proposal_outcome_links (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          proposal_id INTEGER,
          decision_id INTEGER,
          outcome_id INTEGER,
          created_at REAL,
          source TEXT
        )
        """
    )
    conn.commit()


def _link_proposal_outcome(
    proposal_id: int, decision_id: int, outcome_id: Optional[int], source: str
) -> None:
    if not proposal_id or not decision_id:
        return
    try:
        conn = sqlite3.connect(str(KNOWLEDGE_DB))
        _ensure_proposal_links(conn)
        conn.execute(
            """
            INSERT INTO proposal_outcome_links
            (proposal_id, decision_id, outcome_id, created_at, source)
            VALUES (?, ?, ?, ?, ?)
            """,
            (int(proposal_id), int(decision_id), int(outcome_id or 0), time.time(), source),
        )
        conn.commit()
        conn.close()
    except Exception:
        pass


def _ensure_learning_events(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS learning_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fingerprint TEXT UNIQUE,
            created_at REAL NOT NULL,
            source TEXT,
            event_type TEXT,
            item_key TEXT,
            status TEXT,
            confidence REAL,
            detail_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_learning_events_time ON learning_events(created_at DESC)"
    )


def _log_learning_event(
    *, proposal_id: int, outcome_id: Optional[int], result: str, notes: str
) -> None:
    try:
        conn = sqlite3.connect(str(KNOWLEDGE_DB))
        _ensure_learning_events(conn)
        fp_src = f"proposal_outcome|{proposal_id}|{outcome_id}|{result}"
        fp = hashlib.sha1(fp_src.encode("utf-8")).hexdigest()
        payload = {
            "proposal_id": proposal_id,
            "outcome_id": outcome_id,
            "result": result,
            "notes": notes,
        }
        conn.execute(
            """
            INSERT INTO learning_events
            (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                fp,
                time.time(),
                "proposal_apply",
                "proposal_outcome",
                f"proposal:{proposal_id}",
                result,
                None,
                json.dumps(payload, ensure_ascii=False),
            ),
        )
        conn.commit()
        conn.close()
    except Exception:
        pass


def _try_parse_json(payload: str) -> Optional[Any]:
    try:
        return json.loads(payload)
    except Exception:
        return None


def _candidate_plan_from_payload(payload: Any, root: Path) -> Tuple[Optional[PatchPlan], Optional[Path]]:
    if isinstance(payload, dict):
        if "operations" in payload and "id" in payload:
            try:
                return PatchPlan.from_dict(payload), None
            except Exception:
                return None, None
        for key in ("plan", "patch_plan", "write_plan"):
            val = payload.get(key)
            if isinstance(val, dict):
                try:
                    return PatchPlan.from_dict(val), None
                except Exception:
                    return None, None
            if isinstance(val, str):
                cand = Path(val)
                if not cand.is_absolute():
                    cand = root / cand
                if cand.exists():
                    return None, cand
    return None, None


def _extract_plan_from_text(text: str, root: Path) -> Tuple[Optional[PatchPlan], Optional[Path]]:
    if not text:
        return None, None
    raw = str(text).strip()
    if raw == "":
        return None, None
    # path hint
    if raw.lower().endswith((".json", ".yml", ".yaml")):
        cand = Path(raw)
        if not cand.is_absolute():
            cand = root / cand
        if cand.exists():
            return None, cand
    payload = _try_parse_json(raw)
    if payload is not None:
        plan, plan_path = _candidate_plan_from_payload(payload, root)
        if plan or plan_path:
            return plan, plan_path
    return None, None


def _resolve_plan_from_proposal(target: dict, root: Path) -> Tuple[Optional[PatchPlan], Optional[Path]]:
    for key in ("solution", "expectation", "action", "evidence"):
        if key not in target:
            continue
        plan, plan_path = _extract_plan_from_text(str(target.get(key) or ""), root)
        if plan or plan_path:
            return plan, plan_path
    return None, None


def _git_status_lines() -> list[str]:
    if not shutil.which("git"):
        return []
    try:
        proc = subprocess.run(
            ["git", "status", "--porcelain"],
            cwd=str(ROOT),
            capture_output=True,
            text=True,
        )
        if proc.returncode != 0:
            return []
        return [line.rstrip() for line in proc.stdout.splitlines() if line.strip()]
    except Exception:
        return []


def _parse_status(lines: list[str]) -> list[dict]:
    out = []
    for line in lines:
        if not line:
            continue
        status = line[:2].strip()
        path = line[3:] if len(line) > 3 else line
        out.append({"status": status, "path": path})
    return out


def _log_change_summary(proposal_id: str, mode: str, pre_lines: list[str], post_lines: list[str]) -> None:
    try:
        pre_items = _parse_status(pre_lines)
        post_items = _parse_status(post_lines)
        pre_paths = {item["path"] for item in pre_items}
        new_changes = [item for item in post_items if item["path"] not in pre_paths]
        payload = {
            "ts": time.time(),
            "id": proposal_id,
            "mode": mode,
            "pre_count": len(pre_items),
            "post_count": len(post_items),
            "new_changes": new_changes,
            "post_changes": post_items,
        }
        CHANGE_LOG.parent.mkdir(parents=True, exist_ok=True)
        with CHANGE_LOG.open("a", encoding="utf-8") as f:
            f.write(json.dumps(payload, ensure_ascii=False) + "\n")
    except Exception:
        return


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--proposal", required=True, help="Proposal ID to apply")
    parser.add_argument(
        "--force", action="store_true", help="Apply directly (bypass sandbox)"
    )
    parser.add_argument(
        "--plan", help="Explicit patch plan path (JSON/YAML) to apply"
    )
    parser.add_argument("--dry-run", action="store_true", help="Validate plan without writing")
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

    pre_status = _git_status_lines()
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

    plan: Optional[PatchPlan] = None
    plan_path: Optional[Path] = None
    if args.plan:
        plan_path = Path(args.plan)
        if not plan_path.is_absolute():
            plan_path = ROOT / plan_path
        if not plan_path.exists():
            print(f"[!] Plan file not found: {plan_path}")
            return
        try:
            plan = load_plan(plan_path)
        except PlanError as e:
            print(f"[!] Invalid plan file: {e}")
            return
    else:
        plan, plan_path = _resolve_plan_from_proposal(target, ROOT)
        if plan is None and plan_path is not None:
            try:
                plan = load_plan(plan_path)
            except PlanError as e:
                print(f"[!] Invalid plan file: {e}")
                return
        if plan is None and plan_path is None:
            try:
                plan = generate_plan_from_proposal(target, ROOT)
                out_dir = ROOT / ".bgl_core" / "patch_plans"
                out_dir.mkdir(parents=True, exist_ok=True)
                out_path = out_dir / f"auto_{target.get('id')}_{int(time.time())}.json"
                out_path.write_text(
                    json.dumps(
                        {
                            "version": plan.version,
                            "id": plan.plan_id,
                            "description": plan.description,
                            "created_at": plan.created_at,
                            "metadata": plan.metadata,
                            "operations": [op.__dict__ for op in plan.operations],
                        },
                        ensure_ascii=False,
                        indent=2,
                    ),
                    encoding="utf-8",
                )
                plan_path = out_path
                try:
                    conn = sqlite3.connect(str(KNOWLEDGE_DB))
                    conn.execute(
                        "UPDATE agent_proposals SET solution = ? WHERE id = ?",
                        (str(out_path.relative_to(ROOT)).replace("\\", "/"), target.get("id")),
                    )
                    conn.commit()
                    conn.close()
                except Exception:
                    pass
            except (PlanGenerationError, PlanError) as e:
                print(f"[!] Auto plan generation failed: {e}")

    dry_run = bool(args.dry_run)

    # Gate + record decision/outcome
    if dry_run:
        kind = ActionKind.PROBE
    elif args.force:
        kind = ActionKind.WRITE_PROD
    elif plan:
        kind = ActionKind.WRITE_SANDBOX
    else:
        kind = ActionKind.PROPOSE

    scope = []
    if plan:
        scope = [str(op.path) for op in plan.operations][:50]
    else:
        scope = [str(target.get("scope") or "")]
    req = ActionRequest(
        kind=kind,
        operation=f"proposal.apply|{args.proposal}"
        + ("|force" if args.force else "")
        + ("|dry_run" if dry_run else "")
        + (f"|plan:{plan.plan_id}" if plan else ""),
        command=f"apply_proposal --proposal {args.proposal}"
        + (" --force" if args.force else "")
        + (" --dry-run" if dry_run else "")
        + (f" --plan {plan_path}" if plan_path else ""),
        scope=scope,
        reason=str(target.get("recommendation", "apply proposal")),
        confidence=0.9,
        metadata={
            "proposal": target,
            "plan_id": plan.plan_id if plan else None,
            "dry_run": dry_run,
            "policy_key": "apply_proposal",
        },
    )
    gate = auth.gate(req, source="apply_proposal")
    decision_id = int(gate.decision_id or 0)
    if not gate.allowed:
        print(f"[!] BLOCKED: {gate.message}")
        return

    if not plan:
        # No patch plan: preserve legacy behavior (log-only)
        post_status = _git_status_lines()
        mode = "force" if args.force else "sandbox"
        _log_change_summary(str(target.get("id")), mode, pre_status, post_status)
        outcome_id = auth.record_outcome(
            decision_id,
            "success",
            "Proposal logged (no patch plan provided)",
        )
        _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
        _log_learning_event(
            proposal_id=int(target.get("id") or 0),
            outcome_id=outcome_id,
            result="success",
            notes="Proposal logged (no patch plan provided)",
        )
        print(f"[+] Proposal {target.get('id')} logged (no patch plan).")
        return

    # Apply plan (sandbox or direct)
    sandbox = None
    apply_root = ROOT
    if not args.force:
        sandbox = BGLSandbox(ROOT)
        apply_root = sandbox.setup()
        if not apply_root:
            outcome_id = auth.record_outcome(decision_id, "fail", "Sandbox setup failed")
            _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
            _log_learning_event(
                proposal_id=int(target.get("id") or 0),
                outcome_id=outcome_id,
                result="fail",
                notes="Sandbox setup failed",
            )
            print("[!] Sandbox setup failed.")
            return

    try:
        engine = WriteEngine(Path(apply_root))
        try:
            result = engine.apply(plan, dry_run=dry_run)
        except PlanError as e:
            outcome_id = auth.record_outcome(decision_id, "fail", f"Plan error: {e}")
            _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
            _log_learning_event(
                proposal_id=int(target.get("id") or 0),
                outcome_id=outcome_id,
                result="fail",
                notes=f"Plan error: {e}",
            )
            print(f"[!] Plan error: {e}")
            return
        if not result.ok:
            outcome_id = auth.record_outcome(
                decision_id,
                "fail",
                f"Write engine errors: {result.errors}",
                backup_path=(result.backups[0] if result.backups else ""),
            )
            _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
            _log_learning_event(
                proposal_id=int(target.get("id") or 0),
                outcome_id=outcome_id,
                result="fail",
                notes=f"Write engine errors: {result.errors}",
            )
            print(f"[!] Write engine failed: {result.errors}")
            return

        if args.force:
            post_status = _git_status_lines()
            _log_change_summary(str(target.get("id")), "force", pre_status, post_status)
            outcome_id = auth.record_outcome(
                decision_id,
                "success_direct",
                "Proposal patch plan applied to production",
                backup_path=(result.backups[0] if result.backups else ""),
            )
            _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
            _log_learning_event(
                proposal_id=int(target.get("id") or 0),
                outcome_id=outcome_id,
                result="success_direct",
                notes="Proposal patch plan applied to production",
            )
            print(f"[+] Applied proposal {target.get('id')} to PRODUCTION.")
        else:
            # Capture sandbox diff for review
            diff_path = ROOT / ".bgl_core" / "logs" / f"proposal_{target.get('id')}_sandbox.diff"
            try:
                proc = subprocess.run(
                    ["git", "-C", str(apply_root), "diff", "--binary"],
                    capture_output=True,
                    text=True,
                    check=False,
                )
                diff_text = proc.stdout or ""
                if diff_text.strip():
                    diff_path.parent.mkdir(parents=True, exist_ok=True)
                    diff_path.write_text(diff_text, encoding="utf-8")
                else:
                    diff_path = None
            except Exception:
                diff_path = None
            outcome_id = auth.record_outcome(
                decision_id,
                "success_sandbox",
                f"Proposal patch plan applied in sandbox. diff={diff_path}" if diff_path else "Proposal patch plan applied in sandbox.",
                backup_path=(result.backups[0] if result.backups else ""),
            )
            _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
            _log_learning_event(
                proposal_id=int(target.get("id") or 0),
                outcome_id=outcome_id,
                result="success_sandbox",
                notes=f"Proposal patch plan applied in sandbox. diff={diff_path}" if diff_path else "Proposal patch plan applied in sandbox.",
            )
            print(f"[+] Applied proposal {target.get('id')} in SANDBOX.")
            if diff_path:
                print(f"    Diff saved: {diff_path}")
    finally:
        if sandbox:
            sandbox.cleanup()


if __name__ == "__main__":
    main()
