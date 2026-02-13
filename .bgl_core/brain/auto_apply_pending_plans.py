import json
import os
import sqlite3
import subprocess
import sys
import time
from pathlib import Path
from typing import List, Dict, Any

from config_loader import load_config  # type: ignore


ROOT = Path(__file__).resolve().parents[2]
DB_PATH = ROOT / ".bgl_core" / "brain" / "knowledge.db"
STATUS_PATH = ROOT / ".bgl_core" / "logs" / "diagnostic_status.json"


def _read_json(path: Path) -> Dict[str, Any]:
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {}


def _pending_proposals(conn: sqlite3.Connection, limit: int) -> List[Dict[str, Any]]:
    conn.row_factory = sqlite3.Row
    cur = conn.execute(
        """
        SELECT p.*
        FROM agent_proposals p
        LEFT JOIN proposal_outcome_links l ON l.proposal_id = p.id
        WHERE p.solution IS NOT NULL AND TRIM(p.solution) != ''
          AND l.id IS NULL
        ORDER BY p.id DESC
        LIMIT ?
        """,
        (limit,),
    )
    return [dict(r) for r in cur.fetchall()]


def main() -> None:
    cfg = load_config(ROOT)
    auto_apply = str(cfg.get("auto_apply", "0")).strip().lower() in ("1", "true", "yes", "on")
    if not auto_apply:
        print(json.dumps({"ok": False, "skipped": True, "reason": "auto_apply_disabled"}))
        return

    status = _read_json(STATUS_PATH)
    if str(status.get("status") or "").lower().strip() == "running":
        print(json.dumps({"ok": False, "skipped": True, "reason": "diagnostic_running"}))
        return

    if not DB_PATH.exists():
        print(json.dumps({"ok": False, "error": "db_missing"}))
        return

    limit = int(cfg.get("auto_apply_limit", 3) or 3)
    timeout_sec = int(cfg.get("auto_apply_timeout_sec", 300) or 300)
    exe = sys.executable or "python"
    script = ROOT / ".bgl_core" / "brain" / "apply_proposal.py"
    if not script.exists():
        print(json.dumps({"ok": False, "error": "apply_proposal_missing"}))
        return

    conn = sqlite3.connect(str(DB_PATH))
    try:
        pending = _pending_proposals(conn, limit)
    finally:
        try:
            conn.close()
        except Exception:
            pass

    applied: List[Dict[str, Any]] = []
    failed: List[Dict[str, Any]] = []

    for prop in pending:
        pid = prop.get("id")
        if not pid:
            continue
        start = time.time()
        try:
            env = os.environ.copy()
        except Exception:
            env = None
        try:
            proc = subprocess.run(
                [exe, str(script), "--proposal", str(pid)],
                cwd=str(ROOT),
                capture_output=True,
                text=True,
                timeout=timeout_sec,
                env=env,
            )
            if proc.returncode == 0:
                applied.append({"id": pid, "name": prop.get("name")})
            else:
                failed.append(
                    {
                        "id": pid,
                        "name": prop.get("name"),
                        "stderr": (proc.stderr or "").strip()[:400],
                    }
                )
        except Exception as e:
            failed.append({"id": pid, "name": prop.get("name"), "error": str(e)[:400]})
        finally:
            if (time.time() - start) < 1:
                time.sleep(0.25)

    print(
        json.dumps(
            {
                "ok": True,
                "applied": applied,
                "failed": failed,
                "pending": len(pending),
                "ts": time.time(),
            },
            ensure_ascii=False,
        )
    )


if __name__ == "__main__":
    main()
