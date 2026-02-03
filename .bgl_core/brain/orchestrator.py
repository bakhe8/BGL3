import json
import os
import subprocess
from pathlib import Path
from typing import Dict, Any, List, Optional, TypedDict
from patcher import BGLPatcher
from guardrails import BGLGuardrails
from safety import SafetyNet

try:
    from .guardian import BGLGuardian  # type: ignore
except ImportError:
    from guardian import BGLGuardian
from sandbox import BGLSandbox
try:
    from .authority import Authority  # type: ignore
    from .brain_types import ActionRequest, ActionKind  # type: ignore
except Exception:
    from authority import Authority
    from brain_types import ActionRequest, ActionKind


class ExecutionReport(TypedDict):
    task: str
    status: str
    changes: List[str]
    checks: List[str]
    rollback_performed: bool
    message: str
    unified_logs: List[Dict[str, Any]]
    guardian_insights: Optional[Dict[str, Any]]


class BGLOrchestrator:
    def __init__(self, root_dir: Path):
        self.root_dir = root_dir
        self.guardrails = BGLGuardrails(root_dir)
        self.safety = SafetyNet(root_dir)
        self.guardian = BGLGuardian(root_dir)
        self.authority = Authority(root_dir)

    def execute_task(self, task_spec: Dict[str, Any]) -> ExecutionReport:
        """
        Executes a task based on the formal Task Spec JSON and returns an Execution Report.
        """
        task_name = str(task_spec.get("task", "unknown"))
        target_path_raw = str(task_spec.get("target", {}).get("path", ""))
        params = task_spec.get("params", {})
        dry_run = bool(params.get("dry_run", False))

        # Initialize Execution Report
        report: ExecutionReport = {
            "task": task_name,
            "status": "PENDING",
            "changes": [],
            "checks": [],
            "rollback_performed": False,
            "message": "",
            "unified_logs": [],
            "guardian_insights": None,
        }

        # Determine relative path
        if os.path.isabs(target_path_raw):
            try:
                rel_path = str(Path(target_path_raw).relative_to(self.root_dir))
            except ValueError:
                rel_path = target_path_raw
        else:
            rel_path = target_path_raw

        # 1. Guardrail: Path Allowlist
        if not self.guardrails.is_path_allowed(rel_path):
            report["status"] = "FAILED"
            report["message"] = (
                f"Guardrail violation: Path {rel_path} is blocked or not allowed."
            )
            return report

        # 2. Setup Sandbox
        sandbox = BGLSandbox(self.root_dir)
        sandbox_root = sandbox.setup()
        if not sandbox_root:
            report["status"] = "FAILED"
            report["message"] = "Failed to initialize sandbox environment."
            return report

        try:
            # Create patcher in sandbox
            patcher = BGLPatcher(sandbox_root)
            sandbox_target_path = sandbox_root / rel_path

            # Pass the main vendor path to the sandbox
            main_vendor = str(self.root_dir / "vendor")
            os.environ["BGL_VENDOR_PATH"] = main_vendor
            os.environ["BGL_MAIN_ROOT"] = str(self.root_dir)

            print(f"[*] Executing task '{task_name}' in sandbox: {rel_path}...")

            if task_name == "rename_class":
                res = patcher.rename_class(
                    sandbox_target_path,
                    params.get("old_name"),
                    params.get("new_name"),
                    dry_run=dry_run,
                )
            elif task_name == "add_method":
                res = patcher.add_method(
                    sandbox_target_path,
                    params.get("target_class"),
                    params.get("method_name"),
                    dry_run=dry_run,
                )
            elif task_name == "write_file":
                # Generic write handler (create/update)
                content = params.get("content", "")
                mode = params.get("mode", "w")  # w=write, a=append
                try:
                    if dry_run:
                        res = {
                            "status": "success",
                            "message": f"dry_run: would write file: {rel_path}",
                        }
                    else:
                        # Gate sandbox write (single source of truth)
                        req = ActionRequest(
                            kind=ActionKind.WRITE_SANDBOX,
                            operation=f"orchestrator.write_file|{rel_path}",
                            command=f"write_file {rel_path}",
                            scope=[rel_path],
                            reason="orchestrator write_file requested",
                            confidence=0.8,
                            metadata={"mode": mode, "bytes": len(str(content))},
                        )
                        gate = self.authority.gate(req, source="orchestrator")
                        if not gate.allowed:
                            report["status"] = "BLOCKED"
                            report["message"] = gate.message or "Blocked by authority gate."
                            report["guardian_insights"] = {
                                "permission_id": gate.permission_id,
                                "intent_id": gate.intent_id,
                                "decision_id": gate.decision_id,
                            }
                            return report

                        sandbox_target_path.parent.mkdir(parents=True, exist_ok=True)
                        with open(sandbox_target_path, mode, encoding="utf-8") as f:
                            f.write(content)
                        # best-effort audit
                        try:
                            if gate.decision_id:
                                self.authority.record_outcome(
                                    int(gate.decision_id), "success", "sandbox write completed"
                                )
                        except Exception:
                            pass
                        res = {"status": "success", "message": f"File written: {rel_path}"}
                except Exception as e:
                    try:
                        # If we created a gate decision id above, record a fail outcome
                        if "gate" in locals() and getattr(gate, "decision_id", None):
                            self.authority.record_outcome(
                                int(gate.decision_id), "fail", f"write_file failed: {e}"
                            )
                    except Exception:
                        pass
                    res = {"status": "error", "message": f"Write failed: {e}"}
            else:
                res = {"status": "error", "message": f"Unknown task: {task_name}"}

            # Map patcher results to the unified report
            if res.get("status") == "success":
                # 3. Safety Check (Unified Perception)

                # [FIX] For write_file, we validate the PARENT of the file or the file itself if it exists
                target_to_validate = sandbox_target_path

                val_res = self.safety.validate(target_to_validate)
                report["unified_logs"] = val_res.get("logs", [])

                if val_res["valid"]:
                    report["status"] = "SUCCESS"
                    report["changes"].append(rel_path)
                    report["message"] = res.get(
                        "message", "Task completed and verified"
                    )
                    report["checks"] = [
                        "lint",
                        "phpunit",
                        "browser_audit",
                        "architectural_audit",
                    ]
                else:
                    report["status"] = "FAILED"
                    report["message"] = f"Validation Failed: {val_res.get('reason')}"
                    report["rollback_performed"] = not dry_run
            else:
                report["status"] = "FAILED"
                report["message"] = res.get("message", "Patching failed")
                report["rollback_performed"] = not dry_run

            # 4. If success and not dry run, apply to main
            if report["status"] == "SUCCESS" and not dry_run:
                # Gate the prod write: applying sandbox diff back to the main project
                changed_files: List[str] = []
                try:
                    proc = subprocess.run(
                        ["git", "-C", str(sandbox_root), "diff", "--name-only"],
                        capture_output=True,
                        text=True,
                        check=False,
                    )
                    changed_files = [ln.strip() for ln in proc.stdout.splitlines() if ln.strip()]
                except Exception:
                    changed_files = []
                scope = changed_files[:50] or [rel_path]

                req = ActionRequest(
                    kind=ActionKind.WRITE_PROD,
                    operation=f"orchestrator.apply_to_main|{task_name}|{rel_path}",
                    command=f"apply sandbox diff -> main ({len(scope)} file(s))",
                    scope=scope,
                    reason="apply sandbox changes to main working tree",
                    confidence=0.85,
                    metadata={"task": task_name, "changed_files_count": len(changed_files)},
                )
                gate = self.authority.gate(req, source="orchestrator")
                if not gate.allowed:
                    report["status"] = "BLOCKED"
                    report["message"] = gate.message or "Awaiting human approval to apply changes to main."
                    report["guardian_insights"] = {
                        "permission_id": gate.permission_id,
                        "intent_id": gate.intent_id,
                        "decision_id": gate.decision_id,
                    }
                    try:
                        if gate.decision_id:
                            self.authority.record_outcome(
                                int(gate.decision_id), "blocked", "awaiting approval/apply_to_main"
                            )
                    except Exception:
                        pass
                    return report

                sandbox.apply_to_main(rel_path)
                try:
                    if gate.decision_id:
                        self.authority.record_outcome(
                            int(gate.decision_id), "success", "applied sandbox diff to main"
                        )
                except Exception:
                    pass

            return report

        finally:
            sandbox.cleanup()


if __name__ == "__main__":
    # Test Orchestration
    ROOT = Path(__file__).parent.parent.parent
    orchestrator = BGLOrchestrator(ROOT)

    # Example Task Spec
    spec = {
        "task": "rename_class",
        "target": {"path": "app/Services/NamingViolationService.php"},
        "params": {
            "old_name": "NamingViolationService",
            "new_name": "NamingViolationService",
            "dry_run": True,
        },
    }

    res = orchestrator.execute_task(spec)
    print(json.dumps(res, indent=2))
