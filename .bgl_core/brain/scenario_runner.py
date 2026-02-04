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
import subprocess
import time
import sqlite3
import urllib.request
import urllib.error
import json
import re
import random
import hashlib
from pathlib import Path
import sys
from typing import Any, Dict, List, Optional

import yaml  # type: ignore
from config_loader import load_config
from browser_manager import BrowserManager
try:
    # Optional dependency: scenarios should still run without the visible cursor overlay.
    from python_ghost_cursor.playwright_async import install_mouse_helper  # type: ignore
except Exception:  # pragma: no cover
    install_mouse_helper = None  # type: ignore

# تأكد من إمكانية استيراد الطبقات الداخلية عند التشغيل كسكربت
sys.path.append(str(Path(__file__).parent))
from hand_profile import HandProfile  # type: ignore
from motor import Motor, MouseState  # type: ignore
from policy import Policy  # type: ignore
from authority import Authority  # type: ignore
from brain_types import ActionRequest, ActionKind  # type: ignore
from perception import capture_ui_map  # type: ignore
try:
    from llm_client import LLMClient  # type: ignore
except Exception:
    LLMClient = None  # type: ignore

SCENARIOS_DIR = Path(__file__).parent / "scenarios"
ROOT_DIR = Path(__file__).resolve().parents[2]

# Defaults for human-like pacing (can be overridden via env)
DEFAULT_POST_WAIT_MS = int(os.getenv("BGL_POST_WAIT_MS", "400"))
DEFAULT_HOVER_WAIT_MS = int(os.getenv("BGL_HOVER_WAIT_MS", "70"))


async def ensure_cursor(page):
    """Inject ghost-cursor overlay once per page."""
    if getattr(page, "_bgl_cursor_ready", False):
        return
    if install_mouse_helper is None:
        # Degrade gracefully when optional dependency isn't installed.
        page._bgl_cursor_ready = True  # type: ignore
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
            print(
                "[!] PRODUCTION_MODE=true في storage/settings.json — أوقف التشغيل أو عطّل الوضع من settings.php ثم أعد المحاولة."
            )
            raise SystemExit(1)
    except Exception as exc:  # pragma: no cover - حماية من أي parsing خطأ
        print(f"[!] تعذر قراءة settings.json للتحقق من وضع التشغيل: {exc}")
        # لا نوقف التشغيل في حالة الفشل بالقراءة لتجنب حظر خاطئ


async def exploratory_action(
    page, motor: Motor, seen: set, session: str, learn_log: Path
):
    """
    تنفيذ تفاعل آمن واحد غير مذكور (hover/scroll) لا يغيّر البيانات.
    """
    try:
        candidates = await page.query_selector_all(
            "button, a, [role='button'], [data-action]"
        )
        for el in candidates:
            desc = (
                await el.get_attribute("title")
                or await el.text_content()
                or await el.get_attribute("href")
                or ""
            )
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
                learn_log.write_text(
                    learn_log.read_text()
                    + f"{time.time()}\t{session}\texplore\t{desc.strip()}\n"
                    if learn_log.exists()
                    else f"{time.time()}\t{session}\texplore\t{desc.strip()}\n"
                )
                return
        # fallback scroll
        await page.mouse.wheel(0, 300)
        with open(learn_log, "a", encoding="utf-8") as f:
            f.write(f"{time.time()}\t{session}\texplore\tscroll\n")
    except Exception:
        pass


