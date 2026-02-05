"""
Lightweight tool layer to let the local LLM (or any caller) trigger safe utilities.
Tools are pure/side-effect-minimal where possible, and return JSON-serializable data.
"""

from pathlib import Path
import json
import subprocess
import sqlite3
import time
from typing import Any, Dict, List

from agent_verify import run_all_checks
from route_indexer import LaravelRouteIndexer
from embeddings import search as embed_search

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"

from perception import UI_MAP_JS  # Shared DOM extraction script
from observations import latest_env_snapshot  # Unified env snapshot accessor
from volition import latest_volition

# ---------- Tool implementations ----------


def tool_run_checks() -> Dict[str, Any]:
    """Run all static checks from inference_patterns.json."""
    return run_all_checks(ROOT)


def tool_route_index() -> Dict[str, Any]:
    """Rebuild route index and return the discovered routes."""
    idx = LaravelRouteIndexer(ROOT, DB)
    routes = idx.run(return_routes=True) or []
    return {"count": len(routes), "routes": routes}


def tool_logic_bridge(payload: Dict[str, Any]) -> Dict[str, Any]:
    """Call the PHP logic bridge with a JSON payload."""
    bridge = ROOT / ".bgl_core" / "brain" / "logic_bridge.php"
    proc = subprocess.run(
        ["php", str(bridge)],
        input=json.dumps(payload).encode("utf-8"),
        capture_output=True,
        cwd=ROOT,
        timeout=10,
    )
    out = proc.stdout.decode("utf-8", errors="ignore")
    try:
        return json.loads(out)
    except Exception:
        return {"status": "ERROR", "message": "Non-JSON response", "raw": out}


def tool_layout_map(url: str, limit: int = 50) -> Dict[str, Any]:
    """
    Capture a lightweight layout map from a page (headless Chromium).
    Returns positions/text/role of interactive elements.
    """
    try:
        from playwright.sync_api import sync_playwright
    except Exception as e:  # pragma: no cover - env dependent
        return {"status": "ERROR", "message": f"Playwright not available: {e}"}

    try:
        with sync_playwright() as p:
            browser = p.chromium.launch(headless=True)
            page = browser.new_page()
            page.goto(url, wait_until="networkidle", timeout=15000)
            layout = page.evaluate(UI_MAP_JS, limit)
            viewport = page.viewport_size
            browser.close()
            # Keep both keys for backwards compatibility across older prompts/tools.
            return {
                "status": "SUCCESS",
                "layout": layout,
                "layout_map": layout,
                "viewport": viewport,
            }
    except Exception as e:
        return {"status": "ERROR", "message": str(e)}


def tool_context_pack() -> Dict[str, Any]:
    """
    Gather hot context: latest intents/decisions/outcomes + routes count.
    """
    ctx: Dict[str, Any] = {}
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    cur = conn.cursor()
    ctx["intents"] = [
        dict(r) for r in cur.execute("SELECT * FROM intents ORDER BY id DESC LIMIT 10")
    ]
    ctx["decisions"] = [
        dict(r)
        for r in cur.execute("SELECT * FROM decisions ORDER BY id DESC LIMIT 10")
    ]
    ctx["outcomes"] = [
        dict(r) for r in cur.execute("SELECT * FROM outcomes ORDER BY id DESC LIMIT 10")
    ]
    try:
        ctx["routes_count"] = cur.execute("SELECT COUNT(*) FROM routes").fetchone()[0]
    except Exception:
        ctx["routes_count"] = 0
    conn.close()
    # Add a unified snapshot (most recent). This is the canonical "what the agent last observed".
    try:
        ctx["env_snapshot"] = latest_env_snapshot(DB, kind="diagnostic")
    except Exception:
        ctx["env_snapshot"] = None
    try:
        ctx["env_delta"] = latest_env_snapshot(DB, kind="diagnostic_delta")
    except Exception:
        ctx["env_delta"] = None
    try:
        ctx["project_fingerprint"] = latest_env_snapshot(DB, kind="project_fingerprint")
    except Exception:
        ctx["project_fingerprint"] = None
    try:
        ctx["volition"] = latest_volition(DB)
    except Exception:
        ctx["volition"] = None
    return {"status": "SUCCESS", "context": ctx}


DISALLOWED_REGEX = ["page\\.click", "rm -rf", "DROP TABLE", "system\\("]


