import subprocess
import json
import os
import shutil
import sqlite3
from pathlib import Path
from typing import Dict, Any, List
from safety import SafetyNet
from config_loader import load_config

try:
    from .authority import Authority  # type: ignore
    from .brain_types import ActionRequest, ActionKind  # type: ignore
    from .test_gate import evaluate_files, require_tests_enabled  # type: ignore
except Exception:
    from authority import Authority
    from brain_types import ActionRequest, ActionKind
    from test_gate import evaluate_files, require_tests_enabled


class BGLPatcher:
    def __init__(self, project_root: Path):
        self.project_root = project_root
        self.patcher_path = (
            self.project_root / ".bgl_core" / "actuators" / "patcher.php"
        )
        self.safety = SafetyNet(project_root)
        self.composer_path = self._discover_composer(project_root)
        self.config = load_config(project_root)
        self.authority = Authority(project_root)
        # Legacy compat: many helpers still refer to this path name
        self.decision_db_path = self.authority.db_path
        self.execution_mode = str(self.config.get("execution_mode", "sandbox")).lower()

    def rename_class(
        self, file_path: Path, old_name: str, new_name: str, dry_run: bool = False
    ) -> Dict[str, Any]:
        params = {"old_name": old_name, "new_name": new_name, "dry_run": dry_run}
        result = self._run_action(file_path, "rename_class", params)
        # If rename succeeded, propagate reference updates across allowed project areas
        if result.get("status") == "success" and not dry_run:
            self._update_references(old_name, new_name)
        return result

    def rename_reference(
        self, file_path: Path, old_name: str, new_name: str, dry_run: bool = False
    ) -> Dict[str, Any]:
        params = {"old_name": old_name, "new_name": new_name, "dry_run": dry_run}
        return self._run_action(file_path, "rename_reference", params)

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

    def add_import(
        self,
        file_path: Path,
        import_name: str,
        alias: str | None = None,
        dry_run: bool = False,
    ) -> Dict[str, Any]:
        params = {"import": import_name, "alias": alias, "dry_run": dry_run}
        return self._run_action(file_path, "add_import", params)

    def replace_block(
        self,
        file_path: Path,
        match: str,
        content: str,
        regex: bool = False,
        count: int | None = None,
        dry_run: bool = False,
    ) -> Dict[str, Any]:
        params = {
            "match": match,
            "content": content,
            "regex": regex,
            "count": count,
            "dry_run": dry_run,
        }
        return self._run_action(file_path, "replace_block", params)

    def toggle_flag(
        self,
        file_path: Path,
        flag: str,
        value: object = True,
        dry_run: bool = False,
    ) -> Dict[str, Any]:
        params = {"flag": flag, "value": value, "dry_run": dry_run}
        return self._run_action(file_path, "toggle_flag", params)

    def insert_event(
        self,
        file_path: Path,
        match: str,
        content: str,
        regex: bool = False,
        mode: str | None = None,
        dry_run: bool = False,
    ) -> Dict[str, Any]:
        params = {
            "match": match,
            "content": content,
            "regex": regex,
            "mode": mode,
            "dry_run": dry_run,
        }
        return self._run_action(file_path, "insert_event", params)

    def _run_action(
        self, file_path: Path, action: str, params: Dict[str, Any]
    ) -> Dict[str, Any]:
        dry_run = params.get("dry_run", False)

        effective_mode = self.authority.effective_execution_mode()

        # ---- Authority Gate (single source of truth) ----
        try:
            rel = str(file_path.relative_to(self.project_root)).replace("\\", "/")
        except Exception:
            rel = str(file_path)

        # Decide whether this write targets a sandbox tree (vs prod tree).
        # Heuristic: if BGL_MAIN_ROOT is set and differs from this project_root, we're in a sandbox copy.
        sandbox_tree = False
        main_root = os.environ.get("BGL_MAIN_ROOT")
        if main_root:
            try:
                sandbox_tree = Path(main_root).resolve() != self.project_root.resolve()
            except Exception:
                sandbox_tree = True

        if dry_run:
            kind = ActionKind.PROBE
        else:
            kind = ActionKind.WRITE_SANDBOX if sandbox_tree else ActionKind.WRITE_PROD

        op_parts: List[str] = [f"patch.{action}", rel]
        cmd = f"{action} {rel}"
        if action == "rename_class":
            old = str(params.get("old_name", ""))
            new = str(params.get("new_name", ""))
            op_parts.extend([old, "->", new])
            cmd = f"rename_class {rel} {old} -> {new}"
        elif action == "add_method":
            tgt = str(params.get("target_class", ""))
            m = str(params.get("method_name", ""))
            op_parts.extend([tgt, m])
            cmd = f"add_method {rel} {tgt}::{m}"
        elif action == "add_import":
            imp = str(params.get("import", ""))
            alias = str(params.get("alias", "") or "")
            op_parts.extend([imp, alias] if alias else [imp])
            cmd = f"add_import {rel} {imp}" + (f" as {alias}" if alias else "")
        elif action == "replace_block":
            op_parts.append("replace_block")
            cmd = f"replace_block {rel}"
        elif action == "toggle_flag":
            flag = str(params.get("flag", ""))
            op_parts.append(flag)
            cmd = f"toggle_flag {rel} {flag}"
        elif action == "insert_event":
            op_parts.append("insert_event")
            cmd = f"insert_event {rel}"

        operation = "|".join([p for p in op_parts if p])
        meta = {
            "action": action,
            "dry_run": bool(dry_run),
            "effective_mode": effective_mode,
            "sandbox_tree": sandbox_tree,
            # Avoid dumping very large content bodies in the DB snapshot
            "params": {k: v for k, v in params.items() if k != "content"},
        }
        req = ActionRequest(
            kind=kind,
            operation=operation,
            command=cmd,
            scope=[rel],
            reason=f"{action} requested on {file_path.name}",
            confidence=0.8,
            metadata=meta,
        )
        gate_res = self.authority.gate(req, source="patcher")
        decision_id = int(gate_res.decision_id or 0)
        if not gate_res.allowed:
            return {
                "status": "blocked",
                "message": gate_res.message or "Blocked by authority gate.",
                "permission_id": gate_res.permission_id,
                "gate": {
                    "decision_id": gate_res.decision_id,
                    "intent_id": gate_res.intent_id,
                    "requires_human": gate_res.requires_human,
                },
            }

        # Enforce required tests for high-risk files before any patch execution.
        if not dry_run:
            require_tests = require_tests_enabled(
                self.project_root, bool(self.config.get("require_tests", False))
            )
            allow_scenarios = bool(int(os.getenv("BGL_ALLOW_SCENARIO_AS_TEST", "1")))
            gate = evaluate_files(
                self.project_root,
                [rel],
                require_tests=require_tests,
                allow_scenarios=allow_scenarios,
            )
            if not gate.get("ok", True):
                msg = "Required tests missing for high-risk file."
                try:
                    msg = "; ".join(gate.get("errors") or []) or msg
                except Exception:
                    pass
                try:
                    self.authority.record_outcome(decision_id, "blocked", msg)
                except Exception:
                    pass
                return {"status": "blocked", "message": msg}

        # Execution mode enforcement / telemetry
        if effective_mode == "direct":
            self.authority.record_outcome(
                decision_id,
                "mode_direct",
                "Executed in direct mode (live tree)",
            )

        if not file_path.exists():
            # Attempt to copy from main working tree if available (to handle untracked files)
            main_root = os.environ.get("BGL_MAIN_ROOT")
            if main_root:
                main_candidate = Path(main_root) / file_path.relative_to(
                    self.project_root
                )
                if main_candidate.exists():
                    file_path.parent.mkdir(parents=True, exist_ok=True)
                    shutil.copy2(main_candidate, file_path)
            if not file_path.exists():
                return {
                    "status": "error",
                    "message": f"Target file not found: {file_path}",
                }

        # Preflight runtime safety rules (writability, etc.)
        preflight = self.safety.preflight(file_path)
        if not preflight.get("valid", True):
            return {
                "status": "error",
                "message": preflight.get("reason", "Preflight failed"),
            }

        backup_path = ""
        if not dry_run:
            backup_path = str(self.safety.create_backup(file_path))

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
                # Update references (including tests) before running validation
                if action == "rename_class":
                    self._update_references(
                        params.get("old_name", ""), params.get("new_name", "")
                    )

                # Regenerate autoload in sandbox to reflect new classmap (simplest reliable fix; can be optimized لاحقاً)
                if not self._refresh_autoload():
                    return {
                        "status": "error",
                        "message": "Composer autoload refresh failed (see logs).",
                    }

                # Re-index impacted file(s) and dependent calls for better downstream checks
                # Use new path if file was renamed (PSR-4)
                target_path = file_path
                impacted_tests: list[str] = []
                impacted_files: set[Path] = set([file_path])
                if action == "rename_class":
                    path_info = file_path.with_name(
                        file_path.name.replace(
                            params.get("old_name", ""), params.get("new_name", "")
                        )
                    )
                    # safer: recompute with new class name
                    target_path = file_path.with_name(
                        f"{params.get('new_name', '')}.php"
                    )
                    if not target_path.exists() and path_info.exists():
                        target_path = path_info
                    impacted_tests = self._derive_impacted_tests(
                        params.get("old_name", ""), params.get("new_name", "")
                    )
                    impacted_files |= self._derive_impacted_files(
                        params.get("old_name", ""), params.get("new_name", "")
                    )
                    impacted_files.add(target_path)
                # Selective reindex: only impacted files
                self._post_patch_index(list(impacted_files))

                validation = self.safety.validate(
                    target_path, impacted_tests=impacted_tests
                )
                if not validation["valid"]:
                    self.safety.rollback(file_path)
                    msg = validation.get("reason", "Validation failed")
                    self.authority.record_outcome(
                        decision_id, "fail", msg, backup_path=backup_path
                    )
                    return {
                        "status": "error",
                        "message": msg,
                        "logs": validation.get("logs", []),
                    }
                self.safety.clear_backup(file_path)

            self.authority.record_outcome(
                decision_id,
                "success",
                "Action completed",
                backup_path=backup_path,
            )
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
            except Exception:
                pass

            error_msg = (e.stdout + "\n" + e.stderr).strip()
            self.authority.record_outcome(decision_id, "fail", error_msg)
            return {
                "status": "error",
                "message": f"PHP Error: {error_msg}. See sandbox PHP_DEBUG_LOG.txt",
            }
        except Exception as e:
            if not dry_run:
                self.safety.rollback(file_path)
            self.authority.record_outcome(decision_id, "fail", str(e))
            return {"status": "error", "message": str(e)}

    def _update_references(self, old_name: str, new_name: str):
        """
        Best-effort reference rename across key project directories.
        Works inside sandbox; changes will be diff-applied back to main.
        Uses AST-based rename_reference action to avoid accidental text replacements.
        """
        targets = ["app", "api", "templates", "views", "partials", "tests"]
        vendor_path = os.environ.get(
            "BGL_VENDOR_PATH", str(self.project_root / "vendor")
        )
        params = json.dumps({"old_name": old_name, "new_name": new_name})

        for top in targets:
            base = self.project_root / top
            if not base.exists():
                continue
            for path in base.rglob("*.php"):
                try:
                    cmd = [
                        "php",
                        str(self.patcher_path),
                        str(path),
                        "rename_reference",
                        params,
                        vendor_path,
                    ]
                    subprocess.run(cmd, capture_output=True, check=True, text=True)
                except subprocess.CalledProcessError as e:
                    print(f"[!] Reference update failed for {path}: {e.stderr}")
                except Exception as e:
                    print(f"[!] Reference update skipped for {path}: {e}")

    def _derive_impacted_tests(self, old_class: str, new_class: str) -> list[str]:
        """Find tests touching callers of the renamed class using call graph (entity+method) and fallback heuristics."""
        db_path = Path(
            os.environ.get(
                "BGL_SANDBOX_DB",
                self.project_root / ".bgl_core" / "brain" / "knowledge.db",
            )
        )
        tests: set[str] = set()
        try:
            conn = sqlite3.connect(str(db_path))
            cur = conn.cursor()
            norm_old = old_class.split("\\")[-1]
            norm_new = new_class.split("\\")[-1]
            cur.execute(
                """
                SELECT DISTINCT f.path
                FROM calls c
                JOIN methods m ON c.source_method_id = m.id
                JOIN entities e ON m.entity_id = e.id
                JOIN files f ON e.file_id = f.id
                WHERE c.target_entity LIKE ? OR c.target_entity LIKE ? OR c.target_entity IN (?, ?)
                """,
                (f"%{norm_old}", f"%{norm_new}", norm_old, norm_new),
            )
            rows = cur.fetchall()
            conn.close()
            for (rel_path,) in rows:
                stem = Path(rel_path).stem
                for suite in ["tests/Unit", "tests/Feature", "tests/Integration"]:
                    candidate = self.project_root / suite / f"{stem}Test.php"
                    if candidate.exists():
                        tests.add(str(candidate))
        except Exception as e:
            print(f"[!] Impacted test discovery failed: {e}")
        return list(tests)

    def _derive_impacted_files(self, old_class: str, new_class: str) -> set[Path]:
        """Find caller files of the renamed class to reindex selectively."""
        db_path = Path(
            os.environ.get(
                "BGL_SANDBOX_DB",
                self.project_root / ".bgl_core" / "brain" / "knowledge.db",
            )
        )
        files: set[Path] = set()
        try:
            conn = sqlite3.connect(str(db_path))
            cur = conn.cursor()
            norm_old = old_class.split("\\")[-1]
            norm_new = new_class.split("\\")[-1]
            cur.execute(
                """
                SELECT DISTINCT f.path
                FROM calls c
                JOIN methods m ON c.source_method_id = m.id
                JOIN entities e ON m.entity_id = e.id
                JOIN files f ON e.file_id = f.id
                WHERE c.target_entity LIKE ? OR c.target_entity LIKE ? OR c.target_entity IN (?, ?)
                """,
                (f"%{norm_old}", f"%{norm_new}", norm_old, norm_new),
            )
            rows = cur.fetchall()
            conn.close()
            for (rel_path,) in rows:
                candidate = self.project_root / rel_path
                if candidate.exists():
                    files.add(candidate)
        except Exception as e:
            print(f"[!] Impacted file discovery failed: {e}")
        return files

    def _post_patch_index(self, paths: list[Path]):
        """Re-index modified PHP files to keep knowledge.db fresh."""
        try:
            from indexer import EntityIndexer
        except ImportError:
            from .indexer import EntityIndexer  # type: ignore

        sandbox_db_env = os.environ.get("BGL_SANDBOX_DB")
        db_path = (
            Path(sandbox_db_env)
            if sandbox_db_env
            else self.project_root / ".bgl_core" / "brain" / "knowledge.db"
        )
        indexer = EntityIndexer(self.project_root, db_path)
        rels = [str(p.relative_to(self.project_root)) for p in paths if p.exists()]
        if rels:
            indexer.update_impacted(rels)
        indexer.close()

    def _post_patch_index_all(self):
        """Full project reindex (used after rename for accurate dependency graph)."""
        try:
            from indexer import EntityIndexer
        except ImportError:
            from .indexer import EntityIndexer  # type: ignore

        sandbox_db_env = os.environ.get("BGL_SANDBOX_DB")
        db_path = (
            Path(sandbox_db_env)
            if sandbox_db_env
            else self.project_root / ".bgl_core" / "brain" / "knowledge.db"
        )
        indexer = EntityIndexer(self.project_root, db_path)
        indexer.index_project()
        indexer.close()

    def _discover_composer(self, root: Path) -> list[str] | None:
        """Find composer executable/phar. Hard requirement: return path or None (fail)."""
        env_path = os.environ.get("BGL_COMPOSER_PATH")
        candidates: List[Path] = []
        if env_path:
            candidates.append(Path(env_path))
        candidates.extend(
            [
                root / "composer.bat",
                root / "vendor" / "bin" / "composer.bat",
                root / "composer.phar",
            ]
        )
        # Fallback to main root if provided (allows sandbox to use host composer)
        main_root = os.environ.get("BGL_MAIN_ROOT")
        if main_root:
            mr = Path(main_root)
            candidates.extend(
                [
                    mr / "composer.bat",
                    mr / "vendor" / "bin" / "composer.bat",
                    mr / "composer.phar",
                ]
            )
        for cand in candidates:
            c = cand
            if c.exists():
                if c.suffix == ".phar":
                    return ["php", str(c)]
                return [str(c)]
        return None

    def _refresh_autoload(self) -> bool:
        """Run composer dump-autoload in sandbox. Policy: hard requirement."""
        if not self.composer_path:
            print("[!] Composer not found; rename_class requires composer.")
            return False
        try:
            cmd = [*self.composer_path, "dump-autoload"]
            shell = False
            # On Windows .bat files need shell=True
            if len(self.composer_path) == 1 and self.composer_path[0].lower().endswith(
                ".bat"
            ):
                shell = True
                cmd = " ".join(cmd)  # type: ignore
            proc = subprocess.run(
                cmd,
                cwd=str(self.project_root),
                capture_output=True,
                text=True,
                shell=shell,
            )
            if proc.returncode != 0:
                print(f"[!] composer dump-autoload failed: {proc.stderr.strip()}")
                return False
            return True
        except Exception as e:
            print(f"[!] composer dump-autoload error: {e}")
            return False