async def run_step(
    page,
    step: Dict[str, Any],
    policy: Policy,
    db_path: Path,
    *,
    authority: Optional[Authority] = None,
    scenario_name: str = "",
):
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
            await policy.perform_goto(
                page,
                target_url,
                wait_until=step.get("wait_until", "load"),
                post_wait_ms=int(step.get("post_wait_ms", DEFAULT_POST_WAIT_MS)),
            )
    elif action == "wait":
        # Support both "ms" and the older "duration_ms" key.
        await page.wait_for_timeout(int(step.get("ms", step.get("duration_ms", 500))))
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
    elif action == "upload":
        """
        Safe file upload without native OS dialogs.

        step:
          - action: upload
            selector: "css selector that triggers file chooser"
            files: ["relative/or/absolute/path"]  # or single string
            danger: true (recommended; reflects side-effectful nature)
        """
        await ensure_cursor(page)
        danger = bool(step.get("danger", False))
        # Upload is a write operation; require explicit danger:true to even request gating/approval.
        if not (danger or _autonomous_enabled()):
            log_event(
                db_path,
                step.get("session", ""),
                {
                    "event_type": "file_upload_skipped",
                    "route": step.get("selector", ""),
                    "method": "UPLOAD",
                    "payload": "upload blocked (set danger:true or enable autonomous mode)",
                    "status": None,
                },
            )
            return
        selector = step["selector"]
        files = step.get("files") or step.get("file") or []
        if isinstance(files, str):
            files = [files]
        files = [str(f) for f in files if f]
        if not files:
            # Nothing to upload; treat as a no-op.
            return

        resolved: List[str] = []
        for f in files:
            p = Path(f)
            # Convenience: allow passing a bare fixture name like "AUGUST" or "AUGUST.xlsx"
            # which will be resolved from storage/uploads.
            try:
                is_bare = (not p.is_absolute()) and (len(p.parts) == 1)
            except Exception:
                is_bare = False
            if is_bare:
                uploads_dir = (ROOT_DIR / "storage" / "uploads").resolve()
                if p.suffix:
                    candidate = (uploads_dir / p.name)
                    if candidate.exists():
                        resolved.append(str(candidate))
                        continue
                else:
                    for ext in (".xlsx", ".xls", ".csv"):
                        candidate = (uploads_dir / f"{p.name}{ext}")
                        if candidate.exists():
                            resolved.append(str(candidate))
                            break
                    else:
                        # Fall back to workspace-relative resolution below.
                        pass
                    if resolved and resolved[-1].lower().startswith(str(uploads_dir).lower()):
                        continue

            if not p.is_absolute():
                p = (ROOT_DIR / p).resolve()
            resolved.append(str(p))

        # Unified gating via Authority (single source of truth).
        if authority is not None:
            req = ActionRequest(
                kind=ActionKind.WRITE_PROD,
                operation="scenario.upload",
                command=f"upload via selector={selector}",
                scope=[selector, *resolved],
                reason=f"scenario upload step ({scenario_name})",
                confidence=0.7,
                metadata={"scenario": scenario_name, "step": step},
            )
            gate = authority.gate(req, source="scenario_runner")
            if not gate.allowed:
                log_event(
                    db_path,
                    step.get("session", ""),
                    {
                        "event_type": "file_upload_skipped",
                        "route": selector,
                        "method": "UPLOAD",
                        "payload": gate.message or "blocked by authority gate",
                        "status": None,
                    },
                )
                return

        # Prefer direct set_input_files when the selector points to an <input type="file">.
        # This avoids native OS dialogs entirely (most reliable).
        try:
            await page.set_input_files(selector, resolved)
            log_event(
                db_path,
                step.get("session", ""),
                {
                    "event_type": "file_upload",
                    "route": selector,
                    "method": "UPLOAD",
                    "payload": json.dumps({"files": resolved}, ensure_ascii=False),
                    "status": 200,
                },
            )
            return
        except Exception:
            pass

        # Fallback: handle file chooser via Playwright event (still stays in-browser).
        # Temporarily allow file chooser interception so we can set files.
        setattr(page, "_bgl_allow_filechooser", True)
        try:
            async with page.expect_file_chooser() as fc_info:
                await policy.perform_click(
                    page,
                    selector,
                    danger=danger,
                    hover_wait_ms=int(step.get("hover_wait_ms", DEFAULT_HOVER_WAIT_MS)),
                    post_click_ms=int(step.get("click_post_wait_ms", 150)),
                    learn_log=Path(ROOT_DIR / "storage" / "logs" / "learned_events.tsv"),
                    session=step.get("session", ""),
                    screenshot_dir=Path(ROOT_DIR / "storage" / "logs" / "captures"),
                    log_event_fn=log_event,
                    db_path=db_path,
                )
            fc = await fc_info.value
            await fc.set_files(resolved)
            # Record that we handled an upload (for downstream experience digestion).
            log_event(
                db_path,
                step.get("session", ""),
                {
                    "event_type": "file_upload",
                    "route": selector,
                    "method": "UPLOAD",
                    "payload": json.dumps({"files": resolved}, ensure_ascii=False),
                    "status": 200,
                },
            )
        finally:
            setattr(page, "_bgl_allow_filechooser", False)
    elif action == "type":
        await ensure_cursor(page)
        await page.fill(
            step["selector"], step.get("text", ""), timeout=step.get("timeout", 5000)
        )
    elif action == "press":
        await ensure_cursor(page)
        await page.press(
            step["selector"],
            step.get("key", "Enter"),
            timeout=step.get("timeout", 5000),
        )
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


