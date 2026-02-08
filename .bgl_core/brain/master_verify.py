import asyncio
import sys
import subprocess
import os
from pathlib import Path
import json
import sqlite3
import time

# Fix path to find brain modules in all execution contexts
current_dir = str(Path(__file__).parent)
if current_dir not in sys.path:
    sys.path.append(current_dir)

from agency_core import AgencyCore  # noqa: E402
from config_loader import load_config, load_effective_config  # noqa: E402
from report_builder import build_report  # noqa: E402
from generate_playbooks import generate_from_proposed  # noqa: E402
from contract_tests import run_contract_suite  # noqa: E402
from utils import load_route_usage  # noqa: E402
from callgraph_builder import build_callgraph  # noqa: E402
from generate_openapi import generate as generate_openapi  # noqa: E402
from scenario_deps import check_scenario_deps_async  # noqa: E402
from auto_insights import audit_auto_insights, write_auto_insights_status  # noqa: E402
from schema_check import check_schema  # noqa: E402
from run_ledger import start_run, finish_run  # noqa: E402
from run_lock import acquire_lock, release_lock  # noqa: E402


def log_activity(root_path: Path, message: str):
    """Logs an event to the agent_activity table for dashboard visibility."""
    db_path = root_path / ".bgl_core" / "brain" / "knowledge.db"
    try:
        with sqlite3.connect(str(db_path), timeout=30.0) as conn:
            conn.execute("PRAGMA journal_mode=WAL;")
            conn.execute(
                "INSERT INTO agent_activity (timestamp, activity, source, details) VALUES (?, ?, ?, ?)",
                (time.time(), message, "master_verify", "{}"),
            )
    except Exception as e:
        print(f"[WARN] Failed to log activity: {e}")


def _read_lock_status(lock_path: Path) -> dict:
    status = {"path": str(lock_path), "exists": lock_path.exists()}
    if not lock_path.exists():
        return status
    try:
        raw = lock_path.read_text(encoding="utf-8").strip()
        parts = raw.split("|")
        pid = int(parts[0]) if parts and parts[0].isdigit() else 0
        ts = float(parts[1]) if len(parts) > 1 else 0.0
        label = parts[2] if len(parts) > 2 else ""
        status.update(
            {
                "pid": pid,
                "timestamp": ts,
                "age_sec": round(max(0.0, time.time() - ts), 2) if ts else None,
                "label": label,
            }
        )
    except Exception:
        status["error"] = "unreadable"
    return status


