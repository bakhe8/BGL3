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
import sys
from typing import Any, Dict, List

import yaml  # type: ignore
from config_loader import load_config
from browser_manager import BrowserManager
from python_ghost_cursor.playwright_async import install_mouse_helper

# تأكد من إمكانية استيراد الطبقات الداخلية عند التشغيل كسكربت
sys.path.append(str(Path(__file__).parent))
from hand_profile import HandProfile  # type: ignore
from motor import Motor, MouseState  # type: ignore
from policy import Policy  # type: ignore

SCENARIOS_DIR = Path(__file__).parent / "scenarios"
ROOT_DIR = Path(__file__).resolve().parents[2]

# Defaults for human-like pacing (can be overridden via env)
DEFAULT_POST_WAIT_MS = int(os.getenv("BGL_POST_WAIT_MS", "400"))
DEFAULT_HOVER_WAIT_MS = int(os.getenv("BGL_HOVER_WAIT_MS", "70"))

async def ensure_cursor(page):
    """Inject ghost-cursor overlay once per page."""
    if getattr(page, "_bgl_cursor_ready", False):
        return
    try:
        # compat: بعض توابع ghost_cursor تتوقع page.browser؛ نضيف اختصاراً
        try:
            if not hasattr(page, "browser"):
                page.browser = page.context.browser  # type: ignore
        except Exception:
            pass
        await install_mouse_helper(page)
        page._bgl_cursor_ready = True  # type: ignore
    except Exception:
        pass


def ensure_dev_mode():
    """
    Guard: لا تُشغّل السيناريوهات في وضع الإنتاج.
    يعتمد على storage/settings.json إن وُجد، وإلا يفترض وضع التطوير.
    """
    settings_path = ROOT_DIR / "storage" / "settings.json"
    if not settings_path.exists():
        return  # لا يوجد ملف إعدادات، نفترض وضع تطوير
    try:
        import json

        data = json.loads(settings_path.read_text(encoding="utf-8"))
        if data.get("PRODUCTION_MODE") is True:
            print("[!] PRODUCTION_MODE=true في storage/settings.json — أوقف التشغيل أو عطّل الوضع من settings.php ثم أعد المحاولة.")
            raise SystemExit(1)
    except Exception as exc:  # pragma: no cover - حماية من أي parsing خطأ
        print(f"[!] تعذر قراءة settings.json للتحقق من وضع التشغيل: {exc}")
        # لا نوقف التشغيل في حالة الفشل بالقراءة لتجنب حظر خاطئ


async def exploratory_action(page, motor: Motor, seen: set, session: str, learn_log: Path):
    """
    تنفيذ تفاعل آمن واحد غير مذكور (hover/scroll) لا يغيّر البيانات.
    """
    try:
        candidates = await page.query_selector_all("button, a, [role='button'], [data-action]")
        for el in candidates:
            desc = await el.get_attribute("title") or await el.text_content() or await el.get_attribute("href") or ""
            h = hash(desc)
            if h in seen:
                continue
            box = await el.bounding_box()
            if box:
                x = box["x"] + box["width"] / 2
                y = box["y"] + box["height"] / 2
                await motor.move_to(page, x, y, danger=False)
                await page.wait_for_timeout(80)
                seen.add(h)
                learn_log.write_text(learn_log.read_text() + f"{time.time()}\t{session}\texplore\t{desc.strip()}\n" if learn_log.exists() else f"{time.time()}\t{session}\texplore\t{desc.strip()}\n")
                return
        # fallback scroll
        await page.mouse.wheel(0, 300)
        with open(learn_log, "a", encoding="utf-8") as f:
            f.write(f"{time.time()}\t{session}\texplore\tscroll\n")
    except Exception:
        pass