def _load_seen_novel(db_path: Path, limit: int = 500) -> set:
    """
    Return a set of routes previously used in novel probes to avoid repeats.
    """
    try:
        if not db_path.exists():
            return set()
        db = sqlite3.connect(str(db_path))
        cur = db.cursor()
        rows = cur.execute(
            "SELECT route FROM runtime_events WHERE event_type='novel_probe' ORDER BY id DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        db.close()
        return {r[0] for r in rows if r and r[0]}
    except Exception:
        return set()


def _is_safe_novel_href(href: str, text: str, base_url: str) -> bool:
    """
    Conservative safety filter: only allow read-only navigation.
    """
    if _autonomous_enabled():
        if not href:
            return False
        h = href.strip()
        if h.startswith("#") or h.lower().startswith("javascript:"):
            return False
        return True
    if not href:
        return False
    h = href.strip()
    if h.startswith("#") or h.lower().startswith("javascript:"):
        return False
    if "/api/" in h:
        return False
    # Block common write/action words (English + Arabic).
    block = [
        "delete", "remove", "destroy", "drop", "import", "upload", "save", "update",
        "edit", "create", "add", "submit", "approve", "reject", "write",
        "حذف", "استيراد", "رفع", "حفظ", "تحديث", "تعديل", "إنشاء", "انشاء", "اضافة", "إضافة",
        "رفض", "اعتماد", "ارسال", "إرسال",
    ]
    s = (h + " " + (text or "")).lower()
    for b in block:
        if b.lower() in s:
            return False
    # Avoid external domains
    if h.startswith("http"):
        return h.startswith(base_url.rstrip("/"))
    return True


def _autonomous_enabled() -> bool:
    if os.getenv("BGL_AUTONOMOUS", "0") == "1":
        return True
    if os.getenv("BGL_EXECUTION_MODE", "").lower() == "autonomous":
        return True
    try:
        cfg = load_config(ROOT_DIR)
        return str(cfg.get("execution_mode", "")).lower() == "autonomous"
    except Exception:
        return False


def _cfg_value(key: str, default: Any = None) -> Any:
    try:
        cfg = load_config(ROOT_DIR)
        return cfg.get(key, default)
    except Exception:
        return default


def _recent_runtime_routes(db_path: Path, limit: int = 200) -> List[str]:
    try:
        if not db_path.exists():
            return []
        db = sqlite3.connect(str(db_path))
        cur = db.cursor()
        rows = cur.execute(
            "SELECT route FROM runtime_events ORDER BY id DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        db.close()
        uniq = []
        seen = set()
        for r in rows:
            if not r or not r[0]:
                continue
            val = str(r[0]).strip()
            if not val or val in seen:
                continue
            seen.add(val)
            uniq.append(val)
        return uniq
    except Exception:
        return []


def _autonomous_plan_hash(plan: Dict[str, Any]) -> str:
    try:
        steps = plan.get("steps") or []
        normalized = []
        for s in steps:
            action = str(s.get("action", "")).lower()
            sel = str(s.get("selector", "")).lower()
            url = str(s.get("url", "")).lower()
            normalized.append({"a": action, "s": sel, "u": url})
        raw = json.dumps(normalized, sort_keys=True)
    except Exception:
        raw = json.dumps(plan, sort_keys=True)
    return hashlib.sha1(raw.encode("utf-8")).hexdigest()


def _record_autonomous_plan(db_path: Path, plan: Dict[str, Any]) -> None:
    try:
        if not db_path.exists():
            return
        db = sqlite3.connect(str(db_path))
        cur = db.cursor()
        cur.execute(
            "CREATE TABLE IF NOT EXISTS autonomous_plans (hash TEXT PRIMARY KEY, created_at REAL)"
        )
        h = _autonomous_plan_hash(plan)
        cur.execute(
            "INSERT OR REPLACE INTO autonomous_plans (hash, created_at) VALUES (?, ?)",
            (h, time.time()),
        )
        db.commit()
        db.close()
    except Exception:
        return


def _is_recent_autonomous_plan(db_path: Path, plan: Dict[str, Any], limit: int = 20) -> bool:
    try:
        if not db_path.exists():
            return False
        db = sqlite3.connect(str(db_path))
        cur = db.cursor()
        cur.execute(
            "CREATE TABLE IF NOT EXISTS autonomous_plans (hash TEXT PRIMARY KEY, created_at REAL)"
        )
        h = _autonomous_plan_hash(plan)
        rows = cur.execute(
            "SELECT hash FROM autonomous_plans ORDER BY created_at DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        db.close()
        return any(r and r[0] == h for r in rows)
    except Exception:
        return False


def _is_simple_token(val: str) -> bool:
    return bool(re.match(r"^[A-Za-z0-9_-]+$", val or ""))


def _selector_from_element(el: Dict[str, Any]) -> Optional[str]:
    tag = str(el.get("tag") or "").strip().lower()
    el_id = str(el.get("id") or "").strip()
    name = str(el.get("name") or "").strip()
    classes = str(el.get("classes") or "").strip()
    href = str(el.get("href") or "").strip()
    el_type = str(el.get("type") or "").strip().lower()

    if tag == "a" and href:
        safe_href = href.replace('"', "").replace("'", "")
        return f'a[href="{safe_href}"]'
    if el_id and _is_simple_token(el_id):
        return f"#{el_id}"
    if tag and name and _is_simple_token(name):
        return f'{tag}[name="{name}"]'
    if tag == "input" and el_type == "file":
        return 'input[type="file"]'
    if classes:
        for c in classes.split():
            if _is_simple_token(c):
                return f"{tag}.{c}" if tag else f".{c}"
    if tag:
        return tag
    return None


def _build_selector_candidates(ui_map: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    seen = set()
    out: List[Dict[str, Any]] = []
    for el in ui_map or []:
        if not isinstance(el, dict):
            continue
        sel = _selector_from_element(el)
        if not sel or sel in seen:
            continue
        seen.add(sel)
        out.append(
            {
                "selector": sel,
                "tag": el.get("tag"),
                "text": el.get("text"),
                "type": el.get("type"),
                "href": el.get("href"),
                "role": el.get("role"),
                "name": el.get("name"),
            }
        )
    return out


def _list_upload_fixtures() -> List[str]:
    uploads_dir = (ROOT_DIR / "storage" / "uploads").resolve()
    if not uploads_dir.exists():
        return []
    items = []
    for p in uploads_dir.iterdir():
        if p.is_file():
            items.append(p.name)
    return sorted(items)


def _sanitize_steps(
    steps: Any, max_steps: int, uploads: List[str], allow_upload: bool = True
) -> List[Dict[str, Any]]:
    allowed = {"goto", "click", "type", "press", "upload", "wait", "scroll"}
    out: List[Dict[str, Any]] = []
    if not isinstance(steps, list):
        return out
    for raw in steps:
        if not isinstance(raw, dict):
            continue
        action = str(raw.get("action", "")).lower().strip()
        if action not in allowed:
            continue
        if action == "upload" and not allow_upload:
            continue
        step: Dict[str, Any] = {"action": action}
        if action == "goto":
            url = raw.get("url")
            if not url:
                continue
            step["url"] = str(url)
            if raw.get("wait_until"):
                step["wait_until"] = raw.get("wait_until")
            if raw.get("post_wait_ms") is not None:
                step["post_wait_ms"] = int(raw.get("post_wait_ms"))
        elif action in ("click", "type", "press", "upload"):
            selector = raw.get("selector")
            if not selector:
                continue
            step["selector"] = str(selector)
            if action == "type":
                step["text"] = str(raw.get("text", ""))
            if action == "press":
                step["key"] = str(raw.get("key", "Enter"))
            if action == "upload":
                files = raw.get("files") or raw.get("file") or []
                if isinstance(files, str):
                    files = [files]
                files = [str(f) for f in files if f]
                if not files and uploads:
                    files = [uploads[0]]
                if not files:
                    continue
                step["files"] = files
                if "danger" not in raw:
                    step["danger"] = True
        elif action == "wait":
            step["ms"] = int(raw.get("ms", raw.get("duration_ms", 500)))
        elif action == "scroll":
            step["dx"] = int(raw.get("dx", 0))
            step["dy"] = int(raw.get("dy", 400))

        if action != "upload" and "danger" in raw:
            step["danger"] = bool(raw.get("danger"))
        out.append(step)
        if len(out) >= max_steps:
            break
    return out


def _fallback_autonomous_steps(
    candidates: List[Dict[str, Any]],
    recent_routes: List[str],
    uploads: List[str],
    allow_upload: bool,
) -> List[Dict[str, Any]]:
    steps: List[Dict[str, Any]] = []
    if allow_upload and uploads:
        for c in candidates:
            if str(c.get("tag")).lower() == "input" and str(c.get("type")).lower() == "file":
                steps.append(
                    {
                        "action": "upload",
                        "selector": c.get("selector"),
                        "files": [uploads[0]],
                        "danger": True,
                    }
                )
                break
    pick = None
    fresh = [c.get("selector") for c in candidates if c.get("selector") and c.get("selector") not in recent_routes]
    fresh = [s for s in fresh if s]
    if fresh:
        pick = random.choice(fresh)
    elif candidates:
        pick = candidates[random.randrange(0, len(candidates))].get("selector")
    if pick:
        steps.append({"action": "click", "selector": pick})
    if steps:
        steps.append({"action": "wait", "ms": 600})
    return steps


async def _generate_autonomous_plan(
    page, base_url: str, db_path: Path, max_steps: int
) -> Optional[Dict[str, Any]]:
    ui_limit = int(
        os.getenv(
            "BGL_AUTONOMOUS_UI_LIMIT",
            str(_cfg_value("autonomous_ui_limit", "120")),
        )
        or 120
    )
    ui_map = await capture_ui_map(page, limit=ui_limit)
    candidates = _build_selector_candidates(ui_map)
    if not candidates:
        return None

    recent_routes = _recent_runtime_routes(db_path, limit=200)
    # Shuffle candidates to avoid deterministic repetition
    random.shuffle(candidates)
    uploads = _list_upload_fixtures()
    prefer_upload = (
        os.getenv("BGL_UPLOAD_FILE")
        or os.getenv("BGL_UPLOAD_FIXTURE")
        or str(_cfg_value("upload_file", "") or "")
    )
    if prefer_upload and prefer_upload not in uploads:
        uploads = [prefer_upload] + uploads
    avoid_upload = os.getenv("BGL_AUTONOMOUS_AVOID_UPLOAD")
    if avoid_upload is None:
        avoid_upload = str(_cfg_value("autonomous_avoid_upload", "0"))
    allow_upload = str(avoid_upload) != "1"

    plan = None
    if LLMClient is not None:
        prompt = f"""
You are the BGL3 agent running in autonomous mode.
Create ONE UI scenario. Output JSON ONLY:
{{
  "name": "autonomous_<short_name>",
  "steps": [
    {{"action":"goto","url":"{base_url}/"}},
    {{"action":"click","selector":"#..."}},
    {{"action":"type","selector":"input[name='...']","text":"..."}},
    {{"action":"upload","selector":"input[type='file']","files":["AUGUST.xlsx"],"danger":true}}
  ]
}}

Rules:
- Use ONLY selectors from the provided candidates.
- Avoid repeating selectors in recent_routes.
- Up to {max_steps} steps.
- You may click, type, press, upload, wait, scroll, goto.
- If you choose upload, use files from available_uploads.
- If you perform a write-like action (save/submit/import), include "danger": true.

Context JSON:
{json.dumps({"base_url": base_url, "available_uploads": uploads, "recent_routes": recent_routes, "candidates": candidates}, ensure_ascii=False)}
"""
        try:
            client = LLMClient()
            plan = client.chat_json(prompt, temperature=0.8)
        except Exception:
            plan = None

    steps = _sanitize_steps(
        (plan or {}).get("steps"),
        max_steps=max_steps,
        uploads=uploads,
        allow_upload=allow_upload,
    )
    if not steps:
        steps = _fallback_autonomous_steps(candidates, recent_routes, uploads, allow_upload)
    if not steps:
        return None

    name = (plan or {}).get("name") or f"autonomous_{int(time.time())}"
    candidate_plan = {"name": str(name), "kind": "ui", "steps": steps}
    if _is_recent_autonomous_plan(db_path, candidate_plan):
        # Force a new fallback plan if recent repeat detected
        steps = _fallback_autonomous_steps(candidates, recent_routes, uploads, allow_upload)
        if not steps:
            return None
        candidate_plan = {"name": f"autonomous_{int(time.time())}", "kind": "ui", "steps": steps}
    _record_autonomous_plan(db_path, candidate_plan)
    return candidate_plan


async def run_autonomous_scenario(
    manager: BrowserManager,
    page,
    base_url: str,
    db_path: Path,
):
    if not _autonomous_enabled():
        return
    auto_flag = os.getenv("BGL_AUTONOMOUS_SCENARIO")
    if auto_flag is None:
        auto_flag = str(_cfg_value("autonomous_scenario", "1"))
    if str(auto_flag) != "1":
        return

    max_steps = int(
        os.getenv(
            "BGL_AUTONOMOUS_MAX_STEPS",
            str(_cfg_value("autonomous_max_steps", "8")),
        )
        or 8
    )
    try:
        await page.goto(base_url.rstrip("/") + "/", wait_until="domcontentloaded")
    except Exception:
        pass

    plan = await _generate_autonomous_plan(page, base_url, db_path, max_steps=max_steps)
    if not plan or not plan.get("steps"):
        log_event(
            db_path,
            "autonomous_scenario",
            {
                "event_type": "autonomous_scenario_skipped",
                "route": base_url,
                "method": "AUTO",
                "payload": "no_plan",
                "status": None,
            },
        )
        return

    out_dir = SCENARIOS_DIR / "autonomous"
    out_dir.mkdir(parents=True, exist_ok=True)
    path = out_dir / f"auto_{int(time.time())}.yaml"
    try:
        with path.open("w", encoding="utf-8") as f:
            yaml.safe_dump(plan, f, sort_keys=False, allow_unicode=True)
    except Exception:
        # Fallback: ensure file exists even if dump fails.
        path.write_text(json.dumps(plan, ensure_ascii=False, indent=2), encoding="utf-8")

    log_event(
        db_path,
        "autonomous_scenario",
        {
            "event_type": "autonomous_scenario_generated",
            "route": str(path),
            "method": "AUTO",
            "payload": json.dumps({"steps": len(plan.get("steps", []))}, ensure_ascii=False),
            "status": 200,
        },
    )

    await run_scenario(
        manager,
        page,
        base_url,
        path,
        keep_open=False,
        db_path=db_path,
        is_last=False,
    )


async def run_novel_probe(page, base_url: str, db_path: Path):
    """
    Attempt one safe, novel navigation per run.
    """
    novelty_flag = os.getenv("BGL_NOVELTY_AUTO")
    if novelty_flag is None:
        novelty_flag = str(_cfg_value("novelty_auto", "1"))
    if str(novelty_flag) != "1":
        return
    if getattr(page, "_bgl_novelty_done", False):
        return

    base = base_url.rstrip("/")
    start_url = base + "/"
    try:
        await page.goto(start_url, wait_until="domcontentloaded")
    except Exception:
        pass

    ui_map = await capture_ui_map(page, limit=80)
    seen = _load_seen_novel(db_path, limit=500)
    candidates: List[str] = []

    for el in ui_map or []:
        href = (el.get("href") or "").strip()
        text = (el.get("text") or "").strip()
        tag = (el.get("tag") or "").lower()
        if tag != "a" and not href:
            continue
        if not _is_safe_novel_href(href, text, base):
            continue

        # Normalize to absolute URL
        if href.startswith("/"):
            full = base + href
        elif href.startswith("http"):
            full = href
        else:
            full = base + "/" + href

        if full in seen:
            continue
        candidates.append(full)

    if not candidates:
        log_event(
            db_path,
            "novel_probe",
            {
                "event_type": "novel_probe_skipped",
                "route": base,
                "method": "GET",
                "payload": "no_safe_novel_candidate",
                "status": None,
            },
        )
        page._bgl_novelty_done = True  # type: ignore
        return

    target = candidates[0]
    log_event(
        db_path,
        "novel_probe",
        {
            "event_type": "novel_probe",
            "route": target,
            "method": "GET",
            "payload": "auto_novel_navigation",
            "status": None,
        },
    )
    try:
        await page.goto(target, wait_until="domcontentloaded")
        await page.wait_for_timeout(800)
    except Exception:
        pass
    try:
        await page.goto(start_url, wait_until="domcontentloaded")
    except Exception:
        pass
    page._bgl_novelty_done = True  # type: ignore


async def run_scenario(
    manager: BrowserManager,
    page,
    base_url: str,
    scenario_path: Path,
    keep_open: bool,
    db_path: Path,
    is_last: bool = False,
):
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

    # Shared authority instance (avoid rebuilding config/db handlers per scenario).
    authority = getattr(manager, "_bgl_authority", None)
    if authority is None:
        authority = Authority(ROOT_DIR)
        manager._bgl_authority = authority  # type: ignore

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
                        "latency_ms": response.timing.get("responseStart", 0)
                        if response.timing
                        else None,
                    },
                )

        page.on("response", handle_response)
        page._bgl_response_hook = True  # type: ignore

    # Block native OS file dialogs by default. They are outside the browser and break automation.
    # Scenarios can use action="upload" which temporarily enables the chooser interception.
    if not getattr(page, "_bgl_filechooser_hook", False):

        def handle_filechooser(fc):
            allow = bool(getattr(page, "_bgl_allow_filechooser", False))
            if allow:
                return
            try:
                # Cancel asynchronously (Playwright async API)
                asyncio.create_task(fc.cancel())
            except Exception:
                pass
            try:
                log_event(
                    db_path,
                    name,
                    {
                        "event_type": "filechooser_blocked",
                        "route": page.url if hasattr(page, "url") else "",
                        "method": "FILECHOOSER",
                        "payload": "blocked native file chooser (use action=upload)",
                        "status": None,
                    },
                )
            except Exception:
                pass

        page.on("filechooser", handle_filechooser)
        page._bgl_filechooser_hook = True  # type: ignore

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
                if (
                    exploratory_enabled
                    and steps_since_explore >= 2
                    and step.get("action") != "wait"
                ):
                    await exploratory_action(page, motor, explored, name, learn_log)
                    steps_since_explore = 0
                await run_step(
                    page,
                    step,
                    policy,
                    db_path,
                    authority=authority,
                    scenario_name=name,
                )
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
                if (
                    "intercepts pointer events" in str(e)
                    and os.getenv("BGL_ALLOW_BACK", "0") == "1"
                ):
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
        print(
            f"[!] Scenario '{name}' finished. Browser left open for manual review. Close it to continue."
        )
        await page.wait_for_timeout(24 * 60 * 60 * 1000)  # 24h max or until user closes
    else:
        print(f"[+] Scenario '{name}' done")


def _is_api_url(url: str) -> bool:
    if url.startswith("/api/"):
        return True
    if "/api/" in url:
        return True
    return False


def _is_api_scenario(data: Dict[str, Any], scenario_path: Path) -> bool:
    kind = str(data.get("kind", "")).lower()
    if kind == "api":
        return True
    if kind == "ui":
        return False
    # Heuristic: all urls in steps are API
    steps = data.get("steps", [])
    urls = [
        str(s.get("url", ""))
        for s in steps
        if s.get("action") in ("goto", "request")
    ]
    if not urls:
        return False
    return all(_is_api_url(u) for u in urls)


async def run_api_scenario(
    base_url: str,
    scenario_path: Path,
    db_path: Path,
):
    data = yaml.safe_load(scenario_path.read_text(encoding="utf-8")) or {}
    steps: List[Dict[str, Any]] = data.get("steps", [])
    name = data.get("name", scenario_path.stem)
    print(f"[*] API Scenario '{name}' start")
    authority = Authority(ROOT_DIR)

    for step in steps:
        action = step.get("action")
        if action == "wait":
            await asyncio.sleep(int(step.get("ms", 300)) / 1000)
            continue

        if action not in ("goto", "request"):
            continue

        url = str(step.get("url", ""))
        if url.startswith("/"):
            url = base_url.rstrip("/") + url
        method = str(step.get("method", "GET")).upper()
        danger = bool(step.get("danger", False))
        if method in ("POST", "PUT", "PATCH", "DELETE"):
            # Write operations require explicit danger:true, then go through Authority gating.
            if not (danger or _autonomous_enabled()):
                log_event(
                    db_path,
                    name,
                    {
                        "event_type": "api_skipped",
                        "route": url,
                        "method": method,
                        "status": None,
                        "payload": "write blocked (set danger:true or enable autonomous mode)",
                    },
                )
                continue
            gate = authority.gate(
                ActionRequest(
                    kind=ActionKind.WRITE_PROD,
                    operation="scenario.api_write",
                    command=f"{method} {url}",
                    scope=[url],
                    reason=f"API scenario write step ({name})",
                    confidence=0.7,
                    metadata={"scenario": name, "step": step, "method": method, "url": url},
                ),
                source="scenario_runner",
            )
            if not gate.allowed:
                log_event(
                    db_path,
                    name,
                    {
                        "event_type": "api_skipped",
                        "route": url,
                        "method": method,
                        "status": None,
                        "payload": gate.message or "blocked by authority gate",
                    },
                )
                continue

        payload = step.get("payload")
        headers = step.get("headers", {})
        data_bytes = None
        if payload is not None:
            if isinstance(payload, (dict, list)):
                data_bytes = json.dumps(payload).encode("utf-8")
                headers.setdefault("Content-Type", "application/json")
            else:
                data_bytes = str(payload).encode("utf-8")

        start = time.perf_counter()
        status = None
        err = None
        try:
            req = urllib.request.Request(url, data=data_bytes, method=method, headers=headers)
            with urllib.request.urlopen(req, timeout=int(step.get("timeout", 8))) as resp:
                status = resp.getcode()
                resp.read()
        except urllib.error.HTTPError as e:
            status = e.code
            err = str(e)
        except Exception as e:
            err = str(e)

        latency_ms = round((time.perf_counter() - start) * 1000, 1)
        if status is not None and status >= 400:
            log_event(
                db_path,
                name,
                {
                    "event_type": "http_error",
                    "route": url,
                    "method": method,
                    "status": status,
                    "latency_ms": latency_ms,
                    "error": err,
                },
            )
        elif err:
            log_event(
                db_path,
                name,
                {
                    "event_type": "network_fail",
                    "route": url,
                    "method": method,
                    "status": status,
                    "latency_ms": latency_ms,
                    "error": err,
                },
            )
        else:
            log_event(
                db_path,
                name,
                {
                    "event_type": "api_call",
                    "route": url,
                    "method": method,
                    "status": status or 200,
                    "latency_ms": latency_ms,
                },
            )

    print(f"[+] API Scenario '{name}' done")


async def main(
    base_url: str,
    headless: bool,
    keep_open: bool,
    max_pages: int = 3,
    idle_timeout: int = 120,
    include: str | None = None,
    shadow_mode: bool = False,
):
    # Guard: لا تُشغّل السيناريوهات إذا كان Production Mode مفعّل
    ensure_dev_mode()
    cfg = load_config(ROOT_DIR)
    # Apply config defaults for exploration/novelty if env not set
    os.environ.setdefault("BGL_EXPLORATION", str(cfg.get("scenario_exploration", "1")))
    os.environ.setdefault("BGL_NOVELTY_AUTO", str(cfg.get("novelty_auto", "1")))

    if not SCENARIOS_DIR.exists():
        print("[!] Scenarios directory missing; nothing to run.")
        return

    scenario_files = sorted(SCENARIOS_DIR.rglob("*.yaml"))
    if include:
        scenario_files = [
            p for p in scenario_files if include.lower() in p.stem.lower()
        ]
    include_autonomous = os.getenv("BGL_INCLUDE_AUTONOMOUS", "0") == "1"
    if not include_autonomous:
        scenario_files = [
            p
            for p in scenario_files
            if "autonomous" not in {part.lower() for part in p.parts}
        ]
    # Skip API-only scenarios by default unless explicitly included
    include_api_flag = os.getenv("BGL_INCLUDE_API")
    if include_api_flag is None:
        include_api_flag = str(cfg.get("scenario_include_api", "0"))
    include_api = str(include_api_flag) == "1"
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

    auto_only_flag = os.getenv("BGL_AUTONOMOUS_ONLY")
    if auto_only_flag is None:
        auto_only_flag = str(cfg.get("autonomous_only", "0"))
    autonomous_only = str(auto_only_flag) == "1"

    auto_scenario_flag = os.getenv("BGL_AUTONOMOUS_SCENARIO")
    if auto_scenario_flag is None:
        auto_scenario_flag = str(cfg.get("autonomous_scenario", "1"))
    autonomous_scenario = _autonomous_enabled() and str(auto_scenario_flag) == "1"
    if not scenario_files and not autonomous_scenario:
        print("[!] No scenario files found.")
        return

    slow_mo = int(os.getenv("BGL_SLOW_MO_MS", str(cfg.get("slow_mo_ms", 0))))

    extra_headers = {"X-Shadow-Mode": "true"} if shadow_mode else None

    db_path = Path(os.getenv("BGL_SANDBOX_DB", Path(".bgl_core/brain/knowledge.db")))
    try:
        # Split scenarios into API vs UI
        api_scenarios = []
        ui_scenarios = []
        for path in scenario_files:
            try:
                data = yaml.safe_load(path.read_text(encoding="utf-8")) or {}
                if _is_api_scenario(data, path):
                    api_scenarios.append(path)
                else:
                    ui_scenarios.append(path)
            except Exception:
                ui_scenarios.append(path)

        # If autonomous_only, skip predefined scenarios but keep autonomous flow.
        if autonomous_only:
            api_scenarios = []
            ui_scenarios = []

        # Run API scenarios (no browser)
        for path in api_scenarios:
            await run_api_scenario(base_url, path, db_path)

        # Run UI scenarios (browser)
        if ui_scenarios or autonomous_scenario:
            manager = BrowserManager(
                base_url=base_url,
                headless=headless,
                max_pages=max_pages,
                idle_timeout=idle_timeout,
                persist=True,
                slow_mo_ms=slow_mo,
                extra_http_headers=extra_headers,
            )
            # إنشاء صفحة واحدة يعاد استخدامها لكل السيناريوهات لمنع فتح نوافذ متعددة
            shared_page = await manager.new_page()
            await ensure_cursor(shared_page)
            for idx, path in enumerate(ui_scenarios):
                await run_scenario(
                    manager,
                    shared_page,
                    base_url,
                    path,
                    keep_open if idx == len(ui_scenarios) - 1 else False,
                    db_path,
                    is_last=(idx == len(ui_scenarios) - 1),
                )
            if autonomous_scenario:
                await run_autonomous_scenario(manager, shared_page, base_url, db_path)
            # One novel, safe navigation per run (read-only).
            await run_novel_probe(shared_page, base_url, db_path)
            if not keep_open:
                await manager.close()
    finally:
        pass
    # After scenarios, summarize runtime events into experiences
    try:
        import sys

        digest_path = ROOT_DIR / ".bgl_core" / "brain" / "context_digest.py"
        # Avoid cmd.exe quoting pitfalls on Windows; also prevents noisy "filename syntax" errors.
        subprocess.run([sys.executable, str(digest_path)], cwd=ROOT_DIR, timeout=30)
    except Exception:
        pass


if __name__ == "__main__":
    parser = argparse.ArgumentParser()
    cfg = load_config(Path(".").resolve())
    parser.add_argument(
        "--base-url",
        default=os.getenv("BGL_BASE_URL", cfg.get("base_url", "http://localhost:8000")),
    )
    # Default headless = 0 to make the browser visible unless explicitly overridden
    parser.add_argument(
        "--headless",
        type=int,
        default=int(os.getenv("BGL_HEADLESS", str(cfg.get("headless", 0)))),
    )
    parser.add_argument(
        "--keep-open",
        type=int,
        default=int(os.getenv("BGL_KEEP_BROWSER", str(cfg.get("keep_browser", 0)))),
        help="Leave browser open after scenarios",
    )
    parser.add_argument(
        "--max-pages", type=int, default=int(os.getenv("BGL_MAX_PAGES", "3"))
    )
    parser.add_argument(
        "--idle-timeout",
        type=int,
        default=int(os.getenv("BGL_PAGE_IDLE_TIMEOUT", "120")),
    )
    parser.add_argument(
        "--include",
        default=None,
        help="Substring filter to run specific scenario files",
    )
    parser.add_argument(
        "--shadow-mode",
        type=int,
        default=0,
        help="Run in Shadow Mode (X-Shadow-Mode header)",
    )
    args = parser.parse_args()

    asyncio.run(
        main(
            args.base_url,
            bool(args.headless),
            bool(args.keep_open),
            args.max_pages,
            args.idle_timeout,
            args.include,
            bool(args.shadow_mode),
        )
    )
