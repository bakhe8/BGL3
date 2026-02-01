import os
import json
import subprocess
from pathlib import Path
from memory import StructureMemory


class EntityIndexer:
    def __init__(self, root_dir: Path, db_path: Path):
        self.root_dir = root_dir
        self.memory = StructureMemory(db_path)
        self.sensor_path = self.root_dir / ".bgl_core" / "sensors" / "ast_bridge.php"
        self.skip_dirs = {
            "vendor",
            "node_modules",
            ".git",
            ".bgl_core",
            ".mypy_cache",
            ".vscode",
        }
        self.skip_suffixes = {".bak", ".tmp"}
        self._closed = False

    def update_impacted(self, rel_paths: list[str]):
        """
        Re-index only the provided relative paths (for targeted updates after patch).
        """
        for rel in rel_paths:
            abs_path = self.root_dir / rel
            if abs_path.exists() and abs_path.suffix == ".php":
                self._index_file(abs_path, rel)
        self.close()

    def index_project(self):
        print(f"[*] Starting indexing project at {self.root_dir}")
        count = 0
        for root, dirs, files in os.walk(self.root_dir):
            # Skip hidden dirs and vendor
            dirs[:] = [
                d
                for d in dirs
                if not d.startswith(".") and d != "vendor" and d != "node_modules"
            ]

            for file in files:
                if file.endswith(".php"):
                    abs_path = Path(root) / file
                    rel_path = str(abs_path.relative_to(self.root_dir))

                    if self._should_index(abs_path, rel_path):
                        self._index_file(abs_path, rel_path)
                        count += 1

        print(f"[+] Indexing complete. Processed {count} files.")
        self.close()

    def close(self):
        if not self._closed:
            self.memory.close()
            self._closed = True

    def _should_index(self, abs_path: Path, rel_path: str) -> bool:
        """Only index if mtime differs from memory."""
        try:
            current_mtime = os.path.getmtime(abs_path)
            stored = self.memory.get_file_info(rel_path)
            if not stored:
                return True
            return current_mtime > stored.get("last_modified", 0)
        except Exception:
            return True

    def _index_file(self, abs_path: Path, rel_path: str):
        try:
            # Run AST sensor
            result = subprocess.run(
                ["php", str(self.sensor_path), str(abs_path)],
                capture_output=True,
                text=True,
                check=True,
            )

            output = json.loads(result.stdout)
            if output.get("status") == "success":
                mtime = os.path.getmtime(abs_path)
                file_id = self.memory.register_file(rel_path, mtime)
                self.memory.clear_file_data(file_id)
                self.memory.store_nested_symbols(file_id, output.get("data", []))
                # print(f"    Indexed: {rel_path}")
            else:
                print(f"    [!] Sensor error for {rel_path}: {output.get('message')}")

        except Exception as e:
            print(f"    [!] Failed to index {rel_path}: {str(e)}")


if __name__ == "__main__":
    # For testing:
    ROOT = Path(__file__).parent.parent.parent
    DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"

    indexer = EntityIndexer(ROOT, DB)
    indexer.index_project()
    indexer.memory.close()