async def run_step(page, step: Dict[str, Any], policy: Policy, db_path: Path):
    action = step.get("action")
    if action == "goto":
        await ensure_cursor(page)
        target_url = step["url"]
        # إذا كنا بالفعل على نفس العنوان، تجنب التحديث إلا إذا طُلب force
        try:
            current = page.url
        except Exception:
            current = ""
        if current != target_url or step.get("force", 0):
            await policy.perform_goto(page, target_url, wait_until=step.get("wait_until", "load"), post_wait_ms=int(step.get("post_wait_ms", DEFAULT_POST_WAIT_MS)))
    elif action == "wait":
        await page.wait_for_timeout(int(step.get("ms", 500)))
    elif action == "click":
        await ensure_cursor(page)
        danger = bool(step.get("danger", False))
        await policy.perform_click(
            page,
            step["selector"],
            danger=danger,
            hover_wait_ms=int(step.get("hover_wait_ms", DEFAULT_HOVER_WAIT_MS)),
            post_click_ms=int(step.get("click_post_wait_ms", 150)),
            learn_log=Path(ROOT_DIR / "storage" / "logs" / "learned_events.tsv"),
            session=step.get("session", ""),
            screenshot_dir=Path(ROOT_DIR / "storage" / "logs" / "captures"),
            log_event_fn=log_event,
            db_path=db_path,
        )
    elif action == "type":
        await ensure_cursor(page)
        await page.fill(step["selector"], step.get("text", ""), timeout=step.get("timeout", 5000))
    elif action == "press":
        await ensure_cursor(page)
        await page.press(step["selector"], step.get("key", "Enter"), timeout=step.get("timeout", 5000))
    elif action == "scroll":
        await ensure_cursor(page)
        dx = int(step.get("dx", 0))
        dy = int(step.get("dy", 400))
        await page.mouse.wheel(dx, dy)
        await page.wait_for_timeout(int(step.get("post_wait_ms", 200)))
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


async def run_scenario(manager: BrowserManager, page, base_url: str, scenario_path: Path, keep_open: bool, db_path: Path, is_last: bool = False):
    with open(scenario_path, "r", encoding="utf-8") as f:
        data = yaml.safe_load(f) or {}
    steps: List[Dict[str, Any]] = data.get("steps", [])
    name = data.get("name", scenario_path.stem)

    # تأكيد وجود مؤشر الماوس المرئي في الصفحة المشتركة
    await ensure_cursor(page)
    # تهيئة hand_profile ثابتة للجلسة، motor/policy فوق الصفحة
    hand_profile = getattr(manager, "_bgl_hand_profile", None)
    if hand_profile is None:
        hand_profile = HandProfile.generate()
        manager._bgl_hand_profile = hand_profile  # type: ignore
    motor: Motor = Motor(hand_profile)
    policy = Policy(motor)

    print(f"[*] Scenario '{name}' start")
    learn_log = ROOT_DIR / "storage" / "logs" / "learned_events.tsv"
    learn_log.parent.mkdir(parents=True, exist_ok=True)
    # مسار لقطات الأهداف
    shot_dir = ROOT_DIR / "storage" / "logs" / "captures"
    explored = set()
    steps_since_explore = 0
    exploratory_enabled = os.getenv("BGL_EXPLORATION", "1") == "1"

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
        attempt = 0
        while True:
            try:
                if page.is_closed():
                    page = await manager.new_page()
                    await ensure_cursor(page)
                    motor = Motor(hand_profile)
                    policy = Policy(motor)
                # إذا مرّ خطوتان دون استكشاف، نفّذ استكشاف إجباري (يمكن تعطيله بـ BGL_EXPLORATION=0)
                if exploratory_enabled and steps_since_explore >= 2 and step.get("action") != "wait":
                    await exploratory_action(page, motor, explored, name, learn_log)
                    steps_since_explore = 0
                await run_step(page, step, policy, db_path)
                steps_since_explore += 1
                # بعد أي goto أو عند أول خطوة في صفحة جديدة، استكشاف سريع
                if exploratory_enabled and step.get("action") == "goto":
                    await exploratory_action(page, motor, explored, name, learn_log)
                    steps_since_explore = 0
                break
            except Exception as e:
                attempt += 1
                # إذا أُغلقت الصفحة، أعد فتحها وأعد المحاولة مرة واحدة
                if "Target page" in str(e) or "Target closed" in str(e):
                    if attempt > 1:
                        raise
                    page = await manager.new_page()
                    await ensure_cursor(page)
                    motor = Motor(hand_profile)
                    policy = Policy(motor)
                    continue
                # تعافٍ اختياري بالرجوع للخلف عند اعتراض المودال للنقر (سلوك بشري محدود)
                if "intercepts pointer events" in str(e) and os.getenv("BGL_ALLOW_BACK", "0") == "1":
                    if not getattr(page, "_bgl_back_used", False):
                        try:
                            current_url = page.url
                        except Exception:
                            current_url = ""
                        await page.go_back()
                        page._bgl_back_used = True  # type: ignore
                        log_event(
                            db_path,
                            name,
                            {
                                "event_type": "navigation_back",
                                "route": current_url,
                                "method": "BACK",
                                "status": None,
                                "payload": "auto_recovery_back",
                            },
                        )
                        continue
                raise

    if keep_open and is_last:
        print(f"[!] Scenario '{name}' finished. Browser left open for manual review. Close it to continue.")
        await page.wait_for_timeout(24 * 60 * 60 * 1000)  # 24h max or until user closes
    else:
        print(f"[+] Scenario '{name}' done")


