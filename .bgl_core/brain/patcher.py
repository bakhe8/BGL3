import subprocess
import json
import os
import shutil
import sqlite3
from pathlib import Path
from typing import Dict, Any
from safety import SafetyNet
from execution_gate import check
from decision_engine import decide
from intent_resolver import resolve_intent
from config_loader import load_config
from decision_db import init_db, insert_intent, insert_decision, insert_outcome
import json


class BGLPatcher:
    def __init__(self, project_root: Path):
        self.project_root = project_root
        self.patcher_path = (
            self.project_root / ".bgl_core" / "actuators" / "patcher.php"
        )
        self.safety = SafetyNet(project_root)
        self.composer_path = self._discover_composer(project_root)
        self.config = load_config(project_root)
        env_decision_db = os.environ.get("BGL_SANDBOX_DECISION_DB")
        self.decision_db_path = Path(env_decision_db) if env_decision_db else project_root / ".bgl_core" / "brain" / "decision.db"
        self.decision_schema = project_root / ".bgl_core" / "brain" / "decision_schema.sql"
        if self.decision_schema.exists():
            init_db(self.decision_db_path, self.decision_schema)
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

        # determine effective execution mode (auto_trial may flip to direct when eligible)
        effective_mode = self.execution_mode
        if effective_mode == "auto_trial" and self._eligible_for_direct():
            effective_mode = "direct"

        # Decision layer: resolve intent and gate execution (observe-first)
        intent_payload = {
            "intent": "refactor" if action == "rename_class" else "auto_fix",
            "confidence": 0.8,
            "reason": f"{action} requested on {file_path.name}",
            "scope": [str(file_path)],
            "context_snapshot": {
                "health": {},
                "active_route": None,
                "recent_changes": [],
                "guardian_top": [],
                "browser_state": "unknown",
            },
        }
        policy = self.config.get("decision", {})
        decision_payload = decide(intent_payload, policy)
        intent_id = insert_intent(
            self.decision_db_path,
            intent_payload["intent"],
            intent_payload["confidence"],
            intent_payload["reason"],
            json.dumps(intent_payload["scope"]),
            json.dumps(intent_payload["context_snapshot"]),
            source="patcher",
        )
        decision_id = insert_decision(
            self.decision_db_path,
            intent_id,
            decision_payload.get("decision", "observe"),
            decision_payload.get("risk_level", "low"),
            bool(decision_payload.get("requires_human", False)),
            "; ".join(decision_payload.get("justification", [])),
        )
        # Allow only if gate passes
        if not check(decision_payload, action):
            insert_outcome(self.decision_db_path, decision_id, "blocked", "Gate blocked action")
            return {"status": "blocked", "message": "Execution blocked by decision gate (needs approval)."}

        # Execution mode enforcement / telemetry
        if effective_mode == "direct":
            insert_outcome(self.decision_db_path, decision_id, "mode_direct", "Executed in direct mode (live tree)")

        if not file_path.exists():
            # Attempt to copy from main working tree if available (to handle untracked files)
            main_root = os.environ.get("BGL_MAIN_ROOT")
            if main_root:
                main_candidate = Path(main_root) / file_path.relative_to(self.project_root)
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
            return {"status": "error", "message": preflight.get("reason", "Preflight failed")}

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
                # Update references (including tests) before running validation
                if action == "rename_class":
                    self._update_references(params.get("old_name", ""), params.get("new_name", ""))

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
                        file_path.name.replace(params.get("old_name", ""), params.get("new_name", ""))
                    )
                    # safer: recompute with new class name
                    target_path = file_path.with_name(f"{params.get('new_name','')}.php")
                    if not target_path.exists() and path_info.exists():
                        target_path = path_info
                    impacted_tests = self._derive_impacted_tests(params.get("old_name", ""), params.get("new_name", ""))
                    impacted_files |= self._derive_impacted_files(params.get("old_name", ""), params.get("new_name", ""))
                    impacted_files.add(target_path)
                # Selective reindex: only impacted files
                self._post_patch_index(list(impacted_files))

                validation = self.safety.validate(target_path, impacted_tests=impacted_tests)
                if not validation["valid"]:
                    self.safety.rollback(file_path)
                    msg = validation.get("reason", "Validation failed")
                    if 'decision_id' in locals():
                        insert_outcome(self.decision_db_path, decision_id, "fail", msg)
                    return {"status": "error", "message": msg, "logs": validation.get("logs", [])}
                self.safety.clear_backup(file_path)

            if 'decision_id' in locals():
                insert_outcome(self.decision_db_path, decision_id, "success", "Action completed")
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
            if 'decision_id' in locals():
                insert_outcome(self.decision_db_path, decision_id, "fail", error_msg)
            return {
                "status": "error",
                "message": f"PHP Error: {error_msg}. See sandbox PHP_DEBUG_LOG.txt",
            }
        except Exception as e:
            if not dry_run:
                self.safety.rollback(file_path)
            if 'decision_id' in locals():
                insert_outcome(self.decision_db_path, decision_id, "fail", str(e))
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
        db_path = Path(os.environ.get("BGL_SANDBOX_DB", self.project_root / ".bgl_core" / "brain" / "knowledge.db"))
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
        db_path = Path(os.environ.get("BGL_SANDBOX_DB", self.project_root / ".bgl_core" / "brain" / "knowledge.db"))
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
        db_path = Path(sandbox_db_env) if sandbox_db_env else self.project_root / ".bgl_core" / "brain" / "knowledge.db"
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
        db_path = Path(sandbox_db_env) if sandbox_db_env else self.project_root / ".bgl_core" / "brain" / "knowledge.db"
        indexer = EntityIndexer(self.project_root, db_path)
        indexer.index_project()
        indexer.close()

    def _discover_composer(self, root: Path) -> list[str] | None:
        """Find composer executable/phar. Hard requirement: return path or None (fail)."""
        env_path = os.environ.get("BGL_COMPOSER_PATH")
        candidates = []
        if env_path:
            candidates.append(env_path)
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
            c = Path(cand)
            if c.exists():
                if c.suffix == ".phar":
                    return ["php", str(c)]
                return [str(c)]
        return None

    def _eligible_for_direct(self, required_successes: int = 5) -> bool:
        """Direct mode trial: allow only if آخر N نتائج نجاح بلا فشل/بلوك."""
        try:
            conn = sqlite3.connect(str(self.decision_db_path))
            cur = conn.cursor()
            cur.execute(
                """
                SELECT result FROM outcomes
                WHERE result IN ('success','fail','blocked','mode_direct')
                ORDER BY id DESC LIMIT ?
                """,
                (required_successes,),
            )
            rows = [r[0] for r in cur.fetchall()]
            conn.close()
            if len(rows) < required_successes:
                return False
            return all(r == "success" for r in rows)
        except Exception:
            return False

    def _refresh_autoload(self) -> bool:
        """Run composer dump-autoload in sandbox. Policy: hard requirement."""
        if not self.composer_path:
            print("[!] Composer not found; rename_class requires composer.")
            return False
        try:
            cmd = [*self.composer_path, "dump-autoload"]
            shell = False
            # On Windows .bat files need shell=True
            if len(self.composer_path) == 1 and self.composer_path[0].lower().endswith(".bat"):
                shell = True
                cmd = " ".join(cmd)
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
