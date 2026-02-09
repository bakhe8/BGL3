import json
import argparse
import hashlib
from pathlib import Path
import sqlite3
import time
import subprocess
import shutil
import os
import sys
from typing import Any, Optional, Tuple, Dict, List
try:
    from .db_utils import connect_db  # type: ignore
except Exception:
    from db_utils import connect_db  # type: ignore

try:
    from .authority import Authority  # type: ignore
    from .brain_types import ActionRequest, ActionKind  # type: ignore
    from .patch_plan import PatchPlan, load_plan, PlanError  # type: ignore
    from .write_engine import WriteEngine  # type: ignore
    from .sandbox import BGLSandbox  # type: ignore
    from .plan_generator import generate_plan_from_proposal, PlanGenerationError  # type: ignore
    from .canary_release import register_canary_release, evaluate_canary_releases, rollback_release  # type: ignore
    from .agent_verify import run_all_checks  # type: ignore
    from .test_gate import require_tests_enabled, collect_tests_for_files, evaluate_files  # type: ignore
    from .config_loader import load_config  # type: ignore
    from .decision_db import record_decision_trace  # type: ignore
except Exception:
    from authority import Authority
    from brain_types import ActionRequest, ActionKind
    from patch_plan import PatchPlan, load_plan, PlanError
    from write_engine import WriteEngine
    from sandbox import BGLSandbox
    from plan_generator import generate_plan_from_proposal, PlanGenerationError
    from canary_release import register_canary_release, evaluate_canary_releases, rollback_release
    from agent_verify import run_all_checks
    from test_gate import require_tests_enabled, collect_tests_for_files, evaluate_files
    try:
        from config_loader import load_config  # type: ignore
    except Exception:
        load_config = None  # type: ignore
    try:
        from decision_db import record_decision_trace  # type: ignore
    except Exception:
        record_decision_trace = None  # type: ignore

ROOT = Path(__file__).resolve().parent.parent.parent
KNOWLEDGE_DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"
LOG_FILE = ROOT / ".bgl_core" / "logs" / "proposal_actions.log"
CHANGE_LOG = ROOT / ".bgl_core" / "logs" / "proposal_changes.jsonl"


def _load_cfg() -> Dict[str, Any]:
    if load_config is None:
        return {}
    try:
        return load_config(ROOT) or {}
    except Exception:
        return {}


def _cfg_flag(env_key: str, cfg: Dict[str, Any], key: str, default: bool) -> bool:
    env_val = os.getenv(env_key)
    if env_val is not None:
        return str(env_val).strip() == "1"
    try:
        val = cfg.get(key, default)
        if isinstance(val, (int, float)):
            return float(val) != 0.0
        return str(val).strip() == "1"
    except Exception:
        return bool(default)


def _cfg_str(env_key: str, cfg: Dict[str, Any], key: str, default: str) -> str:
    env_val = os.getenv(env_key)
    if env_val is not None:
        return str(env_val)
    try:
        return str(cfg.get(key, default) or default)
    except Exception:
        return default


def _cfg_number(env_key: str, cfg: Dict[str, Any], key: str, default):
    env_val = os.getenv(env_key)
    if env_val is not None:
        try:
            return type(default)(env_val)
        except Exception:
            return default
    try:
        return type(default)(cfg.get(key, default))
    except Exception:
        return default