async def main(base_url: str, headless: bool, keep_open: bool, max_pages: int = 3, idle_timeout: int = 120, include: str | None = None):
    # Guard: لا تُشغّل السيناريوهات إذا كان Production Mode مفعّل
    ensure_dev_mode()

    if not SCENARIOS_DIR.exists():
        print("[!] Scenarios directory missing; nothing to run.")
        return

    scenario_files = sorted(SCENARIOS_DIR.rglob("*.yaml"))
    if include:
        scenario_files = [p for p in scenario_files if include.lower() in p.stem.lower()]
    # Skip API-only scenarios by default unless explicitly included
    include_api = os.getenv("BGL_INCLUDE_API", "0") == "1"
    if not include_api:
        scenario_files = [p for p in scenario_files if "api" not in p.stem]

    # Skip agent dashboard scenarios (الوكيل الصناعي يعمل كموظف على index.php)
    filtered = []
    for path in scenario_files:
        try:
            data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
            steps = data.get("steps", [])
            first = steps[0] if steps else {}
            url = first.get("url", "")
            if "/agent-dashboard.php" in url:
                continue  # استبعد سيناريو لوحة الوكيل
        except Exception:
            pass
        filtered.append(path)
    scenario_files = filtered

    if not scenario_files:
        print("[!] No scenario files found.")
        return

    slow_mo = int(os.getenv("BGL_SLOW_MO_MS", str(cfg.get("slow_mo_ms", 0))))
    manager = BrowserManager(base_url=base_url, headless=headless, max_pages=max_pages, idle_timeout=idle_timeout, persist=True, slow_mo_ms=slow_mo)
    # إنشاء صفحة واحدة يعاد استخدامها لكل السيناريوهات لمنع فتح نوافذ متعددة
    shared_page = await manager.new_page()
    await ensure_cursor(shared_page)

    db_path = Path(os.getenv("BGL_SANDBOX_DB", Path(".bgl_core/brain/knowledge.db")))
    try:
        for idx, path in enumerate(scenario_files):
            await run_scenario(manager, shared_page, base_url, path, keep_open if idx == len(scenario_files)-1 else False, db_path, is_last=(idx == len(scenario_files)-1))
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
    parser.add_argument("--include", default=None, help="Substring filter to run specific scenario files")
    args = parser.parse_args()

    asyncio.run(main(args.base_url, bool(args.headless), bool(args.keep_open), args.max_pages, args.idle_timeout, args.include))
