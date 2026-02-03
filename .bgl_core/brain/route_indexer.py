import re
from pathlib import Path
from typing import Optional


from memory import StructureMemory


class LaravelRouteIndexer:
    def __init__(self, root_dir: Path, db_path: Path):
        self.root_dir = root_dir
        self.db_path = db_path
        # Ensure schema is initialized
        self.memory = StructureMemory(db_path)

    def run(self, return_routes: bool = False):
        print(f"[*] Indexing routes for {self.root_dir}")
        import sqlite3

        conn = sqlite3.connect(str(self.db_path))
        cursor = conn.cursor()

        # Routes will be updated or ignored via ON CONFLICT

        routes = []

        # 1. Index root index.php
        routes.append(
            {
                "uri": "/",
                "http_method": "GET",
                "action": "index.php",
                "file_path": str(self.root_dir / "index.php"),
            }
        )

        # 2. Scan api directory (Recursive)
        api_dir = self.root_dir / "api"
        if api_dir.exists():
            for php_file in api_dir.rglob("*.php"):
                rel_path = php_file.relative_to(self.root_dir)
                uri = f"/{rel_path.as_posix()}"

                # Basic assumption: API files handle POST/GET
                try:
                    content = php_file.read_text(encoding="utf-8", errors="ignore")
                except Exception:
                    content = ""
                http_method = self._infer_method(php_file.name, content)
                routes.append(
                    {
                        "uri": uri,
                        "http_method": http_method,
                        "action": str(rel_path.as_posix()),
                        "file_path": str(php_file),
                    }
                )

        # 3. Scan views directory (Recursive)
        views_dir = self.root_dir / "views"
        if views_dir.exists():
            for php_file in views_dir.rglob("*.php"):
                rel_path = php_file.relative_to(self.root_dir)
                uri = f"/{rel_path.as_posix()}"
                routes.append(
                    {
                        "uri": uri,
                        "http_method": "GET",
                        "action": str(rel_path.as_posix()),
                        "file_path": str(php_file),
                    }
                )

        # 4. Enhanced Analysis: Look for primary Controllers/Services
        for route in routes:
            route["controller"], route["action_method"] = self._analyze_file(
                Path(route["file_path"])
            )

        # 5. Store in DB
        for route in routes:
            cursor.execute(
                """
                INSERT INTO routes (uri, http_method, controller, action, file_path)
                VALUES (?, ?, ?, ?, ?)
                ON CONFLICT(uri, http_method) DO UPDATE SET
                    controller=excluded.controller,
                    action=excluded.action,
                    file_path=excluded.file_path
            """,
                (
                    route["uri"],
                    route["http_method"],
                    route["controller"],
                    route["action_method"],
                    route["file_path"],
                ),
            )

        conn.commit()
        conn.close()
        print(f"[+] Indexed {len(routes)} routes.")
        if return_routes:
            return routes

    # Alias for callers expecting index_project
    def index_project(self, return_routes: bool = False):
        return self.run(return_routes=return_routes)

    def _analyze_file(self, file_path: Path) -> tuple[Optional[str], Optional[str]]:
        """Attempts to find the primary Service/Controller used in a PHP file."""
        if not file_path.exists():
            return None, None

        content = file_path.read_text(errors="ignore")

        # Look for "new App\Services\...\SomeService" or "use App\Services\SomeService"
        # We prioritize Services and Repositories as 'controllers' in this custom architecture

        service_match = re.search(r"use App\\Services\\([\w\\]+);", content)
        if service_match:
            return service_match.group(1), "handle"  # Default action

        repo_match = re.search(r"use App\\Repositories\\([\w\\]+);", content)
        if repo_match:
            return repo_match.group(1), "query"

        return "GenericHandler", "main"

    def _infer_method(self, filename: str, content: str = "") -> str:
        name = filename.lower()
        lowered = content.lower()
        if "$_post" in lowered or "application/json" in lowered:
            return "POST"
        if (
            "$_get" in lowered
            and "header('content-type: application/json" not in lowered
        ):
            return "GET"
        if "file_upload" in lowered or "multipart/form-data" in lowered:
            return "POST"
        if any(
            name.startswith(prefix)
            for prefix in [
                "create",
                "update",
                "delete",
                "import",
                "save",
                "upload",
                "extend",
                "release",
                "reduce",
                "save-",
            ]
        ):
            return "POST"
        return "GET"


if __name__ == "__main__":
    ROOT = Path(__file__).parent.parent.parent
    DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"
    indexer = LaravelRouteIndexer(ROOT, DB)
    indexer.run()