def _auto_run_scenarios_after_apply(cfg: Dict[str, Any], *, proposal_id: Optional[str], mode: str) -> None:
    enabled = _cfg_flag(
        "BGL_AUTO_RUN_SCENARIOS_AFTER_APPLY",
        cfg,
        "auto_run_scenarios_after_apply",
        False,
    )
    if not enabled:
        return
    if mode == "prod":
        prod_enabled = _cfg_flag(
            "BGL_AUTO_RUN_SCENARIOS_AFTER_APPLY_PROD",
            cfg,
            "auto_run_scenarios_after_apply_prod",
            True,
        )
        if not prod_enabled:
            return
    try:
        limit = int(
            _cfg_number(
                "BGL_AUTO_RUN_SCENARIOS_AFTER_APPLY_LIMIT",
                cfg,
                "auto_run_scenarios_after_apply_limit",
                6,
            )
        )
    except Exception:
        limit = 6
    try:
        timeout_sec = int(
            _cfg_number(
                "BGL_AUTO_RUN_SCENARIOS_AFTER_APPLY_TIMEOUT_SEC",
                cfg,
                "auto_run_scenarios_after_apply_timeout_sec",
                240,
            )
        )
    except Exception:
        timeout_sec = 240
    include_api = _cfg_flag(
        "BGL_AUTO_RUN_SCENARIOS_AFTER_APPLY_INCLUDE_API",
        cfg,
        "auto_run_scenarios_after_apply_include_api",
        True,
    )
    include_autonomous = _cfg_flag(
        "BGL_AUTO_RUN_SCENARIOS_AFTER_APPLY_INCLUDE_AUTONOMOUS",
        cfg,
        "auto_run_scenarios_after_apply_include_autonomous",
        False,
    )
    headless = _cfg_flag(
        "BGL_AUTO_RUN_SCENARIOS_AFTER_APPLY_HEADLESS",
        cfg,
        "auto_run_scenarios_after_apply_headless",
        True,
    )
    env = os.environ.copy()
    env.setdefault("BGL_SCENARIO_BATCH_LIMIT", str(limit))
    env.setdefault("BGL_INCLUDE_API", "1" if include_api else "0")
    env.setdefault("BGL_INCLUDE_AUTONOMOUS", "1" if include_autonomous else "0")
    env.setdefault("BGL_AUTONOMOUS_SCENARIO", "1" if include_autonomous else "0")
    env.setdefault("BGL_HEADLESS", "1" if headless else "0")
    env.setdefault("BGL_RUN_AFTER_APPLY", "1")
    if proposal_id:
        env.setdefault("BGL_APPLY_PROPOSAL_ID", str(proposal_id))
    try:
        subprocess.run(
            [sys.executable, str(ROOT / ".bgl_core" / "brain" / "run_scenarios.py")],
            cwd=ROOT,
            env=env,
            timeout=timeout_sec,
        )
    except Exception:
        return


def _lint_files(root: Path, files: List[str], max_files: int = 4) -> Dict[str, Any]:
    linted = 0
    failures = []
    for rel in files:
        if linted >= max_files:
            break
        if not rel.lower().endswith(".php"):
            continue
        path = root / rel
        if not path.exists():
            continue
        try:
            result = subprocess.run(
                ["php", "-l", str(path)],
                capture_output=True,
                text=True,
                timeout=30,
            )
        except FileNotFoundError:
            return {"ok": True, "skipped": True, "reason": "php_missing"}
        except Exception as e:
            failures.append(f"{rel}: {e}")
            continue
        linted += 1
        if result.returncode != 0:
            failures.append(f"{rel}: {result.stderr.strip() or result.stdout.strip()}")
    if failures:
        return {"ok": False, "failures": failures, "linted": linted}
    return {"ok": True, "linted": linted}


def _run_phpunit_tests(root: Path, tests: List[str], max_files: int = 6) -> Dict[str, Any]:
    phpunit_bin = root / "vendor" / "bin" / "phpunit"
    if not phpunit_bin.exists():
        return {"ok": False, "error": "phpunit_missing"}
    if not tests:
        return {"ok": False, "error": "tests_missing"}
    args = ["php", str(phpunit_bin)]
    for t in tests[:max_files]:
        args.append(str(t))
    try:
        result = subprocess.run(args, capture_output=True, text=True, timeout=180)
        return {
            "ok": result.returncode == 0,
            "output": (result.stdout + result.stderr).strip(),
            "tests": tests[:max_files],
        }
    except subprocess.TimeoutExpired:
        return {"ok": False, "error": "phpunit_timeout", "tests": tests[:max_files]}
    except Exception as e:
        return {"ok": False, "error": f"phpunit_error:{e}"}


