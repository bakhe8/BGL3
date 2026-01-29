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

    def setup(self):
        self.sandbox_path = Path(tempfile.mkdtemp(prefix="bgl_sandbox_"))
        print(f"[*] Setting up sandbox at {self.sandbox_path}")

        try:
            subprocess.run(
                ["git", "clone", str(self.root_dir), str(self.sandbox_path)],
                check=True,
                capture_output=True,
            )

            # Junction vendor for PHP support
            main_vendor = self.root_dir / "vendor"
            sandbox_vendor = self.sandbox_path / "vendor"
            if main_vendor.exists():
                os.system(f'mklink /J "{sandbox_vendor}" "{main_vendor}"')

            return self.sandbox_path
        except Exception as e:
            print(f"[!] Sandbox setup failed: {e}")
            self.sandbox_path = None
            return None

    def apply_to_main(self, rel_path: str):
        if not self.sandbox_path:
            return
        src = self.sandbox_path / rel_path
        dst = self.root_dir / rel_path
        if src.exists():
            shutil.copy2(src, dst)
            print(f"[+] Applied sandbox changes for {rel_path} to main project.")

    def cleanup(self):
        if self.sandbox_path and self.sandbox_path.exists():
            # Remove junction
            sandbox_vendor = self.sandbox_path / "vendor"
            if sandbox_vendor.exists():
                os.system(f'rmdir "{sandbox_vendor}"')

            def remove_readonly(func, path, excinfo):
                os.chmod(path, stat.S_IWRITE)
                func(path)

            shutil.rmtree(self.sandbox_path, onerror=remove_readonly)
            print(f"[*] Sandbox at {self.sandbox_path} cleaned up.")
            self.sandbox_path = None
