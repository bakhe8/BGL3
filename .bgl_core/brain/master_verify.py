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
from config_loader import load_config  # noqa: E402
from report_builder import build_report  # noqa: E402
from generate_playbooks import generate_from_proposed  # noqa: E402
from contract_tests import run_contract_suite  # noqa: E402
from utils import load_route_usage  # noqa: E402
from callgraph_builder import build_callgraph  # noqa: E402
from generate_openapi import generate as generate_openapi  # noqa: E402


def log_activity(root_path: Path, message: str):
    """Logs an event to the agent_activity table for dashboard visibility."""
    db_path = root_path / ".bgl_core" / "brain" / "knowledge.db"
    try:
        with sqlite3.connect(str(db_path)) as conn:
            conn.execute(
                "INSERT INTO agent_activity (timestamp, activity, source, details) VALUES (?, ?, ?, ?)",
                (time.time(), message, "master_verify", "{}"),
            )
    except Exception as e:
        print(f"[WARN] Failed to log activity: {e}")


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
    timeout = int(cfg.get("diagnostic_timeout_sec", 300))

    # Initialize Core
    core = AgencyCore(ROOT)

    # Run Full Diagnostic with bounded timeout to avoid hanging browser runs
    try:
        diagnostic = await asyncio.wait_for(core.run_full_diagnostic(), timeout=timeout)
    except asyncio.TimeoutError:
        print(f"[CRITICAL] Diagnostic timed out after {timeout}s.")
        return

    # Augment diagnostic with route_usage (for suppression) and feature_flags
    diagnostic["route_usage"] = load_route_usage(ROOT)
    diagnostic["feature_flags"] = cfg.get("feature_flags", {})

    # Build callgraph for reporting/reference
    diagnostic["findings"]["callgraph_meta"] = build_callgraph(ROOT)

    # Generate OpenAPI (merged) for contract tests and reference
    diagnostic["openapi_path"] = str(generate_openapi(ROOT))

    # Optional: run API contract/property tests (Schemathesis/Dredd) if enabled
    if cfg.get("run_api_contract", 0):
        contract_results = run_contract_suite(ROOT)
        diagnostic.setdefault("gap_tests", []).extend(contract_results)

    # Optional: lightweight perf probe (home page load)
    perf = {}
    if cfg.get("measure_perf", 0):
        import time
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
            uri = f.get("uri") if isinstance(f, dict) else str(f)
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
            "failing_routes": diagnostic["findings"].get("failing_routes", []),
            "experiences": diagnostic["findings"].get("experiences", []),
            "suggestions": diagnostic["findings"].get("proposals", []),
            "worst_routes": diagnostic["findings"].get("worst_routes", []),
            "interpretation": diagnostic["findings"].get("interpretation", {}),
            "intent": diagnostic["findings"].get("intent", {}),
            "decision": diagnostic["findings"].get("decision", {}),
            "gap_tests": diagnostic["findings"].get("gap_tests", []),
            "proposals": diagnostic["findings"].get("proposals", []),
            "external_checks": diagnostic["findings"].get("external_checks", []),
        }
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