def _post_apply_evaluate(root: Path, changed_files: List[str], mode: str, max_files: int) -> Dict[str, Any]:
    mode = (mode or "checks").lower().strip()
    if mode in ("none", "off", "skip"):
        return {"ok": True, "mode": mode, "skipped": True}
    results: Dict[str, Any] = {"mode": mode}
    require_tests = require_tests_enabled(root, False)
    if require_tests:
        test_paths = collect_tests_for_files(root, changed_files)
        if not test_paths:
            # Allow scenario-backed validation for high-risk files when tests are missing
            try:
                gate = evaluate_files(
                    root,
                    changed_files,
                    require_tests=True,
                    allow_scenarios=True,
                )
            except Exception as e:
                gate = {"ok": False, "errors": [f"scenario_gate_error:{e}"]}
            results["tests_gate"] = gate
            if not gate.get("ok", False):
                results["ok"] = False
                return results
        else:
            phpunit = _run_phpunit_tests(root, test_paths, max_files=max_files)
            results["phpunit"] = phpunit
            if not phpunit.get("ok", False):
                results["ok"] = False
                return results
    # Lint-only mode
    if mode in ("lint", "php_lint"):
        lint = _lint_files(root, changed_files, max_files=max_files)
        results["lint"] = lint
        results["ok"] = bool(lint.get("ok", True))
        return results

    # Checks (agent_verify) + optional lint
    if mode in ("checks", "verify", "default"):
        lint = _lint_files(root, changed_files, max_files=max_files)
        results["lint"] = lint
        try:
            checks = run_all_checks(root)
        except Exception as e:
            checks = {"passed": False, "error": str(e), "results": []}
        results["checks"] = checks
        results["ok"] = bool(lint.get("ok", True)) and bool(checks.get("passed", False))
        return results

    # Full mode: fallback to checks + lint (safety.validate is heavy)
    lint = _lint_files(root, changed_files, max_files=max_files)
    results["lint"] = lint
    try:
        checks = run_all_checks(root)
    except Exception as e:
        checks = {"passed": False, "error": str(e), "results": []}
    results["checks"] = checks
    results["ok"] = bool(lint.get("ok", True)) and bool(checks.get("passed", False))
    return results


def _summarize_eval_failure(eval_result: Dict[str, Any], max_items: int = 3) -> str:
    parts: List[str] = []
    lint = eval_result.get("lint") or {}
    if isinstance(lint, dict) and not lint.get("ok", True):
        failures = lint.get("failures") or []
        if failures:
            parts.append("lint:" + "; ".join(str(f) for f in failures[:max_items]))
        else:
            parts.append("lint:failed")
    phpunit = eval_result.get("phpunit") or {}
    if isinstance(phpunit, dict) and not phpunit.get("ok", True):
        err = phpunit.get("error") or ""
        out = phpunit.get("output") or ""
        detail = err or out
        if detail:
            parts.append(f"phpunit:{str(detail)[:200]}")
        else:
            parts.append("phpunit:failed")
    tests_gate = eval_result.get("tests_gate") or {}
    if isinstance(tests_gate, dict) and not tests_gate.get("ok", True):
        errors = tests_gate.get("errors") or []
        if errors:
            parts.append("tests_gate:" + "; ".join(str(e) for e in errors[:max_items]))
        else:
            parts.append("tests_gate:failed")
    checks = eval_result.get("checks") or {}
    if isinstance(checks, dict) and not checks.get("passed", True):
        failed = []
        for r in checks.get("results") or []:
            try:
                if not r.get("passed", False):
                    cid = r.get("id") or r.get("check") or "check"
                    evidence = r.get("evidence") or []
                    ev = ""
                    if isinstance(evidence, list) and evidence:
                        ev = str(evidence[0])
                    elif evidence:
                        ev = str(evidence)
                    item = f"{cid}"
                    if ev:
                        item += f"({ev[:120]})"
                    failed.append(item)
            except Exception:
                continue
        if failed:
            parts.append("checks:" + "; ".join(failed[:max_items]))
        else:
            parts.append("checks:failed")
    return " | ".join(parts)


