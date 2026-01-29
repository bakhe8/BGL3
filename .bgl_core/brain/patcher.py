import subprocess
import json
import os
from pathlib import Path
from typing import Dict, Any
from safety import SafetyNet


class BGLPatcher:
    def __init__(self, project_root: Path):
        self.project_root = project_root
        self.patcher_path = (
            self.project_root / ".bgl_core" / "actuators" / "patcher.php"
        )
        self.safety = SafetyNet(project_root)

    def rename_class(
        self, file_path: Path, old_name: str, new_name: str, dry_run: bool = False
    ) -> Dict[str, Any]:
        params = {"old_name": old_name, "new_name": new_name, "dry_run": dry_run}
        return self._run_action(file_path, "rename_class", params)

    def add_method(
        self,
        file_path: Path,
        target_class: str,
        method_name: str,
        dry_run: bool = False,
    ) -> Dict[str, Any]:
        params = {
            "target_class": target_class,
            "method_name": method_name,
            "dry_run": dry_run,
        }
        return self._run_action(file_path, "add_method", params)

    def _run_action(
        self, file_path: Path, action: str, params: Dict[str, Any]
    ) -> Dict[str, Any]:
        dry_run = params.get("dry_run", False)

        if not dry_run:
            self.safety.create_backup(file_path)

        try:
            # Use environment variable if set by orchestrator, else default to project root / vendor
            vendor_path = os.environ.get(
                "BGL_VENDOR_PATH", str(self.project_root / "vendor")
            )

            cmd = [
                "php",
                str(self.patcher_path),
                str(file_path),
                action,
                json.dumps(params),
                vendor_path,
            ]

            # Explicitly pass current environment to ensure BGL_VENDOR_PATH is seen
            result = subprocess.run(
                cmd, capture_output=True, text=True, check=True, env=os.environ
            )
            output = json.loads(result.stdout)

            if output.get("status") == "success" and not dry_run:
                validation = self.safety.validate(file_path)
                if not validation["valid"]:
                    self.safety.rollback(file_path)
                    return {"status": "error", "message": validation["reason"]}
                self.safety.clear_backup(file_path)

            return output

        except subprocess.CalledProcessError as e:
            if not dry_run:
                self.safety.rollback(file_path)

            # EMERGENCY DEBUG LOGGING
            # Try to write to .bgl_core/logs
            try:
                log_dir = self.project_root / ".bgl_core" / "logs"
                log_dir.mkdir(exist_ok=True)
                debug_log = log_dir / "PHP_DEBUG_LOG.txt"
                with open(debug_log, "w") as f:
                    f.write(f"STDOUT:\n{e.stdout}\n\nSTDERR:\n{e.stderr}")
            except:
                pass

            error_msg = (e.stdout + "\n" + e.stderr).strip()
            return {
                "status": "error",
                "message": f"PHP Error: {error_msg}. See sandbox PHP_DEBUG_LOG.txt",
            }
        except Exception as e:
            if not dry_run:
                self.safety.rollback(file_path)
            return {"status": "error", "message": str(e)}