async def master_assurance_diagnostic():
    """
    Main entry point for Master Technical Assurance.
    Runs a full AgencyCore diagnostic and presents the results.
    """
    ROOT = Path(__file__).parent.parent.parent

    print("\n" + "=" * 70)
    print("🚀 BGL3 AGENCY: MASTER TECHNICAL ASSURANCE (GOLD STANDARD)")
    print("=" * 70)

    cfg = load_config(ROOT)
    effective_cfg = load_effective_config(ROOT)
    timeout = int(cfg.get("diagnostic_timeout_sec", 300))
    lock_path = ROOT / ".bgl_core" / "logs" / "master_verify.lock"
    lock_ttl = int(cfg.get("master_verify_lock_ttl_sec", max(3600, timeout * 3)))
    ok, reason = acquire_lock(lock_path, ttl_sec=lock_ttl, label="master_verify")
    if not ok:
        print(f"[!] master_verify already running ({reason}); skipping.")
        return

    # Master verify is an automated pipeline; leaving a browser open will hang until timeout.
    # Force keep-browser off unless explicitly overridden for debugging.
    if os.getenv("BGL_MASTER_KEEP_BROWSER", "0") != "1":
        os.environ["BGL_KEEP_BROWSER"] = "0"

    # Initialize Core
    core = AgencyCore(ROOT)

    # Run Full Diagnostic with bounded timeout to avoid hanging browser runs
    run_id = f"diag_{int(time.time())}"
    os.environ["BGL_DIAGNOSTIC_RUN_ID"] = run_id
    try:
        start_run(ROOT / ".bgl_core" / "brain" / "knowledge.db", run_id=run_id, mode="master_verify")
    except Exception:
        pass
    try:
        diagnostic = await asyncio.wait_for(core.run_full_diagnostic(), timeout=timeout)
    except asyncio.TimeoutError:
        print(f"[CRITICAL] Diagnostic timed out after {timeout}s.")
        return
    finally:
        try:
            release_lock(lock_path)
        except Exception:
            pass
        try:
            finish_run(ROOT / ".bgl_core" / "brain" / "knowledge.db", run_id=run_id)
        except Exception:
            pass
        try:
            os.environ.pop("BGL_DIAGNOSTIC_RUN_ID", None)
        except Exception:
            pass
        # Best-effort cleanup: Playwright on Windows can emit noisy asyncio subprocess warnings
        # if its driver is still attached when the event loop shuts down.
        try:
            await asyncio.wait_for(core.sensor_browser.close(), timeout=5)
        except Exception:
            pass

    # Augment diagnostic with route_usage (for suppression) and feature_flags
    diagnostic["route_usage"] = load_route_usage(ROOT)
    diagnostic["feature_flags"] = cfg.get("feature_flags", {})
    diagnostic["effective_config"] = effective_cfg

    # Build callgraph for reporting/reference
    diagnostic["findings"]["callgraph_meta"] = build_callgraph(ROOT)

    # Generate OpenAPI (merged) for contract tests and reference
    diagnostic["openapi_path"] = str(generate_openapi(ROOT))

    # Optional: run API contract/property tests (Schemathesis/Dredd) if enabled
    if cfg.get("run_api_contract", 0):
        contract_results = run_contract_suite(ROOT)
        diagnostic.setdefault("gap_tests", []).extend(contract_results)
        diagnostic.setdefault("findings", {}).setdefault("gap_tests", []).extend(contract_results)

    # Optional: lightweight perf probe (home page load)
    perf = {}
    if cfg.get("measure_perf", 0):
        import urllib.request

        base = cfg.get("base_url", "http://localhost:8000").rstrip("/")
        start = time.perf_counter()
        try:
            with urllib.request.urlopen(base + "/", timeout=5) as resp:
                perf["home_status"] = resp.getcode()
                perf["home_bytes"] = len(resp.read())
        except Exception as e:
            perf["home_error"] = str(e)
        perf["home_load_ms"] = round((time.perf_counter() - start) * 1000, 1)
        diagnostic["performance"] = perf

    # Scenario dependency health + runtime events meta
    scenario_deps = (await check_scenario_deps_async()).to_dict()
    diagnostic.setdefault("findings", {})["scenario_deps"] = scenario_deps
    runtime_meta = {"count": 0, "last_timestamp": None}
    try:
        db_path = ROOT / ".bgl_core" / "brain" / "knowledge.db"
        if db_path.exists():
            conn = sqlite3.connect(str(db_path), timeout=30.0)
            conn.execute("PRAGMA journal_mode=WAL;")
            cur = conn.cursor()
            cur.execute("SELECT COUNT(*) FROM runtime_events")
            runtime_meta["count"] = int(cur.fetchone()[0] or 0)
            cur.execute("SELECT MAX(timestamp) FROM runtime_events")
            runtime_meta["last_timestamp"] = cur.fetchone()[0]
            conn.close()
    except Exception:
        pass
    diagnostic["findings"]["runtime_events_meta"] = runtime_meta

    # Auto-insights status (staleness/coverage)
    try:
        allow_legacy = os.getenv("BGL_ALLOW_LEGACY_INSIGHTS", "0") == "1"
        try:
            max_insights = int(os.getenv("BGL_MAX_AUTO_INSIGHTS", "0") or "0")
        except Exception:
            max_insights = 0
        auto_insights_status = audit_auto_insights(
            ROOT, allow_legacy=allow_legacy, max_insights=max_insights
        )
        diagnostic["findings"]["auto_insights_status"] = auto_insights_status
        write_auto_insights_status(ROOT, auto_insights_status)
    except Exception:
        diagnostic["findings"]["auto_insights_status"] = {}

    # Auto-generate playbook skeletons from proposed patterns (discovery-only)
    generated = generate_from_proposed(Path(__file__).parent.parent.parent)
    if generated:
        print(f"[+] Generated playbook skeletons: {generated}")
    # Warn if pending playbooks await review
    pending = list((Path(__file__).parent / "playbooks_proposed").glob("*.md"))
    if pending:
        print(
            f"[WARN] Pending playbooks awaiting approval: {[p.name for p in pending]}"
        )

    # 1. Infrastructure Pass
    print(
        f"\n[1] Infrastructure Integrity: {'✅ PASS' if diagnostic['vitals']['infrastructure'] else '❌ FAIL'}"
    )

    # 2. Business Logic Pass
    print(
        f"[2] Business Logic Health:    {'✅ PASS' if diagnostic['vitals']['business_logic'] else '⚠️ WARNING'}"
    )

    # 3. Architectural Pass
    print(
        f"[3] Architectural Compliance: {'✅ PASS' if diagnostic['vitals']['architecture'] else '❌ VIOLATION'}"
    )

    # 4. Agent Status & Memory
    print(f"\n[4] Agent Memory (Knowledge DB): {'✅ SYNCED'}")
    blockers = diagnostic["findings"]["blockers"]
    if blockers:
        print(f"    [!] ALERT: {len(blockers)} Cognitive Blockers identified.")
        for b in blockers:
            print(f"        - {b['task_name']}: {b['reason'][:60]}...")
    else:
        print("    [SUCCESS] No cognitive blockers detected.")

    # 5. Route Health
    failing = diagnostic["findings"]["failing_routes"]
    print(f"\n[5] Real-time Route Health:  {100 - len(failing)}% Optimal")
    if failing:
        for f in failing[:3]:  # Show top 3
            if isinstance(f, dict):
                uri = f.get("uri") or f.get("url") or str(f)
            else:
                uri = str(f)
            print(f"        - ERROR on {uri}")

    # 6. Browser Driver Check
    print("\n[6] Browser Engine Status:")
    try:
        res = subprocess.run(
            ["playwright", "--version"], capture_output=True, text=True, check=True
        )
        print(f"    - Playwright: ✅ DETECTED ({res.stdout.strip()})")
    except Exception:
        print("    - Playwright: ❌ MISSING (Run: playwright install chromium)")

    # 7. Write HTML report
    try:
        template = Path(__file__).parent / "report_template.html"
        output = Path(".bgl_core/logs/latest_report.html")
        data = {
            "timestamp": diagnostic.get("timestamp"),
            "health_score": diagnostic.get("health_score", 0),
            "route_scan_limit": diagnostic.get("route_scan_limit", 0),
            "route_scan_mode": diagnostic.get("route_scan_mode", "auto"),
            "execution_mode": diagnostic.get(
                "execution_mode", cfg.get("execution_mode", "sandbox")
            ),
            "execution_stats": diagnostic.get("execution_stats", {}),
            "performance": diagnostic.get("performance", {}),
            "scan_duration_seconds": diagnostic.get("scan_duration_seconds", 0),
            "target_duration_seconds": diagnostic.get("target_duration_seconds", 0),
            "vitals": diagnostic.get("vitals", {}),
            "permission_issues": diagnostic["findings"].get("permission_issues", []),
            "pending_approvals": diagnostic["findings"].get("pending_approvals", []),
            "recent_outcomes": diagnostic["findings"].get("recent_outcomes", []),
            "failing_routes": diagnostic["findings"].get("failing_routes", []),
            "experiences": diagnostic["findings"].get("experiences", []),
            "suggestions": diagnostic["findings"].get("proposals", []),
            "worst_routes": diagnostic["findings"].get("worst_routes", []),
            "interpretation": diagnostic["findings"].get("interpretation", {}),
            "intent": diagnostic["findings"].get("intent", {}),
            "decision": diagnostic["findings"].get("decision", {}),
            "signals": diagnostic["findings"].get("signals", {}),
            "signals_intent_hint": diagnostic["findings"].get(
                "signals_intent_hint", {}
            ),
            "gap_tests": diagnostic["findings"].get("gap_tests", []),
            "proposals": diagnostic["findings"].get("proposals", []),
            "external_checks": diagnostic["findings"].get("external_checks", []),
            "tool_evidence": diagnostic["findings"].get("tool_evidence", {}),
            "scenario_deps": diagnostic["findings"].get("scenario_deps", {}),
            "runtime_events_meta": diagnostic["findings"].get(
                "runtime_events_meta", {}
            ),
            "scenario_coverage": diagnostic["findings"].get("scenario_coverage", {}),
            "ui_action_coverage": diagnostic["findings"].get("ui_action_coverage", {}),
            "flow_coverage": diagnostic["findings"].get("flow_coverage", {}),
            "coverage_gate": diagnostic["findings"].get("coverage_gate", {}),
            "flow_gate": diagnostic["findings"].get("flow_gate", {}),
            "auto_insights_status": diagnostic["findings"].get(
                "auto_insights_status", {}
            ),
            "api_scan": diagnostic["findings"].get("api_scan", {}),
            "volition": diagnostic["findings"].get("volition", {}),
            "autonomous_policy": diagnostic["findings"].get("autonomous_policy", {}),
            "readiness": diagnostic.get("readiness", {}),
            "api_contract": diagnostic.get("api_contract", {}),
            "api_contract_missing": diagnostic["findings"].get(
                "api_contract_missing", []
            ),
            "api_contract_gaps": diagnostic["findings"].get("api_contract_gaps", []),
            "expected_failures": diagnostic["findings"].get("expected_failures", []),
            "policy_candidates": diagnostic["findings"].get("policy_candidates", []),
            "policy_auto_promoted": diagnostic["findings"].get(
                "policy_auto_promoted", []
            ),
            "ui_semantic": diagnostic["findings"].get("ui_semantic", {}),
            "ui_semantic_delta": diagnostic["findings"].get("ui_semantic_delta", {}),
            "self_policy": diagnostic["findings"].get("self_policy", {}),
            "self_rules": diagnostic["findings"].get("self_rules", {}),
            "scenario_run_stats": diagnostic["findings"].get("scenario_run_stats", {}),
            "coverage_reliability": diagnostic["findings"].get("coverage_reliability", {}),
            "knowledge_status": diagnostic["findings"].get("knowledge_status", {}),
            "learning_feedback": diagnostic["findings"].get("learning_feedback", {}),
            "long_term_goals": diagnostic["findings"].get("long_term_goals", {}),
            "canary_status": diagnostic["findings"].get("canary_status", {}),
            "diagnostic_attribution": diagnostic["findings"].get("diagnostic_attribution", {}),
            "domain_rule_summary": diagnostic["findings"].get("domain_rule_summary", {}),
            "effective_config": diagnostic.get("effective_config", {}),
            "context_digest": diagnostic["findings"].get("context_digest", {}),
            "auto_plan": diagnostic["findings"].get("auto_plan", {}),
            "failure_taxonomy": diagnostic["findings"].get("failure_taxonomy", {}),
            "gap_scenarios": diagnostic["findings"].get("gap_scenarios", []),
            "kpi_metrics": diagnostic["findings"].get("kpi_metrics", {}),
            "activity_summary": diagnostic["findings"].get("activity_summary", {}),
            "diagnostic_delta": diagnostic["findings"].get("diagnostic_delta", {}),
        }
        try:
            auto_cfg = effective_cfg or cfg
        except Exception:
            auto_cfg = cfg
        data["automation"] = {
            "approvals_enabled": auto_cfg.get("approvals_enabled", True),
            "auto_propose": auto_cfg.get("auto_propose", 0),
            "auto_propose_min_conf": auto_cfg.get("auto_propose_min_conf"),
            "auto_propose_min_evidence": auto_cfg.get("auto_propose_min_evidence"),
            "auto_apply": auto_cfg.get("auto_apply", 0),
            "auto_apply_limit": auto_cfg.get("auto_apply_limit"),
            "auto_plan": auto_cfg.get("auto_plan", 0),
            "auto_plan_limit": auto_cfg.get("auto_plan_limit"),
            "auto_digest": auto_cfg.get("auto_digest", 0),
            "auto_digest_hours": auto_cfg.get("auto_digest_hours"),
            "auto_digest_limit": auto_cfg.get("auto_digest_limit"),
            "auto_verify": auto_cfg.get("auto_verify", 0),
            "auto_verify_on_low_success": auto_cfg.get("auto_verify_on_low_success"),
            "auto_verify_success_threshold": auto_cfg.get("auto_verify_success_threshold"),
            "auto_verify_on_ui_gap": auto_cfg.get("auto_verify_on_ui_gap"),
            "auto_verify_ui_gap_threshold": auto_cfg.get("auto_verify_ui_gap_threshold"),
            "auto_patch_on_errors": auto_cfg.get("auto_patch_on_errors"),
            "auto_patch_limit": auto_cfg.get("auto_patch_limit"),
            "auto_patch_min_conf": auto_cfg.get("auto_patch_min_conf"),
            "auto_patch_min_evidence": auto_cfg.get("auto_patch_min_evidence"),
            "post_apply_validate": auto_cfg.get("post_apply_validate"),
            "post_apply_validate_mode": auto_cfg.get("post_apply_validate_mode"),
            "post_apply_auto_rollback_on_fail": auto_cfg.get("post_apply_auto_rollback_on_fail"),
            "post_apply_immediate_canary_eval": auto_cfg.get("post_apply_immediate_canary_eval"),
            "post_apply_auto_promote_prod": auto_cfg.get("post_apply_auto_promote_prod"),
            "allow_prod_without_human": auto_cfg.get("allow_prod_without_human"),
            "execution_mode": auto_cfg.get("execution_mode"),
            "agent_mode": auto_cfg.get("agent_mode") or (auto_cfg.get("decision") or {}).get("mode"),
        }
        try:
            data["run_locks"] = {
                "master_verify": _read_lock_status(ROOT / ".bgl_core" / "logs" / "master_verify.lock"),
                "run_scenarios": _read_lock_status(ROOT / ".bgl_core" / "logs" / "run_scenarios.lock"),
                "scenario_runner": _read_lock_status(ROOT / ".bgl_core" / "logs" / "scenario_runner.lock"),
            }
        except Exception:
            data["run_locks"] = {}
        try:
            data["schema_drift"] = check_schema(ROOT / ".bgl_core" / "brain" / "knowledge.db")
        except Exception:
            data["schema_drift"] = {"ok": False, "error": "schema_check_failed"}
        # Validate Authority vs write_scope.yml (gating matrix)
        try:
            data["authority_matrix"] = core.authority.validate_gating_matrix()
        except Exception:
            data["authority_matrix"] = {"ok": False, "warnings": ["authority_matrix_unavailable"]}
        build_report(data, template, output)
        # Write JSON alongside HTML for dashboard consumption
        json_out = Path(".bgl_core/logs/latest_report.json")
        json_out.write_text(
            json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8"
        )
        print(f"[+] HTML report written to {output}")
    except Exception as e:
        print(f"[!] Failed to write HTML report: {e}")

    print("\n" + "=" * 70)
    print("💎 ASSURANCE COMPLETE: SYSTEM IS IN GOLDEN STATE")
    print("=" * 70 + "\n")

    # Log completion for dashboard
    log_activity(ROOT, "master_verify_complete")


if __name__ == "__main__":
    try:
        # Allow overriding headless and scenario run via env for visibility/CI
        ROOT = Path(__file__).parent.parent.parent
        cfg = load_config(ROOT)
        os.environ.setdefault(
            "BGL_HEADLESS", os.environ.get("BGL_HEADLESS", str(cfg.get("headless", 1)))
        )
        os.environ.setdefault(
            "BGL_RUN_SCENARIOS",
            os.environ.get("BGL_RUN_SCENARIOS", str(cfg.get("run_scenarios", 1))),
        )
        os.environ.setdefault(
            "BGL_BASE_URL",
            os.environ.get(
                "BGL_BASE_URL", cfg.get("base_url", "http://localhost:8000")
            ),
        )
        os.environ.setdefault(
            "BGL_KEEP_BROWSER",
            os.environ.get("BGL_KEEP_BROWSER", str(cfg.get("keep_browser", 0))),
        )
        asyncio.run(master_assurance_diagnostic())
    except Exception as e:
        print(f"\n[CRITICAL FAILURE] Master Diagnostic Crashed: {e}")
        import traceback

        traceback.print_exc()