def _write_eval_artifact(proposal_id: Any, eval_result: Dict[str, Any]) -> Optional[Path]:
    try:
        out_dir = ROOT / ".bgl_core" / "logs"
        out_dir.mkdir(parents=True, exist_ok=True)
        path = out_dir / f"post_apply_eval_{proposal_id}.json"
        path.write_text(json.dumps(eval_result, ensure_ascii=False, indent=2), encoding="utf-8")
        return path
    except Exception:
        return None


def _load_code_contracts(root: Path) -> Dict[str, Any]:
    path = root / "analysis" / "code_contracts.json"
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {}


def _patch_risk_summary(root: Path, changed_files: List[str]) -> Dict[str, Any]:
    data = _load_code_contracts(root)
    contracts = data.get("contracts") or []
    if not isinstance(contracts, list):
        contracts = []
    contract_map: Dict[str, Dict[str, Any]] = {}
    for c in contracts:
        if not isinstance(c, dict):
            continue
        file_path = str(c.get("file") or "").replace("\\", "/").lstrip("/")
        if file_path:
            contract_map[file_path] = c
    high_risk = 0
    stateful = 0
    startup_exec = 0
    accumulates = 0
    missing_tests = 0
    inspected = 0
    markers: List[str] = []
    for rel in changed_files:
        rel_norm = str(rel or "").replace("\\", "/").lstrip("/")
        if not rel_norm:
            continue
        inspected += 1
        c = contract_map.get(rel_norm) or {}
        risk = str(c.get("risk") or "").lower()
        if risk == "high":
            high_risk += 1
        temporal = c.get("temporal_profile") or {}
        if isinstance(temporal, dict):
            if temporal.get("stateful"):
                stateful += 1
            if temporal.get("startup_exec"):
                startup_exec += 1
            if temporal.get("accumulates") or temporal.get("first_request_writes"):
                accumulates += 1
            if temporal.get("stateful") or temporal.get("startup_exec"):
                markers.append(rel_norm)
        tests = c.get("tests") or []
        if not tests:
            missing_tests += 1
    ratio_stateful = round(stateful / max(1, inspected), 3) if inspected else 0.0
    risk_level = "low"
    if high_risk > 0 or startup_exec > 0 or accumulates > 0:
        risk_level = "medium"
    if high_risk >= 2 or (ratio_stateful >= 0.4 and startup_exec > 0):
        risk_level = "high"
    return {
        "inspected": inspected,
        "high_risk_files": high_risk,
        "stateful_files": stateful,
        "startup_exec_files": startup_exec,
        "accumulates_files": accumulates,
        "missing_tests": missing_tests,
        "stateful_ratio": ratio_stateful,
        "risk_level": risk_level,
        "markers": markers[:6],
    }


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
        conn = connect_db(KNOWLEDGE_DB, timeout=30.0)
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
        conn = connect_db(KNOWLEDGE_DB, timeout=30.0)
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
    conn_kb = connect_db(KNOWLEDGE_DB, timeout=30.0)
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

    cfg = _load_cfg()

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
                    conn = connect_db(KNOWLEDGE_DB, timeout=30.0)
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

        changed_files: List[str] = []
        try:
            for ch in result.changes:
                if isinstance(ch, dict) and ch.get("path"):
                    changed_files.append(str(ch.get("path")))
                elif isinstance(ch, dict) and ch.get("from"):
                    changed_files.append(str(ch.get("from")))
        except Exception:
            changed_files = []

        patch_risk = _patch_risk_summary(Path(apply_root), changed_files)
        try:
            if record_decision_trace is not None:
                record_decision_trace(
                    KNOWLEDGE_DB,
                    kind="patch_risk",
                    decision_id=int(decision_id or 0),
                    outcome_id=None,
                    operation=f"proposal.apply|{target.get('id')}",
                    result=str(patch_risk.get("risk_level") or ""),
                    source="apply_proposal",
                    details={"changed_files": changed_files, "patch_risk": patch_risk},
                )
        except Exception:
            pass

        validate_enabled = _cfg_flag("BGL_POST_APPLY_VALIDATE", cfg, "post_apply_validate", True)
        validate_mode = _cfg_str("BGL_POST_APPLY_VALIDATE_MODE", cfg, "post_apply_validate_mode", "checks")
        validate_max_files = int(_cfg_number("BGL_POST_APPLY_VALIDATE_MAX", cfg, "post_apply_validate_max_files", 4))
        validate_prod = _cfg_flag("BGL_POST_APPLY_VALIDATE_PROD", cfg, "post_apply_validate_prod", True)
        eval_result: Dict[str, Any] = {"ok": True, "skipped": True}
        if validate_enabled and (not args.force or validate_prod):
            eval_result = _post_apply_evaluate(Path(apply_root), changed_files, validate_mode, validate_max_files)
        eval_result["patch_risk"] = patch_risk

        if args.force:
            post_status = _git_status_lines()
            _log_change_summary(str(target.get("id")), "force", pre_status, post_status)
            # Register canary release for safe monitoring/rollback
            release_id = None
            try:
                change_scope = []
                for ch in result.changes:
                    if isinstance(ch, dict) and ch.get("path"):
                        change_scope.append(str(ch.get("path")))
                    elif isinstance(ch, dict) and ch.get("from"):
                        change_scope.append(str(ch.get("from")))
                backup_dir = ROOT / ".bgl_core" / "backups" / str(result.plan_id)
                canary = register_canary_release(
                    ROOT,
                    KNOWLEDGE_DB,
                    plan_id=str(result.plan_id),
                    change_scope=change_scope,
                    source="apply_proposal",
                    backup_dir=backup_dir if backup_dir.exists() else None,
                    notes=f"proposal_id={target.get('id')}",
                )
                if isinstance(canary, dict) and canary.get("release_id"):
                    release_id = str(canary.get("release_id"))
            except Exception:
                release_id = None

            eval_ok = bool(eval_result.get("ok", True))
            eval_note = f"post_validate={eval_ok} mode={eval_result.get('mode')}"
            if not eval_ok:
                detail = _summarize_eval_failure(eval_result)
                artifact = _write_eval_artifact(target.get("id"), eval_result)
                if detail:
                    eval_note += f" detail={detail}"
                if artifact:
                    eval_note += f" eval={artifact}"
            if not eval_ok:
                # Immediate rollback on validation failure if configured
                auto_rb = _cfg_flag("BGL_POST_APPLY_AUTO_ROLLBACK", cfg, "post_apply_auto_rollback_on_fail", True)
                rolled = False
                if auto_rb and release_id:
                    try:
                        rolled = rollback_release(ROOT, release_id)
                    except Exception:
                        rolled = False
                note = "Production apply failed validation."
                if rolled:
                    note += " rollback_performed=1"
                note += f" {eval_note}"
                outcome_id = auth.record_outcome(
                    decision_id,
                    "fail",
                    note,
                    backup_path=(result.backups[0] if result.backups else ""),
                )
                _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
                _log_learning_event(
                    proposal_id=int(target.get("id") or 0),
                    outcome_id=outcome_id,
                    result="fail",
                    notes=note,
                )
                # Optionally evaluate canary immediately
                try:
                    if _cfg_flag("BGL_CANARY_EVAL_IMMEDIATE", cfg, "post_apply_immediate_canary_eval", True):
                        evaluate_canary_releases(ROOT, KNOWLEDGE_DB, min_age_sec=0, auto_rollback=True)
                except Exception:
                    pass
                print(f"[!] Production apply failed validation for proposal {target.get('id')}.")
                return

            outcome_id = auth.record_outcome(
                decision_id,
                "success_direct",
                f"Proposal patch plan applied to production. {eval_note}",
                backup_path=(result.backups[0] if result.backups else ""),
            )
            _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
            _log_learning_event(
                proposal_id=int(target.get("id") or 0),
                outcome_id=outcome_id,
                result="success_direct",
                notes=f"Proposal patch plan applied to production. {eval_note}",
            )
            # Optional immediate canary evaluation
            try:
                if _cfg_flag("BGL_CANARY_EVAL_IMMEDIATE", cfg, "post_apply_immediate_canary_eval", True):
                    evaluate_canary_releases(ROOT, KNOWLEDGE_DB, min_age_sec=0, auto_rollback=True)
            except Exception:
                pass
            print(f"[+] Applied proposal {target.get('id')} to PRODUCTION.")
            _auto_run_scenarios_after_apply(cfg, proposal_id=str(target.get("id") or ""), mode="prod")
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
            eval_ok = bool(eval_result.get("ok", True))
            eval_note = f"post_validate={eval_ok} mode={eval_result.get('mode')}"
            if not eval_ok:
                detail = _summarize_eval_failure(eval_result)
                artifact = _write_eval_artifact(target.get("id"), eval_result)
                if detail:
                    eval_note += f" detail={detail}"
                if artifact:
                    eval_note += f" eval={artifact}"
            if not eval_ok:
                outcome_id = auth.record_outcome(
                    decision_id,
                    "fail",
                    f"Sandbox validation failed. {eval_note}",
                    backup_path=(result.backups[0] if result.backups else ""),
                )
                _link_proposal_outcome(int(target.get("id") or 0), decision_id, outcome_id, "apply_proposal")
                _log_learning_event(
                    proposal_id=int(target.get("id") or 0),
                    outcome_id=outcome_id,
                    result="fail",
                    notes=f"Sandbox validation failed. {eval_note}",
                )
                print(f"[!] Sandbox validation failed for proposal {target.get('id')}.")
                return

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
            # Register canary release even for sandbox (shadow monitoring)
            try:
                change_scope = []
                for ch in result.changes:
                    if isinstance(ch, dict) and ch.get("path"):
                        change_scope.append(str(ch.get("path")))
                    elif isinstance(ch, dict) and ch.get("from"):
                        change_scope.append(str(ch.get("from")))
                backup_dir = ROOT / ".bgl_core" / "backups" / str(result.plan_id)
                register_canary_release(
                    ROOT,
                    KNOWLEDGE_DB,
                    plan_id=str(result.plan_id),
                    change_scope=change_scope,
                    source="apply_proposal_sandbox",
                    backup_dir=backup_dir if backup_dir.exists() else None,
                    notes=f"sandbox proposal_id={target.get('id')}",
                )
            except Exception:
                pass
            # Optional immediate canary evaluation for sandbox releases
            try:
                if _cfg_flag("BGL_CANARY_EVAL_IMMEDIATE", cfg, "post_apply_immediate_canary_eval", True):
                    evaluate_canary_releases(ROOT, KNOWLEDGE_DB, min_age_sec=0, auto_rollback=False)
            except Exception:
                pass
            print(f"[+] Applied proposal {target.get('id')} in SANDBOX.")
            if diff_path:
                print(f"    Diff saved: {diff_path}")
            _auto_run_scenarios_after_apply(cfg, proposal_id=str(target.get("id") or ""), mode="sandbox")

            # Auto-promote to production after sandbox success (optional)
            try:
                auto_promote = _cfg_flag("BGL_AUTO_PROMOTE_TO_PROD", cfg, "post_apply_auto_promote_prod", False)
            except Exception:
                auto_promote = False
            if auto_promote and not args.force and not dry_run:
                try:
                    exe = sys.executable or "python"
                except Exception:
                    exe = "python"
                cmd = [exe, str(Path(__file__).resolve()), "--proposal", str(target.get("id") or args.proposal), "--force"]
                if plan_path is not None:
                    cmd.extend(["--plan", str(plan_path)])
                try:
                    subprocess.run(
                        cmd,
                        cwd=str(ROOT),
                        capture_output=True,
                        text=True,
                        check=False,
                    )
                except Exception:
                    pass
    finally:
        if sandbox:
            sandbox.cleanup()


if __name__ == "__main__":
    main()
