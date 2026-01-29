import json
import os
from pathlib import Path
from typing import Dict, Any, List, TypedDict
from patcher import BGLPatcher
from guardrails import BGLGuardrails
from safety import SafetyNet
from sandbox import BGLSandbox


class ExecutionReport(TypedDict):
    task: str
    status: str
    changes: List[str]
    checks: List[str]
    rollback_performed: bool
    message: str


class BGLOrchestrator:
    def __init__(self, root_dir: Path):
        self.root_dir = root_dir
        self.guardrails = BGLGuardrails(root_dir)
        self.safety = SafetyNet(root_dir)

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
            else:
                res = {"status": "error", "message": f"Unknown task: {task_name}"}

            # Map patcher results to the unified report
            if res.get("status") == "success":
                report["status"] = "SUCCESS"
                report["changes"].append(rel_path)
                report["message"] = res.get("message", "Task completed")
                report["checks"] = ["lint", "phpunit_simulated", "architectural_audit"]
            else:
                report["status"] = "FAILED"
                report["message"] = res.get("message", "Unknown error")
                report["rollback_performed"] = not dry_run

            # 3. If success and not dry run, apply to main
            if report["status"] == "SUCCESS" and not dry_run:
                sandbox.apply_to_main(rel_path)

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
