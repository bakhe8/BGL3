"""
Scenario Runner
---------------
Reads YAML scenarios from .bgl_core/brain/scenarios and executes them with Playwright.
Intended to generate runtime_events via the in-page sensor before Guardian auditing.

Usage:
    python .bgl_core/brain/scenario_runner.py --base-url http://localhost:8000 --headless 1
Env overrides:
    BGL_BASE_URL      (default http://localhost:8000)
    BGL_HEADLESS      (1/0, default 1)
"""

import argparse
import asyncio
import os
import time
import sqlite3
from pathlib import Path
from typing import Any, Dict, List

import yaml  # type: ignore
from config_loader import load_config
from browser_manager import BrowserManager

SCENARIOS_DIR = Path(__file__).parent / "scenarios"


async def run_step(page, step: Dict[str, Any]):
    action = step.get("action")
    if action == "goto":
        await page.goto(step["url"], wait_until=step.get("wait_until", "load"))
    elif action == "wait":
        await page.wait_for_timeout(int(step.get("ms", 500)))
    elif action == "click":
        await page.click(step["selector"], timeout=step.get("timeout", 5000))
    elif action == "type":
        await page.fill(step["selector"], step.get("text", ""), timeout=step.get("timeout", 5000))
    else:
        print(f"[!] Unknown action in scenario: {action}")


def log_event(db_path: Path, session: str, event: Dict[str, Any]):
    db = sqlite3.connect(str(db_path))
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS runtime_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp REAL NOT NULL,
            session TEXT,
            event_type TEXT NOT NULL,
            route TEXT,
            method TEXT,
            target TEXT,
            payload TEXT,
            status INTEGER,
            latency_ms REAL,
            error TEXT
        )
    """
    )
    db.execute(
        """
        INSERT INTO runtime_events (timestamp, session, event_type, route, method, target, payload, status, latency_ms, error)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """,
        (
            event.get("timestamp", time.time()),
            session,
            event.get("event_type"),
            event.get("route"),
            event.get("method"),
            event.get("target"),
            event.get("payload"),
            event.get("status"),
            event.get("latency_ms"),
            event.get("error"),
        ),
    )
    db.commit()
    db.close()


async def run_scenario(manager: BrowserManager, base_url: str, scenario_path: Path, keep_open: bool, db_path: Path):
    with open(scenario_path, "r", encoding="utf-8") as f:
        data = yaml.safe_load(f) or {}
    steps: List[Dict[str, Any]] = data.get("steps", [])
    name = data.get("name", scenario_path.stem)

    page = await manager.new_page()
    print(f"[*] Scenario '{name}' start")

    # Attach logging hooks once per page
    if not getattr(page, "_bgl_console_hook", False):
        page.on(
            "console",
            lambda msg: log_event(
                db_path,
                name,
                {
                    "event_type": "console_error" if msg.type == "error" else "console",
                    "payload": msg.text,
                },
            )
            if msg.type in ["error", "warning"]
            else None,
        )
        page._bgl_console_hook = True  # type: ignore

    if not getattr(page, "_bgl_requestfailed_hook", False):
        async def handle_request_failed(request):
            fail = request.failure
            if isinstance(fail, str):
                error_msg = fail
            elif hasattr(fail, "error"):
                error_msg = fail.error
            else:
                error_msg = "Unknown Error"
            log_event(
                db_path,
                name,
                {
                    "event_type": "network_fail",
                    "route": request.url,
                    "method": request.method,
                    "status": None,
                    "error": error_msg,
                },
            )

        page.on("requestfailed", handle_request_failed)
        page._bgl_requestfailed_hook = True  # type: ignore

    if not getattr(page, "_bgl_response_hook", False):
        async def handle_response(response):
            if response.status >= 400:
                log_event(
                    db_path,
                    name,
                    {
                        "event_type": "http_error",
                        "route": response.url,
                        "method": response.request.method,
                        "status": response.status,
                        "latency_ms": response.timing.get("responseStart", 0) if response.timing else None,
                    },
                )

        page.on("response", handle_response)
        page._bgl_response_hook = True  # type: ignore

    for step in steps:
        # prepend base_url for relative goto
        if step.get("action") == "goto" and step.get("url", "").startswith("/"):
            step = {**step, "url": base_url.rstrip("/") + step["url"]}
        await run_step(page, step)

    if keep_open:
        print(f"[!] Scenario '{name}' finished. Browser left open for manual review. Close it to continue.")
        await page.wait_for_timeout(24 * 60 * 60 * 1000)  # 24h max or until user closes
    else:
        print(f"[+] Scenario '{name}' done")


async def main(base_url: str, headless: bool, keep_open: bool, max_pages: int = 3, idle_timeout: int = 120):
    if not SCENARIOS_DIR.exists():
        print("[!] Scenarios directory missing; nothing to run.")
        return

    scenario_files = sorted(SCENARIOS_DIR.rglob("*.yaml"))
    if not scenario_files:
        print("[!] No scenario files found.")
        return

    manager = BrowserManager(base_url=base_url, headless=headless, max_pages=max_pages, idle_timeout=idle_timeout, persist=keep_open)

    db_path = Path(os.getenv("BGL_SANDBOX_DB", Path(".bgl_core/brain/knowledge.db")))
    try:
        for idx, path in enumerate(scenario_files):
            await run_scenario(manager, base_url, path, keep_open if idx == len(scenario_files)-1 else False, db_path)
    finally:
        if not keep_open:
            await manager.close()
    # After scenarios, summarize runtime events into experiences
    try:
        os.system(f"python .bgl_core/brain/context_digest.py")
    except Exception:
        pass


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    cfg = load_config(Path(".").resolve())
    parser.add_argument("--base-url", default=os.getenv("BGL_BASE_URL", cfg.get("base_url", "http://localhost:8000")))
    # Default headless = 0 to make the browser visible unless explicitly overridden
    parser.add_argument("--headless", type=int, default=int(os.getenv("BGL_HEADLESS", str(cfg.get("headless", 0)))))
    parser.add_argument("--keep-open", type=int, default=int(os.getenv("BGL_KEEP_BROWSER", str(cfg.get("keep_browser", 0)))), help="Leave browser open after scenarios")
    parser.add_argument("--max-pages", type=int, default=int(os.getenv("BGL_MAX_PAGES", "3")))
    parser.add_argument("--idle-timeout", type=int, default=int(os.getenv("BGL_PAGE_IDLE_TIMEOUT", "120")))
    args = parser.parse_args()

    asyncio.run(main(args.base_url, bool(args.headless), bool(args.keep_open), args.max_pages, args.idle_timeout))
