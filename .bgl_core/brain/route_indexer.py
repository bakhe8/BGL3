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

    def run(self):
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

        # 2. Scan api directory
        api_dir = self.root_dir / "api"
        if api_dir.exists():
            for php_file in api_dir.glob("*.php"):
                rel_path = php_file.relative_to(self.root_dir)
                uri = f"/api/{php_file.name}"

                # Basic assumption: API files handle POST/GET
                routes.append(
                    {
                        "uri": uri,
                        "http_method": "ANY",
                        "action": str(rel_path.as_posix()),
                        "file_path": str(php_file),
                    }
                )

        # 3. Enhanced Analysis: Look for primary Controllers/Services
        for route in routes:
            route["controller"], route["action_method"] = self._analyze_file(
                Path(route["file_path"])
            )

        # 4. Store in DB
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


if __name__ == "__main__":
    ROOT = Path(__file__).parent.parent.parent
    DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"
    indexer = LaravelRouteIndexer(ROOT, DB)
    indexer.run()
