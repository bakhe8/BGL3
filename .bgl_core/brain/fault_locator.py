import sqlite3
from pathlib import Path
from typing import Dict, Any, Optional, List
from urllib.parse import urlparse


class FaultLocator:
    def __init__(self, db_path: Path, project_root: Path):
        self.db_path = db_path
        self.project_root = project_root
        self.log_path = self.project_root / "storage" / "logs" / "app.log"

    def get_context_from_log(self, lines_to_read: int = 100) -> str:
        """Reads the last N lines from the application's log file."""
        if not self.log_path.exists():
            return "Log file not found."

        try:
            with open(self.log_path, "r", encoding="utf-8") as f:
                # Read all lines and take the last N
                lines = f.readlines()
                last_lines = lines[-lines_to_read:]
                return "".join(last_lines)
        except Exception as e:
            return f"Error reading log file: {e}"

    def _locate_url(self, url: str) -> Optional[Dict[str, Any]]:
        """
        Maps a URL to its backend route information. (Internal method)
        """
        parsed = urlparse(url)
        path = parsed.path
        if not path:
            path = "/"

        # Normalize: ensure leading slash, remove trailing
        if not path.startswith("/"):
            path = "/" + path
        if len(path) > 1 and path.endswith("/"):
            path = path[:-1]

        conn = sqlite3.connect(str(self.db_path))
        conn.row_factory = sqlite3.Row
        cursor = conn.cursor()

        # Try exact match first
        cursor.execute("SELECT * FROM routes WHERE uri = ?", (path,))
        row = cursor.fetchone()

        if not row:
            # Try cleaning .php extension if present in input but not in DB or vice versa
            alt_path = (
                path.replace(".php", "") if path.endswith(".php") else path + ".php"
            )
            cursor.execute("SELECT * FROM routes WHERE uri = ?", (alt_path,))
            row = cursor.fetchone()

        if row:
            result = dict(row)
            conn.close()
            return result

        conn.close()
        return None

    def locate_url(self, url: str) -> Optional[Dict[str, Any]]:
        """Public wrapper used by Guardian and Safety layers."""
        return self._locate_url(url)

    def diagnose_fault(self, url: str) -> Dict[str, Any]:
        """
        Diagnoses a fault by locating the URL and grabbing recent log context.
        """
        location_info = self._locate_url(url)
        log_context = self.get_context_from_log()

        return {
            "fault_location": location_info or "Unknown",
            "log_context": log_context,
            "url": url,
        }


if __name__ == "__main__":
    # Note: For direct execution, the project root needs to be set correctly.
    # This assumes the script is in .bgl_core/brain
    PROJECT_ROOT = Path(__file__).parent.parent.parent
    DB = PROJECT_ROOT / ".bgl_core" / "brain" / "knowledge.db"
    
    locator = FaultLocator(DB, PROJECT_ROOT)
    
    # Test with a URL
    test_url = "http://localhost:8000/api/get-record.php?index=1"
    diagnosis = locator.diagnose_fault(test_url)

    print("--- Fault Diagnosis ---")
    print(f"URL: {diagnosis['url']}")
    print("\n[Location Info]")
    print(diagnosis['fault_location'])
    print("\n[Recent Log Context]")
    print(diagnosis['log_context'])
    print("-----------------------")
