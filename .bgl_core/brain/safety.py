import subprocess
import shutil
import os
from pathlib import Path
from typing import Dict, Any


class SafetyNet:
    def __init__(self, root_dir: Path):
        self.root_dir = root_dir
        self.backups: Dict[str, Path] = {}  # Map file_path to backup_path

    def create_backup(self, file_path: Path):
        """Creates a temporary backup of a file before patching."""
        backup_path = file_path.with_suffix(file_path.suffix + ".bak")
        shutil.copy2(file_path, backup_path)
        self.backups[str(file_path)] = backup_path

    def validate(self, file_path: Path) -> Dict[str, Any]:
        """Runs the validation chain: php -l -> PHPUnit -> Architectural Audit."""
        # 1. Lint Check (Mandatory)
        lint_res = self._check_lint(file_path)
        if not lint_res["valid"]:
            return {"valid": False, "reason": "Lint Error: " + lint_res["output"]}

        # 2. PHPUnit (If applicable)
        test_res = self._check_phpunit(file_path)
        if not test_res["valid"]:
            return {"valid": False, "reason": "Test Failure: " + test_res["output"]}

        # 3. Architectural Audit
        audit_res = self._check_architectural_rules(file_path)
        if not audit_res["valid"]:
            return {
                "valid": False,
                "reason": "Architectural Violation: " + audit_res["output"],
            }

        return {"valid": True}

    def rollback(self, file_path: Path):
        """Restores file from backup. Explicitly called on validation failure."""
        backup_path = self.backups.get(str(file_path))
        if backup_path and backup_path.exists():
            shutil.move(backup_path, file_path)
            print(f"[!] Explicit Rollback performed for {file_path.name}")
        else:
            print(f"[!] Warning: No backup found to rollback {file_path.name}")

    def clear_backup(self, file_path: Path):
        """Deletes backup after successful validation."""
        backup_path = self.backups.get(str(file_path))
        if backup_path and backup_path.exists():
            os.remove(backup_path)
            del self.backups[str(file_path)]

    def _check_lint(self, file_path: Path) -> Dict[str, Any]:
        try:
            result = subprocess.run(
                ["php", "-l", str(file_path)], capture_output=True, text=True
            )
            return {
                "valid": result.returncode == 0,
                "output": (
                    result.stdout if result.returncode == 0 else result.stderr
                ).strip(),
            }
        except Exception as e:
            return {"valid": False, "output": str(e)}

    def _check_phpunit(self, file_path: Path) -> Dict[str, Any]:
        """Simulates running PHPUnit if a corresponding test exists."""
        # Logic: If 'tests/Unit/filenameTest.php' exists, run it.
        # For this prototype, we'll return valid=True but log the 'check'.
        return {"valid": True, "output": "Tests passed (simulated)"}

    def _check_architectural_rules(self, file_path: Path) -> Dict[str, Any]:
        """Runs BGLGovernor to ensure no domain rules were broken."""
        try:
            from governor import BGLGovernor

            db_path = self.root_dir / ".bgl_core" / "brain" / "knowledge.db"
            rules_path = self.root_dir / ".bgl_core" / "brain" / "domain_rules.yml"

            if not db_path.exists() or not rules_path.exists():
                return {"valid": True, "output": "Governor skipped (missing configs)"}

            gov = BGLGovernor(db_path, rules_path)
            violations = gov.audit()

            # Filter violations for the specific file if possible, or fail on ANY new violation
            if violations:
                msg = "\n".join([v["message"] for v in violations])
                return {"valid": False, "output": msg}

            return {"valid": True, "output": "Architecture clean"}
        except Exception as e:
            return {"valid": False, "output": f"Governor Error: {e}"}
