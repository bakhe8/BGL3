import subprocess
import shutil
import tempfile
import os
import stat
from pathlib import Path


class BGLSandbox:
    def __init__(self, root_dir: Path):
        self.root_dir = root_dir
        self.sandbox_path = None
        self.sandbox_decision_db = None

    def setup(self):
        self.sandbox_path = Path(tempfile.mkdtemp(prefix="bgl_sandbox_"))
        print(f"[*] Setting up sandbox at {self.sandbox_path}")

        try:
            subprocess.run(
                ["git", "clone", str(self.root_dir), str(self.sandbox_path)],
                check=True,
                capture_output=True,
            )
            # Clear read-only attributes that sometimes come from Windows git clones
            try:
                subprocess.run(
                    ["attrib", "-R", str(self.sandbox_path / "*"), "/S", "/D"],
                    shell=True,
                    capture_output=True,
                )
            except Exception:
                pass

            # Ensure working tree is checked out (git clone in bare could miss files)
            try:
                subprocess.run(
                    ["git", "-C", str(self.sandbox_path), "checkout", "."],
                    capture_output=True,
                    text=True,
                    check=True,
                )
            except Exception as e:
                print(f"[!] Sandbox checkout warning: {e}")

            # Copy untracked files from main into sandbox (excluding heavy/system dirs)
            self._copy_untracked()

            # Use a disposable knowledge DB inside sandbox to avoid locking the main one
            self._prepare_sandbox_db()
            self._prepare_decision_db()

            # Junction vendor for PHP support
            main_vendor = self.root_dir / "vendor"
            sandbox_vendor = self.sandbox_path / "vendor"
            if main_vendor.exists():
                # Always ensure vendor is available via junction to satisfy PHP autoload in sandbox
                try:
                    if sandbox_vendor.exists():
                        # If it's a directory from robocopy, remove and create junction
                        if sandbox_vendor.is_dir() and not sandbox_vendor.is_symlink():
                            shutil.rmtree(sandbox_vendor, ignore_errors=True)
                    os.system(f'mklink /J "{sandbox_vendor}" "{main_vendor}"')
                except Exception as e:
                    print(f"[!] Vendor junction warning: {e}")

            return self.sandbox_path
        except Exception as e:
            print(f"[!] Sandbox setup failed: {e}")
            self.sandbox_path = None
            return None

    def apply_to_main(self, rel_path: str):
        """
        Applies all modified files from the sandbox back to the main project using git diff.
        Falls back to copying the provided path if git diff is unavailable.
        """
        if not self.sandbox_path:
            return

        try:
            # Capture diff from sandbox HEAD to working tree
            diff = subprocess.run(
                ["git", "-C", str(self.sandbox_path), "diff", "--binary"],
                capture_output=True,
                text=True,
                check=True,
            ).stdout
            if diff.strip():
                # Apply diff to main repo
                apply = subprocess.run(
                    ["git", "-C", str(self.root_dir), "apply", "--binary"],
                    input=diff,
                    text=True,
                    capture_output=True,
                )
                if apply.returncode != 0:
                    print(f"[!] git apply failed, falling back to file copy. stderr: {apply.stderr}")
                    raise RuntimeError("git apply failed")
                print("[+] Applied sandbox diff to main project.")
                return
        except Exception as e:
            print(f"[!] Sandbox diff apply failed: {e}")

        # Fallback: copy the single path
        src = self.sandbox_path / rel_path
        dst = self.root_dir / rel_path
        dst.parent.mkdir(parents=True, exist_ok=True)
        if src.exists():
            shutil.copy2(src, dst)
            print(f"[+] Applied sandbox changes for {rel_path} to main project.")
        else:
            print(f"[!] Skipped copy; {rel_path} missing in sandbox. Manual review required.")

    def cleanup(self):
        if self.sandbox_path and self.sandbox_path.exists():
            # Remove junction
            sandbox_vendor = self.sandbox_path / "vendor"
            if sandbox_vendor.exists():
                os.system(f'rmdir "{sandbox_vendor}"')

            def remove_readonly(func, path, excinfo):
                try:
                    os.chmod(path, stat.S_IWRITE)
                except Exception:
                    pass
                func(path)

            import time

            for attempt in range(3):
                try:
                    shutil.rmtree(self.sandbox_path, onerror=remove_readonly)
                    print(f"[*] Sandbox at {self.sandbox_path} cleaned up.")
                    self.sandbox_path = None
                    break
                except PermissionError as e:
                    if attempt == 2:
                        print(f"[!] Sandbox cleanup skipped (locked): {e}")
                    else:
                        time.sleep(0.5)
            # Remove WAL/SHM files if any remain
            if self.sandbox_path:
                for suffix in ["-wal", "-shm"]:
                    wal = (self.sandbox_path / ".bgl_core" / "brain" / f"knowledge.db{suffix}")
                    if wal.exists():
                        try:
                            wal.unlink()
                        except Exception:
                            pass
                if self.sandbox_decision_db:
                    for suffix in ["-wal", "-shm"]:
                        wal = Path(str(self.sandbox_decision_db) + suffix)
                        if wal.exists():
                            try:
                                wal.unlink()
                            except Exception:
                                pass

    def _copy_untracked(self):
        """
        Copy files that git clone might omit (untracked) from main project to sandbox.
        Uses robocopy on Windows for reliability. Skips heavy/system dirs.
        """
        exclude = [".git", "vendor", "node_modules", "storage", ".mypy_cache", ".vscode", ".bgl_core\\logs"]
        src = str(self.root_dir)
        dst = str(self.sandbox_path)
        try:
            # robocopy returns codes >1 sometimes for skipped files; tolerate up to 3
            cmd = ["robocopy", src, dst, "/E"] + [f"/XD {x}" for x in exclude]
            proc = subprocess.run(" ".join(cmd), shell=True)
            if proc.returncode > 3:
                print(f"[!] robocopy returned {proc.returncode}, some files may be missing.")
        except Exception as e:
            print(f"[!] Untracked copy warning: {e}")

    def _prepare_sandbox_db(self):
        """Copy knowledge.db to a sandbox-local temp DB to avoid locking the main file."""
        main_db = self.root_dir / ".bgl_core" / "brain" / "knowledge.db"
        sandbox_db_dir = self.sandbox_path / ".bgl_core" / "brain"
        sandbox_db_dir.mkdir(parents=True, exist_ok=True)
        sandbox_db = sandbox_db_dir / "knowledge.db"
        try:
            shutil.copy2(main_db, sandbox_db)
        except Exception as e:
            print(f"[!] Unable to copy knowledge.db to sandbox: {e}")
        # Point environment so indexer/locators in sandbox use the temp DB
        os.environ["BGL_SANDBOX_DB"] = str(sandbox_db)

    def _prepare_decision_db(self):
        """Legacy hook: now uses knowledge.db; keeps env var for backward compatibility."""
        main_db = self.root_dir / ".bgl_core" / "brain" / "knowledge.db"
        sandbox_db_dir = self.sandbox_path / ".bgl_core" / "brain"
        sandbox_db_dir.mkdir(parents=True, exist_ok=True)
        self.sandbox_decision_db = sandbox_db_dir / "knowledge.db"
        if main_db.exists():
            try:
                shutil.copy2(main_db, self.sandbox_decision_db)
            except Exception as e:
                print(f"[!] Unable to copy knowledge.db to sandbox (decision alias): {e}")
        # keep env var name for legacy code paths
        os.environ["BGL_SANDBOX_DECISION_DB"] = str(self.sandbox_decision_db)
