from pathlib import Path
from typing import List, Set


class BGLGuardrails:
    def __init__(self, root_dir: Path):
        self.root_dir = root_dir
        self.max_files = 10
        self.max_lines = 500
        # Allowlist broadened to cover primary project surfaces while still excluding infrastructure/system dirs
        self.allowlist: Set[str] = {
            "app",
            "api",
            "routes",
            "public",
            "resources",
            "config",
            "templates",
            "views",
            "partials",
            "docs",
        }
        self.blocklist: Set[str] = {
            "vendor",
            "storage",
            "bootstrap",
            ".git",
            ".bgl_core",
        }

    def is_path_allowed(self, rel_path: str) -> bool:
        path_parts = Path(rel_path).parts
        if not path_parts:
            return False

        top_dir = path_parts[0]

        # Must be in allowlist and NOT in blocklist
        if top_dir in self.blocklist:
            return False

        if top_dir not in self.allowlist:
            return False

        return True

    def validate_changes(self, file_count: int, line_count: int):
        if file_count > self.max_files:
            raise PermissionError(
                f"Guardrail violation: Too many files changed ({file_count} > {self.max_files})"
            )

        if line_count > self.max_lines:
            raise PermissionError(
                f"Guardrail violation: Too many lines changed ({line_count} > {self.max_lines})"
            )

    def filter_paths(self, rel_paths: List[str]) -> List[str]:
        return [p for p in rel_paths if self.is_path_allowed(p)]