def tool_score_response(text: str) -> Dict[str, Any]:
    """
    Score an LLM response with simple rule-based checks and agent_verify outcome.
    Enhanced with file existence and method existence checking.
    Writes score to knowledge.db (table llm_scores).
    """
    import re

    score = 1.0
    issues: List[str] = []
    for pat in DISALLOWED_REGEX:
        if re.search(pat, text, re.IGNORECASE):
            issues.append(f"disallowed:{pat}")
            score -= 0.3

    # NEW: File existence check
    file_mentions = re.findall(r"[\w/\\]+\.(?:php|py|js|yaml|yml|md|json|sql)", text)
    for file_path in file_mentions:
        full_path = ROOT / file_path
        if not full_path.exists():
            alt_path = Path(file_path)
            if not alt_path.exists():
                issues.append(f"file_not_found:{file_path}")
                score -= 0.2

    # NEW: Method existence check (PHP Class::method() pattern)
    method_mentions = re.findall(r"(\w+)::(\w+)\(\)", text)
    for class_name, method_name in method_mentions:
        if not _method_exists(class_name, method_name):
            issues.append(f"method_not_found:{class_name}::{method_name}")
            score -= 0.2

    # run static checks as signal
    checks = run_all_checks(ROOT)
    if not checks.get("passed", True):
        score -= 0.2
        issues.append("checks_failed")

    ts = time.time()
    conn = sqlite3.connect(DB)
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS llm_scores(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          timestamp REAL,
          text TEXT,
          score REAL,
          issues TEXT
        )
        """
    )
    conn.execute(
        "INSERT INTO llm_scores (timestamp, text, score, issues) VALUES (?, ?, ?, ?)",
        (ts, text[:4000], score, json.dumps(issues, ensure_ascii=False)),
    )
    conn.commit()
    conn.close()
    return {"status": "SUCCESS", "score": score, "issues": issues}


def _method_exists(class_name: str, method_name: str) -> bool:
    """
    Check if a method exists in knowledge.db structure memory.
    Returns True if found, False otherwise.
    """
    try:
        conn = sqlite3.connect(DB)
        result = conn.execute(
            """
            SELECT 1 FROM methods m
            JOIN classes c ON m.class_id = c.id
            WHERE c.name = ? AND m.name = ?
            LIMIT 1
            """,
            (class_name, method_name),
        ).fetchone()
        conn.close()
        return result is not None
    except Exception:
        # If DB error, assume method might exist (benefit of doubt)
        return True


def tool_schema() -> Dict[str, Any]:
    """Return available tools and their parameters for prompt wiring."""
    return {
        "tools": [
            {
                "name": "run_checks",
                "params": {},
                "description": "Run static checks from inference_patterns.",
            },
            {
                "name": "route_index",
                "params": {},
                "description": "Rebuild and return route map.",
            },
            {
                "name": "logic_bridge",
                "params": {"payload": "object"},
                "description": "Invoke PHP logic bridge with JSON payload.",
            },
            {
                "name": "layout_map",
                "params": {"url": "string", "limit": "int(optional)"},
                "description": "Capture DOM layout map (headless).",
            },
            {
                "name": "context_pack",
                "params": {},
                "description": "Recent intents/decisions/outcomes + routes count.",
            },
            {
                "name": "score_response",
                "params": {"text": "string"},
                "description": "Rule-based scoring of LLM output; logs to DB.",
            },
            {
                "name": "tool_schema",
                "params": {},
                "description": "Return this schema list.",
            },
        ]
    }


TOOLS = {
    "run_checks": tool_run_checks,
    "route_index": tool_route_index,
    "logic_bridge": tool_logic_bridge,
    "layout_map": lambda payload: tool_layout_map(
        payload.get("url", ""), payload.get("limit", 50)
    ),
    "context_pack": tool_context_pack,
    "score_response": lambda payload: tool_score_response(payload.get("text", "")),
    "tool_schema": tool_schema,
    "embeddings_search": lambda payload: {
        "results": embed_search(payload.get("query", ""))
    },
}


def dispatch(request: Dict[str, Any]) -> Dict[str, Any]:
    """
    request = {"tool": "run_checks"} or {"tool": "logic_bridge", "payload": {...}}
    """
    name = request.get("tool")
    if name not in TOOLS:
        return {"status": "ERROR", "message": f"Unknown tool {name}"}
    try:
        if name in ("logic_bridge", "layout_map", "score_response"):
            return TOOLS[name](request.get("payload", {}))
        return TOOLS[name]()
    except Exception as e:
        return {"status": "ERROR", "message": str(e)}


if __name__ == "__main__":
    import sys

    req = json.load(sys.stdin)
    resp = dispatch(req)
    print(json.dumps(resp, ensure_ascii=False))
