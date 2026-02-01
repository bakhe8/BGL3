import subprocess
import shutil
import os
import time
import sqlite3
from pathlib import Path
from typing import Dict, Any, List

try:
    from .browser_core import BrowserCore  # type: ignore
    from .fault_locator import FaultLocator  # type: ignore
    from .config_loader import load_config  # type: ignore
except ImportError:
    from browser_core import BrowserCore
    from fault_locator import FaultLocator
    from config_loader import load_config


class SafetyNet:
    def __init__(
        self,
        root_dir: Path,
        base_url: str = "http://localhost:8000",
        enable_browser: bool | None = None,
    ):
        self.root_dir = root_dir
        self.backups: Dict[str, Path] = {}  # Map file_path to backup_path
        cfg = load_config(root_dir)
        # Browser enable/disable (default off unless explicitly enabled)
        env_skip = os.environ.get("BGL_SKIP_BROWSER") == "1"
        env_enable = os.environ.get("BGL_ENABLE_BROWSER") == "1"
        cfg_enable = bool(int(str(cfg.get("browser_enabled", 0)))) if cfg else False
        self.enable_browser = (
            enable_browser
            if enable_browser is not None
            else (False if env_skip else (env_enable or cfg_enable))
        )
        # Keep reports/logs co-located with the agent instead of cwd
        self.browser = None
        if self.enable_browser:
            self.browser = BrowserCore(
                base_url=base_url,
                headless=True,
                keep_page=True,
                max_idle_seconds=120,
                cpu_max_percent=self._safe_float(
                    cfg.get("browser_cpu_max", os.getenv("BGL_BROWSER_CPU_MAX", None))
                ),
                ram_min_gb=self._safe_float(
                    cfg.get(
                        "browser_ram_min_gb", os.getenv("BGL_BROWSER_RAM_MIN_GB", None)
                    )
                ),
            )
        db_path = self.root_dir / ".bgl_core" / "brain" / "knowledge.db"
        self.locator = FaultLocator(db_path, root_dir)
        self.runtime_rules_path = (
            self.root_dir / ".bgl_core" / "brain" / "runtime_safety.yml"
        )

    @staticmethod
    def _safe_float(val):
        if val in (None, "", "null"):
            return None
        try:
            f = float(val)
            return f if f != 0.0 else None
        except Exception:
            return None

    def create_backup(self, file_path: Path) -> Path:
        """Creates a protected backup of a file before patching."""
        backup_dir = self.root_dir / ".bgl_core" / "backups"
        backup_dir.mkdir(parents=True, exist_ok=True)
        # Use filename + timestamp to prevent collisions
        ts = int(time.time())
        backup_path = backup_dir / f"{file_path.name}.{ts}.bak"
        shutil.copy2(file_path, backup_path)
        self.backups[str(file_path)] = backup_path
        return backup_path

    def preflight(self, file_path: Path) -> Dict[str, Any]:
        """Pre-checks runtime safety rules (e.g., writability) before patching."""
        # RS001: writability check
        issues = []
        try:
            if not file_path.exists():
                issues.append(f"Target file missing: {file_path}")
                return {"valid": False, "reason": "; ".join(issues)}
            # Try to make the file writable if needed (Windows git clones sometimes read-only)
            if file_path.exists() and not os.access(file_path, os.W_OK):
                try:
                    os.chmod(file_path, 0o666)
                    subprocess.run(
                        ["attrib", "-R", str(file_path)], capture_output=True
                    )
                except Exception:
                    pass
            if not os.access(file_path, os.W_OK):
                issues.append(f"File not writable: {file_path}")

            parent = file_path.parent
            if not os.access(parent, os.W_OK):
                try:
                    os.chmod(parent, 0o777)
                    subprocess.run(["attrib", "-R", str(parent)], capture_output=True)
                except Exception:
                    pass
            if not os.access(parent, os.W_OK):
                issues.append(f"Directory not writable: {parent}")
        except Exception as e:
            issues.append(f"Writability check error: {e}")

        if issues:
            return {"valid": True, "warning": "; ".join(issues)}
        return {"valid": True}

    def validate(
        self, file_path: Path, impacted_tests: List[str] | None = None
    ) -> Dict[str, Any]:
        """Runs the validation chain: php -l -> PHPUnit -> Architectural Audit."""
        import time

        start_time = time.time()

        # 1. Lint Check (Mandatory)
        lint_res = self._check_lint(file_path)
        if not lint_res["valid"]:
            return {"valid": False, "reason": "Lint Error: " + lint_res["output"]}

        # 2. PHPUnit (If applicable)
        test_res = self._check_phpunit(file_path, impacted_tests)
        if not test_res["valid"]:
            return {"valid": False, "reason": "Test Failure: " + test_res["output"]}

        # 3. Browser Audit (Frontend Perception)
        browser_res = self._check_browser_audit()

        # Gather logs regardless of browser success to provide context
        unified_logs = self._gather_unified_logs(
            start_time, browser_res.get("report", {})
        )

        if not browser_res["valid"]:
            return {
                "valid": False,
                "reason": "Frontend Error detected",
                "logs": unified_logs,
            }

        # 4. Architectural Audit
        audit_res = self._check_architectural_rules(file_path)
        if not audit_res["valid"]:
            return {
                "valid": False,
                "reason": "Architectural Violation: " + audit_res["output"],
                "logs": unified_logs,
            }

        return {"valid": True, "logs": unified_logs}

    def _gather_unified_logs(
        self, start_time: float, browser_report: Dict[str, Any]
    ) -> List[Dict[str, Any]]:
        """Correlates frontend and backend logs."""
        logs = []

        # 1. Add Browser Console Errors
        for msg in browser_report.get("console_errors", []):
            logs.append(
                {
                    "source": "frontend_console",
                    "severity": "ERROR",
                    "message": msg,
                    "timestamp": time.time(),  # Approximation
                }
            )

        # 2. Add Network Failures
        for fail in browser_report.get("network_failures", []):
            entry = {
                "source": "frontend_network",
                "severity": "ERROR",
                "message": f"Failed to load {fail['url']}: {fail['error']}",
                "timestamp": time.time(),
            }
            # Attempt Localization
            location = self.locator.locate_url(fail["url"])
            if location:
                entry["suspect_code"] = {
                    "file": location["file_path"],
                    "controller": location["controller"],
                    "action": location["action"],
                }
            logs.append(entry)

        # 3. Add Backend Logs (Laravel)
        backend_logs = self._read_backend_logs(start_time)
        logs.extend(backend_logs)

        # Sort by timestamp
        return sorted(logs, key=lambda x: x.get("timestamp", 0))

    def _read_backend_logs(self, start_time: float) -> List[Dict[str, Any]]:
        """Reads and filters Laravel logs."""
        log_path = self.root_dir / "storage" / "logs" / "app.log"
        if not log_path.exists():
            return []

        parsed_logs = []
        try:
            with open(log_path, "r") as f:
                # Read last 50 lines for efficiency
                lines = f.readlines()[-50:]
                for line in lines:
                    # Simple parse: [YYYY-MM-DD HH:MM:SS] SEVERITY: message
                    if line.startswith("["):
                        try:
                            ts_str = line[1:20]
                            # Convert to timestamp
                            from datetime import datetime

                            log_ts = datetime.strptime(
                                ts_str, "%Y-%m-%d %H:%M:%S"
                            ).timestamp()

                            # Use 60 second buffer to handle clock drift/second-level precision
                            if log_ts >= (start_time - 60):
                                parts = line[22:].split(":", 1)
                                severity = (
                                    parts[0].strip() if len(parts) > 1 else "INFO"
                                )
                                message = (
                                    parts[1].strip()
                                    if len(parts) > 1
                                    else parts[0].strip()
                                )

                                parsed_logs.append(
                                    {
                                        "source": "backend_laravel",
                                        "severity": severity,
                                        "message": message,
                                        "timestamp": log_ts,
                                    }
                                )
                        except ValueError:
                            continue
        except Exception as e:
            print(f"[!] Error reading backend logs: {e}")

        return parsed_logs

    def _tests_from_experiences(self, file_path: Path) -> List[str]:
        """Derive test files to run based on experiential memory linking to the file."""
        db_path = self.root_dir / ".bgl_core" / "brain" / "knowledge.db"
        if not db_path.exists():
            return []
        try:
            conn = sqlite3.connect(str(db_path))
            cur = conn.cursor()
            pattern = f"%{file_path.name}%"
            rows = cur.execute(
                """
                SELECT related_files FROM experiences
                WHERE confidence >= 0.6 AND related_files LIKE ?
                ORDER BY created_at DESC
                LIMIT 5
                """,
                (pattern,),
            ).fetchall()
            conn.close()
            tests: List[str] = []
            for (related,) in rows:
                for rel in (related or "").split(","):
                    stem = Path(rel.strip()).stem
                    for suite in ["tests/Unit", "tests/Feature", "tests/Integration"]:
                        candidate = self.root_dir / suite / f"{stem}Test.php"
                        if candidate.exists() and str(candidate) not in tests:
                            tests.append(str(candidate))
            return tests
        except Exception as e:
            print(f"[!] Unable to derive tests from experiences: {e}")
            return []

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

    def _check_phpunit(
        self, file_path: Path, impacted_tests: List[str] | None = None
    ) -> Dict[str, Any]:
        """Runs PHPUnit on targeted tests if available; otherwise, falls back to fast suite if configured.
        If impacted_tests provided, run those first (selective re-verify)."""
        tests_dir = self.root_dir / "tests"
        phpunit_bin = self.root_dir / "vendor" / "bin" / "phpunit"
        config = self.root_dir / "phpunit.xml"

        if not phpunit_bin.exists():
            return {"valid": True, "output": "PHPUnit skipped (binary missing)"}

        # Map to nearest test; else run a fast group if defined
        args = ["php", str(phpunit_bin)]

        target_tests = []
        if tests_dir.exists():
            candidate_name = file_path.stem + "Test.php"
            target_tests = list(tests_dir.rglob(candidate_name))

        # Highest priority: impacted tests discovered from dependency graph
        if impacted_tests:
            existing = [Path(t) for t in impacted_tests if Path(t).exists()]
            if existing:
                args.extend([str(p) for p in existing])
                mode = f"impacted:{','.join([p.name for p in existing])}"
            else:
                impacted_tests = None  # fall back

        # Second priority: experiences mentioning this file
        exp_tests: List[str] = []
        if not impacted_tests:
            exp_tests = self._tests_from_experiences(file_path)
            if exp_tests:
                args.extend(exp_tests)
                mode = f"experience:{','.join([Path(t).name for t in exp_tests])}"

        if target_tests and not impacted_tests and not exp_tests:
            args.append(str(target_tests[0]))
            mode = f"targeted:{target_tests[0].name}"
        else:
            # Fallback: run tests marked fast if available
            if config.exists() and tests_dir.exists():
                args.extend(["--group", "fast"])
                mode = "group:fast"
            else:
                return {"valid": True, "output": "PHPUnit skipped (no tests mapped)"}

        try:
            result = subprocess.run(args, capture_output=True, text=True, timeout=120)
            return {
                "valid": result.returncode == 0,
                "output": f"{mode}\n" + (result.stdout + result.stderr).strip(),
            }
        except subprocess.TimeoutExpired:
            return {"valid": False, "output": f"{mode}: timeout"}
        except Exception as e:
            return {"valid": False, "output": f"{mode}: error {e}"}

    async def _async_browser_check(self) -> Dict[str, Any]:
        """Internal async bridge for Playwright."""
        try:
            report = await self.browser.scan_url("/")
            if (
                report["status"] != "SUCCESS"
                or report["console_errors"]
                or report["network_failures"]
            ):
                return {
                    "valid": False,
                    "report": report,
                    "errors": {
                        "console": report["console_errors"],
                        "network": report["network_failures"],
                    },
                }
            return {"valid": True, "report": report}
        except Exception as e:
            return {"valid": False, "errors": str(e), "report": {}}

    def _check_browser_audit(self) -> Dict[str, Any]:
        """Synchronous wrapper for the async browser scan."""
        import asyncio

        if not self.enable_browser or not self.browser:
            return {"valid": True, "report": {}}

        # If a loop is already running, we might need to handle it differently,
        # but for scripts this is standard.
        try:
            return asyncio.run(self._async_browser_check())
        except RuntimeError:
            # Fallback when already in event loop (e.g., within Guardian async flow)
            loop = asyncio.get_event_loop()
            task = loop.create_task(self._async_browser_check())
            return loop.run_until_complete(task)
        except Exception as e:
            return {"valid": False, "errors": f"Sensor Execution Error: {e}"}

    def _check_architectural_rules(self, file_path: Path) -> Dict[str, Any]:
        """Runs BGLGovernor to ensure no domain rules were broken."""
        try:
            from governor import BGLGovernor

            db_path = self.root_dir / ".bgl_core" / "brain" / "knowledge.db"
            rules_path = self.root_dir / ".bgl_core" / "brain" / "domain_rules.yml"
            style_path = self.root_dir / ".bgl_core" / "brain" / "style_rules.yml"

            if not db_path.exists() or not rules_path.exists():
                return {"valid": True, "output": "Governor skipped (missing configs)"}

            gov = BGLGovernor(db_path, rules_path, style_path)
            violations = gov.audit()

            # Filter violations for the specific file if possible, or fail on ANY new violation
            if violations:
                # Allow WARN-only violations to pass with context
                severities = {v.get("severity", "").upper() for v in violations}
                msg = "\n".join([v["message"] for v in violations])
                if severities.issubset({"WARN", "INFO"}):
                    return {"valid": True, "output": f"Warnings: {msg}"}
                return {"valid": False, "output": msg}

            return {"valid": True, "output": "Architecture clean"}
        except Exception as e:
            return {"valid": False, "output": f"Governor Error: {e}"}
