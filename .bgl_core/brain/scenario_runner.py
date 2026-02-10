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
from urllib.parse import urlparse
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
from typing import Any, Dict, List, Optional, Tuple
import atexit

import yaml  # type: ignore
from config_loader import load_config
from browser_manager import BrowserManager
try:
    from .db_utils import connect_db  # type: ignore
except Exception:
    from db_utils import connect_db  # type: ignore

try:
    # Optional dependency: scenarios should still run without the visible cursor overlay.
    from python_ghost_cursor.playwright_async import install_mouse_helper  # type: ignore
except Exception:  # pragma: no cover
    install_mouse_helper = None  # type: ignore

try:
    from .behavior_learning import (
        record_behavior_hint,
        get_behavior_hints,
        mark_hint_result,
    )  # type: ignore
except Exception:
    try:
        from behavior_learning import (
            record_behavior_hint,
            get_behavior_hints,
            mark_hint_result,
        )  # type: ignore
    except Exception:
        record_behavior_hint = None  # type: ignore
        get_behavior_hints = None  # type: ignore
        mark_hint_result = None  # type: ignore

try:
    from .memory_index import upsert_memory_item  # type: ignore
except Exception:
    try:
        from memory_index import upsert_memory_item  # type: ignore
    except Exception:
        upsert_memory_item = None  # type: ignore

# تأكد من إمكانية استيراد الطبقات الداخلية عند التشغيل كسكربت
sys.path.append(str(Path(__file__).parent))
from hand_profile import HandProfile  # type: ignore
from motor import Motor  # type: ignore
from policy import Policy  # type: ignore
from authority import Authority  # type: ignore
from brain_types import ActionRequest, ActionKind  # type: ignore
from perception import capture_ui_map, capture_semantic_map, summarize_semantic_map  # type: ignore
try:
    from .observations import (
        store_ui_semantic_snapshot,
        store_ui_action_snapshot,
        previous_ui_semantic_snapshot,
        compute_ui_semantic_delta,
        record_ui_flow_transition,
    )  # type: ignore
except Exception:
    try:
        from observations import (
            store_ui_semantic_snapshot,
            store_ui_action_snapshot,
            previous_ui_semantic_snapshot,
            compute_ui_semantic_delta,
            record_ui_flow_transition,
        )  # type: ignore
    except Exception:
        store_ui_semantic_snapshot = None  # type: ignore
        store_ui_action_snapshot = None  # type: ignore
        previous_ui_semantic_snapshot = None  # type: ignore
        compute_ui_semantic_delta = None  # type: ignore
        record_ui_flow_transition = None  # type: ignore
try:
    from .run_ledger import start_run, finish_run  # type: ignore
except Exception:
    try:
        from run_ledger import start_run, finish_run  # type: ignore
    except Exception:
        start_run = None  # type: ignore
        finish_run = None  # type: ignore
try:
    from .run_lock import acquire_lock, release_lock, refresh_lock  # type: ignore
except Exception:
    try:
        from run_lock import acquire_lock, release_lock, refresh_lock  # type: ignore
    except Exception:
        acquire_lock = None  # type: ignore
        release_lock = None  # type: ignore
        refresh_lock = None  # type: ignore

try:
    from .long_term_goals import pick_long_term_goals, record_long_term_goal_result  # type: ignore
except Exception:
    try:
        from long_term_goals import pick_long_term_goals, record_long_term_goal_result  # type: ignore
    except Exception:
        pick_long_term_goals = None  # type: ignore
        record_long_term_goal_result = None  # type: ignore

try:
    from llm_client import LLMClient  # type: ignore
except Exception:
    LLMClient = None  # type: ignore
from route_indexer import LaravelRouteIndexer  # type: ignore

SCENARIOS_DIR = Path(__file__).parent / "scenarios"
ROOT_DIR = Path(__file__).resolve().parents[2]
SCENARIO_LOCK_PATH = ROOT_DIR / ".bgl_core" / "logs" / "scenario_runner.lock"
_SCENARIO_LOCKED = False
_LAST_LOCK_HEARTBEAT = 0.0

# Defaults for human-like pacing (can be overridden via env)
DEFAULT_POST_WAIT_MS = int(os.getenv("BGL_POST_WAIT_MS", "400"))
DEFAULT_HOVER_WAIT_MS = int(os.getenv("BGL_HOVER_WAIT_MS", "70"))

# Active run identifier to tag runtime_events for attribution.
_CURRENT_RUN_ID = ""
_CURRENT_SCENARIO_ID = ""
_CURRENT_SCENARIO_NAME = ""
_CURRENT_GOAL_ID = ""
_CURRENT_GOAL_NAME = ""
_CONTEXT_ENV_KEYS = (
    "BGL_RUN_ID",
    "BGL_SCENARIO_ID",
    "BGL_SCENARIO_NAME",
    "BGL_GOAL_ID",
    "BGL_GOAL_NAME",
)


def _push_context(
    *,
    scenario_id: Optional[str] = None,
    scenario_name: Optional[str] = None,
    goal_id: Optional[str] = None,
    goal_name: Optional[str] = None,
) -> Dict[str, Any]:
    global _CURRENT_SCENARIO_ID, _CURRENT_SCENARIO_NAME, _CURRENT_GOAL_ID, _CURRENT_GOAL_NAME
    prev_env = {k: os.getenv(k) for k in _CONTEXT_ENV_KEYS}
    prev_ctx = {
        "scenario_id": _CURRENT_SCENARIO_ID,
        "scenario_name": _CURRENT_SCENARIO_NAME,
        "goal_id": _CURRENT_GOAL_ID,
        "goal_name": _CURRENT_GOAL_NAME,
        "env": prev_env,
    }
    if scenario_id is not None:
        _CURRENT_SCENARIO_ID = str(scenario_id)
        if _CURRENT_SCENARIO_ID:
            os.environ["BGL_SCENARIO_ID"] = _CURRENT_SCENARIO_ID
        else:
            os.environ.pop("BGL_SCENARIO_ID", None)
    if scenario_name is not None:
        _CURRENT_SCENARIO_NAME = str(scenario_name)
        if _CURRENT_SCENARIO_NAME:
            os.environ["BGL_SCENARIO_NAME"] = _CURRENT_SCENARIO_NAME
        else:
            os.environ.pop("BGL_SCENARIO_NAME", None)
    if goal_id is not None:
        _CURRENT_GOAL_ID = str(goal_id)
        if _CURRENT_GOAL_ID:
            os.environ["BGL_GOAL_ID"] = _CURRENT_GOAL_ID
        else:
            os.environ.pop("BGL_GOAL_ID", None)
    if goal_name is not None:
        _CURRENT_GOAL_NAME = str(goal_name)
        if _CURRENT_GOAL_NAME:
            os.environ["BGL_GOAL_NAME"] = _CURRENT_GOAL_NAME
        else:
            os.environ.pop("BGL_GOAL_NAME", None)
    return prev_ctx


def _pop_context(prev: Dict[str, Any]) -> None:
    global _CURRENT_SCENARIO_ID, _CURRENT_SCENARIO_NAME, _CURRENT_GOAL_ID, _CURRENT_GOAL_NAME
    _CURRENT_SCENARIO_ID = str(prev.get("scenario_id") or "")
    _CURRENT_SCENARIO_NAME = str(prev.get("scenario_name") or "")
    _CURRENT_GOAL_ID = str(prev.get("goal_id") or "")
    _CURRENT_GOAL_NAME = str(prev.get("goal_name") or "")
    prev_env = prev.get("env") or {}
    for key in _CONTEXT_ENV_KEYS:
        val = prev_env.get(key)
        if val is None:
            os.environ.pop(key, None)
        else:
            os.environ[key] = str(val)

def _trace(msg: str) -> None:
    if os.getenv("BGL_TRACE_SCENARIO", "0") != "1":
        return
    try:
        ts = time.strftime("%H:%M:%S")
    except Exception:
        ts = "?"
    print(f"[trace {ts}] {msg}", flush=True)


def _acquire_scenario_lock(cfg: dict) -> tuple[bool, str]:
    global _SCENARIO_LOCKED
    if _SCENARIO_LOCKED or acquire_lock is None:
        return True, "already_locked"
    try:
        ttl = int(os.getenv("BGL_SCENARIO_LOCK_TTL", str(cfg.get("scenario_lock_ttl_sec", 7200))))
    except Exception:
        ttl = 7200
    ok, reason = acquire_lock(SCENARIO_LOCK_PATH, ttl_sec=ttl, label="scenario_runner")
    if ok:
        _SCENARIO_LOCKED = True
        global _LAST_LOCK_HEARTBEAT
        _LAST_LOCK_HEARTBEAT = time.time()
        if release_lock is not None:
            atexit.register(lambda: release_lock(SCENARIO_LOCK_PATH))
    return ok, reason


def _refresh_scenario_lock() -> None:
    global _LAST_LOCK_HEARTBEAT
    if not _SCENARIO_LOCKED or refresh_lock is None:
        return
    try:
        interval = int(os.getenv("BGL_SCENARIO_LOCK_HEARTBEAT_SEC", "60"))
    except Exception:
        interval = 60
    now = time.time()
    if _LAST_LOCK_HEARTBEAT and (now - _LAST_LOCK_HEARTBEAT) < interval:
        return
    if refresh_lock(SCENARIO_LOCK_PATH, label="scenario_runner"):
        _LAST_LOCK_HEARTBEAT = now


def _decorate_session(session: str) -> str:
    global _CURRENT_RUN_ID
    rid = str(_CURRENT_RUN_ID or "")
    raw = str(session or "").strip()
    if not rid:
        return raw
    if not raw:
        return rid
    if raw.startswith(rid):
        return raw
    return f"{rid}|{raw}"


async def _maybe_store_semantic_snapshot(
    page,
    db_path: Path,
    *,
    source: str,
    session: str,
) -> Optional[Dict[str, Any]]:
    if os.getenv("BGL_STORE_UI_SEMANTIC", "1") != "1":
        return None
    if store_ui_semantic_snapshot is None or compute_ui_semantic_delta is None:
        return None
    if not db_path.exists():
        return None
    try:
        limit = int(os.getenv("BGL_SEMANTIC_LIMIT", "12") or "12")
    except Exception:
        limit = 12
    try:
        semantic_map = await capture_semantic_map(page, limit=limit)
    except Exception:
        semantic_map = {}
    if not semantic_map:
        return None
    summary = summarize_semantic_map(semantic_map)
    try:
        url = page.url if hasattr(page, "url") else ""
    except Exception:
        url = ""
    created_at = time.time()
    try:
        store_ui_semantic_snapshot(
            db_path,
            url=url,
            summary=summary,
            payload=semantic_map,
            source=source,
            created_at=created_at,
        )
    except Exception:
        pass

    delta = {"changed": False, "reason": "no_prev"}
    try:
        if previous_ui_semantic_snapshot is not None:
            prev = previous_ui_semantic_snapshot(db_path, url=url, before_ts=created_at)
            if prev and prev.get("summary"):
                delta = compute_ui_semantic_delta(prev.get("summary"), summary)
    except Exception:
        pass
    try:
        log_event(
            db_path,
            session,
            {
                "event_type": "ui_semantic_snapshot",
                "route": url,
                "method": "SNAPSHOT",
                "payload": {"source": source, "changed": bool(delta.get("changed"))},
                "status": 200,
            },
        )
        if delta.get("changed"):
            log_event(
                db_path,
                session,
                {
                    "event_type": "ui_semantic_change",
                    "route": url,
                    "method": "SNAPSHOT",
                    "payload": {
                        "source": source,
                        "change_count": int(delta.get("change_count") or 0),
                    },
                    "status": 200,
                },
            )
    except Exception:
        pass
    return {"url": url, "summary": summary, "delta": delta, "session": session}


async def _maybe_store_action_snapshot(
    page,
    db_path: Path,
    *,
    source: str,
    session: str,
) -> Optional[Dict[str, Any]]:
    if os.getenv("BGL_STORE_UI_ACTIONS", "1") != "1":
        return None
    if store_ui_action_snapshot is None:
        return None
    if not db_path.exists():
        return None
    try:
        limit = int(os.getenv("BGL_UI_ACTION_LIMIT", "80") or "80")
    except Exception:
        limit = 80
    try:
        ui_map = await capture_ui_map(page, limit=limit)
    except Exception:
        ui_map = []
    if not ui_map:
        return None
    candidates = _build_selector_candidates(ui_map)
    if not candidates:
        return None
    try:
        url = page.url if hasattr(page, "url") else ""
    except Exception:
        url = ""
    compact = []
    for c in candidates:
        if not isinstance(c, dict):
            continue
        compact.append(
            {
                "selector": c.get("selector") or "",
                "href": c.get("href") or "",
                "text": c.get("text") or "",
                "tag": c.get("tag") or "",
                "role": c.get("role") or "",
                "type": c.get("type") or "",
            }
        )
    try:
        store_ui_action_snapshot(
            db_path,
            url=url,
            candidates=compact,
            source=source,
            created_at=time.time(),
        )
    except Exception:
        pass
    try:
        log_event(
            db_path,
            session,
            {
                "event_type": "ui_action_snapshot",
                "route": url,
                "method": "SNAPSHOT",
                "payload": {"source": source, "count": len(compact)},
                "status": 200,
            },
        )
    except Exception:
        pass
    return {"url": url, "count": len(compact), "session": session}


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


async def _dom_state_hash(page) -> str:
    try:
        return await page.evaluate(
            """() => {
                const txt = (document.body && document.body.innerText) ? document.body.innerText.slice(0, 2000) : '';
                const modalCount = document.querySelectorAll('[role="dialog"], [aria-modal="true"], .modal.show, .modal[style*="display"]').length;
                let activeTab = '';
                const tab = document.querySelector('[role="tab"][aria-selected="true"], .tab.active, .nav-link.active, [data-tab].active');
                if (tab && tab.textContent) {
                    activeTab = tab.textContent.trim().replace(/\\s+/g, ' ').slice(0, 40);
                }
                activeTab = activeTab.replaceAll('|', ' ');
                return (location.pathname + '|' + location.search + '|' + txt.length + '|m' + modalCount + '|t' + activeTab);
            }"""
        )
    except Exception:
        return ""


async def _handle_ui_states(page, db_path: Path, session: str, *, reason: str = "pre_step") -> Dict[str, Any]:
    """
    Lightweight UI state handling:
    - Close open modals
    - Wait for loading indicators
    - Log errors/warnings/toasts/empty states
    """
    now = time.time()
    last_checked = float(getattr(page, "_bgl_ui_state_checked_at", 0) or 0)
    # Throttle checks to avoid heavy sampling on every step.
    if now - last_checked < 1.0:
        return {}
    page._bgl_ui_state_checked_at = now  # type: ignore

    summary: Dict[str, Any] = {}
    try:
        semantic_map = await capture_semantic_map(page, limit=8)
        summary = summarize_semantic_map(semantic_map) if semantic_map else {}
    except Exception:
        summary = {}
    ui_states = summary.get("ui_states") or {}
    if not isinstance(ui_states, dict) or not ui_states:
        return {}

    actions: List[str] = []
    retry_needed = False

    if ui_states.get("modals"):
        try:
            await page.keyboard.press("Escape")
            actions.append("press_escape")
            retry_needed = True
        except Exception:
            pass
        for sel in (
            "[data-bs-dismiss='modal']",
            ".modal.show .btn-close",
            ".modal.show button.close",
            "[role='dialog'] [aria-label='Close']",
            ".offcanvas.show .btn-close",
            ".offcanvas.show button.close",
        ):
            try:
                el = await page.query_selector(sel)
                if el:
                    await el.click()
                    actions.append(f"click:{sel}")
                    retry_needed = True
                    await page.wait_for_timeout(120)
            except Exception:
                continue
        # Fallback: click backdrop to close overlays when close buttons are missing.
        for sel in (".modal-backdrop", ".offcanvas-backdrop", ".overlay", "[data-backdrop]"):
            try:
                el = await page.query_selector(sel)
                if el:
                    await el.click()
                    actions.append(f"backdrop:{sel}")
                    retry_needed = True
                    await page.wait_for_timeout(120)
                    break
            except Exception:
                continue

    if ui_states.get("loading"):
        try:
            await page.wait_for_timeout(700)
            actions.append("wait_loading")
            retry_needed = True
        except Exception:
            pass
        try:
            await page.wait_for_load_state("networkidle", timeout=1500)
            actions.append("wait_networkidle")
        except Exception:
            actions.append("loading_persist")

    # Attempt gentle recovery for common error/empty states (safe actions only).
    try:
        recovery_texts = [
            "Retry",
            "Try again",
            "Refresh",
            "Reload",
            "Back",
            "رجوع",
            "إعادة المحاولة",
            "محاولة مرة أخرى",
            "تحديث",
            "إعادة تحميل",
            "اعادة تحميل",
            "إعادة التشغيل",
            "OK",
            "موافق",
            "حسنًا",
            "حسنا",
            "إغلاق",
            "اغلاق",
            "Close",
        ]
        recovery_selectors = []
        for txt in recovery_texts:
            recovery_selectors.extend(
                [
                    f"button:has-text('{txt}')",
                    f"[role='button']:has-text('{txt}')",
                    f"a:has-text('{txt}')",
                ]
            )
        for sel in recovery_selectors:
            try:
                el = await page.query_selector(sel)
                if not el:
                    continue
                await el.click()
                actions.append(f"recover:{sel}")
                retry_needed = True
                await page.wait_for_timeout(150)
                # Only one recovery click to avoid accidental cascades.
                break
            except Exception:
                continue
    except Exception:
        pass

    # Log notable UI states for attribution.
    for key in ("errors", "warnings", "toasts", "empty_states"):
        items = ui_states.get(key) or []
        if items:
            try:
                log_event(
                    db_path,
                    session,
                    {
                        "event_type": f"ui_state_{key}",
                        "route": page.url if hasattr(page, "url") else "",
                        "method": "STATE",
                        "payload": {"reason": reason, "items": items[:4]},
                        "status": None,
                    },
                )
            except Exception:
                pass

    if actions:
        try:
            log_event(
                db_path,
                session,
                {
                    "event_type": "ui_state_action",
                    "route": page.url if hasattr(page, "url") else "",
                    "method": "STATE",
                    "payload": {"reason": reason, "actions": actions},
                    "status": 200,
                },
            )
        except Exception:
            pass
        # Persist snapshots + flow transition so UI state recoveries count as operational evidence.
        try:
            sem = await _maybe_store_semantic_snapshot(
                page,
                db_path,
                source="ui_state_recovery",
                session=session,
            )
            await _maybe_store_action_snapshot(
                page,
                db_path,
                source="ui_state_recovery",
                session=session,
            )
            if sem and record_ui_flow_transition is not None:
                try:
                    ui_states = (sem.get("summary") or {}).get("ui_states") or {}
                except Exception:
                    ui_states = {}
                record_ui_flow_transition(
                    db_path,
                    session=session,
                    from_url=sem.get("url") or "",
                    to_url=sem.get("url") or "",
                    action="ui_state_recovery",
                    selector="|".join(actions)[:180],
                    semantic_delta=sem.get("delta") or {},
                    ui_states=ui_states,
                    created_at=time.time(),
                )
        except Exception:
            pass

    return {"ui_states": ui_states, "actions": actions, "summary": summary, "retry": retry_needed}


async def _attempt_semantic_shift(page, db_path: Path, session: str) -> bool:
    """
    Try a safe UI interaction to force a semantic change when the page appears static.
    This is a best-effort, non-destructive action (tabs/collapses/menus).
    """
    try:
        if os.getenv("BGL_SEMANTIC_SHIFT", "1") != "1":
            return False
    except Exception:
        pass
    try:
        before_hash = await _dom_state_hash(page)
    except Exception:
        before_hash = ""
    selectors = [
        "[role='tab']",
        ".nav-link",
        "[data-bs-toggle='tab']",
        "[data-tab]",
        "[data-bs-toggle='collapse']",
        "[data-toggle='collapse']",
        "[data-bs-toggle='dropdown']",
        "[data-toggle='dropdown']",
        "[aria-expanded='false']",
        "[aria-controls]",
    ]
    candidate_selectors: List[Tuple[str, Dict[str, Any]]] = []
    try:
        ui_limit = int(os.getenv("BGL_UI_ACTION_LIMIT", "80") or "80")
    except Exception:
        ui_limit = 80
    try:
        ui_map = await capture_ui_map(page, limit=ui_limit)
    except Exception:
        ui_map = []
    if ui_map:
        try:
            candidates = _build_selector_candidates(ui_map)
        except Exception:
            candidates = []
        for c in candidates:
            sel = str(c.get("selector") or "")
            if not sel:
                continue
            if _candidate_is_disabled(c):
                continue
            if _candidate_is_tab_like(c):
                if _candidate_is_active(c):
                    continue
                candidate_selectors.append((sel, c))
    seen = set()

    def _iter_selectors():
        for sel, meta in candidate_selectors:
            if sel in seen:
                continue
            seen.add(sel)
            yield sel, meta, "ui_map"
        for sel in selectors:
            if sel in seen:
                continue
            seen.add(sel)
            yield sel, {}, "fallback"

    for sel, meta, source in _iter_selectors():
        try:
            el = await page.query_selector(sel)
            if not el:
                continue
            try:
                text = (await el.inner_text()) or ""
            except Exception:
                text = ""
            if _semantic_is_write_action(text):
                continue
            try:
                if not meta or _candidate_needs_hover(meta):
                    await el.hover()
                    await page.wait_for_timeout(120)
            except Exception:
                pass
            try:
                await el.click()
            except Exception:
                # Some selectors are hover-only; ignore click errors.
                pass
            await page.wait_for_timeout(240)
            after_hash = await _dom_state_hash(page)
            changed = bool(before_hash and after_hash and before_hash != after_hash)
            try:
                log_event(
                    db_path,
                    session,
                    {
                        "event_type": "semantic_shift_attempt",
                        "route": page.url if hasattr(page, "url") else "",
                        "method": "SHIFT",
                        "payload": {
                            "selector": sel,
                            "changed": changed,
                            "source": source,
                        },
                        "status": 200 if changed else 204,
                    },
                )
            except Exception:
                pass
            if changed:
                try:
                    sem = await _maybe_store_semantic_snapshot(
                        page,
                        db_path,
                        source="semantic_shift",
                        session=session,
                    )
                    await _maybe_store_action_snapshot(
                        page,
                        db_path,
                        source="semantic_shift",
                        session=session,
                    )
                    try:
                        if _record_exploration_outcome:
                            _record_exploration_outcome(
                                db_path,
                                selector=sel,
                                href="",
                                route=page.url if hasattr(page, "url") else "",
                                result="changed",
                                delta_score=1.0,
                                selector_key=_selector_key_from_selector(sel),
                            )
                    except Exception:
                        pass
                    if sem and record_ui_flow_transition is not None:
                        ui_states = {}
                        try:
                            ui_states = (sem.get("summary") or {}).get("ui_states") or {}
                        except Exception:
                            ui_states = {}
                        record_ui_flow_transition(
                            db_path,
                            session=session,
                            from_url=sem.get("url") or "",
                            to_url=sem.get("url") or "",
                            action="semantic_shift",
                            selector=str(sel),
                            semantic_delta=sem.get("delta") or {},
                            ui_states=ui_states,
                            created_at=time.time(),
                        )
                except Exception:
                    pass
                return True
        except Exception:
            continue
    return False


async def exploratory_action(
    page, motor: Motor, seen: set, session: str, learn_log: Path, db_path: Path
):
    """
    تنفيذ تفاعل آمن واحد غير مذكور (hover/scroll) لا يغيّر البيانات.
    """
    try:
        candidates = await page.query_selector_all(
            "button, a, [role='button'], [data-action], input, textarea, select"
        )
        candidate_meta = []
        for el in candidates:
            try:
                tag = (
                    await el.evaluate("el => (el.tagName || '').toLowerCase()")
                ) or ""
                text = (
                    await el.evaluate(
                        "el => (el.innerText || el.textContent || '').trim().slice(0, 120)"
                    )
                ) or ""
                role = await el.get_attribute("role") or ""
                onclick = await el.get_attribute("onclick") or ""
                data_tab = await el.get_attribute("data-tab") or ""
                data_target = await el.get_attribute("data-target") or ""
                data_bs_target = await el.get_attribute("data-bs-target") or ""
                data_target_attr = (
                    "data-bs-target" if data_bs_target else ("data-target" if data_target else "")
                )
                aria_controls = await el.get_attribute("aria-controls") or ""
                classes = await el.get_attribute("class") or ""
                elem_id = await el.get_attribute("id") or ""
                elem_name = await el.get_attribute("name") or ""
                testid = ""
                testattr = ""
                for attr in ("data-testid", "data-test", "data-qa", "data-cy"):
                    try:
                        val = await el.get_attribute(attr)
                    except Exception:
                        val = None
                    if val:
                        testid = str(val)
                        testattr = attr
                        break
                aria_selected = await el.get_attribute("aria-selected") or ""
                aria_expanded = await el.get_attribute("aria-expanded") or ""
                aria_disabled = await el.get_attribute("aria-disabled") or ""
                try:
                    disabled_prop = await el.evaluate("el => !!el.disabled")
                except Exception:
                    disabled_prop = False
                disabled_attr = await el.get_attribute("disabled")
                disabled = "true" if disabled_prop or disabled_attr is not None else ""
                meta = {
                    "element": el,
                    "tag": tag,
                    "role": role,
                    "classes": classes,
                    "text": text,
                    "href": await el.get_attribute("href") or "",
                    "selector": _selector_from_element(
                        {
                            "tag": tag,
                            "id": elem_id,
                            "name": elem_name,
                            "classes": classes,
                            "href": await el.get_attribute("href") or "",
                            "type": await el.get_attribute("type") or "",
                            "text": text,
                            "role": role,
                            "onclick": onclick,
                            "datatab": data_tab,
                            "datatarget": data_bs_target or data_target,
                            "datatarget_attr": data_target_attr,
                            "ariacontrols": aria_controls,
                            "aria_selected": aria_selected,
                            "aria_expanded": aria_expanded,
                            "aria_disabled": aria_disabled,
                            "disabled": disabled,
                            "testid": testid,
                            "testattr": testattr,
                        }
                    )
                    or "",
                    "id": elem_id,
                    "name": elem_name,
                    "testid": testid,
                    "testattr": testattr,
                    "onclick": onclick,
                    "datatab": data_tab,
                    "datatarget": data_bs_target or data_target,
                    "datatarget_attr": data_target_attr,
                    "ariacontrols": aria_controls,
                    "aria_selected": aria_selected,
                    "aria_expanded": aria_expanded,
                    "aria_disabled": aria_disabled,
                    "disabled": disabled,
                }
                meta["selector_key"] = _stable_selector_key(meta)
                candidate_meta.append(meta)
            except Exception:
                continue

        if candidate_meta:
            candidate_meta = [
                m
                for m in candidate_meta
                if not _candidate_is_disabled(m)
                and not (_candidate_is_tab_like(m) and _candidate_is_active(m))
            ]

        explored = _load_explored_selectors(
            ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db"
        )
        # Prefer selectors that previously led to meaningful UI transitions on this route.
        flow_bias = _load_ui_flow_bias(
            ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db",
            page.url if hasattr(page, "url") else "",
        )
        bias_scores = {
            str(item.get("selector") or ""): float(item.get("count") or 0)
            for item in (flow_bias or [])
            if item.get("selector")
        }
        def _score_candidate(meta: Dict[str, Any]) -> float:
            score = _rank_exploration_candidate(meta, explored)
            sel = str(meta.get("selector") or "")
            if sel and sel in bias_scores:
                score += min(2.0, 0.4 + 0.15 * bias_scores.get(sel, 0))
            if _candidate_is_tab_like(meta) and not _candidate_is_active(meta):
                score += 0.25
            return score
        candidate_meta.sort(key=_score_candidate, reverse=True)
        if random.random() < 0.35:
            random.shuffle(candidate_meta)

        # Prefer scroll sometimes to explore below the fold
        if random.random() < 0.35:
            await page.mouse.wheel(0, 600)
            with open(learn_log, "a", encoding="utf-8") as f:
                f.write(f"{time.time()}\t{session}\texplore\tscroll\n")
            return

        # Try typing into a search input if present (diverse terms)
        if random.random() < 0.35:
            search_inputs = await page.query_selector_all(
                "input[type='search'], input[name*='search'], input[placeholder*='بحث'], input[placeholder*='search']"
            )
            if search_inputs:
                el = random.choice(search_inputs)
                try:
                    before_hash = await _dom_state_hash(page)
                    box = await el.bounding_box()
                    if box:
                        x = box["x"] + box["width"] / 2
                        y = box["y"] + box["height"] / 2
                        await motor.move_to(page, x, y, danger=False)
                    await el.click()
                    terms = _build_search_terms()
                    term = random.choice(terms)
                    await el.fill("")
                    await el.type(term, delay=50)
                    await page.wait_for_timeout(300)
                    after_hash = await _dom_state_hash(page)
                    seen.add(hash(f"search:{term}"))
                    with open(learn_log, "a", encoding="utf-8") as f:
                        f.write(f"{time.time()}\t{session}\texplore\tsearch:{term}\n")
                    unchanged = bool(before_hash and after_hash and before_hash == after_hash)
                    force_submit = bool(_cfg_value("auto_submit_search", True))
                    if unchanged or force_submit:
                        auto_changed = await _attempt_search_submit(
                            page,
                            selector="input[type='search']",
                            db_path=db_path,
                            session=session,
                            reason="search_no_change" if unchanged else "auto_submit",
                        )
                        if auto_changed:
                            after_hash = await _dom_state_hash(page)
                            unchanged = False
                    if unchanged:
                        log_event(
                            db_path,
                            session,
                            {
                                "event_type": "search_no_change",
                                "route": page.url if hasattr(page, "url") else "",
                                "method": "SEARCH",
                                "payload": f"term:{term}",
                                "status": None,
                            },
                        )
                        # Persist hint for future sessions (press Enter / click search button).
                        try:
                            if record_behavior_hint:
                                record_behavior_hint(
                                    db_path,
                                    page_url=page.url if hasattr(page, "url") else "",
                                    action="type",
                                    selector="input[type='search']",
                                    hint="press_enter",
                                    confidence=0.55,
                                    notes="search_no_change",
                                )
                                record_behavior_hint(
                                    db_path,
                                    page_url=page.url if hasattr(page, "url") else "",
                                    action="type",
                                    selector="input[type='search']",
                                    hint="click_search_button",
                                    confidence=0.5,
                                    notes="search_no_change",
                                )
                        except Exception:
                            pass
                    _record_exploration_outcome(
                        db_path,
                        selector="input[type='search']",
                        href="",
                        route=page.url if hasattr(page, "url") else "",
                        result="search",
                        selector_key=_selector_key_from_selector("input[type='search']"),
                    )
                    return
                except Exception:
                    pass

        for m in candidate_meta:
            el = m["element"]
            desc = (
                await el.get_attribute("title")
                or await el.text_content()
                or await el.get_attribute("href")
                or ""
            )
            href = await el.get_attribute("href") or ""
            href_norm = href.strip().lower()
            # Avoid repeatedly hovering/clicking home links
            if (
                href_norm in ("/", "/index.php", "index.php")
                or "index.php" in href_norm
            ):
                continue
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
                _record_explored_selector(
                    ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db",
                    m.get("selector") or "",
                    href,
                    m.get("tag") or "",
                )
                _record_exploration_outcome(
                    db_path,
                    selector=m.get("selector") or "",
                    href=href,
                    route=page.url if hasattr(page, "url") else "",
                    result="hover",
                    selector_key=str(m.get("selector_key") or ""),
                )
                # Capture hover-driven UI changes for operational coverage.
                try:
                    sem = await _maybe_store_semantic_snapshot(
                        page,
                        db_path,
                        source="explore_hover",
                        session=session,
                    )
                    await _maybe_store_action_snapshot(
                        page,
                        db_path,
                        source="explore_hover",
                        session=session,
                    )
                    if sem and record_ui_flow_transition is not None:
                        try:
                            ui_states = (sem.get("summary") or {}).get("ui_states") or {}
                        except Exception:
                            ui_states = {}
                        record_ui_flow_transition(
                            db_path,
                            session=session,
                            from_url=sem.get("url") or "",
                            to_url=sem.get("url") or "",
                            action="hover",
                            selector=str(m.get("selector") or ""),
                            semantic_delta=sem.get("delta") or {},
                            ui_states=ui_states,
                            created_at=time.time(),
                        )
                    log_event(
                        db_path,
                        session,
                        {
                            "event_type": "ui_hover",
                            "route": page.url if hasattr(page, "url") else "",
                            "method": "HOVER",
                            "payload": f"selector:{m.get('selector') or ''}",
                            "status": 200,
                        },
                    )
                except Exception:
                    pass
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
    _refresh_scenario_lock()
    async def _apply_pre_hints(action_name: str, selector: str) -> None:
        if not get_behavior_hints:
            return
        if not page_url or not selector:
            return
        try:
            hints = get_behavior_hints(
                db_path,
                page_url=page_url,
                action=action_name,
                selector=selector,
                limit=4,
            )
        except Exception:
            return
        for h in hints:
            hint = str(h.get("hint") or "").strip().lower()
            if not hint:
                continue
            try:
                if hint == "scroll_into_view":
                    await page.locator(selector).scroll_into_view_if_needed(timeout=800)
                    used_hint_ids.append(int(h.get("id") or 0))
                elif hint.startswith("preclick:"):
                    sel = hint.split(":", 1)[1].strip()
                    if not sel or sel == selector:
                        continue
                    await policy.perform_click(
                        page,
                        sel,
                        danger=False,
                        hover_wait_ms=int(step.get("hover_wait_ms", DEFAULT_HOVER_WAIT_MS)),
                        post_click_ms=int(step.get("click_post_wait_ms", 120)),
                        learn_log=Path(ROOT_DIR / "storage" / "logs" / "learned_events.tsv"),
                        session=step.get("session", "") + ":hint",
                        screenshot_dir=Path(ROOT_DIR / "storage" / "logs" / "captures"),
                        log_event_fn=log_event,
                        db_path=db_path,
                    )
                    used_hint_ids.append(int(h.get("id") or 0))
            except Exception:
                continue

    action = step.get("action")
    is_interactive = action in ("click", "type", "press", "hover")
    before_hash = ""
    after_hash = ""
    page_url = ""
    try:
        page_url = page.url if hasattr(page, "url") else ""
    except Exception:
        page_url = ""
    prev_url = page_url
    used_hint_ids: List[int] = []
    extra_wait_ms = 0
    did_submit = False
    changed = False
    unchanged = False
    if is_interactive and step.get("track_outcome", True):
        before_hash = await _dom_state_hash(page)
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
        try:
            await _apply_pre_hints("click", str(step.get("selector", "")))
        except Exception:
            pass
        # Apply learned hint: wait longer after click
        try:
            if get_behavior_hints and page_url:
                hints = get_behavior_hints(
                    db_path,
                    page_url=page_url,
                    action="click",
                    selector=str(step.get("selector", "")),
                    limit=3,
                )
                for h in hints:
                    if str(h.get("hint") or "").lower() == "wait_longer":
                        extra_wait_ms = max(extra_wait_ms, 700)
                        used_hint_ids.append(int(h.get("id") or 0))
                        break
        except Exception:
            pass
        if danger:
            try:
                auth = authority or Authority(ROOT_DIR)
                gate = auth.gate(
                    ActionRequest(
                        kind=ActionKind.WRITE_PROD,
                        operation="scenario.ui_click",
                        command=f"click {step.get('selector','')}",
                        scope=[str(step.get("selector", ""))],
                        reason=f"UI click marked dangerous ({scenario_name})",
                        confidence=0.6,
                        metadata={"scenario": scenario_name, "step": step},
                    ),
                    source="scenario_runner",
                )
                if not gate.allowed:
                    log_event(
                        db_path,
                        step.get("session", ""),
                        {
                            "event_type": "ui_click_skipped",
                            "route": step.get("selector", ""),
                            "method": "CLICK",
                            "payload": gate.message or "blocked by authority gate",
                            "status": None,
                        },
                    )
                    return
            except Exception:
                pass
        try:
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
            if extra_wait_ms:
                await page.wait_for_timeout(int(extra_wait_ms))
        except Exception:
            if not step.get("optional"):
                raise
    elif action == "hover":
        await ensure_cursor(page)
        try:
            selector = str(step.get("selector", ""))
            if selector:
                await page.hover(selector)
                await page.wait_for_timeout(int(step.get("hover_wait_ms", 120)))
        except Exception:
            if not step.get("optional"):
                raise
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
        # Gate upload via Authority
        try:
            auth = authority or Authority(ROOT_DIR)
            gate = auth.gate(
                ActionRequest(
                    kind=ActionKind.WRITE_PROD,
                    operation="scenario.file_upload",
                    command=f"upload {step.get('selector','')}",
                    scope=[str(step.get("selector", ""))],
                    reason=f"File upload in scenario ({scenario_name})",
                    confidence=0.7,
                    metadata={"scenario": scenario_name, "step": step},
                ),
                source="scenario_runner",
            )
            if not gate.allowed:
                log_event(
                    db_path,
                    step.get("session", ""),
                    {
                        "event_type": "file_upload_skipped",
                        "route": step.get("selector", ""),
                        "method": "UPLOAD",
                        "payload": gate.message or "blocked by authority gate",
                        "status": None,
                    },
                )
                return
        except Exception:
            pass
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
                    candidate = uploads_dir / p.name
                    if candidate.exists():
                        resolved.append(str(candidate))
                        continue
                else:
                    for ext in (".xlsx", ".xls", ".csv"):
                        candidate = uploads_dir / f"{p.name}{ext}"
                        if candidate.exists():
                            resolved.append(str(candidate))
                            break
                    else:
                        # Fall back to workspace-relative resolution below.
                        pass
                    if resolved and resolved[-1].lower().startswith(
                        str(uploads_dir).lower()
                    ):
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
                    learn_log=Path(
                        ROOT_DIR / "storage" / "logs" / "learned_events.tsv"
                    ),
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
        try:
            await _apply_pre_hints("type", str(step.get("selector", "")))
        except Exception:
            pass
        try:
            await page.fill(
                step["selector"],
                step.get("text", ""),
                timeout=step.get("timeout", 5000),
            )
            # Apply learned hints (press Enter / click search button)
            try:
                if get_behavior_hints and page_url:
                    hints = get_behavior_hints(
                        db_path,
                        page_url=page_url,
                        action="type",
                        selector=str(step.get("selector", "")),
                        limit=3,
                    )
                    for h in hints:
                        hint = str(h.get("hint") or "").lower()
                        if hint == "press_enter":
                            await page.press(step["selector"], "Enter", timeout=3000)
                            used_hint_ids.append(int(h.get("id") or 0))
                            did_submit = True
                        elif hint == "click_search_button":
                            if await _try_click_search_button(page):
                                used_hint_ids.append(int(h.get("id") or 0))
                                did_submit = True
            except Exception:
                pass
        except Exception:
            allow_soft_fail = step.get("optional") or str(scenario_name or "").startswith("autonomous_")
            if not allow_soft_fail:
                raise
            try:
                log_event(
                    db_path,
                    step.get("session", ""),
                    {
                        "event_type": "ui_type_failed",
                        "route": page.url if hasattr(page, "url") else "",
                        "method": "TYPE",
                        "payload": f"selector:{step.get('selector', '')}",
                        "status": None,
                    },
                )
            except Exception:
                pass
            return {
                "action": action,
                "changed": False,
                "unchanged": True,
                "url": page_url,
            }
    elif action == "press":
        await ensure_cursor(page)
        try:
            await _apply_pre_hints("press", str(step.get("selector", "")))
        except Exception:
            pass
        try:
            await page.press(
                step["selector"],
                step.get("key", "Enter"),
                timeout=step.get("timeout", 5000),
            )
        except Exception:
            allow_soft_fail = step.get("optional") or str(scenario_name or "").startswith("autonomous_")
            if not allow_soft_fail:
                raise
            try:
                log_event(
                    db_path,
                    step.get("session", ""),
                    {
                        "event_type": "ui_press_failed",
                        "route": page.url if hasattr(page, "url") else "",
                        "method": "PRESS",
                        "payload": f"selector:{step.get('selector', '')}",
                        "status": None,
                    },
                )
            except Exception:
                pass
            return {
                "action": action,
                "changed": False,
                "unchanged": True,
                "url": page_url,
            }
    elif action == "scroll":
        await ensure_cursor(page)
        dx = int(step.get("dx", 0))
        dy = int(step.get("dy", 400))
        await page.mouse.wheel(dx, dy)
        await page.wait_for_timeout(int(step.get("post_wait_ms", 200)))
    else:
        print(f"[!] Unknown action in scenario: {action}")
    if is_interactive and step.get("track_outcome", True):
        try:
            after_hash = await _dom_state_hash(page)
            unchanged = bool(before_hash and after_hash and before_hash == after_hash)
            changed = bool(before_hash and after_hash and before_hash != after_hash)
            # If typing into search and nothing changed, attempt auto-submit once.
            if action == "type":
                selector = str(step.get("selector", ""))
                is_search = await _is_search_input(page, selector)
                if is_search:
                    force_submit = bool(
                        step.get("auto_submit", _cfg_value("auto_submit_search", True))
                    )
                    if (unchanged or force_submit) and not did_submit:
                        auto_changed = await _attempt_search_submit(
                            page,
                            selector=selector,
                            db_path=db_path,
                            session=step.get("session", ""),
                            reason="dom_no_change" if unchanged else "auto_submit",
                        )
                        did_submit = True
                        if auto_changed:
                            after_hash = await _dom_state_hash(page)
                            unchanged = False
                            changed = True
                else:
                    force_submit_form = bool(
                        step.get(
                            "auto_submit_form",
                            _cfg_value("auto_submit_form", True),
                        )
                    )
                    if (unchanged or force_submit_form) and not did_submit:
                        auto_changed = await _attempt_form_submit(
                            page,
                            selector=selector,
                            db_path=db_path,
                            session=step.get("session", ""),
                            reason="dom_no_change" if unchanged else "auto_submit_form",
                        )
                        did_submit = True
                        if auto_changed:
                            after_hash = await _dom_state_hash(page)
                            unchanged = False
                            changed = True
            if unchanged:
                log_event(
                    db_path,
                    step.get("session", ""),
                    {
                        "event_type": "dom_no_change",
                        "route": page.url if hasattr(page, "url") else "",
                        "method": str(action).upper(),
                        "payload": f"selector:{step.get('selector', '')}",
                        "status": None,
                    },
                )
                if action == "hover":
                    log_event(
                        db_path,
                        step.get("session", ""),
                        {
                            "event_type": "ui_hover",
                            "route": page.url if hasattr(page, "url") else "",
                            "method": "HOVER",
                            "payload": f"selector:{step.get('selector', '')}",
                            "status": None,
                        },
                    )
            if action == "hover":
                try:
                    selector = str(step.get("selector") or "")
                    href = str(step.get("href") or "")
                    selector_key = str(step.get("selector_key") or "") or _selector_key_from_selector(selector)
                    _record_exploration_outcome(
                        db_path,
                        selector=selector,
                        href=href,
                        route=page.url if hasattr(page, "url") else "",
                        result="hover",
                        selector_key=selector_key,
                    )
                except Exception:
                    pass
            # Record successful UI interaction when it produces a DOM change.
            if changed and action in ("click", "press", "type", "hover"):
                try:
                    if action == "hover":
                        event_type = "ui_hover"
                    else:
                        event_type = "ui_click" if action in ("click", "press") else "ui_input"
                    log_event(
                        db_path,
                        step.get("session", ""),
                        {
                            "event_type": event_type,
                            "route": page.url if hasattr(page, "url") else "",
                            "method": str(action).upper(),
                            "payload": f"selector:{step.get('selector', '')}",
                            "status": 200,
                        },
                    )
                except Exception:
                    pass
            # Record virtual navigation when DOM changes without URL change (modals/tabs).
            try:
                current_url = page.url if hasattr(page, "url") else prev_url
            except Exception:
                current_url = prev_url
            if changed and current_url and current_url == prev_url:
                log_event(
                    db_path,
                    step.get("session", ""),
                    {
                        "event_type": "virtual_nav",
                        "route": current_url,
                        "method": str(action).upper(),
                        "payload": json.dumps(
                            {
                                "selector": step.get("selector", ""),
                                "before": before_hash,
                                "after": after_hash,
                                "reason": "dom_change_no_url",
                            },
                            ensure_ascii=False,
                        ),
                        "status": 200,
                    },
                )
            # Record real interaction only when it produced a meaningful DOM change.
            if changed and action in ("click", "press", "type", "upload"):
                try:
                    route = current_url or ""
                    selector = str(step.get("selector") or "")
                    href = str(step.get("href") or "")
                    _record_exploration_outcome(
                        db_path,
                        selector=selector,
                        href=href,
                        route=route,
                        result=str(action),
                    )
                except Exception:
                    pass
            # Record persistent behavior hints
            try:
                if record_behavior_hint and page_url and unchanged:
                    selector = str(step.get("selector", ""))
                    if action == "type" and await _is_search_input(page, selector):
                        record_behavior_hint(
                            db_path,
                            page_url=page_url,
                            action="type",
                            selector=selector,
                            hint="press_enter",
                            confidence=0.6,
                            notes="dom_no_change",
                        )
                        record_behavior_hint(
                            db_path,
                            page_url=page_url,
                            action="type",
                            selector=selector,
                            hint="click_search_button",
                            confidence=0.55,
                            notes="dom_no_change",
                        )
                    elif action == "click":
                        record_behavior_hint(
                            db_path,
                            page_url=page_url,
                            action="click",
                            selector=selector,
                            hint="wait_longer",
                            confidence=0.5,
                            notes="dom_no_change",
                        )
            except Exception:
                pass
            # Update hint success/failure
            if used_hint_ids and mark_hint_result:
                success = bool(before_hash and after_hash and before_hash != after_hash)
                for hid in used_hint_ids:
                    if hid:
                        mark_hint_result(db_path, hid, success)
        except Exception:
            pass

    # Persist UI semantic snapshot + flow transition (phase 3)
    try:
        if action in ("goto", "click", "type", "press", "upload", "hover"):
            session_name = str(step.get("session") or scenario_name or "")
            sem = await _maybe_store_semantic_snapshot(
                page,
                db_path,
                source="scenario_runner",
                session=session_name,
            )
            if sem and isinstance(sem.get("summary"), dict):
                summary = sem.get("summary") or {}
                text_blocks = summary.get("text_blocks") or []
                keywords = summary.get("text_keywords") or []
                if text_blocks or keywords:
                    try:
                        log_event(
                            db_path,
                            session_name,
                            {
                                "event_type": "text_block_seen",
                                "route": sem.get("url") or "",
                                "method": "TEXT",
                                "payload": {
                                    "keywords": keywords[:6],
                                    "sample": text_blocks[:3],
                                },
                                "status": 200,
                            },
                        )
                        try:
                            # Promote meaningful non-interactive content into autonomy goals
                            # so exploration can verify context, not just buttons.
                            _write_autonomy_goal(
                                db_path,
                                "text_focus",
                                {
                                    "url": sem.get("url") or "",
                                    "keywords": keywords[:6],
                                    "sample": text_blocks[:3],
                                    "priority_score": 45 + (5 * min(3, len(keywords))),
                                },
                                source="text_blocks",
                                ttl_days=3,
                            )
                        except Exception:
                            pass
                    except Exception:
                        pass
            await _maybe_store_action_snapshot(
                page,
                db_path,
                source="scenario_runner",
                session=session_name,
            )
            if sem and record_ui_flow_transition is not None:
                ui_states = {}
                try:
                    ui_states = (sem.get("summary") or {}).get("ui_states") or {}
                except Exception:
                    ui_states = {}
                record_ui_flow_transition(
                    db_path,
                    session=session_name,
                    from_url=prev_url,
                    to_url=sem.get("url") or "",
                    action=str(action),
                    selector=str(step.get("selector", "")),
                    semantic_delta=sem.get("delta") or {},
                    ui_states=ui_states,
                    created_at=time.time(),
                )
            # If no semantic change detected, attempt one safe semantic shift per scenario.
            try:
                if sem and not (sem.get("delta") or {}).get("changed"):
                    if not getattr(page, "_bgl_semantic_shift_done", False):
                        attempts = int(getattr(page, "_bgl_semantic_shift_attempts", 0) or 0)
                        if attempts < 2:
                            shifted = await _attempt_semantic_shift(page, db_path, session_name)
                            page._bgl_semantic_shift_attempts = attempts + 1  # type: ignore
                            if shifted:
                                page._bgl_semantic_shift_done = True  # type: ignore
            except Exception:
                pass
    except Exception:
        pass

    # Record executed UI actions as exploration outcomes (operational coverage).
    try:
        if action in ("click", "type", "press", "hover"):
            selector = str(step.get("selector") or "")
            if selector:
                tag = ""
                href = ""
                try:
                    el = await page.query_selector(selector)
                    if el:
                        tag = (
                            await el.evaluate(
                                "el => (el.tagName || '').toLowerCase()"
                            )
                        ) or ""
                        href = await el.get_attribute("href") or ""
                except Exception:
                    tag = ""
                    href = ""
                if _record_explored_selector:
                    _record_explored_selector(db_path, selector, href, tag)
                result = "changed" if changed else action
                if _record_exploration_outcome:
                    selector_key = _selector_key_from_selector(selector)
                    if not selector_key and href:
                        selector_key = f"href={_href_basename(href)}"
                    _record_exploration_outcome(
                        db_path,
                        selector=selector,
                        href=href,
                        route=page_url,
                        result=result,
                        delta_score=1.0 if changed else 0.0,
                        selector_key=selector_key,
                    )
    except Exception:
        pass

    return {
        "action": action,
        "changed": changed,
        "unchanged": unchanged,
        "url": page_url,
    }


def log_event(db_path: Path, session: str, event: Dict[str, Any]):
    try:
        timeout = float(os.getenv("BGL_EVENT_DB_TIMEOUT", "5"))
    except Exception:
        timeout = 5.0
    if os.getenv("BGL_TRACE_SCENARIO", "0") == "1":
        _trace(f"log_event: start type={event.get('event_type')} session={session}")
    try:
        db = connect_db(str(db_path), timeout=timeout)
        db.execute("PRAGMA journal_mode=WAL;")
        try:
            db.execute(f"PRAGMA busy_timeout={int(timeout * 1000)};")
        except Exception:
            pass
    except Exception as e:
        if os.getenv("BGL_TRACE_SCENARIO", "0") == "1":
            _trace(f"log_event: db open error {e}")
        return
    try:
        db.execute(
            """
        CREATE TABLE IF NOT EXISTS runtime_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp REAL NOT NULL,
            session TEXT,
            run_id TEXT,
            scenario_id TEXT,
            goal_id TEXT,
            source TEXT,
            event_type TEXT NOT NULL,
            route TEXT,
            method TEXT,
            target TEXT,
            step_id TEXT,
            payload TEXT,
            status INTEGER,
            latency_ms REAL,
            error TEXT
        )
    """
        )
    except Exception as e:
        if os.getenv("BGL_TRACE_SCENARIO", "0") == "1":
            _trace(f"log_event: table error {e}")
        try:
            db.close()
        except Exception:
            pass
        return
    try:
        cols = {r[1] for r in db.execute("PRAGMA table_info(runtime_events)").fetchall()}
        if "run_id" not in cols:
            db.execute("ALTER TABLE runtime_events ADD COLUMN run_id TEXT")
        if "scenario_id" not in cols:
            db.execute("ALTER TABLE runtime_events ADD COLUMN scenario_id TEXT")
        if "goal_id" not in cols:
            db.execute("ALTER TABLE runtime_events ADD COLUMN goal_id TEXT")
        if "source" not in cols:
            db.execute("ALTER TABLE runtime_events ADD COLUMN source TEXT")
        if "step_id" not in cols:
            db.execute("ALTER TABLE runtime_events ADD COLUMN step_id TEXT")
    except Exception:
        pass
    payload = event.get("payload")
    meta = event.get("meta")
    if isinstance(payload, dict):
        if isinstance(meta, dict) and meta:
            payload = {**payload, **meta}
        payload = json.dumps(payload, ensure_ascii=False)
    elif isinstance(meta, dict) and meta:
        payload = json.dumps({"payload": payload, "meta": meta}, ensure_ascii=False)
    try:
        db.execute(
            """
        INSERT INTO runtime_events (timestamp, session, run_id, scenario_id, goal_id, source, event_type, route, method, target, step_id, payload, status, latency_ms, error)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
    """,
            (
                event.get("timestamp", time.time()),
                _decorate_session(session),
                str(event.get("run_id") or _CURRENT_RUN_ID or ""),
                str(event.get("scenario_id") or _CURRENT_SCENARIO_ID or os.getenv("BGL_SCENARIO_ID") or ""),
                str(event.get("goal_id") or _CURRENT_GOAL_ID or os.getenv("BGL_GOAL_ID") or ""),
                str(event.get("source") or "agent"),
                event.get("event_type"),
                event.get("route"),
                event.get("method"),
                event.get("target"),
                event.get("step_id"),
                payload,
                event.get("status"),
                event.get("latency_ms"),
                event.get("error"),
            ),
        )
        db.commit()
    except Exception as e:
        if os.getenv("BGL_TRACE_SCENARIO", "0") == "1":
            _trace(f"log_event: insert error {e}")
        # Fallback: append to jsonl if DB is locked, to avoid stalling scenarios
        try:
            if "database is locked" in str(e).lower():
                fallback = ROOT_DIR / ".bgl_core" / "logs" / "runtime_events_fallback.jsonl"
                fallback.parent.mkdir(parents=True, exist_ok=True)
                payload = {
                    "timestamp": time.time(),
                    "session": _decorate_session(session),
                    "event": event,
                    "error": "database_is_locked",
                }
                fallback.open("a", encoding="utf-8").write(
                    json.dumps(payload, ensure_ascii=False) + "\n"
                )
        except Exception:
            pass
    finally:
        try:
            db.close()
        except Exception:
            pass
    if os.getenv("BGL_TRACE_SCENARIO", "0") == "1":
        _trace(f"log_event: done type={event.get('event_type')}")


def _ensure_outcomes_tables(db: sqlite3.Connection) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS exploration_outcomes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp REAL NOT NULL,
            source TEXT,
            kind TEXT,
            value TEXT,
            route TEXT,
            payload_json TEXT,
            session TEXT,
            run_id TEXT,
            scenario_id TEXT,
            goal_id TEXT
        )
        """
    )
    try:
        cols = {
            r[1]
            for r in db.execute("PRAGMA table_info(exploration_outcomes)").fetchall()
        }
        if "run_id" not in cols:
            db.execute("ALTER TABLE exploration_outcomes ADD COLUMN run_id TEXT")
        if "scenario_id" not in cols:
            db.execute("ALTER TABLE exploration_outcomes ADD COLUMN scenario_id TEXT")
        if "goal_id" not in cols:
            db.execute("ALTER TABLE exploration_outcomes ADD COLUMN goal_id TEXT")
    except Exception:
        pass
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS exploration_outcome_relations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at REAL NOT NULL,
            outcome_id_a INTEGER NOT NULL,
            outcome_id_b INTEGER NOT NULL,
            relation TEXT NOT NULL,
            score REAL NOT NULL,
            reason TEXT,
            FOREIGN KEY(outcome_id_a) REFERENCES exploration_outcomes(id),
            FOREIGN KEY(outcome_id_b) REFERENCES exploration_outcomes(id)
        )
        """
    )
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS exploration_outcome_scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            outcome_id INTEGER NOT NULL,
            created_at REAL NOT NULL,
            base_score REAL NOT NULL,
            relation_score REAL NOT NULL,
            total_score REAL NOT NULL,
            FOREIGN KEY(outcome_id) REFERENCES exploration_outcomes(id)
        )
        """
    )


def _log_outcome(
    db_path: Path,
    *,
    source: str,
    kind: str,
    value: str,
    route: str = "",
    payload: Optional[Dict[str, Any]] = None,
    session: str = "",
    ts: Optional[float] = None,
) -> Optional[int]:
    try:
        if not db_path.exists():
            return None
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_outcomes_tables(db)
        payload_json = json.dumps(payload or {}, ensure_ascii=False)
        cols = {
            r[1]
            for r in db.execute("PRAGMA table_info(exploration_outcomes)").fetchall()
        }
        run_id = str(_CURRENT_RUN_ID or os.getenv("BGL_RUN_ID") or "")
        scenario_id = str(_CURRENT_SCENARIO_ID or os.getenv("BGL_SCENARIO_ID") or "")
        goal_id = str(_CURRENT_GOAL_ID or os.getenv("BGL_GOAL_ID") or "")
        if {"run_id", "scenario_id", "goal_id"}.issubset(cols):
            cur = db.execute(
                "INSERT INTO exploration_outcomes (timestamp, source, kind, value, route, payload_json, session, run_id, scenario_id, goal_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)",
                (
                    ts or time.time(),
                    source,
                    kind,
                    value,
                    route,
                    payload_json,
                    _decorate_session(session),
                    run_id,
                    scenario_id,
                    goal_id,
                ),
            )
        else:
            cur = db.execute(
                "INSERT INTO exploration_outcomes (timestamp, source, kind, value, route, payload_json, session) VALUES (?, ?, ?, ?, ?, ?, ?)",
                (
                    ts or time.time(),
                    source,
                    kind,
                    value,
                    route,
                    payload_json,
                    _decorate_session(session),
                ),
            )
        oid = cur.lastrowid
        db.commit()
        db.close()
        if upsert_memory_item and oid:
            upsert_memory_item(
                db_path,
                kind="outcome",
                key_text=f"{kind}:{value}:{route}",
                summary=f"{kind} {value} on {route or 'unknown'}",
                evidence_count=1,
                confidence=0.65 if str(value).lower() in ("success", "changed") else 0.5,
                meta={
                    "source": source,
                    "payload": payload or {},
                    "session": session,
                    "run_id": run_id,
                    "scenario_id": scenario_id,
                    "goal_id": goal_id,
                },
                source_table="exploration_outcomes",
                source_id=int(oid),
            )
        return int(oid) if oid else None
    except Exception:
        return None


def _log_relation(
    db_path: Path,
    *,
    a_id: int,
    b_id: int,
    relation: str,
    score: float,
    reason: str,
) -> None:
    try:
        if not db_path.exists():
            return
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_outcomes_tables(db)
        db.execute(
            "INSERT INTO exploration_outcome_relations (created_at, outcome_id_a, outcome_id_b, relation, score, reason) VALUES (?, ?, ?, ?, ?, ?)",
            (time.time(), a_id, b_id, relation, score, reason),
        )
        db.commit()
        db.close()
    except Exception:
        return


def _derive_outcomes_from_runtime(
    db_path: Path, since_ts: float, limit: int = 400
) -> List[int]:
    ids: List[int] = []
    try:
        if not db_path.exists():
            return ids
        ext_window = int(_cfg_value("external_dependency_window_minutes", 30) or 30)
        ext_dep = _recent_external_dependency(db_path, minutes=ext_window)
        ext_active = bool(ext_dep.get("count") or 0)
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        rows = cur.execute(
            """
            SELECT timestamp, event_type, route, method, status, payload, session, source, run_id, step_id
            FROM runtime_events
            WHERE timestamp >= ?
            ORDER BY id DESC
            LIMIT ?
            """,
            (since_ts, int(limit)),
        ).fetchall()
        db.close()
        current_run = str(_CURRENT_RUN_ID or "")
        for ts, event_type, route, method, status, payload, session, source_tag, run_id, step_id in rows:
            kind = ""
            value = ""
            source = "runtime"
            attribution = "agent"
            if source_tag and str(source_tag).lower() not in ("agent", "scenario"):
                source = f"runtime:{source_tag}"
                attribution = str(source_tag)
            if current_run:
                if run_id and str(run_id) != current_run:
                    attribution = "external"
                elif (not run_id) and session and not str(session).startswith(current_run):
                    attribution = "external"
            if event_type in ("http_error", "network_fail", "console_error"):
                if ext_active:
                    kind = "deferred"
                    value = "external_dependency"
                else:
                    kind = "error"
                    value = event_type
            elif event_type == "api_call":
                kind = "api_result"
                value = str(status or "")
            elif event_type == "autonomy_goal_result":
                kind = "goal_result"
                value = str(status or "")
            elif event_type in ("route_index_auto", "route_index_exploration"):
                kind = "route_index"
                value = event_type
            elif event_type == "filechooser_blocked":
                if ext_active:
                    kind = "deferred"
                    value = "external_dependency"
                else:
                    kind = "gap"
                    value = "filechooser_blocked"
            elif event_type in ("search_no_change", "dom_no_change"):
                if ext_active:
                    kind = "deferred"
                    value = "external_dependency"
                else:
                    kind = "gap"
                    value = event_type
            else:
                continue
            oid = _log_outcome(
                db_path,
                source=source,
                kind=kind,
                value=value,
                route=str(route or ""),
                payload={
                    "event_type": event_type,
                    "method": method,
                    "status": status,
                    "payload": payload,
                    "event_source": source_tag,
                    "run_id": run_id,
                    "step_id": step_id,
                    "attribution": attribution,
                    "external_dependency": ext_active,
                    "external_detail": ext_dep.get("last_message", "") if ext_active else "",
                },
                session=str(session or ""),
                ts=float(ts or time.time()),
            )
            if oid:
                ids.append(oid)
    except Exception:
        return ids
    return ids


def _derive_outcomes_from_learning(
    db_path: Path, since_ts: float, limit: int = 120
) -> List[int]:
    ids: List[int] = []
    try:
        learn_log = ROOT_DIR / "storage" / "logs" / "learned_events.tsv"
        if not learn_log.exists():
            return ids
        lines = learn_log.read_text(encoding="utf-8", errors="ignore").splitlines()
        for line in lines[-limit:]:
            if "\t" not in line:
                continue
            parts = line.split("\t")
            if len(parts) < 4:
                continue
            try:
                ts = float(parts[0])
            except Exception:
                ts = None
            if ts is not None and ts < since_ts:
                continue
            _ = parts[2]
            detail = parts[3]
            if not detail.startswith("search:"):
                continue
            term = detail.split("search:", 1)[1].strip()
            if not term:
                continue
            oid = _log_outcome(
                db_path,
                source="explore",
                kind="search_query",
                value=term,
                route="",
                payload={"raw": detail},
                session=str(parts[1] if len(parts) > 1 else ""),
                ts=ts or time.time(),
            )
            if oid:
                ids.append(oid)
    except Exception:
        return ids
    return ids


def _last_selector_from_payload(payload: str) -> str:
    try:
        if not payload:
            return ""
        if payload.startswith("selector:"):
            return payload.split("selector:", 1)[1].strip()
        if payload.startswith("term:"):
            # Map search term payloads to a generic search selector for scoring
            return "input[type='search']"
        # try json
        obj = json.loads(payload)
        if isinstance(obj, dict):
            return str(obj.get("selector") or "")
    except Exception:
        return ""
    return ""


def _apply_exploration_reward(db_path: Path, selector: str, delta: float) -> None:
    if not selector:
        return
    _record_exploration_outcome(
        db_path,
        selector=selector,
        href="",
        route="",
        result="reward",
        delta_score=delta,
        selector_key=_selector_key_from_selector(selector),
    )


def _reward_exploration_from_outcomes(db_path: Path, since_ts: float) -> None:
    """
    Positive reward for useful outcomes; penalty for no-effect outcomes.
    """
    try:
        if not db_path.exists():
            return
        ext_window = int(_cfg_value("external_dependency_window_minutes", 30) or 30)
        ext_dep = _recent_external_dependency(db_path, minutes=ext_window)
        if ext_dep.get("count"):
            return
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        rows = cur.execute(
            "SELECT event_type, payload, route FROM runtime_events WHERE timestamp >= ? AND event_type IN ('dom_no_change','search_no_change','http_error','network_fail','api_call') ORDER BY id DESC LIMIT 200",
            (since_ts,),
        ).fetchall()
        db.close()
        for event_type, payload, route in rows:
            sel = _last_selector_from_payload(str(payload or ""))
            if event_type in ("dom_no_change", "search_no_change"):
                penalty = -0.6
                try:
                    # Increase penalty if selector is repeatedly unproductive
                    if sel and _novelty_score(db_path, sel) < 0.2:
                        penalty = -1.0
                except Exception:
                    pass
                _apply_exploration_reward(db_path, sel, penalty)
                # If repeated no-change, push a gap-deepen goal
                try:
                    if penalty <= -1.0:
                        term = ""
                        if str(payload or "").startswith("term:"):
                            term = str(payload or "").split("term:", 1)[1].strip()
                        _write_autonomy_goal(
                            db_path,
                            goal="gap_deepen",
                            payload={
                                "uri": str(route or ""),
                                "kind": "gap",
                                "value": event_type,
                                "score": float(penalty),
                                "search_term": term,
                            },
                            source="stall_guard",
                            ttl_days=5,
                        )
                except Exception:
                    pass
            elif event_type in ("http_error", "network_fail"):
                _apply_exploration_reward(db_path, sel, -0.3)
            elif event_type == "api_call":
                _apply_exploration_reward(db_path, sel, 0.4)
    except Exception:
        return


def _score_outcomes(db_path: Path, since_ts: float, window_sec: float = 300.0) -> None:
    try:
        if not db_path.exists():
            return
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_outcomes_tables(db)
        cur = db.cursor()
        rows = cur.execute(
            "SELECT id, timestamp, kind, value, route FROM exploration_outcomes WHERE timestamp >= ? ORDER BY id DESC LIMIT 400",
            (since_ts,),
        ).fetchall()
        if not rows:
            db.close()
            return
        # Base scoring
        base_scores = {}
        for oid, ts, kind, value, route in rows:
            if kind == "error":
                base = 2.0
            elif kind == "api_result":
                base = 1.0
            elif kind == "goal_result":
                base = 1.0
            elif kind == "gap":
                base = 2.5
            elif kind == "search_query":
                base = 0.5
            else:
                base = 0.0
            base_scores[int(oid)] = base

        # Relations
        for i in range(len(rows)):
            oid_a, ts_a, kind_a, val_a, route_a = rows[i]
            for j in range(i + 1, len(rows)):
                oid_b, ts_b, kind_b, val_b, route_b = rows[j]
                if abs((ts_a or 0) - (ts_b or 0)) > window_sec:
                    continue
                if not route_a or not route_b or str(route_a) != str(route_b):
                    continue
                relation = ""
                score = 0.0
                reason = ""
                # Contradiction: error + successful api result on same route
                if (kind_a == "error" and kind_b == "api_result") or (
                    kind_b == "error" and kind_a == "api_result"
                ):
                    try:
                        ok = int(val_a or 0) < 400 or int(val_b or 0) < 400
                    except Exception:
                        ok = False
                    if ok:
                        relation = "contradiction"
                        score = -3.0
                        reason = "error vs api_success"
                # Reinforcement: two errors on same route
                elif kind_a == "error" and kind_b == "error":
                    relation = "reinforcement"
                    score = 1.0
                    reason = "error_repeat"
                # Corroboration: two api_results ok on same route
                elif kind_a == "api_result" and kind_b == "api_result":
                    try:
                        ok_a = int(val_a or 0) < 400
                        ok_b = int(val_b or 0) < 400
                    except Exception:
                        ok_a = ok_b = False
                    if ok_a and ok_b:
                        relation = "corroboration"
                        score = 1.0
                        reason = "api_success_repeat"
                if relation:
                    _log_relation(
                        db_path,
                        a_id=int(oid_a),
                        b_id=int(oid_b),
                        relation=relation,
                        score=score,
                        reason=reason,
                    )

        # Aggregate relation scores per outcome and write outcome_scores
        for oid in base_scores:
            rel_sum = cur.execute(
                "SELECT COALESCE(SUM(score),0) FROM exploration_outcome_relations WHERE outcome_id_a=? OR outcome_id_b=?",
                (oid, oid),
            ).fetchone()[0]
            total = float(base_scores[oid]) + float(rel_sum or 0)
            db.execute(
                "INSERT INTO exploration_outcome_scores (outcome_id, created_at, base_score, relation_score, total_score) VALUES (?, ?, ?, ?, ?)",
                (oid, time.time(), float(base_scores[oid]), float(rel_sum or 0), total),
            )
        db.commit()
        db.close()
    except Exception:
        return


def _seed_goals_from_outcome_scores(
    db_path: Path,
    since_ts: float,
    negative_threshold: float = -2.0,
    limit: int = 10,
) -> None:
    """
    Convert strongly negative outcomes into 'gap_deepen' goals.
    """
    try:
        if not db_path.exists():
            return
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_outcomes_tables(db)
        rows = db.execute(
            """
            SELECT o.id, o.kind, o.value, o.route, o.payload_json, s.total_score
            FROM exploration_outcome_scores s
            JOIN exploration_outcomes o ON o.id = s.outcome_id
            WHERE s.created_at >= ? AND s.total_score <= ?
            ORDER BY s.total_score ASC
            LIMIT ?
            """,
            (since_ts, float(negative_threshold), int(limit)),
        ).fetchall()
        db.close()
        for oid, kind, value, route, payload_json, total_score in rows:
            try:
                payload = json.loads(payload_json) if payload_json else {}
            except Exception:
                payload = {}
            if kind == "search_query" and value:
                payload["search_term"] = value
            _write_autonomy_goal(
                db_path,
                goal="gap_deepen",
                payload={
                    "uri": route or payload.get("route") or "",
                    "kind": kind,
                    "value": value,
                    "score": float(total_score),
                    "search_term": payload.get("search_term", ""),
                },
                source="outcome_score",
                ttl_days=7,
            )
    except Exception:
        return


def _load_seen_novel(db_path: Path, limit: int = 500) -> set:
    """
    Return a set of routes previously used in novel probes to avoid repeats.
    """
    try:
        if not db_path.exists():
            return set()
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        rows = cur.execute(
            "SELECT route FROM runtime_events WHERE event_type='novel_probe' ORDER BY id DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        db.close()
        return {r[0] for r in rows if r and r[0]}
    except Exception:
        return set()


def _external_nav_recent_count(db_path: Path, hours: float = 24.0) -> int:
    try:
        if not db_path.exists():
            return 0
        cutoff = time.time() - float(hours) * 3600.0
        db = connect_db(str(db_path), timeout=15.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        row = cur.execute(
            "SELECT COUNT(*) FROM runtime_events WHERE event_type='external_nav' AND timestamp >= ?",
            (cutoff,),
        ).fetchone()
        db.close()
        return int(row[0] or 0) if row else 0
    except Exception:
        return 0


def _external_domain_allowed(href: str, base_url: str) -> bool:
    try:
        parsed = urlparse(href)
        netloc = (parsed.netloc or "").lower()
    except Exception:
        return False
    if not netloc:
        return True
    try:
        base_netloc = urlparse(base_url).netloc.lower()
    except Exception:
        base_netloc = ""
    if base_netloc and (netloc == base_netloc or netloc.endswith("." + base_netloc)):
        return True
    try:
        cfg = load_config(ROOT_DIR)
        allow = cfg.get("external_allow_domains") or cfg.get("allow_external_domains") or []
    except Exception:
        allow = []
    if isinstance(allow, str):
        allow = [allow]
    allow = [str(d).strip().lower() for d in allow if d]
    for dom in allow:
        if netloc == dom or netloc.endswith("." + dom):
            return True
    return False


def _is_external_url(href: str, base_url: str) -> bool:
    try:
        parsed = urlparse(href)
        netloc = (parsed.netloc or "").lower()
    except Exception:
        return False
    if not netloc:
        return False
    try:
        base_netloc = urlparse(base_url).netloc.lower()
    except Exception:
        base_netloc = ""
    if base_netloc and (netloc == base_netloc or netloc.endswith("." + base_netloc)):
        return False
    return True


def _is_safe_novel_href(href: str, text: str, base_url: str) -> bool:
    """
    Conservative safety filter: only allow read-only navigation.
    """
    if not href:
        return False
    h = href.strip()
    if h.startswith("#") or h.lower().startswith("javascript:"):
        return False
    if not _autonomous_enabled():
        if "/api/" in h:
            return False
        # Block common write/action words (English + Arabic).
        block = [
            "delete",
            "remove",
            "destroy",
            "drop",
            "import",
            "upload",
            "save",
            "update",
            "edit",
            "create",
            "add",
            "submit",
            "approve",
            "reject",
            "write",
            "حذف",
            "استيراد",
            "رفع",
            "حفظ",
            "تحديث",
            "تعديل",
            "إنشاء",
            "انشاء",
            "اضافة",
            "إضافة",
            "رفض",
            "اعتماد",
            "ارسال",
            "إرسال",
        ]
        s = (h + " " + (text or "")).lower()
        for b in block:
            if b.lower() in s:
                return False
    # Avoid external domains unless allowlisted
    if h.startswith("http"):
        return _external_domain_allowed(h, base_url)
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


def _routes_table_count(db_path: Path) -> int:
    try:
        if not db_path.exists():
            return 0
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        count = cur.execute("SELECT COUNT(*) FROM routes").fetchone()[0]
        db.close()
        return int(count or 0)
    except Exception:
        return 0


def _route_source_files(root: Path) -> List[Path]:
    candidates = [
        root / "routes" / "web.php",
        root / "routes" / "api.php",
    ]
    return [p for p in candidates if p.exists()]


def _routes_last_index_ts(root: Path) -> float:
    try:
        last = root / ".bgl_core" / "logs" / "route_index.last"
        if last.exists():
            return float(last.read_text().strip() or "0")
    except Exception:
        return 0.0
    return 0.0


def _routes_need_reindex(root: Path, db_path: Path) -> bool:
    # If routes table is empty, reindex immediately.
    if _routes_table_count(db_path) == 0:
        return True
    last_ts = _routes_last_index_ts(root)
    if last_ts <= 0:
        return True
    for p in _route_source_files(root):
        try:
            if p.stat().st_mtime > last_ts:
                return True
        except Exception:
            continue
    return False


def _auto_reindex_routes(root: Path, db_path: Path) -> None:
    if str(_cfg_value("routes_auto_reindex", "1")) != "1":
        return
    if not _routes_need_reindex(root, db_path):
        return
    try:
        idx = LaravelRouteIndexer(root, db_path)
        idx.run(return_routes=False)
        last = root / ".bgl_core" / "logs" / "route_index.last"
        last.parent.mkdir(parents=True, exist_ok=True)
        last.write_text(str(time.time()))
        try:
            log_event(
                db_path,
                "route_index_auto",
                {
                    "event_type": "route_index_auto",
                    "route": "routes/web.php, routes/api.php",
                    "method": "INDEX",
                    "status": 200,
                },
            )
        except Exception:
            pass
    except Exception:
        return


def _unknown_routes_from_runtime(db_path: Path, limit: int = 60) -> List[str]:
    """
    Return recent runtime routes not present in the canonical routes table.
    This lets exploration drive when to refresh the route index.
    """
    try:
        if not db_path.exists():
            return []
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        rows = cur.execute(
            "SELECT route FROM runtime_events WHERE route IS NOT NULL ORDER BY id DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        if not rows:
            db.close()
            return []
        candidates = []
        seen = set()
        for (r,) in rows:
            if not r:
                continue
            s = _normalize_route_path(str(r))
            if not s or s in seen:
                continue
            seen.add(s)
            candidates.append(s)
        if not candidates:
            db.close()
            return []
        placeholders = ",".join("?" for _ in candidates)
        existing = cur.execute(
            f"SELECT uri FROM routes WHERE uri IN ({placeholders})",
            candidates,
        ).fetchall()
        db.close()
        known = {str(r[0]) for r in existing if r and r[0]}
        missing = [c for c in candidates if c not in known]
        return missing
    except Exception:
        return []


def _maybe_reindex_after_exploration(root: Path, db_path: Path) -> None:
    if str(_cfg_value("routes_refresh_on_exploration", "1")) != "1":
        return
    # Only reindex if exploration surfaced routes not in the canonical table.
    missing = _unknown_routes_from_runtime(
        db_path, limit=int(_cfg_value("routes_refresh_probe_limit", 60) or 60)
    )
    if not missing:
        return
    try:
        idx = LaravelRouteIndexer(root, db_path)
        idx.run(return_routes=False)
        last = root / ".bgl_core" / "logs" / "route_index.last"
        last.parent.mkdir(parents=True, exist_ok=True)
        last.write_text(str(time.time()))
        try:
            log_event(
                db_path,
                "route_index_exploration",
                {
                    "event_type": "route_index_exploration",
                    "route": "routes/web.php, routes/api.php",
                    "method": "INDEX",
                    "payload": json.dumps(
                        {"missing_routes": missing[:10]}, ensure_ascii=False
                    ),
                    "status": 200,
                },
            )
        except Exception:
            pass
    except Exception:
        return


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
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
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


def _recent_routes_within_days(
    db_path: Path, days: int = 7, limit: int = 1500
) -> List[str]:
    try:
        if not db_path.exists():
            return []
        cutoff = time.time() - (days * 86400)
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        rows = cur.execute(
            "SELECT route FROM runtime_events WHERE timestamp >= ? AND route IS NOT NULL ORDER BY timestamp DESC LIMIT ?",
            (cutoff, int(limit)),
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


def _load_insight_basenames() -> List[str]:
    out: List[str] = []
    try:
        base = Path(".bgl_core/knowledge/auto_insights")
        if not base.exists():
            return out
        for p in base.rglob("*.insight.md"):
            name = p.name.lower()
            # Strip suffixes like .insight.md or .insight.md.insight.md
            name = name.replace(".insight.md", "")
            out.append(name)
    except Exception:
        return out
    return out


def _normalize_route_path(route: str) -> str:
    if not route:
        return ""
    s = str(route).strip().replace("\\", "/")
    # Strip scheme/host if present
    if s.startswith("http://") or s.startswith("https://"):
        try:
            s = "/" + s.split("/", 3)[3]
        except Exception:
            pass
    # Remove query/fragment
    if "?" in s:
        s = s.split("?", 1)[0]
    if "#" in s:
        s = s.split("#", 1)[0]
    s = s.replace("/./", "/")
    return s


def _resolve_route_to_file(route: str) -> Optional[Path]:
    s = _normalize_route_path(route)
    if not s:
        return None
    if s.startswith("/"):
        s = s[1:]
    if not s:
        return None
    candidates: List[Path] = []
    candidates.append(ROOT_DIR / s)
    base = Path(s).name
    if s.endswith(".php"):
        candidates.append(ROOT_DIR / "views" / base)
        candidates.append(ROOT_DIR / "api" / base)
    if s.endswith((".py", ".json", ".md", ".sql", ".php")):
        for c in candidates:
            try:
                if c.exists() and c.is_file():
                    return c.relative_to(ROOT_DIR)
            except Exception:
                continue
    return None


def _collect_dream_targets(db_path: Path, limit: int = 20) -> List[str]:
    allowed_ext = {".php", ".py", ".json", ".md", ".sql"}
    seen = set()
    out: List[str] = []

    def add_path(p: Optional[Path]) -> None:
        if not p:
            return
        try:
            if not p.is_absolute():
                p = ROOT_DIR / p
            if not p.exists() or not p.is_file():
                return
        except Exception:
            return
        if p.suffix.lower() not in allowed_ext:
            return
        rel = str(p.relative_to(ROOT_DIR) if p.is_absolute() else p).replace("\\", "/")
        if rel in seen:
            return
        seen.add(rel)
        out.append(rel)

    insight_names = set(_load_insight_basenames())

    # 1) Routes table (file_path column is the most reliable)
    for r in _read_recent_routes_from_db(db_path, days=7, limit=16):
        fp = r.get("file_path")
        if fp:
            try:
                add_path(Path(fp))
            except Exception:
                pass

    # 2) Snapshot delta (changed files)
    delta = _read_latest_delta(db_path)
    if isinstance(delta, dict):
        for key in ("changed_files", "files", "paths", "changed"):
            for p in delta.get(key, []) or []:
                try:
                    add_path(Path(p))
                except Exception:
                    continue

    # 3) Recent runtime routes missing insights
    for route in _recent_routes_within_days(db_path, days=7, limit=220):
        p = _resolve_route_to_file(route)
        if not p:
            continue
        base = Path(p).name
        if base in insight_names:
            continue
        add_path(p if p.is_absolute() else (ROOT_DIR / p))

    return out[: int(limit)]


def _routes_for_file(db_path: Path, file_rel: str, limit: int = 6) -> List[str]:
    try:
        if not db_path.exists():
            return []
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        like_unix = f"%/{file_rel}"
        like_win = f"%\\{file_rel}"
        rows = cur.execute(
            "SELECT uri FROM routes WHERE file_path LIKE ? OR file_path LIKE ? ORDER BY last_validated DESC LIMIT ?",
            (like_unix, like_win, int(limit)),
        ).fetchall()
        db.close()
        out = []
        seen = set()
        for (uri,) in rows:
            if not uri:
                continue
            u = _normalize_route_path(str(uri))
            if not u or u in seen:
                continue
            seen.add(u)
            out.append(u)
        return out
    except Exception:
        return []


def _ingest_insights_to_goals(db_path: Path, since_ts: float) -> None:
    try:
        base = ROOT_DIR / ".bgl_core" / "knowledge" / "auto_insights"
        if not base.exists():
            return
        insight_files = list(base.rglob("*.insight.md"))
        if not insight_files:
            return
        for p in insight_files:
            try:
                if p.stat().st_mtime < since_ts:
                    continue
            except Exception:
                continue
            try:
                content = p.read_text(encoding="utf-8", errors="ignore")
            except Exception:
                continue
            if upsert_memory_item:
                try:
                    first_line = ""
                    for line in content.splitlines():
                        if line.strip():
                            first_line = line.strip()
                            break
                    upsert_memory_item(
                        db_path,
                        kind="insight",
                        key_text=str(p.name),
                        summary=first_line or "auto_insight",
                        evidence_count=1,
                        confidence=0.6,
                        meta={"file": str(p.name)},
                        source_table="auto_insights",
                        source_id=None,
                    )
                except Exception:
                    pass
            m = re.search(r"\*\*Path\*\*: `(.+?)`", content)
            if not m:
                continue
            file_rel = m.group(1).strip()
            if not file_rel:
                continue
            routes = _routes_for_file(db_path, file_rel, limit=6)
            for uri in routes:
                _write_autonomy_goal(
                    db_path,
                    goal="insight_gap",
                    payload={"uri": uri, "file": file_rel},
                    source="auto_insight",
                    ttl_days=14,
                )
    except Exception:
        return


def _should_trigger_dream() -> bool:
    try:
        if os.getenv("BGL_SKIP_DREAM", "0") == "1":
            return False
        if str(_cfg_value("dream_mode_on_exploration", "1")) != "1":
            return False
    except Exception:
        pass
    last_path = ROOT_DIR / ".bgl_core" / "logs" / "dream_mode.last"
    min_minutes = int(_cfg_value("dream_mode_min_interval_minutes", 60))
    now = time.time()
    try:
        if last_path.exists() and (now - last_path.stat().st_mtime) < (
            min_minutes * 60
        ):
            return False
    except Exception:
        pass
    pid_path = ROOT_DIR / ".bgl_core" / "logs" / "dream_mode.pid"
    try:
        if pid_path.exists() and (now - pid_path.stat().st_mtime) < max(
            min_minutes * 60, 1800
        ):
            return False
    except Exception:
        pass
    return True


def _trigger_dream_from_exploration(db_path: Path) -> None:
    if not _should_trigger_dream():
        return
    targets = _collect_dream_targets(
        db_path, limit=int(_cfg_value("dream_mode_batch_limit", 24) or 24)
    )
    if not targets:
        return
    started = time.time()
    try:
        import sys

        dream_path = ROOT_DIR / "scripts" / "dream_mode.py"
        max_insights = min(
            len(targets), int(_cfg_value("dream_mode_max_insights", 24) or 24)
        )
        subprocess.run(
            [
                sys.executable,
                str(dream_path),
                "--files",
                *targets,
                "--max",
                str(max_insights),
                "--sleep",
                str(float(_cfg_value("dream_mode_sleep_seconds", 0.2) or 0.2)),
                "--source",
                "exploration",
            ],
            cwd=ROOT_DIR,
            timeout=int(_cfg_value("dream_mode_timeout_sec", 180) or 180),
        )
        (ROOT_DIR / ".bgl_core" / "logs" / "dream_mode.last").write_text(
            str(time.time())
        )
        _ingest_insights_to_goals(db_path, since_ts=started)
    except Exception:
        pass


def _href_basename(href: Optional[str]) -> str:
    if not href:
        return ""
    h = str(href).split("?")[0].split("#")[0].strip().lower()
    if "/" in h:
        h = h.split("/")[-1]
    return h


def _build_search_terms(limit: int = 12) -> List[str]:
    # Simple, diverse search terms to avoid repetition
    terms = [
        "ضمان",
        "بنك",
        "مورد",
        "دفعة",
        "تقرير",
        "batch",
        "supplier",
        "bank",
        "guarantee",
        "audit",
        "risk",
        "conflict",
    ]
    # Add insight-derived tokens
    try:
        names = _load_insight_basenames()
        for n in names[:20]:
            base = n.replace(".php", "").replace("-", " ").replace("_", " ").strip()
            if base and base not in terms:
                terms.append(base)
    except Exception:
        pass
    # De-dup and cap
    out = []
    seen = set()
    for t in terms:
        if t in seen:
            continue
        seen.add(t)
        out.append(t)
        if len(out) >= limit:
            break
    return out


def _load_ui_flow_bias(
    db_path: Path, current_url: str, *, days: int = 7, limit: int = 6
) -> List[Dict[str, Any]]:
    """
    Load frequent UI transitions for the current route to bias exploration.
    Returns list of {selector, to, count}.
    """
    if not db_path.exists() or not current_url:
        return []
    try:
        norm = _normalize_route_path(current_url)
    except Exception:
        norm = ""
    if not norm:
        return []
    cutoff = time.time() - (days * 86400)
    out: List[Dict[str, Any]] = []
    try:
        db = connect_db(str(db_path), timeout=30.0)
        db.row_factory = sqlite3.Row
        rows = db.execute(
            """
            SELECT from_url, to_url, action, selector, COUNT(*) as c
            FROM ui_flow_transitions
            WHERE created_at >= ?
            GROUP BY from_url, to_url, action, selector
            ORDER BY c DESC
            LIMIT ?
            """,
            (cutoff, int(limit) * 4),
        ).fetchall()
        db.close()
    except Exception:
        return []
    for r in rows:
        from_url = _normalize_route_path(str(r["from_url"] or ""))
        if from_url != norm:
            continue
        selector = str(r["selector"] or "")
        if not selector:
            continue
        out.append(
            {
                "selector": selector,
                "to": _normalize_route_path(str(r["to_url"] or "")),
                "action": str(r["action"] or ""),
                "count": int(r["c"] or 0),
            }
        )
        if len(out) >= limit:
            break
    if out:
        return out
    # Fallback: use last computed ui_flow_model.json if present.
    try:
        flow_path = ROOT_DIR / "analysis" / "ui_flow_model.json"
        if flow_path.exists():
            model = json.loads(flow_path.read_text(encoding="utf-8"))
            transitions = model.get("transitions") or []
            for t in transitions:
                if not isinstance(t, dict):
                    continue
                from_url = _normalize_route_path(str(t.get("from") or ""))
                if from_url != norm:
                    continue
                selector = str(t.get("selector") or "")
                if not selector:
                    continue
                out.append(
                    {
                        "selector": selector,
                        "to": _normalize_route_path(str(t.get("to") or "")),
                        "action": str(t.get("action") or ""),
                        "count": int(t.get("count") or 0),
                    }
                )
                if len(out) >= limit:
                    break
    except Exception:
        return out
    return out


def _session_semantic_changed(db_path: Path, session: str, since_ts: float) -> bool:
    if not db_path.exists() or not session:
        return False
    try:
        db = connect_db(str(db_path), timeout=30.0)
        db.row_factory = sqlite3.Row
        rows = db.execute(
            """
            SELECT semantic_delta_json
            FROM ui_flow_transitions
            WHERE session = ? AND created_at >= ?
            ORDER BY created_at DESC
            LIMIT 12
            """,
            (session, float(since_ts)),
        ).fetchall()
        db.close()
    except Exception:
        return False
    for row in rows:
        try:
            payload = json.loads(row[0] or "{}")
        except Exception:
            payload = {}
        if isinstance(payload, dict) and payload.get("changed"):
            return True
    return False


def _semantic_preferred_selectors(
    semantic_summary: Dict[str, Any], candidates: List[Dict[str, Any]]
) -> List[str]:
    if not semantic_summary or not candidates:
        return []
    prefer: List[str] = []
    # Search fields (id/name/placeholder)
    search_fields = semantic_summary.get("search_fields") or []
    search_ids = []
    search_names = []
    search_ph = []
    for f in search_fields:
        if not isinstance(f, dict):
            continue
        if f.get("id"):
            search_ids.append(str(f.get("id")))
        if f.get("name"):
            search_names.append(str(f.get("name")))
        if f.get("placeholder"):
            search_ph.append(str(f.get("placeholder")))

    def _match_text(candidate_text: str, tokens: List[str]) -> bool:
        t = (candidate_text or "").lower()
        return any(tok.lower() in t for tok in tokens if tok)

    for c in candidates:
        sel = str(c.get("selector") or "")
        if not sel:
            continue
        name = str(c.get("name") or "")
        if name and name in search_names:
            prefer.append(sel)
            continue
        if any(f"#{sid}" == sel for sid in search_ids):
            prefer.append(sel)
            continue
        text = str(c.get("text") or "")
        if _match_text(text, search_ph):
            prefer.append(sel)

    # Primary actions / nav items / keywords
    prim = semantic_summary.get("primary_actions") or []
    navs = semantic_summary.get("nav_items") or []
    keywords = semantic_summary.get("text_keywords") or []
    action_texts = [str(p.get("text") or "") for p in prim if isinstance(p, dict)]
    nav_texts = [str(n.get("text") or "") for n in navs if isinstance(n, dict)]
    text_focus = [t for t in (action_texts + nav_texts + keywords) if t]
    if text_focus:
        for c in candidates:
            sel = str(c.get("selector") or "")
            if not sel or sel in prefer:
                continue
            text = str(c.get("text") or "")
            href = str(c.get("href") or "")
            if _match_text(text, text_focus) or _match_text(href, text_focus):
                prefer.append(sel)
    return _unique_order(prefer)


def _semantic_search_selectors(
    semantic_summary: Dict[str, Any], candidates: List[Dict[str, Any]]
) -> List[str]:
    if not semantic_summary or not candidates:
        return []
    search_fields = semantic_summary.get("search_fields") or []
    if not isinstance(search_fields, list) or not search_fields:
        return []
    search_ids = set()
    search_names = set()
    search_ph = []
    for f in search_fields:
        if not isinstance(f, dict):
            continue
        if f.get("id"):
            search_ids.add(str(f.get("id")))
        if f.get("name"):
            search_names.add(str(f.get("name")))
        if f.get("placeholder"):
            search_ph.append(str(f.get("placeholder")))

    def _match_text(candidate_text: str, tokens: List[str]) -> bool:
        t = (candidate_text or "").lower()
        return any(tok.lower() in t for tok in tokens if tok)

    selectors: List[str] = []
    for c in candidates:
        if _candidate_is_disabled(c):
            continue
        sel = str(c.get("selector") or "")
        if not sel:
            continue
        tag = str(c.get("tag") or "").lower()
        ctype = str(c.get("type") or "").lower()
        name = str(c.get("name") or "")
        text = str(c.get("text") or "")
        if name and name in search_names:
            selectors.append(sel)
            continue
        if sel.startswith("#") and sel[1:] in search_ids:
            selectors.append(sel)
            continue
        if tag == "input" and ctype == "search":
            selectors.append(sel)
            continue
        if _match_text(text, search_ph):
            selectors.append(sel)
    return _unique_order(selectors)


def _semantic_action_selectors(
    semantic_summary: Dict[str, Any], candidates: List[Dict[str, Any]]
) -> List[str]:
    if not semantic_summary or not candidates:
        return []
    prim = semantic_summary.get("primary_actions") or []
    navs = semantic_summary.get("nav_items") or []
    keywords = semantic_summary.get("text_keywords") or []
    action_texts = [str(p.get("text") or "") for p in prim if isinstance(p, dict)]
    nav_texts = [str(n.get("text") or "") for n in navs if isinstance(n, dict)]
    focus = [t for t in (action_texts + nav_texts + keywords) if t]
    if not focus:
        return []
    selectors: List[str] = []
    for c in candidates:
        if _candidate_is_disabled(c):
            continue
        if _candidate_is_tab_like(c) and _candidate_is_active(c):
            continue
        sel = str(c.get("selector") or "")
        if not sel:
            continue
        text = str(c.get("text") or "")
        href = str(c.get("href") or "")
        if any(f.lower() in (text or "").lower() for f in focus):
            selectors.append(sel)
            continue
        if any(f.lower() in (href or "").lower() for f in focus):
            selectors.append(sel)
    return _unique_order(selectors)


def _semantic_is_write_action(text: str) -> bool:
    t = (text or "").lower()
    keywords = [
        "save",
        "submit",
        "create",
        "add",
        "new",
        "import",
        "delete",
        "update",
        "edit",
        "confirm",
        "approve",
        "حفظ",
        "إرسال",
        "ارسال",
        "انشاء",
        "إنشاء",
        "اضافة",
        "إضافة",
        "جديد",
        "استيراد",
        "حذف",
        "تحديث",
        "تعديل",
        "تأكيد",
        "اعتماد",
    ]
    return any(k in t for k in keywords)


def _semantic_plan_steps(
    semantic_summary: Dict[str, Any],
    candidates: List[Dict[str, Any]],
    max_steps: int,
) -> List[Dict[str, Any]]:
    """
    Build a minimal action plan directly from semantic_summary signals.
    Deterministic fallback when LLM output is weak or empty.
    """
    steps: List[Dict[str, Any]] = []
    if not semantic_summary or not candidates or max_steps <= 0:
        return steps
    search_selectors = _semantic_search_selectors(semantic_summary, candidates)
    action_selectors = _semantic_action_selectors(semantic_summary, candidates)
    cand_by_sel = {str(c.get("selector") or ""): c for c in candidates if c.get("selector")}

    if search_selectors and len(steps) + 2 <= max_steps:
        sel = search_selectors[0]
        term = random.choice(_build_search_terms())
        steps.append({"action": "click", "selector": sel, "optional": True})
        steps.append(
            {"action": "type", "selector": sel, "text": term, "optional": True, "auto_submit": True}
        )
        if len(steps) < max_steps:
            steps.append({"action": "press", "selector": sel, "key": "Enter", "optional": True})

    for sel in action_selectors:
        if len(steps) >= max_steps:
            break
        meta = cand_by_sel.get(sel, {})
        if _candidate_needs_hover(meta):
            steps.append({"action": "hover", "selector": sel, "optional": True})
        steps.append({"action": "click", "selector": sel, "optional": True})
        break

    try:
        page_type = str(semantic_summary.get("page_type") or "").strip().lower()
    except Exception:
        page_type = ""
    if page_type and len(steps) < max_steps:
        def _pick_by_tag(tags: set[str]) -> str:
            for c in candidates:
                tag = str(c.get("tag") or "").lower()
                sel = str(c.get("selector") or "")
                if tag in tags and sel:
                    return sel
            return ""
        if any(k in page_type for k in ("list", "table", "grid", "index")):
            sel = _pick_by_tag({"tr", "td", "li"}) or "table tbody tr"
            steps.append({"action": "click", "selector": sel, "optional": True})
        elif any(k in page_type for k in ("detail", "form", "edit")):
            sel = _pick_by_tag({"input", "textarea", "select"}) or "input, textarea, select"
            steps.append({"action": "click", "selector": sel, "optional": True})
            steps.append({"action": "scroll", "dy": 400})
        elif any(k in page_type for k in ("dashboard", "summary", "report")):
            steps.append({"action": "scroll", "dy": 600})
    return steps


def _ensure_semantic_steps(
    steps: List[Dict[str, Any]],
    semantic_summary: Dict[str, Any],
    candidates: List[Dict[str, Any]],
    max_steps: int,
) -> List[Dict[str, Any]]:
    if not semantic_summary or not candidates:
        return steps
    if len(steps) >= max_steps:
        return steps

    # Build lookup for candidates
    cand_by_sel = {str(c.get("selector") or ""): c for c in candidates if c.get("selector")}

    search_selectors = _semantic_search_selectors(semantic_summary, candidates)
    action_selectors = _semantic_action_selectors(semantic_summary, candidates)

    # Check if search behavior already present
    has_search = False
    for s in steps:
        if s.get("action") in ("type", "press") and s.get("selector") in search_selectors:
            has_search = True
            break

    # Ensure search interaction if search fields exist
    if search_selectors and not has_search and len(steps) + 2 <= max_steps:
        sel = search_selectors[0]
        term = random.choice(_build_search_terms())
        steps.append({"action": "click", "selector": sel, "optional": True})
        steps.append({"action": "type", "selector": sel, "text": term, "optional": True, "auto_submit": True})
        if len(steps) < max_steps:
            steps.append({"action": "press", "selector": sel, "key": "Enter", "optional": True})

    # Ensure at least one semantic primary/nav action
    has_primary = False
    for s in steps:
        if s.get("action") == "click" and s.get("selector") in action_selectors:
            has_primary = True
            break
    if action_selectors and not has_primary and len(steps) < max_steps:
        sel = action_selectors[0]
        meta = cand_by_sel.get(sel, {})
        text = str(meta.get("text") or "") + " " + str(meta.get("href") or "")
        step: Dict[str, Any] = {"action": "click", "selector": sel}
        if _semantic_is_write_action(text):
            step["danger"] = True
        if _candidate_needs_hover(meta):
            steps.append({"action": "hover", "selector": sel, "optional": True})
        steps.append(step)

    # Use page_type to suggest a safe, meaningful interaction
    try:
        page_type = str(semantic_summary.get("page_type") or "").strip().lower()
    except Exception:
        page_type = ""
    if page_type and len(steps) < max_steps:
        def _pick_by_tag(tags: set[str]) -> str:
            for c in candidates:
                tag = str(c.get("tag") or "").lower()
                sel = str(c.get("selector") or "")
                if tag in tags and sel:
                    return sel
            return ""
        if any(k in page_type for k in ("list", "table", "grid", "index")):
            sel = _pick_by_tag({"tr", "td", "li"})
            if not sel:
                sel = "table tbody tr"
            steps.append({"action": "click", "selector": sel, "optional": True})
        elif any(k in page_type for k in ("detail", "form", "edit")):
            sel = _pick_by_tag({"input", "textarea", "select"})
            if not sel:
                sel = "input, textarea, select"
            steps.append({"action": "click", "selector": sel, "optional": True})
            steps.append({"action": "scroll", "dy": 400})
        elif any(k in page_type for k in ("dashboard", "summary", "report")):
            steps.append({"action": "scroll", "dy": 600})

    plan_steps = _semantic_plan_steps(semantic_summary, candidates, max_steps)
    if plan_steps:
        def _sig(s: Dict[str, Any]) -> tuple:
            return (s.get("action"), s.get("selector") or "", s.get("url") or "")
        existing = {_sig(s) for s in steps}
        for s in plan_steps:
            if len(steps) >= max_steps:
                break
            if _sig(s) in existing:
                continue
            steps.append(s)
            existing.add(_sig(s))

    return steps


def _prioritize_candidates(
    candidates: List[Dict[str, Any]], preferred_selectors: List[str]
) -> List[Dict[str, Any]]:
    if not preferred_selectors:
        return candidates
    pref_set = {p for p in preferred_selectors if p}
    pref, rest = [], []
    for c in candidates:
        sel = str(c.get("selector") or "")
        if sel and sel in pref_set:
            pref.append(c)
        else:
            rest.append(c)
    return pref + rest


async def _probe_element_state(page, action: str, selector: str) -> Dict[str, Any]:
    data: Dict[str, Any] = {"selector": selector, "action": action, "found": False}
    try:
        el = await page.query_selector(selector)
        if not el:
            return data
        data["found"] = True
        try:
            data["visible"] = await el.is_visible()
        except Exception:
            data["visible"] = None
        try:
            data["enabled"] = await el.is_enabled()
        except Exception:
            data["enabled"] = None
        try:
            box = await el.bounding_box()
        except Exception:
            box = None
        data["box"] = box
        if box:
            cx = box.get("x", 0) + (box.get("width", 0) / 2)
            cy = box.get("y", 0) + (box.get("height", 0) / 2)
            data["center"] = {"x": cx, "y": cy}
            try:
                data["obscured"] = await page.evaluate(
                    """(el) => {
                        const r = el.getBoundingClientRect();
                        const x = r.left + (r.width / 2);
                        const y = r.top + (r.height / 2);
                        const top = document.elementFromPoint(x, y);
                        return !(top === el || (top && el.contains(top)));
                    }""",
                    el,
                )
            except Exception:
                data["obscured"] = None
        if action == "type":
            try:
                info = await el.evaluate(
                    """el => ({
                        disabled: !!el.disabled || el.getAttribute('disabled') !== null,
                        readonly: !!el.readOnly || el.getAttribute('readonly') !== null,
                        tag: (el.tagName || '').toLowerCase(),
                        type: (el.getAttribute('type') || ''),
                        role: (el.getAttribute('role') || '')
                    })"""
                )
                if isinstance(info, dict):
                    data.update(info)
            except Exception:
                pass
        return data
    except Exception:
        return data


def _unique_order(values: List[str]) -> List[str]:
    seen: set = set()
    out: List[str] = []
    for v in values:
        if not v:
            continue
        if v in seen:
            continue
        seen.add(v)
        out.append(v)
    return out


def _escape_has_text(value: str) -> str:
    try:
        return value.replace("\\", "\\\\").replace('"', '\\"')
    except Exception:
        return value


def _text_hints_from_context(ctx: Dict[str, Any], limit: int = 3) -> List[str]:
    texts: List[str] = []
    for key in (
        "label_text",
        "aria_label",
        "placeholder",
        "panel_label_text",
        "modal_title",
        "menu_label_text",
    ):
        val = str(ctx.get(key) or "").strip()
        if val:
            texts.append(val)
    # Fall back to element_id tokens if nothing else.
    element_id = str(ctx.get("element_id") or "").strip()
    if element_id:
        try:
            tokens = re.split(r"[^a-zA-Z0-9\u0600-\u06FF]+", element_id)
        except Exception:
            tokens = [element_id]
        for t in tokens:
            t = t.strip()
            if len(t) >= 3:
                texts.append(t)
    # De-dup and cap
    return _unique_order(texts)[: max(1, int(limit or 1))]


def _text_reveal_selectors(text_hints: List[str]) -> List[str]:
    selectors: List[str] = []
    for raw in text_hints:
        text = _escape_has_text(str(raw or "").strip())
        if not text:
            continue
        selectors += [
            f"[role='tab']:has-text(\"{text}\")",
            f"[data-tab]:has-text(\"{text}\")",
            f"[data-bs-toggle]:has-text(\"{text}\")",
            f"[data-toggle]:has-text(\"{text}\")",
            f"button[aria-controls]:has-text(\"{text}\")",
            f"button[aria-expanded]:has-text(\"{text}\")",
            f"a[aria-controls]:has-text(\"{text}\")",
            f"[role='button'][aria-controls]:has-text(\"{text}\")",
            f"button.tab-btn:has-text(\"{text}\")",
            f"[onclick*=\"switchTab\"]:has-text(\"{text}\")",
            f"button[onclick*=\"switchTab\"]:has-text(\"{text}\")",
            f"summary:has-text(\"{text}\")",
        ]
    return _unique_order(selectors)


async def _collect_reveal_context(page, selector: str) -> Dict[str, Any]:
    ctx: Dict[str, Any] = {
        "element_id": "",
        "panel_id": "",
        "collapse_id": "",
        "modal_id": "",
        "offcanvas_id": "",
        "menu_id": "",
        "labelled_by": "",
        "details_closed": False,
        "label_text": "",
        "aria_label": "",
        "placeholder": "",
        "panel_label_text": "",
        "modal_title": "",
        "menu_label_text": "",
    }
    try:
        el = await page.query_selector(selector)
        if not el:
            return ctx
        info = await el.evaluate(
            """el => {
                const out = {
                    elementId: el.id || '',
                    panelId: '',
                    collapseId: '',
                    labelledBy: '',
                    detailsClosed: false
                };
                const panel = el.closest('[role=\"tabpanel\"], .tab-pane');
                if (panel && panel.id) {
                    out.panelId = panel.id;
                    out.labelledBy = panel.getAttribute('aria-labelledby') || '';
                }
                const modal = el.closest('.modal, [role=\"dialog\"], [aria-modal=\"true\"]');
                if (modal && modal.id) {
                    out.modalId = modal.id;
                }
                const offcanvas = el.closest('.offcanvas');
                if (offcanvas && offcanvas.id) {
                    out.offcanvasId = offcanvas.id;
                }
                const menu = el.closest('.dropdown-menu, [role=\"menu\"], .menu');
                if (menu && menu.id) {
                    out.menuId = menu.id;
                }
                const collapse = el.closest('.collapse');
                if (collapse && collapse.id) {
                    out.collapseId = collapse.id;
                }
                out.ariaLabel = el.getAttribute('aria-label') || '';
                out.placeholder = el.getAttribute('placeholder') || '';
                try {
                    if (el.labels && el.labels.length) {
                        out.labelText = Array.from(el.labels).map(l => (l.innerText || '').trim()).join(' ');
                    } else if (el.id) {
                        const lab = document.querySelector(`label[for='${el.id}']`);
                        if (lab) out.labelText = (lab.innerText || '').trim();
                    }
                } catch (e) {}
                if (panel && out.labelledBy) {
                    const lab = document.getElementById(out.labelledBy);
                    if (lab) out.panelLabelText = (lab.innerText || '').trim();
                }
                if (modal) {
                    const title = modal.querySelector('.modal-title');
                    if (title) out.modalTitle = (title.innerText || '').trim();
                }
                if (menu) {
                    const lbl = menu.getAttribute('aria-labelledby');
                    if (lbl) {
                        const lab = document.getElementById(lbl);
                        if (lab) out.menuLabelText = (lab.innerText || '').trim();
                    }
                }
                const details = el.closest('details');
                if (details && !details.open) {
                    out.detailsClosed = true;
                }
                if (!out.labelledBy) {
                    out.labelledBy = el.getAttribute('aria-labelledby') || '';
                }
                return out;
            }"""
        )
        if isinstance(info, dict):
            ctx["element_id"] = str(info.get("elementId") or "")
            ctx["panel_id"] = str(info.get("panelId") or "")
            ctx["collapse_id"] = str(info.get("collapseId") or "")
            ctx["modal_id"] = str(info.get("modalId") or "")
            ctx["offcanvas_id"] = str(info.get("offcanvasId") or "")
            ctx["menu_id"] = str(info.get("menuId") or "")
            ctx["labelled_by"] = str(info.get("labelledBy") or "")
            ctx["details_closed"] = bool(info.get("detailsClosed"))
            ctx["label_text"] = str(info.get("labelText") or "")
            ctx["aria_label"] = str(info.get("ariaLabel") or "")
            ctx["placeholder"] = str(info.get("placeholder") or "")
            ctx["panel_label_text"] = str(info.get("panelLabelText") or "")
            ctx["modal_title"] = str(info.get("modalTitle") or "")
            ctx["menu_label_text"] = str(info.get("menuLabelText") or "")
    except Exception:
        pass
    return ctx


def _build_reveal_selectors(ctx: Dict[str, Any]) -> List[str]:
    ids: List[str] = []
    for key in ("panel_id", "collapse_id", "element_id"):
        val = str(ctx.get(key) or "").strip()
        if val:
            ids.append(val)
    modal_id = str(ctx.get("modal_id") or "").strip()
    offcanvas_id = str(ctx.get("offcanvas_id") or "").strip()
    menu_id = str(ctx.get("menu_id") or "").strip()
    labelled_by = str(ctx.get("labelled_by") or "").strip()
    selectors: List[str] = []
    for ident in ids:
        selectors += [
            f"[href='#{ident}']",
            f"[data-bs-target='#{ident}']",
            f"[data-target='#{ident}']",
            f"[aria-controls='{ident}']",
            f"[role='tab'][aria-controls='{ident}']",
        ]
    if modal_id:
        selectors += [
            f"[data-bs-toggle='modal'][data-bs-target='#{modal_id}']",
            f"[data-toggle='modal'][data-target='#{modal_id}']",
            f"[href='#{modal_id}'][data-bs-toggle='modal']",
            f"[aria-controls='{modal_id}']",
            f"[data-bs-target='#{modal_id}']",
        ]
    if offcanvas_id:
        selectors += [
            f"[data-bs-toggle='offcanvas'][data-bs-target='#{offcanvas_id}']",
            f"[data-toggle='offcanvas'][data-target='#{offcanvas_id}']",
            f"[href='#{offcanvas_id}'][data-bs-toggle='offcanvas']",
            f"[aria-controls='{offcanvas_id}']",
            f"[data-bs-target='#{offcanvas_id}']",
        ]
    if menu_id:
        selectors += [
            f"[aria-controls='{menu_id}']",
            f"[data-bs-target='#{menu_id}']",
            f"[data-target='#{menu_id}']",
        ]
    if labelled_by:
        selectors += [
            f"#{labelled_by}",
            f"[id='{labelled_by}']",
            f"[aria-controls='{labelled_by}']",
        ]
    return _unique_order(selectors)


async def _attempt_self_heal(
    page,
    selector: str,
    action: str,
    precheck: Dict[str, Any],
    db_path: Path,
    scenario_name: str,
) -> Dict[str, Any]:
    result: Dict[str, Any] = {"success": False, "actions": [], "post": precheck}
    if not selector:
        return result
    try:
        heal_enabled = bool(_cfg_value("auto_self_heal", True))
    except Exception:
        heal_enabled = True
    if not heal_enabled:
        return result

    actions: List[Dict[str, Any]] = []
    try:
        await page.locator(selector).scroll_into_view_if_needed(timeout=800)
        actions.append({"kind": "scroll_into_view", "selector": selector})
    except Exception:
        pass

    ctx = await _collect_reveal_context(page, selector)
    reveal_selectors = _build_reveal_selectors(ctx)
    try:
        text_limit = int(_cfg_value("self_heal_text_limit", 3) or 3)
    except Exception:
        text_limit = 3
    text_hints = _text_hints_from_context(ctx, limit=text_limit)
    text_reveals = _text_reveal_selectors(text_hints)
    try:
        limit = int(_cfg_value("self_heal_toggle_limit", 3) or 3)
    except Exception:
        limit = 3
    clicked: List[str] = []
    combined_reveals = reveal_selectors + text_reveals
    for sel in combined_reveals[: max(1, limit)]:
        try:
            el = await page.query_selector(sel)
            if not el:
                continue
            await page.click(sel, timeout=1200)
            clicked.append(sel)
            actions.append({"kind": "click_reveal", "selector": sel})
            await page.wait_for_timeout(120)
        except Exception:
            continue

    if ctx.get("details_closed"):
        try:
            opened = await page.evaluate(
                """(sel) => {
                    const el = document.querySelector(sel);
                    if (!el) return false;
                    const details = el.closest('details');
                    if (details && !details.open) {
                        details.open = true;
                        return true;
                    }
                    return false;
                }""",
                selector,
            )
            if opened:
                actions.append({"kind": "open_details", "selector": selector})
        except Exception:
            pass

    if actions:
        try:
            await page.wait_for_timeout(180)
        except Exception:
            pass
    post = await _probe_element_state(page, action, selector)
    result["post"] = post
    result["actions"] = actions
    result["reveal_selectors"] = clicked
    result["context"] = ctx
    result["text_hints"] = text_hints

    success = bool(
        post.get("found")
        and post.get("visible") is not False
        and post.get("enabled") is not False
        and post.get("obscured") is not True
    )
    result["success"] = success

    # Persist learned hints for future runs.
    try:
        page_url = page.url if hasattr(page, "url") else ""
    except Exception:
        page_url = ""
    if success and record_behavior_hint and page_url:
        try:
            if any(a.get("kind") == "scroll_into_view" for a in actions):
                record_behavior_hint(
                    db_path,
                    page_url=page_url,
                    action=action,
                    selector=selector,
                    hint="scroll_into_view",
                    confidence=0.55,
                    notes="ui_self_heal",
                )
            for sel in clicked:
                record_behavior_hint(
                    db_path,
                    page_url=page_url,
                    action=action,
                    selector=selector,
                    hint=f"preclick:{sel}",
                    confidence=0.6,
                    notes="ui_self_heal",
                )
        except Exception:
            pass

    # Emit a goal so the correction can be regenerated into scenarios.
    try:
        goal_enabled = bool(_cfg_value("self_heal_write_goal", True))
    except Exception:
        goal_enabled = True
    if goal_enabled and (success or actions):
        try:
            payload = {
                "selector": selector,
                "uri": page_url,
                "reveal_selectors": clicked,
                "context": ctx,
                "text_hints": text_hints,
                "reason": "ui_self_heal",
                "scenario": scenario_name,
            }
            ttl_days = int(_cfg_value("self_heal_goal_ttl_days", 7) or 7)
            _write_autonomy_goal(db_path, "ui_action_gap", payload, "self_heal", ttl_days=ttl_days)
        except Exception:
            pass
    if (not success) and goal_enabled:
        try:
            gap_enabled = bool(_cfg_value("self_heal_write_gap_goal", True))
        except Exception:
            gap_enabled = True
        if gap_enabled:
            try:
                payload = {
                    "selector": selector,
                    "uri": page_url,
                    "context": ctx,
                    "text_hints": text_hints,
                    # preserve any reveal selectors we already tried to open
                    "reveal_selectors": clicked,
                    "reason": "ui_self_heal_failed",
                    "scenario": scenario_name,
                }
                ttl_days = int(_cfg_value("self_heal_goal_ttl_days", 7) or 7)
                _write_autonomy_goal(
                    db_path,
                    "ui_self_heal_gap",
                    payload,
                    "self_heal",
                    ttl_days=ttl_days,
                )
            except Exception:
                pass
    return result


async def _is_search_input(page, selector: str) -> bool:
    try:
        el = await page.query_selector(selector)
        if not el:
            return False
        info = await el.evaluate(
            """el => ({
                tag: (el.tagName || '').toLowerCase(),
                type: (el.getAttribute('type') || ''),
                name: (el.getAttribute('name') || ''),
                placeholder: (el.getAttribute('placeholder') || ''),
                aria: (el.getAttribute('aria-label') || ''),
                role: (el.getAttribute('role') || ''),
                inputmode: (el.getAttribute('inputmode') || ''),
                contenteditable: (el.getAttribute('contenteditable') || ''),
                isContentEditable: !!el.isContentEditable
            })"""
        )
        if not isinstance(info, dict):
            return False
        text = " ".join(
            [
                str(info.get("type", "")),
                str(info.get("name", "")),
                str(info.get("placeholder", "")),
                str(info.get("aria", "")),
                str(info.get("role", "")),
                str(info.get("inputmode", "")),
            ]
        ).lower()
        if "search" in text or "بحث" in text or "filter" in text or "query" in text:
            return True
        if str(info.get("type", "")).lower() == "search":
            return True
        if str(info.get("role", "")).lower() in ("searchbox", "combobox"):
            return True
        if str(info.get("inputmode", "")).lower() == "search":
            return True
        if str(info.get("contenteditable", "")).lower() in ("true", "plaintext-only"):
            return True if ("search" in text or "بحث" in text) else False
        return bool(info.get("isContentEditable")) and ("search" in text or "بحث" in text)
    except Exception:
        return False


async def _try_click_search_button(page) -> bool:
    selectors = [
        "button[type='submit']",
        "input[type='submit']",
        "button[aria-label*='search' i]",
        "button[aria-label*='بحث' i]",
        "[role='button'][aria-label*='search' i]",
        "button:has-text('Search')",
        "button:has-text('بحث')",
    ]
    for sel in selectors:
        try:
            el = await page.query_selector(sel)
            if not el:
                continue
            await el.click(timeout=2000)
            return True
        except Exception:
            continue
    return False


async def _attempt_search_submit(
    page,
    *,
    selector: str,
    db_path: Path,
    session: str,
    reason: str = "dom_no_change",
) -> bool:
    """
    After a no-change search input, try a single submit (Enter, then click).
    Returns True if DOM hash changes.
    """
    before = await _dom_state_hash(page)
    changed = False
    try:
        await page.press(selector, "Enter", timeout=2000)
        await page.wait_for_timeout(250)
    except Exception:
        pass
    after = await _dom_state_hash(page)
    if before and after and before != after:
        changed = True
        log_event(
            db_path,
            session,
            {
                "event_type": "search_auto_submit",
                "route": page.url if hasattr(page, "url") else "",
                "method": "ENTER",
                "payload": reason,
                "status": 200,
            },
        )
        return True
    # Fallback: try clicking a search button
    try:
        if await _try_click_search_button(page):
            await page.wait_for_timeout(250)
            after2 = await _dom_state_hash(page)
            if before and after2 and before != after2:
                changed = True
                log_event(
                    db_path,
                    session,
                    {
                        "event_type": "search_auto_submit",
                        "route": page.url if hasattr(page, "url") else "",
                        "method": "CLICK",
                        "payload": reason,
                        "status": 200,
                    },
                )
                return True
    except Exception:
        pass
    if not changed:
        log_event(
            db_path,
            session,
            {
                "event_type": "search_auto_submit_fail",
                "route": page.url if hasattr(page, "url") else "",
                "method": "SUBMIT",
                "payload": reason,
                "status": None,
            },
        )
    return False


async def _attempt_form_submit(
    page,
    *,
    selector: str,
    db_path: Path,
    session: str,
    reason: str = "dom_no_change",
) -> bool:
    """
    Generic commit attempt after typing: press Enter on the input.
    Returns True if DOM hash changes.
    """
    before = await _dom_state_hash(page)
    changed = False
    try:
        await page.press(selector, "Enter", timeout=2000)
        await page.wait_for_timeout(250)
    except Exception:
        pass
    after = await _dom_state_hash(page)
    if before and after and before != after:
        changed = True
        log_event(
            db_path,
            session,
            {
                "event_type": "form_auto_submit",
                "route": page.url if hasattr(page, "url") else "",
                "method": "ENTER",
                "payload": reason,
                "status": 200,
            },
        )
        return True
    if not changed:
        log_event(
            db_path,
            session,
            {
                "event_type": "form_auto_submit_fail",
                "route": page.url if hasattr(page, "url") else "",
                "method": "ENTER",
                "payload": reason,
                "status": None,
            },
        )
    return False


def _ensure_exploration_table(db: sqlite3.Connection) -> None:
    db.execute(
        "CREATE TABLE IF NOT EXISTS exploration_history (id INTEGER PRIMARY KEY AUTOINCREMENT, selector TEXT, href TEXT, tag TEXT, created_at REAL)"
    )


def _ensure_exploration_novelty_table(db: sqlite3.Connection) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS exploration_novelty (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            selector TEXT,
            selector_key TEXT,
            href_base TEXT,
            route TEXT,
            seen_count INTEGER DEFAULT 0,
            last_seen REAL,
            last_result TEXT,
            last_score_delta REAL
        )
        """
    )
    try:
        cols = {r[1] for r in db.execute("PRAGMA table_info(exploration_novelty)").fetchall()}
        if "selector_key" not in cols:
            db.execute("ALTER TABLE exploration_novelty ADD COLUMN selector_key TEXT")
        if "href_base" not in cols:
            db.execute("ALTER TABLE exploration_novelty ADD COLUMN href_base TEXT")
        if "route" not in cols:
            db.execute("ALTER TABLE exploration_novelty ADD COLUMN route TEXT")
        if "last_score_delta" not in cols:
            db.execute("ALTER TABLE exploration_novelty ADD COLUMN last_score_delta REAL")
    except Exception:
        pass


def _load_explored_selectors(db_path: Path, limit: int = 2000) -> set:
    try:
        if not db_path.exists():
            return set()
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_exploration_table(db)
        rows = db.execute(
            "SELECT selector, href FROM exploration_history ORDER BY created_at DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        db.close()
        seen = set()
        for sel, href in rows:
            if sel:
                seen.add(str(sel))
            if href:
                seen.add(str(href))
        return seen
    except Exception:
        return set()


def _record_explored_selector(
    db_path: Path, selector: str, href: str, tag: str
) -> None:
    try:
        if not db_path.exists():
            return
        for attempt in range(2):
            try:
                db = connect_db(str(db_path), timeout=3.0)
                db.execute("PRAGMA journal_mode=WAL;")
                try:
                    db.execute("PRAGMA busy_timeout=3000;")
                except Exception:
                    pass
                _ensure_exploration_table(db)
                db.execute(
                    "INSERT INTO exploration_history (selector, href, tag, created_at) VALUES (?, ?, ?, ?)",
                    (selector, href, tag, time.time()),
                )
                db.commit()
                db.close()
                break
            except sqlite3.OperationalError as e:
                try:
                    db.close()
                except Exception:
                    pass
                if attempt == 0 and "locked" in str(e).lower():
                    time.sleep(0.08)
                    continue
                return
    except Exception:
        return


def _record_exploration_outcome(
    db_path: Path,
    *,
    selector: str,
    href: str,
    route: str,
    result: str,
    delta_score: float = 0.0,
    selector_key: str | None = None,
) -> None:
    try:
        if not db_path.exists():
            return
        for attempt in range(2):
            try:
                db = connect_db(str(db_path), timeout=3.0)
                db.execute("PRAGMA journal_mode=WAL;")
                try:
                    db.execute("PRAGMA busy_timeout=3000;")
                except Exception:
                    pass
                _ensure_exploration_novelty_table(db)
                href_base = _href_basename(href or "") if href else ""
                key = str(selector_key or "").strip() or _selector_key_from_selector(selector)
                if key:
                    row = db.execute(
                        "SELECT id, seen_count FROM exploration_novelty WHERE selector_key=?",
                        (key,),
                    ).fetchone()
                else:
                    row = db.execute(
                        "SELECT id, seen_count FROM exploration_novelty WHERE selector=?",
                        (selector,),
                    ).fetchone()
                if row:
                    rid, sc = row
                    sc = int(sc or 0) + 1
                    db.execute(
                        "UPDATE exploration_novelty SET seen_count=?, last_seen=?, last_result=?, href_base=?, route=?, last_score_delta=?, selector_key=? WHERE id=?",
                        (sc, time.time(), result, href_base, route, delta_score, key, rid),
                    )
                else:
                    db.execute(
                        "INSERT INTO exploration_novelty (selector, selector_key, href_base, route, seen_count, last_seen, last_result, last_score_delta) VALUES (?, ?, ?, ?, ?, ?, ?, ?)",
                        (selector, key, href_base, route, 1, time.time(), result, delta_score),
                    )
                db.commit()
                db.close()
                break
            except sqlite3.OperationalError as e:
                try:
                    db.close()
                except Exception:
                    pass
                if attempt == 0 and "locked" in str(e).lower():
                    time.sleep(0.08)
                    continue
                return
        if upsert_memory_item:
            upsert_memory_item(
                db_path,
                kind="exploration",
                key_text=selector,
                summary=f"{result} on {route or 'unknown'}",
                evidence_count=1,
                confidence=0.55 if result == "changed" else 0.4,
                meta={"href": href, "route": route, "delta_score": delta_score},
                source_table="exploration_novelty",
                source_id=None,
            )
    except Exception:
        return


def _novelty_score(db_path: Path, selector: str, selector_key: str = "") -> float:
    try:
        if not db_path.exists() or (not selector and not selector_key):
            return 1.0
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_exploration_novelty_table(db)
        key = str(selector_key or "").strip()
        if key:
            row = db.execute(
                "SELECT seen_count, last_seen, last_score_delta FROM exploration_novelty WHERE selector_key=?",
                (key,),
            ).fetchone()
        else:
            row = db.execute(
                "SELECT seen_count, last_seen, last_score_delta FROM exploration_novelty WHERE selector=?",
                (selector,),
            ).fetchone()
        db.close()
        if not row:
            return 3.0
        seen_count, last_seen, last_score_delta = row
        seen_count = int(seen_count or 0)
        age = time.time() - float(last_seen or 0)
        recent_boost = float(last_score_delta or 0.0)
        # Strongly penalize repeated "no-change" selectors for a while
        if seen_count >= 4 and recent_boost <= -0.5 and age < 4 * 3600:
            return 0.1
        return max(0.3, 1.4 / max(1, seen_count)) + min(1.2, age / 3600.0) + recent_boost
    except Exception:
        return 1.0


def _rank_exploration_candidate(meta: Dict[str, Any], explored: set) -> float:
    tag = str(meta.get("tag") or "").lower()
    selector = str(meta.get("selector") or "")
    selector_key = str(meta.get("selector_key") or "")
    href = str(meta.get("href") or "")
    novelty = 1.0 if selector not in explored and href not in explored else 0.2
    novelty += _novelty_score(
        ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db", selector, selector_key
    )
    tag_bonus = 0.4 if tag in ("button", "a") else 0.1
    # If selector is heavily penalized, push it down
    if novelty < 0.25:
        return novelty * 0.2 + tag_bonus * 0.1
    return novelty + tag_bonus


def _ensure_autonomy_goals_table(db: sqlite3.Connection) -> None:
    db.execute(
        "CREATE TABLE IF NOT EXISTS autonomy_goals (id INTEGER PRIMARY KEY AUTOINCREMENT, goal TEXT, payload TEXT, source TEXT, created_at REAL, expires_at REAL)"
    )


def _cleanup_autonomy_goals(db: sqlite3.Connection) -> None:
    try:
        db.execute(
            "DELETE FROM autonomy_goals WHERE expires_at IS NOT NULL AND expires_at < ?",
            (time.time(),),
        )
        db.commit()
    except Exception:
        pass


def _read_autonomy_goals(db_path: Path, limit: int = 8) -> List[Dict[str, Any]]:
    try:
        if not db_path.exists():
            return []
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_autonomy_goals_table(db)
        _cleanup_autonomy_goals(db)
        rows = db.execute(
            "SELECT id, goal, payload, source, created_at, expires_at FROM autonomy_goals ORDER BY created_at DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        db.close()
        out = []
        for goal_id, goal, payload, source, created_at, expires_at in rows:
            try:
                payload_obj = json.loads(payload) if payload else {}
            except Exception:
                payload_obj = {}
            try:
                not_before = float(payload_obj.get("not_before") or 0)
            except Exception:
                not_before = 0.0
            if not_before and time.time() < not_before:
                continue
            out.append(
                {
                    "id": goal_id,
                    "goal": goal,
                    "payload": payload_obj,
                    "source": source,
                    "created_at": created_at,
                    "expires_at": expires_at,
                }
            )
        return out
    except Exception:
        return []


_FLOW_PRIORITY_CACHE: Dict[str, Any] = {"ts": 0.0, "map": {}}


def _load_flow_priority_map(max_age_s: int = 300) -> Dict[str, float]:
    """
    Load UI flow model and compute per-route priority.
    Lower observed counts => higher priority (0..1).
    """
    now = time.time()
    cached = _FLOW_PRIORITY_CACHE
    try:
        if cached.get("map") and (now - float(cached.get("ts") or 0.0)) < max_age_s:
            return cached.get("map") or {}
    except Exception:
        pass
    priorities: Dict[str, float] = {}
    try:
        flow_path = ROOT_DIR / "analysis" / "ui_flow_model.json"
        if flow_path.exists():
            model = json.loads(flow_path.read_text(encoding="utf-8"))
            nodes = model.get("nodes") or {}
            counts = []
            for route, node in nodes.items():
                try:
                    cnt = float(node.get("out") or 0) + float(node.get("in") or 0)
                except Exception:
                    cnt = 0.0
                counts.append(cnt)
            max_count = max(counts) if counts else 0.0
            for route, node in nodes.items():
                try:
                    cnt = float(node.get("out") or 0) + float(node.get("in") or 0)
                except Exception:
                    cnt = 0.0
                norm_route = _normalize_route_path(str(route or ""))
                if not norm_route:
                    continue
                if max_count <= 0:
                    priorities[norm_route] = 0.0
                else:
                    # 1.0 => least seen, 0.0 => most seen
                    priorities[norm_route] = max(0.0, min(1.0, 1.0 - (cnt / max_count)))
    except Exception:
        priorities = {}
    _FLOW_PRIORITY_CACHE["ts"] = now
    _FLOW_PRIORITY_CACHE["map"] = priorities
    return priorities


def _load_flow_priority_routes(max_age_s: int = 300, limit: int = 6) -> List[str]:
    """
    Load least-seen routes from ui_flow_model.json to guide exploration order.
    """
    try:
        flow_path = ROOT_DIR / "analysis" / "ui_flow_model.json"
        if flow_path.exists():
            model = json.loads(flow_path.read_text(encoding="utf-8"))
            routes = model.get("priority_routes") or []
            if isinstance(routes, list) and routes:
                return [str(r) for r in routes if r][:limit]
            nodes = model.get("nodes") or {}
            if isinstance(nodes, dict) and nodes:
                counts = []
                for route, node in nodes.items():
                    try:
                        cnt = float(node.get("out") or 0) + float(node.get("in") or 0)
                    except Exception:
                        cnt = 0.0
                    counts.append((str(route), cnt))
                counts = sorted(counts, key=lambda kv: kv[1])
                return [r for r, _ in counts[:limit] if r]
    except Exception:
        return []
    return []


def _prioritize_candidates_by_routes(
    candidates: List[Dict[str, Any]], priority_routes: List[str]
) -> List[Dict[str, Any]]:
    if not candidates or not priority_routes:
        return candidates
    priority_set = {str(r) for r in priority_routes if r}
    pref, rest = [], []
    for c in candidates:
        href = str(c.get("href") or "")
        route = _normalize_route_path(href) if href else ""
        if route and route in priority_set:
            pref.append(c)
        else:
            rest.append(c)
    return pref + rest


def _inject_priority_goto(
    steps: List[Dict[str, Any]],
    priority_routes: List[str],
    base_url: str,
    current_url: str,
    max_steps: int,
) -> List[Dict[str, Any]]:
    if not priority_routes or len(steps) >= max_steps:
        return steps
    try:
        current_norm = _normalize_route_path(current_url or "")
    except Exception:
        current_norm = ""
    target_route = ""
    for r in priority_routes:
        if not r:
            continue
        if current_norm and _normalize_route_path(r) == current_norm:
            continue
        target_route = str(r)
        break
    if not target_route:
        return steps
    target_url = target_route
    if not target_route.startswith("http"):
        if not target_route.startswith("/"):
            target_route = f"/{target_route}"
        target_url = base_url.rstrip("/") + target_route
    for s in steps:
        if s.get("action") == "goto" and str(s.get("url") or "") in (
            target_url,
            target_route,
        ):
            return steps
    steps.insert(0, {"action": "goto", "url": target_url, "optional": True})
    if len(steps) > max_steps:
        return steps[:max_steps]
    return steps


def _candidate_needs_hover(candidate: Dict[str, Any]) -> bool:
    cls = str(candidate.get("classes") or "").lower()
    role = str(candidate.get("role") or "").lower()
    text = str(candidate.get("text") or "").lower()
    datatarget = str(candidate.get("datatarget") or "").lower()
    aria_controls = str(candidate.get("ariacontrols") or "").lower()
    aria_expanded = str(candidate.get("aria_expanded") or "").lower()
    onclick = str(candidate.get("onclick") or "").lower()
    tokens = ("dropdown", "menu", "submenu", "nav", "navbar", "tab", "toggle")
    if any(t in cls for t in tokens):
        return True
    if any(t in role for t in ("menu", "menuitem", "tab", "navigation")):
        return True
    if any(t in text for t in ("menu", "القائمة", "القوائم")):
        return True
    if datatarget or aria_controls:
        return True
    if aria_expanded in ("false", "0"):
        return True
    if "dropdown" in onclick or "toggle" in onclick:
        return True
    return False


def _flag_truthy(value: Any) -> bool:
    if value is True:
        return True
    if value is False or value is None:
        return False
    s = str(value).strip().lower()
    return s in ("1", "true", "yes", "y", "on")


def _candidate_is_disabled(candidate: Dict[str, Any]) -> bool:
    if _flag_truthy(candidate.get("disabled")):
        return True
    if _flag_truthy(candidate.get("aria_disabled")):
        return True
    cls = str(candidate.get("classes") or "").lower()
    return "disabled" in cls or "is-disabled" in cls


def _candidate_is_active(candidate: Dict[str, Any]) -> bool:
    if _flag_truthy(candidate.get("aria_selected")):
        return True
    cls = str(candidate.get("classes") or "").lower()
    if any(t in cls for t in ("active", "selected", "current", "is-active", "open")):
        return True
    role = str(candidate.get("role") or "").lower()
    aria_expanded = candidate.get("aria_expanded")
    if role in ("tab", "button") and _flag_truthy(aria_expanded):
        return True
    return False


def _candidate_is_tab_like(candidate: Dict[str, Any]) -> bool:
    role = str(candidate.get("role") or "").lower()
    if role == "tab":
        return True
    cls = str(candidate.get("classes") or "").lower()
    if "tab" in cls:
        return True
    if str(candidate.get("datatab") or ""):
        return True
    if str(candidate.get("datatarget") or "") and "tab" in cls:
        return True
    onclick = str(candidate.get("onclick") or "").lower()
    if "switchtab" in onclick:
        return True
    return False


def _goal_flow_priority(goal: Dict[str, Any], flow_map: Dict[str, float]) -> float:
    payload = goal.get("payload") or {}
    target = (
        payload.get("href")
        or payload.get("uri")
        or payload.get("url")
        or payload.get("route")
    )
    if not target:
        return 0.0
    norm = _normalize_route_path(str(target))
    if not norm:
        return 0.0
    try:
        return float(flow_map.get(norm) or 0.0)
    except Exception:
        return 0.0


def _prioritize_autonomy_goals(goals: List[Dict[str, Any]]) -> List[Dict[str, Any]]:
    """
    Prefer high-impact exploration goals first (UI gaps, coverage gaps),
    then fall back to other goals by recency.
    """
    if not goals:
        return goals
    flow_map = _load_flow_priority_map()
    # Lower number = higher priority
    order = {
        "ui_action_gap": 0,
        "ui_self_heal_gap": 1,
        "coverage_gap": 2,
        "flow_gap": 3,
        "gap_deepen": 4,
        "goal_gap_deepen": 4,
        "goal_route_recent": 5,
        "text_focus": 6,
        "insight_gap": 6,
        "delta_change": 7,
        "purpose_focus": 8,
        "decision_focus": 9,
        "long_term_focus": 10,
        "external_dependency": 18,
    }

    def _score(item: Dict[str, Any]):
        name = str(item.get("goal") or "").strip().lower()
        payload = item.get("payload") or {}
        base = order.get(name, 20)
        try:
            priority = float(payload.get("priority_score") or 0.0)
        except Exception:
            priority = 0.0
        try:
            created = float(item.get("created_at") or 0.0)
        except Exception:
            created = 0.0
        # Promote risky/side-effectful UI gaps slightly for visibility (still gated by danger).
        risk = str(payload.get("risk") or "").strip().lower()
        if risk in ("danger", "write"):
            priority += 5.0
        flow_priority = _goal_flow_priority(item, flow_map)
        return (base, -priority, -flow_priority, -created)

    try:
        return sorted(goals, key=_score)
    except Exception:
        return goals

def _extract_goal_targets(goals: List[Dict[str, Any]]) -> Dict[str, List[Dict[str, str]]]:
    """Extract target URLs from goals, prioritizing operator-sourced goals."""
    operator: List[Dict[str, str]] = []
    others: List[Dict[str, str]] = []
    for g in goals:
        payload = g.get("payload") or {}
        target = payload.get("href") or payload.get("uri") or payload.get("url")
        if not target:
            continue
        entry = {
            "target": str(target),
            "goal": str(g.get("goal") or ""),
            "source": str(g.get("source") or ""),
        }
        if str(g.get("source") or "") == "operator":
            operator.append(entry)
        else:
            others.append(entry)
    return {"operator": operator, "all": operator + others}


def _write_autonomy_goal(
    db_path: Path, goal: str, payload: Dict[str, Any], source: str, ttl_days: int = 7
) -> None:
    try:
        if not db_path.exists():
            return
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_autonomy_goals_table(db)
        _cleanup_autonomy_goals(db)
        # Avoid duplicating identical goals
        try:
            payload_hash = hashlib.sha1(
                json.dumps(payload, sort_keys=True).encode("utf-8")
            ).hexdigest()
        except Exception:
            payload_hash = ""
        recent = db.execute(
            "SELECT payload FROM autonomy_goals ORDER BY created_at DESC LIMIT 60"
        ).fetchall()
        for (p,) in recent:
            try:
                existing = json.loads(p) if p else {}
            except Exception:
                existing = {}
            try:
                existing_hash = hashlib.sha1(
                    json.dumps(existing, sort_keys=True).encode("utf-8")
                ).hexdigest()
            except Exception:
                existing_hash = ""
            if payload_hash and payload_hash == existing_hash:
                db.close()
                return
        expires = time.time() + (ttl_days * 86400)
        db.execute(
            "INSERT INTO autonomy_goals (goal, payload, source, created_at, expires_at) VALUES (?, ?, ?, ?, ?)",
            (
                goal,
                json.dumps(payload, ensure_ascii=False),
                source,
                time.time(),
                expires,
            ),
        )
        db.commit()
        db.close()
    except Exception:
        return


def _read_latest_delta(db_path: Path) -> Dict[str, Any]:
    try:
        if not db_path.exists():
            return {}
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        row = cur.execute(
            "SELECT payload_json FROM env_snapshots WHERE kind = 'diagnostic_delta' ORDER BY created_at DESC LIMIT 1"
        ).fetchone()
        db.close()
        if not row or not row[0]:
            return {}
        return json.loads(row[0]) if row[0] else {}
    except Exception:
        return {}


def _read_recent_routes_from_db(
    db_path: Path, days: int = 7, limit: int = 12
) -> List[Dict[str, Any]]:
    try:
        if not db_path.exists():
            return []
        cutoff = time.time() - (days * 86400)
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        rows = cur.execute(
            "SELECT uri, http_method, file_path, last_validated FROM routes WHERE last_validated >= ? ORDER BY last_validated DESC LIMIT ?",
            (cutoff, int(limit)),
        ).fetchall()
        db.close()
        out = []
        for uri, method, file_path, last_validated in rows:
            out.append(
                {
                    "uri": uri,
                    "method": method,
                    "file_path": file_path,
                    "last_validated": last_validated,
                }
            )
        return out
    except Exception:
        return []


def _read_log_highlights(limit: int = 8) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    sources = [
        ("backend", Path("storage/logs/laravel.log")),
        ("backend", Path("storage/logs/app.log")),
        ("agent", Path(".bgl_core/logs/ts.log")),
    ]
    patterns = ["ERROR", "Exception", "Traceback", "CRITICAL", "FATAL"]
    for name, path in sources:
        if not path.exists():
            continue
        try:
            lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
        except Exception:
            continue
        for line in reversed(lines[-200:]):
            if any(p.lower() in line.lower() for p in patterns):
                out.append({"source": name, "message": line.strip()[:220]})
                if len(out) >= limit:
                    return out
    return out


def _external_dependency_patterns() -> List[str]:
    return [
        "database connection timeout",
        "sqlstate",
        "pdoexception",
        "mysql",
        "connection refused",
        "econnrefused",
        "too many connections",
        "connection timeout",
        "db connection timeout",
    ]


def _recent_external_dependency(
    db_path: Path, minutes: int = 30
) -> Dict[str, Any]:
    out = {"count": 0, "last_ts": 0.0, "last_message": ""}
    try:
        if not db_path.exists():
            return out
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cutoff = time.time() - (minutes * 60)
        rows = db.execute(
            """
            SELECT timestamp, payload
            FROM runtime_events
            WHERE timestamp >= ? AND event_type='log_highlight'
            ORDER BY id DESC
            LIMIT 200
            """,
            (cutoff,),
        ).fetchall()
        db.close()
        patterns = _external_dependency_patterns()
        for ts, payload in rows:
            try:
                if isinstance(payload, str):
                    obj = json.loads(payload)
                else:
                    obj = payload or {}
            except Exception:
                obj = {"message": str(payload or "")}
            msg = str((obj or {}).get("message") or "").lower()
            if not msg:
                continue
            if any(p in msg for p in patterns):
                out["count"] += 1
                if float(ts or 0) >= float(out["last_ts"] or 0):
                    out["last_ts"] = float(ts or 0)
                    out["last_message"] = str((obj or {}).get("message") or "")
        return out
    except Exception:
        return out


def _recent_error_routes(db_path: Path, limit: int = 6) -> List[str]:
    try:
        if not db_path.exists():
            return []
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        cur = db.cursor()
        rows = cur.execute(
            "SELECT route FROM runtime_events WHERE event_type IN ('http_error','network_fail','console_error') AND route IS NOT NULL ORDER BY id DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        db.close()
        out = []
        seen = set()
        for (r,) in rows:
            if not r:
                continue
            s = str(r).strip()
            if s.startswith("http://") or s.startswith("https://"):
                try:
                    s = "/" + s.split("/", 3)[3]
                except Exception:
                    pass
            if not s or s in seen:
                continue
            seen.add(s)
            out.append(s)
        return out
    except Exception:
        return []


def _seed_goals_from_system_signals(db_path: Path) -> None:
    # Refresh long-term goals and surface top items into autonomy goals.
    try:
        from .long_term_goals import refresh_long_term_goals, pick_long_term_goals  # type: ignore
    except Exception:
        try:
            from long_term_goals import refresh_long_term_goals, pick_long_term_goals  # type: ignore
        except Exception:
            refresh_long_term_goals = None  # type: ignore
            pick_long_term_goals = None  # type: ignore
    if refresh_long_term_goals and pick_long_term_goals:
        try:
            refresh_long_term_goals(db_path, lookback_days=30, max_candidates=12)
            ltg = pick_long_term_goals(db_path, limit=3, min_priority=0.25)
            for g in ltg:
                _write_autonomy_goal(
                    db_path,
                    goal=str(g.get("goal") or "long_term_goal"),
                    payload={
                        "goal_key": g.get("goal_key"),
                        "title": g.get("title"),
                        "payload": g.get("payload"),
                        "priority": g.get("priority"),
                    },
                    source="long_term_goals",
                    ttl_days=7,
                )
        except Exception:
            pass
    # Snapshot delta -> goals
    delta = _read_latest_delta(db_path)
    if isinstance(delta, dict):
        for h in (delta.get("highlights") or [])[:8]:
            key = h.get("key")
            if not key:
                continue
            _write_autonomy_goal(
                db_path,
                goal="delta_change",
                payload={"key": key, "from": h.get("from"), "to": h.get("to")},
                source="snapshot_delta",
                ttl_days=7,
            )
    # Recent route updates -> goals
    for r in _read_recent_routes_from_db(db_path, days=7, limit=10):
        if not r.get("uri"):
            continue
        _write_autonomy_goal(
            db_path,
            goal="route_recent",
            payload={
                "uri": r.get("uri"),
                "method": r.get("method"),
                "file": r.get("file_path"),
            },
            source="routes",
            ttl_days=7,
        )
    # Log highlights -> goals
    error_routes = _recent_error_routes(db_path, limit=6)
    for item in _read_log_highlights(limit=8):
        msg = item.get("message") or ""
        # Try to extract related route from log message
        route_hint = None
        m = re.search(r"(\/api\/[A-Za-z0-9_\\-\\/\\.]+)", msg)
        if m:
            route_hint = m.group(1)
        if not route_hint:
            m = re.search(r"(\/views\/[A-Za-z0-9_\\-\\/\\.]+)", msg)
            if m:
                route_hint = m.group(1)
        # Fallback: bind log error to most recent error route
        if not route_hint and error_routes:
            route_hint = error_routes[0]
        payload = {"source": item.get("source"), "message": msg}
        if route_hint:
            payload["uri"] = route_hint
        # Record log highlight into runtime_events for unified memory digestion
        try:
            log_event(
                db_path,
                session="signals",
                event={
                    "event_type": "log_highlight",
                    "route": route_hint or f"log:{item.get('source') or 'log'}",
                    "method": "LOG",
                    "payload": payload,
                    "status": None,
                    "source": "logs",
                },
            )
        except Exception:
            pass


def _seed_goals_from_semantic(
    db_path: Path, summary: Dict[str, Any], *, current_url: str = ""
) -> None:
    if not summary:
        return
    terms: List[str] = []
    for t in (summary.get("text_keywords") or []):
        if isinstance(t, str) and t.strip():
            terms.append(t.strip())
    # Fold in headings/text blocks for non-interactive context anchors.
    for h in (summary.get("headings") or []):
        if isinstance(h, dict):
            txt = str(h.get("text") or "").strip()
        else:
            txt = str(h or "").strip()
        if txt:
            terms.append(txt)
    for block in (summary.get("text_blocks") or [])[:2]:
        txt = str(block or "").strip()
        if txt:
            terms.append(txt)
    if not terms:
        return
    def _trim_term(term: str) -> str:
        cleaned = re.sub(r"\\s+", " ", term or "").strip()
        parts = cleaned.split(" ")
        return " ".join(parts[:6]) if parts else ""
    # De-dup and cap
    seen = set()
    out_terms = []
    for t in terms:
        t = _trim_term(t)
        if not t:
            continue
        if t in seen:
            continue
        seen.add(t)
        out_terms.append(t)
        if len(out_terms) >= 3:
            break
    for term in out_terms:
        payload = {"term": term, "reason": "semantic_text"}
        if current_url:
            payload["uri"] = current_url
        _write_autonomy_goal(
            db_path,
            goal="purpose_focus",
            payload=payload,
            source="ui_semantic",
            ttl_days=3,
        )
        _write_autonomy_goal(
            db_path,
            goal="log_error",
            payload=payload,
            source="logs",
            ttl_days=7,
        )
        # External dependency retry goal (defer execution)
        try:
            if any(
                p in msg.lower() for p in _external_dependency_patterns()
            ):
                retry_minutes = int(_cfg_value("external_dependency_retry_minutes", 30) or 30)
                payload_retry = dict(payload)
                payload_retry["not_before"] = time.time() + (retry_minutes * 60)
                payload_retry["reason"] = "external_dependency"
                _write_autonomy_goal(
                    db_path,
                    goal="external_dependency",
                    payload=payload_retry,
                    source="logs",
                    ttl_days=3,
                )
        except Exception:
            pass


def _goal_to_scenario(goal: Dict[str, Any], base_url: str) -> Optional[Dict[str, Any]]:
    g = (goal.get("goal") or "").lower()
    payload = goal.get("payload") or {}
    steps: List[Dict[str, Any]] = [
        {"action": "goto", "url": base_url.rstrip("/") + "/"}
    ]
    name = "goal_" + (g or "unknown")

    if g == "route_recent":
        uri = payload.get("uri") or payload.get("href") or ""
        if not uri:
            return None
        # Prefer realistic navigation: open home then click matching link if present.
        basename = _href_basename(uri)
        if basename and basename not in ("index.php", ""):
            steps = [
                {"action": "goto", "url": base_url.rstrip("/") + "/"},
                {"action": "click", "selector": f"a[href*='{basename}']"},
                {"action": "wait", "ms": 800},
            ]
        else:
            # For home/index, just open home without extra clicks.
            if uri in ("/", "/index.php", "index.php", ""):
                url = base_url.rstrip("/") + "/"
            elif uri.startswith("http"):
                url = uri
            else:
                url = base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri)
            steps = [{"action": "goto", "url": url}, {"action": "wait", "ms": 800}]
        name = "goal_route_recent"
    elif g == "insight_gap":
        uri = payload.get("uri") or payload.get("href") or ""
        if not uri:
            return None
        basename = _href_basename(uri)
        if basename and basename not in ("index.php", ""):
            steps = [
                {"action": "goto", "url": base_url.rstrip("/") + "/"},
                {"action": "click", "selector": f"a[href*='{basename}']"},
                {"action": "wait", "ms": 800},
            ]
        else:
            if uri in ("/", "/index.php", "index.php", ""):
                url = base_url.rstrip("/") + "/"
            elif uri.startswith("http"):
                url = uri
            else:
                url = base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri)
            steps = [{"action": "goto", "url": url}, {"action": "wait", "ms": 800}]
        name = "goal_insight_gap"
    elif g == "delta_change":
        # goal is delta_change
        steps.append({"action": "wait", "ms": 600})
        name = "goal_delta_change"
    elif g == "log_error":
        steps.append({"action": "wait", "ms": 600})
        name = "goal_log_error"
    elif g == "external_dependency":
        steps.append({"action": "wait", "ms": 1200})
        name = "goal_external_dependency"
    elif g == "gap_deepen":
        uri = payload.get("uri") or ""
        search_term = (payload.get("search_term") or "").strip()
        search_selector = "input[type='search'], input[name*='search'], input[placeholder*='بحث'], input[placeholder*='search']"
        if uri:
            basename = _href_basename(uri)
            if basename and basename not in ("index.php", ""):
                steps = [
                    {"action": "goto", "url": base_url.rstrip("/") + "/"},
                    {"action": "click", "selector": f"a[href*='{basename}']"},
                    {"action": "wait", "ms": 900},
                ]
            else:
                if uri in ("/", "/index.php", "index.php", ""):
                    url = base_url.rstrip("/") + "/"
                elif uri.startswith("http"):
                    url = uri
                else:
                    url = base_url.rstrip("/") + (
                        uri if uri.startswith("/") else "/" + uri
                    )
                steps = [
                    {"action": "goto", "url": url},
                    {"action": "wait", "ms": 900},
                ]
        else:
            steps = [{"action": "goto", "url": base_url.rstrip("/") + "/"}]
        # If we have a search term, attempt a real search + submit.
        if search_term:
            steps += [
                {"action": "click", "selector": search_selector, "optional": True},
                {
                    "action": "type",
                    "selector": search_selector,
                    "text": search_term,
                    "optional": True,
                },
                {
                    "action": "press",
                    "selector": search_selector,
                    "key": "Enter",
                    "optional": True,
                },
                {"action": "wait", "ms": 800},
            ]
        # Depth escalation: scroll + try switching tabs/filters
        steps += [
            {"action": "scroll", "dy": 700},
            {"action": "click", "selector": "[role='tab']", "optional": True},
            {"action": "click", "selector": "button.tab-btn, .tab-btn", "optional": True},
            {"action": "click", "selector": "[onclick*=\"switchTab\"]", "optional": True},
            {"action": "click", "selector": "[data-filter]", "optional": True},
            {
                "action": "click",
                "selector": "button.filter, .filter button, .filters button",
                "optional": True,
            },
            {"action": "scroll", "dy": 600},
        ]
        name = "goal_gap_deepen"
    elif g == "ui_action_gap":
        uri = payload.get("uri") or payload.get("route") or ""
        selector = payload.get("selector") or ""
        risk = str(payload.get("risk") or "").lower()
        danger = risk in ("danger", "write")
        reveal = payload.get("reveal_selectors") or payload.get("reveal") or []
        text_hints = payload.get("text_hints") or []
        if isinstance(reveal, str):
            reveal = [reveal]
        if isinstance(text_hints, str):
            text_hints = [text_hints]
        if uri:
            if uri.startswith("http"):
                steps = [{"action": "goto", "url": uri}]
            else:
                steps = [
                    {
                        "action": "goto",
                        "url": base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri),
                    }
                ]
        if selector:
            for sel in [str(s) for s in (reveal or []) if s]:
                steps.append({"action": "click", "selector": sel, "optional": True})
                steps.append({"action": "wait", "ms": 250})
            for sel in _text_reveal_selectors([str(t) for t in (text_hints or []) if t])[:6]:
                steps.append({"action": "click", "selector": sel, "optional": True})
                steps.append({"action": "wait", "ms": 200})
            click_step: Dict[str, Any] = {"action": "click", "selector": selector}
            if danger:
                click_step["danger"] = True
            steps.append(click_step)
            steps.append({"action": "wait", "ms": 600})
        name = "goal_ui_action_gap"
    elif g == "ui_self_heal_gap":
        uri = payload.get("uri") or ""
        selector = payload.get("selector") or ""
        context = payload.get("context") or {}
        text_hints = payload.get("text_hints") or []
        reveal = payload.get("reveal_selectors") or []
        if isinstance(text_hints, str):
            text_hints = [text_hints]
        if isinstance(reveal, str):
            reveal = [reveal]
        steps = []
        if uri:
            if uri.startswith("http"):
                steps.append({"action": "goto", "url": uri})
            else:
                steps.append(
                    {
                        "action": "goto",
                        "url": base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri),
                    }
                )
        else:
            steps.append({"action": "goto", "url": base_url.rstrip("/") + "/"})
        # Prefer targeted reveals captured during the failed self-heal attempt.
        for sel in _unique_order([str(s) for s in (reveal or []) if s])[:8]:
            steps.append({"action": "click", "selector": sel, "optional": True})
            steps.append({"action": "wait", "ms": 200})
        for sel in _text_reveal_selectors([str(t) for t in (text_hints or []) if t])[:6]:
            steps.append({"action": "click", "selector": sel, "optional": True})
            steps.append({"action": "wait", "ms": 200})
        # If we already have contextual IDs, use them directly.
        ctx = context if isinstance(context, dict) else {}
        reveal_ids = []
        for k in ("panel_id", "collapse_id", "modal_id", "offcanvas_id", "menu_id"):
            v = str(ctx.get(k) or "").strip()
            if v:
                reveal_ids.append(v)
        for rid in reveal_ids:
            steps.append({"action": "click", "selector": f"[aria-controls='{rid}']", "optional": True})
            steps.append({"action": "click", "selector": f"[data-bs-target='#{rid}']", "optional": True})
            steps.append({"action": "click", "selector": f"[data-target='#{rid}']", "optional": True})
        # If we have no targeted context, fall back to a broad reveal sweep.
        if not reveal and not reveal_ids:
            steps += [
                {"action": "click", "selector": "[role='tab']", "optional": True},
                {"action": "click", "selector": "[data-bs-toggle='collapse'], [data-toggle='collapse']", "optional": True},
                {"action": "click", "selector": "[data-bs-toggle='modal'], [data-toggle='modal']", "optional": True},
                {"action": "click", "selector": "[data-bs-toggle='offcanvas'], [data-toggle='offcanvas']", "optional": True},
                {"action": "click", "selector": "[data-bs-toggle='dropdown'], [data-toggle='dropdown']", "optional": True},
                {"action": "scroll", "dy": 700},
            ]
        if selector:
            steps.append({"action": "click", "selector": selector, "optional": True})
            steps.append({"action": "wait", "ms": 600})
        name = "goal_ui_self_heal_gap"
    elif g in ("coverage_gap", "flow_gap"):
        uri = payload.get("uri") or ""
        if not uri:
            return None
        basename = _href_basename(uri)
        if basename and basename not in ("index.php", ""):
            steps = [
                {"action": "goto", "url": base_url.rstrip("/") + "/"},
                {"action": "click", "selector": f"a[href*='{basename}']"},
                {"action": "wait", "ms": 800},
            ]
        else:
            if uri in ("/", "/index.php", "index.php", ""):
                url = base_url.rstrip("/") + "/"
            elif uri.startswith("http"):
                url = uri
            else:
                url = base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri)
            steps = [{"action": "goto", "url": url}, {"action": "wait", "ms": 800}]
        name = f"goal_{g}"
    elif g in ("purpose_focus", "decision_focus", "long_term_focus", "text_focus"):
        uri = payload.get("uri") or payload.get("href") or ""
        term = (
            payload.get("term")
            or payload.get("purpose")
            or payload.get("reason")
            or payload.get("intent")
            or ""
        )
        term = str(term or "").strip()
        if not term and g == "text_focus":
            try:
                keywords = payload.get("keywords") or []
                if isinstance(keywords, list) and keywords:
                    term = str(keywords[0] or "").strip()
            except Exception:
                term = ""
        search_selector = "input[type='search'], input[name*='search'], input[placeholder*='بحث'], input[placeholder*='search']"
        if uri:
            basename = _href_basename(uri)
            if basename and basename not in ("index.php", ""):
                steps = [
                    {"action": "goto", "url": base_url.rstrip("/") + "/"},
                    {"action": "click", "selector": f"a[href*='{basename}']"},
                    {"action": "wait", "ms": 800},
                ]
            else:
                if uri in ("/", "/index.php", "index.php", ""):
                    url = base_url.rstrip("/") + "/"
                elif uri.startswith("http"):
                    url = uri
                else:
                    url = base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri)
                steps = [{"action": "goto", "url": url}, {"action": "wait", "ms": 800}]
            if term:
                safe_term = re.sub(r"[\"'\\n\\r]+", " ", term).strip()
                if safe_term:
                    steps.append({"action": "hover", "selector": f"text={safe_term[:32]}", "optional": True})
                    steps.append({"action": "scroll", "dy": 500})
        else:
            steps = [{"action": "goto", "url": base_url.rstrip("/") + "/"}]
            if term:
                steps += [
                    {"action": "click", "selector": search_selector, "optional": True},
                    {"action": "type", "selector": search_selector, "text": term, "optional": True},
                    {"action": "press", "selector": search_selector, "key": "Enter", "optional": True},
                    {"action": "wait", "ms": 800},
                ]
            steps += [{"action": "scroll", "dy": 600}]
        name = "goal_" + g
    else:
        return None

    return {"name": name, "kind": "ui", "steps": steps}


def _goal_route_kind(uri: str) -> str:
    u = (uri or "").strip().lower()
    if u.startswith("http"):
        try:
            path = u.split("://", 1)[1]
            path = "/" + path.split("/", 1)[1] if "/" in path else "/"
            u = path
        except Exception:
            pass
    if u.startswith("/api/"):
        return "api"
    if u.startswith("/views/") or u.endswith(".php") or u.startswith("/"):
        return "ui"
    return "unknown"


def _ensure_goal_strategy_table(db: sqlite3.Connection) -> None:
    db.execute(
        "CREATE TABLE IF NOT EXISTS autonomy_goal_strategy (id INTEGER PRIMARY KEY AUTOINCREMENT, goal TEXT, route_kind TEXT, strategy TEXT, success INTEGER, fail INTEGER, updated_at REAL)"
    )


def _record_goal_strategy_result(
    db_path: Path, goal: str, route_kind: str, strategy: str, ok: bool
) -> None:
    try:
        if not db_path.exists():
            return
        if os.getenv("BGL_TRACE_SCENARIO", "0") == "1":
            _trace(f"goal: record strategy start goal={goal} strat={strategy} ok={ok}")
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_goal_strategy_table(db)
        row = db.execute(
            "SELECT id, success, fail FROM autonomy_goal_strategy WHERE goal=? AND route_kind=? AND strategy=?",
            (goal, route_kind, strategy),
        ).fetchone()
        if row:
            sid, s, f = row
            s = int(s or 0)
            f = int(f or 0)
            if ok:
                s += 1
            else:
                f += 1
            db.execute(
                "UPDATE autonomy_goal_strategy SET success=?, fail=?, updated_at=? WHERE id=?",
                (s, f, time.time(), sid),
            )
        else:
            db.execute(
                "INSERT INTO autonomy_goal_strategy (goal, route_kind, strategy, success, fail, updated_at) VALUES (?, ?, ?, ?, ?, ?)",
                (
                    goal,
                    route_kind,
                    strategy,
                    1 if ok else 0,
                    0 if ok else 1,
                    time.time(),
                ),
            )
        db.commit()
        db.close()
        if os.getenv("BGL_TRACE_SCENARIO", "0") == "1":
            _trace("goal: record strategy done")
    except Exception:
        if os.getenv("BGL_TRACE_SCENARIO", "0") == "1":
            _trace("goal: record strategy error")
        return


def _pick_goal_strategy(
    db_path: Path, goal: str, route_kind: str, default_strategy: str
) -> str:
    try:
        if not db_path.exists():
            return default_strategy
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
        _ensure_goal_strategy_table(db)
        rows = db.execute(
            "SELECT strategy, success, fail FROM autonomy_goal_strategy WHERE goal=? AND route_kind=?",
            (goal, route_kind),
        ).fetchall()
        db.close()
        if not rows:
            return default_strategy
        best = None
        best_score = -1.0
        for strat, s, f in rows:
            s = int(s or 0)
            f = int(f or 0)
            score = s / max(1, s + f)
            if score > best_score:
                best_score = score
                best = strat
        return best or default_strategy
    except Exception:
        return default_strategy


def _http_check(url: str, timeout_s: float = 4.0) -> bool:
    try:
        req = urllib.request.Request(url, method="GET")
        with urllib.request.urlopen(req, timeout=timeout_s) as resp:
            return 200 <= resp.getcode() < 300
    except Exception:
        return False


def _derive_goal_context(goal: Dict[str, Any]) -> Dict[str, str]:
    goal_name = (goal.get("goal") or "unknown").lower()
    created_at = goal.get("created_at") or time.time()
    goal_id = str(goal.get("id") or goal.get("goal_id") or f"{goal_name}_{int(created_at)}")
    return {
        "goal_id": goal_id,
        "goal_name": goal_name,
        "scenario_id": f"goal:{goal_id}",
    }


async def run_goal_scenario(
    manager: BrowserManager,
    page,
    base_url: str,
    db_path: Path,
    goal: Dict[str, Any],
):
    goal_ctx = _derive_goal_context(goal)
    ctx_token = _push_context(
        goal_id=goal_ctx.get("goal_id"),
        goal_name=goal_ctx.get("goal_name"),
        scenario_id=goal_ctx.get("scenario_id"),
        scenario_name=f"goal_{goal_ctx.get('goal_name') or 'unknown'}",
    )
    try:
        return await _run_goal_scenario_impl(
            manager,
            page,
            base_url,
            db_path,
            goal,
            goal_ctx=goal_ctx,
        )
    finally:
        _pop_context(ctx_token)


async def _run_goal_scenario_impl(
    manager: BrowserManager,
    page,
    base_url: str,
    db_path: Path,
    goal: Dict[str, Any],
    *,
    goal_ctx: Optional[Dict[str, str]] = None,
):
    _trace(f"goal: start {goal.get('goal')} payload={list((goal.get('payload') or {}).keys())}")
    scenario = _goal_to_scenario(goal, base_url)
    _trace(f"goal: scenario={'yes' if scenario else 'no'}")
    payload = goal.get("payload") or {}
    uri = payload.get("uri") or payload.get("href") or ""
    route_kind = _goal_route_kind(uri)
    goal_ctx = goal_ctx or _derive_goal_context(goal)
    goal_id = goal_ctx.get("goal_id") or ""
    goal_name = goal_ctx.get("goal_name") or (goal.get("goal") or "unknown").lower()
    goal_scenario_id = goal_ctx.get("scenario_id") or f"goal:{goal_id}"

    default_strategy = "api" if route_kind == "api" else "ui"
    strategy = _pick_goal_strategy(db_path, goal_name, route_kind, default_strategy)
    _trace(f"goal: strategy={strategy} route_kind={route_kind} uri={uri}")
    tried = []

    async def _run_ui() -> bool:
        if not scenario:
            return False
        # Enrich goal steps using semantic summary for the target page.
        try:
            target_url = ""
            steps = scenario.get("steps") if isinstance(scenario, dict) else None
            if isinstance(steps, list):
                for s in steps:
                    if not isinstance(s, dict):
                        continue
                    if s.get("action") == "goto" and s.get("url"):
                        target_url = str(s.get("url"))
                        break
            if target_url:
                try:
                    await page.goto(target_url, wait_until="domcontentloaded")
                except Exception:
                    pass
                ui_limit = int(_cfg_value("goal_semantic_ui_limit", 80) or 80)
                semantic_summary = {}
                candidates = []
                try:
                    semantic_map = await capture_semantic_map(page, limit=min(12, ui_limit))
                    semantic_summary = summarize_semantic_map(semantic_map) if semantic_map else {}
                except Exception:
                    semantic_summary = {}
                try:
                    ui_map = await capture_ui_map(page, limit=ui_limit)
                    candidates = _build_selector_candidates(ui_map)
                except Exception:
                    candidates = []
                if semantic_summary and candidates and isinstance(scenario.get("steps"), list):
                    max_steps = int(
                        _cfg_value("goal_semantic_max_steps", len(scenario["steps"]) + 3)
                        or (len(scenario["steps"]) + 3)
                    )
                    max_steps = max(len(scenario["steps"]), max_steps)
                    scenario["steps"] = _ensure_semantic_steps(
                        list(scenario["steps"]),
                        semantic_summary,
                        candidates,
                        max_steps,
                    )
        except Exception:
            pass
        _trace("goal: ui path prepare")
        out_dir = SCENARIOS_DIR / "goals"
        out_dir.mkdir(parents=True, exist_ok=True)
        path = out_dir / f"goal_{int(time.time())}.yaml"
        try:
            with path.open("w", encoding="utf-8") as f:
                yaml.safe_dump(scenario, f, sort_keys=False, allow_unicode=True)
        except Exception:
            path.write_text(
                json.dumps(scenario, ensure_ascii=False, indent=2), encoding="utf-8"
            )
        _trace(f"goal: ui scenario written {path}")
        log_event(
            db_path,
            "autonomy_goal_scenario",
            {
                "event_type": "autonomy_goal_scenario",
                "route": str(path),
                "method": "AUTO",
                "payload": json.dumps(goal, ensure_ascii=False),
                "status": 200,
                "goal_id": goal_id,
            },
        )
        try:
            _trace("goal: ui run_scenario start")
            await run_scenario(
                manager,
                page,
                base_url,
                path,
                keep_open=False,
                db_path=db_path,
                is_last=False,
                scenario_id=goal_scenario_id,
                goal_id=goal_id,
                goal_name=goal_name,
            )
            _trace("goal: ui run_scenario done")
            return True
        except Exception:
            _trace("goal: ui run_scenario error")
            return False

    def _run_api() -> bool:
        if not uri:
            return False
        if uri.startswith("http"):
            url = uri
        else:
            url = base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri)
        _trace(f"goal: api check {url}")
        return _http_check(url)

    ok = False
    for strat in (strategy, "ui" if strategy == "api" else "api"):
        if strat in tried:
            continue
        tried.append(strat)
        if strat == "api":
            ok = _run_api()
        else:
            ok = await _run_ui()
        _trace(f"goal: strategy {strat} ok={ok}")
        _record_goal_strategy_result(db_path, goal_name, route_kind, strat, ok)
        if ok:
            break

    # Record a meaningful outcome for goal scenarios using route map when possible.
    try:
        if uri:
            log_event(
                db_path,
                "autonomy_goal_result",
                {
                    "event_type": "autonomy_goal_result",
                    "route": str(uri),
                    "method": str(payload.get("method") or "GET"),
                    "payload": json.dumps(
                        {
                            "goal": goal.get("goal"),
                            "status": "checked" if ok else "failed",
                            "source": goal.get("source"),
                            "strategy": strategy,
                        },
                        ensure_ascii=False,
                    ),
                    "status": 200 if ok else 500,
                    "goal_id": goal_id,
                },
            )
        else:
            log_event(
                db_path,
                "autonomy_goal_result",
                {
                    "event_type": "autonomy_goal_result",
                    "route": "unknown",
                    "method": "AUTO",
                    "payload": json.dumps(
                        {
                            "goal": goal.get("goal"),
                            "status": "no_route",
                            "source": goal.get("source"),
                            "strategy": strategy,
                        },
                        ensure_ascii=False,
                    ),
                    "status": 200,
                    "goal_id": goal_id,
                },
            )
    except Exception:
        pass

    # Update long-term goal stats if this run was scheduled from the planner.
    try:
        lt_key = payload.get("long_term_key")
        if lt_key and record_long_term_goal_result:
            record_long_term_goal_result(
                db_path,
                str(lt_key),
                ok=bool(ok),
                details={
                    "goal": goal.get("goal"),
                    "source": goal.get("source"),
                    "strategy": strategy,
                    "route": uri,
                },
            )
    except Exception:
        pass


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
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
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


def _is_recent_autonomous_plan(
    db_path: Path, plan: Dict[str, Any], limit: int = 20
) -> bool:
    try:
        if not db_path.exists():
            return False
        db = connect_db(str(db_path), timeout=30.0)
        db.execute("PRAGMA journal_mode=WAL;")
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
    test_id = str(el.get("testid") or "").strip()
    test_attr = str(el.get("testattr") or "").strip()
    data_tab = str(el.get("datatab") or "").strip()
    data_target = str(el.get("datatarget") or "").strip()
    data_target_attr = str(el.get("datatarget_attr") or "").strip()
    aria_controls = str(el.get("ariacontrols") or "").strip()
    onclick = str(el.get("onclick") or "").strip()
    role = str(el.get("role") or "").strip().lower()
    text = str(el.get("text") or "").strip()

    if test_id and test_attr:
        safe_val = test_id.replace('"', "").replace("'", "")
        return f'[{test_attr}="{safe_val}"]'
    if data_tab:
        safe_val = data_tab.replace('"', "").replace("'", "")
        return f'[data-tab="{safe_val}"]'
    if aria_controls:
        safe_val = aria_controls.replace('"', "").replace("'", "")
        return f'[aria-controls="{safe_val}"]'
    if data_target:
        safe_val = data_target.replace('"', "").replace("'", "")
        if "data-bs-target" in data_target_attr:
            return f'[data-bs-target="{safe_val}"]'
        return f'[data-target="{safe_val}"]'
    if onclick:
        # Handle common tab switchers like switchTab('banks')
        try:
            m = re.search(r"switchTab\\(['\\\"]([^'\\\"]+)['\\\"]\\)", onclick)
        except Exception:
            m = None
        if m:
            arg = m.group(1)
            if _is_simple_token(arg):
                return f'[onclick*="switchTab"][onclick*="{arg}"]'
        if "switchTab" in onclick:
            return '[onclick*="switchTab"]'
    if tag == "a" and href:
        safe_href = href.replace('"', "").replace("'", "")
        return f'a[href="{safe_href}"]'
    if el_id and _is_simple_token(el_id):
        return f"#{el_id}"
    if tag and name and _is_simple_token(name):
        return f'{tag}[name="{name}"]'
    if tag == "input" and el_type == "file":
        return 'input[type="file"]'
    if text and (role == "tab" or "tab" in classes.lower() or "tab-btn" in classes.lower()):
        try:
            safe_text = _escape_has_text(text)
        except Exception:
            safe_text = text.replace('"', '\\"')
        if safe_text:
            return f'{tag or "button"}:has-text("{safe_text}")'
    if classes:
        tokens = [c for c in classes.split() if _is_simple_token(c)]
        if tokens:
            prefer = [c for c in tokens if "tab" in c.lower()]
            ordered = prefer + [c for c in tokens if c not in prefer]
            for c in ordered:
                return f"{tag}.{c}" if tag else f".{c}"
    if tag:
        return tag
    return None


def _stable_selector_key(meta: Dict[str, Any]) -> str:
    """
    Build a stable, attribute-based key for UI elements to reduce selector churn.
    Prefer explicit test IDs, then stable attributes, then text-derived fallback.
    """
    if not isinstance(meta, dict):
        return ""
    testid = str(meta.get("testid") or "").strip()
    testattr = str(meta.get("testattr") or "data-testid").strip() or "data-testid"
    if testid:
        return f"{testattr}={testid}"
    elem_id = str(meta.get("id") or "").strip()
    if elem_id:
        return f"id={elem_id}"
    name = str(meta.get("name") or "").strip()
    if name:
        return f"name={name}"
    datatab = str(meta.get("datatab") or "").strip()
    if datatab:
        return f"data-tab={datatab}"
    datatarget = str(meta.get("datatarget") or "").strip()
    datatarget_attr = str(meta.get("datatarget_attr") or "data-target").strip() or "data-target"
    if datatarget:
        return f"{datatarget_attr}={datatarget}"
    aria_controls = str(meta.get("ariacontrols") or "").strip()
    if aria_controls:
        return f"aria-controls={aria_controls}"
    href = str(meta.get("href") or "").strip()
    href_base = _href_basename(href) if href else ""
    if href_base:
        return f"href={href_base}"
    role = str(meta.get("role") or "").strip().lower()
    text = str(meta.get("text") or "").strip()
    if role and text:
        return f"role={role}|text={text[:32]}"
    if text:
        return f"text={text[:32]}"
    return ""


def _selector_key_from_selector(selector: str) -> str:
    sel = str(selector or "").strip()
    if not sel:
        return ""
    if sel.startswith("#") and len(sel) > 1:
        return f"id={sel[1:]}"
    # data-testid/data-test/data-qa/data-cy
    m = re.search(r"\[(data-(?:testid|test|qa|cy))=['\"]?([^'\"]+)['\"]?\]", sel, re.IGNORECASE)
    if m:
        return f"{m.group(1)}={m.group(2)}"
    m = re.search(r"\[(name|id|aria-controls|data-tab|data-target|data-bs-target)=['\"]?([^'\"]+)['\"]?\]", sel, re.IGNORECASE)
    if m:
        return f"{m.group(1)}={m.group(2)}"
    m = re.search(r"href\\*=['\"]?([^'\"]+)['\"]?", sel, re.IGNORECASE)
    if m:
        return f"href={_href_basename(m.group(1))}"
    return ""


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
        selector_key = _stable_selector_key({**el, "selector": sel})
        out.append(
            {
                "selector": sel,
                "selector_key": selector_key,
                "tag": el.get("tag"),
                "text": el.get("text"),
                "type": el.get("type"),
                "href": el.get("href"),
                "role": el.get("role"),
                "id": el.get("id"),
                "name": el.get("name"),
                "classes": el.get("classes"),
                "onclick": el.get("onclick"),
                "datatab": el.get("datatab"),
                "datatarget": el.get("datatarget"),
                "datatarget_attr": el.get("datatarget_attr"),
                "ariacontrols": el.get("ariacontrols"),
                "aria_selected": el.get("aria_selected"),
                "aria_expanded": el.get("aria_expanded"),
                "aria_disabled": el.get("aria_disabled"),
                "disabled": el.get("disabled"),
                "testid": el.get("testid"),
                "testattr": el.get("testattr"),
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
    allowed = {"goto", "click", "type", "press", "upload", "wait", "scroll", "hover"}
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
                step["post_wait_ms"] = int(raw.get("post_wait_ms") or 0)
        elif action in ("click", "type", "press", "upload", "hover"):
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
            if action == "hover":
                step["hover_wait_ms"] = int(raw.get("hover_wait_ms", 120))
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
    recent_week_routes: List[str],
    uploads: List[str],
    allow_upload: bool,
    goal_targets: Optional[List[Dict[str, str]]] = None,
    semantic_summary: Optional[Dict[str, Any]] = None,
    flow_bias: Optional[List[Dict[str, Any]]] = None,
    priority_routes: Optional[List[str]] = None,
    max_steps: int = 8,
) -> List[Dict[str, Any]]:
    steps: List[Dict[str, Any]] = []
    used_selectors: set[str] = set()
    if goal_targets:
        target = goal_targets[0].get("target")
        if target:
            steps.append({"action": "goto", "url": target})
    elif priority_routes:
        target = priority_routes[0]
        if target:
            steps.append({"action": "goto", "url": target})
    # Prefer semantic search fields when available
    if semantic_summary:
        semantic_steps = _semantic_plan_steps(semantic_summary, candidates, max_steps=max_steps)
        for s in semantic_steps:
            if len(steps) >= max_steps:
                break
            sel = str(s.get("selector") or "")
            if sel and sel in used_selectors:
                continue
            steps.append(s)
            if sel:
                used_selectors.add(sel)
        preferred = _semantic_preferred_selectors(semantic_summary, candidates)
        if preferred:
            term = random.choice(_build_search_terms())
            if preferred[0] not in used_selectors:
                steps.append(
                    {"action": "type", "selector": preferred[0], "text": term, "auto_submit": True}
                )
                used_selectors.add(preferred[0])
        # If primary actions/nav items exist, attempt a click after search/typing
        if preferred:
            for sel in preferred[1:4]:
                if sel and sel not in used_selectors:
                    steps.append({"action": "click", "selector": sel})
                    used_selectors.add(sel)
                    break
    if allow_upload and uploads:
        for c in candidates:
            if (
                str(c.get("tag")).lower() == "input"
                and str(c.get("type")).lower() == "file"
            ):
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
    if flow_bias:
        for fb in flow_bias:
            sel = str(fb.get("selector") or "")
            if sel:
                pick = sel
                break
    recent_set = set(recent_week_routes or recent_routes or [])
    fresh = [
        c.get("selector")
        for c in candidates
        if c.get("selector") and c.get("selector") not in recent_set
    ]
    fresh = [s for s in fresh if s]
    if fresh:
        # Avoid repeatedly unproductive selectors if possible
        scored = []
        for s in fresh:
            score = _novelty_score(ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db", s)
            scored.append((score, s))
        scored.sort(reverse=True)
        if scored and scored[0][0] < 0.2:
            pick = random.choice(fresh)
        else:
            pick = scored[0][1] if scored else random.choice(fresh)
    elif candidates:
        pick = candidates[random.randrange(0, len(candidates))].get("selector")
    if pick:
        cand_by_sel = {str(c.get("selector") or ""): c for c in candidates if c.get("selector")}
        if _candidate_needs_hover(cand_by_sel.get(pick, {})):
            steps.append({"action": "hover", "selector": pick, "optional": True})
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
    semantic_summary = {}
    try:
        semantic_map = await capture_semantic_map(page, limit=min(12, ui_limit))
        semantic_summary = summarize_semantic_map(semantic_map) if semantic_map else {}
    except Exception:
        semantic_summary = {}
    candidates = _build_selector_candidates(ui_map)
    if not candidates:
        return None

    recent_routes = _recent_runtime_routes(db_path, limit=200)
    recent_week_routes = _recent_routes_within_days(db_path, days=7, limit=1200)
    insight_names = _load_insight_basenames()
    gap_candidates = []
    for c in candidates:
        href_base = _href_basename(c.get("href"))
        if href_base and href_base not in insight_names:
            gap_candidates.append(href_base)
    goals = _read_autonomy_goals(db_path, limit=8)
    goal_targets = _extract_goal_targets(goals)
    operator_targets = goal_targets["operator"]
    all_targets = goal_targets["all"]
    # Bias candidates using recent UI flow transitions for the current page
    flow_bias = _load_ui_flow_bias(db_path, page.url if hasattr(page, "url") else "")
    flow_selectors = [str(fb.get("selector") or "") for fb in flow_bias if fb.get("selector")]
    priority_routes = _load_flow_priority_routes()
    semantic_selectors = _semantic_preferred_selectors(semantic_summary, candidates)
    candidates = _prioritize_candidates(candidates, flow_selectors + semantic_selectors)
    candidates = _prioritize_candidates_by_routes(candidates, priority_routes)
    # Filter out stale/unproductive selectors when enough alternatives exist
    if len(candidates) > 8:
        filtered = []
        for c in candidates:
            sel = str(c.get("selector") or "")
            if not sel:
                continue
            if _novelty_score(db_path, sel) < 0.15:
                continue
            filtered.append(c)
        if len(filtered) >= 4:
            candidates = filtered
    # Shuffle candidates to avoid deterministic repetition
    random.shuffle(candidates)
    # Soft-bias candidates using goals (do not block open exploration)
    if goals:
        goal_selectors = {
            str((g.get("payload") or {}).get("selector") or "")
            for g in goals
            if (g.get("payload") or {}).get("selector")
        }
        operator_hrefs = {str(t["target"]).lower() for t in operator_targets if t.get("target")}
        goal_hrefs = {str(t["target"]).lower() for t in all_targets if t.get("target")}

        if goal_selectors:
            pref, rest = [], []
            for c in candidates:
                sel = str(c.get("selector") or "")
                if sel and sel in goal_selectors:
                    pref.append(c)
                else:
                    rest.append(c)
            if pref:
                candidates = pref + rest

        def _split_by_hrefs(hrefs: set[str]) -> tuple[list, list]:
            pref, rest = [], []
            for c in candidates:
                href = str(c.get("href") or "").lower()
                if href and href in hrefs:
                    pref.append(c)
                else:
                    rest.append(c)
            return pref, rest

        if operator_hrefs:
            pref, rest = _split_by_hrefs(operator_hrefs)
            candidates = pref + rest
        elif goal_hrefs:
            pref, rest = _split_by_hrefs(goal_hrefs)
            candidates = pref + rest

        # If goal asks for gap deepen / coverage gap, prioritize gap candidates.
        if any(
            str(g.get("goal") or "")
            in ("gap_deepen", "gap", "coverage_gap", "flow_gap", "ui_action_gap")
            for g in goals
        ) and gap_candidates:
            preferred = []
            others = []
            gap_set = set(gap_candidates)
            for c in candidates:
                href_base = _href_basename(c.get("href"))
                if href_base and href_base in gap_set:
                    preferred.append(c)
                else:
                    others.append(c)
            candidates = preferred + others
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
        operator_goal_urls = [t["target"] for t in operator_targets if t.get("target")]
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
- You may click, type, press, upload, wait, scroll, goto, hover.
- If you choose upload, use files from available_uploads.
- If you perform a write-like action (save/submit/import), include "danger": true.
- Prefer selectors that are not used in the last 7 days.
- Prefer hrefs whose filename is missing in auto_insights (gap_candidates).
- Prefer goal targets in autonomy_goals when available, but do not ignore open exploration.
 - Operator goals MUST be prioritized. If operator provides a URL, include a goto to it before other steps.
 - If semantic_summary.search_fields exist, include a type + press/submit on a search field.
 - If semantic_summary.primary_actions/nav_items exist, include at least one click on a matching selector.
 - Prefer priority_routes when choosing navigation targets.

Context JSON:
{json.dumps({"base_url": base_url, "available_uploads": uploads, "recent_routes": recent_routes, "recent_routes_7d": recent_week_routes, "gap_candidates": gap_candidates, "autonomy_goals": goals, "operator_goals": operator_goal_urls, "candidates": candidates, "semantic_summary": semantic_summary, "flow_bias": flow_bias, "priority_routes": priority_routes}, ensure_ascii=False)}
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
        steps = _fallback_autonomous_steps(
            candidates,
            recent_routes,
            recent_week_routes,
            uploads,
            allow_upload,
            goal_targets=all_targets,
            semantic_summary=semantic_summary,
            flow_bias=flow_bias,
            priority_routes=priority_routes,
            max_steps=max_steps,
        )
    if steps:
        steps = _ensure_semantic_steps(steps, semantic_summary, candidates, max_steps)
        try:
            current_url = page.url if hasattr(page, "url") else ""
        except Exception:
            current_url = ""
        steps = _inject_priority_goto(steps, priority_routes, base_url, current_url, max_steps)
    if not steps:
        return None

    name = (plan or {}).get("name") or f"autonomous_{int(time.time())}"
    candidate_plan = {"name": str(name), "kind": "ui", "steps": steps}
    if _is_recent_autonomous_plan(db_path, candidate_plan):
        # Force a new fallback plan if recent repeat detected
        steps = _fallback_autonomous_steps(
            candidates,
            recent_routes,
            recent_week_routes,
            uploads,
            allow_upload,
            goal_targets=all_targets,
            semantic_summary=semantic_summary,
            flow_bias=flow_bias,
            priority_routes=priority_routes,
            max_steps=max_steps,
        )
        if not steps:
            return None
        candidate_plan = {
            "name": f"autonomous_{int(time.time())}",
            "kind": "ui",
            "steps": steps,
        }
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

    # Seed goals from system signals (logs/snapshots/routes) without blocking open exploration.
    _seed_goals_from_system_signals(db_path)
    # Seed goals from semantic text blocks/keywords to expand non-interactive exploration.
    try:
        semantic_map = await capture_semantic_map(page, limit=12)
        semantic_summary = summarize_semantic_map(semantic_map) if semantic_map else {}
        current_url = ""
        try:
            current_url = page.url if hasattr(page, "url") else ""
        except Exception:
            current_url = ""
        _seed_goals_from_semantic(db_path, semantic_summary, current_url=current_url)
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
        path.write_text(
            json.dumps(plan, ensure_ascii=False, indent=2), encoding="utf-8"
        )

    log_event(
        db_path,
        "autonomous_scenario",
        {
            "event_type": "autonomous_scenario_generated",
            "route": str(path),
            "method": "AUTO",
            "payload": json.dumps(
                {"steps": len(plan.get("steps", []))}, ensure_ascii=False
            ),
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

    # Seed lightweight goals from open exploration (no hard constraints).
    try:
        ui_map = await capture_ui_map(page, limit=120)
        candidates = _build_selector_candidates(ui_map)
        insight_names = _load_insight_basenames()
        for c in candidates:
            href = c.get("href")
            href_base = _href_basename(href)
            if href_base and href_base not in insight_names:
                _write_autonomy_goal(
                    db_path,
                    goal="gap_no_insight",
                    payload={"href": str(href), "base": href_base},
                    source="open_exploration",
                    ttl_days=7,
                )
    except Exception:
        pass


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
    external_candidates: List[str] = []
    max_external_per_run = int(_cfg_value("external_allow_max_per_run", 1) or 1)
    max_external_per_day = int(_cfg_value("external_allow_max_per_day", 5) or 5)
    external_today = _external_nav_recent_count(db_path, hours=24.0)

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
        if _is_external_url(full, base):
            if external_today >= max_external_per_day:
                continue
            external_candidates.append(full)
            continue
        candidates.append(full)

    if not candidates and not external_candidates:
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

    target = candidates[0] if candidates else None
    if not target and external_candidates and max_external_per_run > 0:
        target = external_candidates[0]
    if not target:
        page._bgl_novelty_done = True  # type: ignore
        return
    is_external = _is_external_url(target, base)
    if is_external and max_external_per_run <= 0:
        page._bgl_novelty_done = True  # type: ignore
        return
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
    if is_external:
        log_event(
            db_path,
            "novel_probe",
            {
                "event_type": "external_nav",
                "route": target,
                "method": "GET",
                "payload": "external_allowlisted_navigation",
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
    scenario_id: Optional[str] = None,
    goal_id: Optional[str] = None,
    goal_name: Optional[str] = None,
):
    scenario_name = scenario_path.stem
    scenario_id_local = ""
    try:
        with open(scenario_path, "r", encoding="utf-8") as f:
            data = yaml.safe_load(f) or {}
        scenario_name = data.get("name", scenario_path.stem)
        scenario_id_local = str(scenario_id or data.get("id") or "")
    except Exception:
        data = None
    if not scenario_id_local:
        scenario_id_local = str(scenario_id or f"{scenario_name}:{int(time.time())}")
    ctx_token = _push_context(
        scenario_id=scenario_id_local,
        scenario_name=str(scenario_name),
        goal_id=goal_id,
        goal_name=goal_name,
    )
    try:
        return await _run_scenario_impl(
            manager,
            page,
            base_url,
            scenario_path,
            keep_open,
            db_path,
            is_last=is_last,
            scenario_id=scenario_id_local,
            goal_id=goal_id,
            goal_name=goal_name,
        )
    finally:
        _pop_context(ctx_token)


async def _run_scenario_impl(
    manager: BrowserManager,
    page,
    base_url: str,
    scenario_path: Path,
    keep_open: bool,
    db_path: Path,
    is_last: bool = False,
    scenario_id: Optional[str] = None,
    goal_id: Optional[str] = None,
    goal_name: Optional[str] = None,
):
    _trace(f"scenario: load {scenario_path}")
    with open(scenario_path, "r", encoding="utf-8") as f:
        data = yaml.safe_load(f) or {}
    steps: List[Dict[str, Any]] = data.get("steps", [])
    name = data.get("name", scenario_path.stem)
    _trace(f"scenario: {name} steps={len(steps)}")
    meta = data.get("meta") or {}
    origin = str(meta.get("origin") or "").lower()
    is_gap = bool(
        name.startswith("gap_")
        or "gap" in origin
        or str(data.get("generated") or "").lower() in ("1", "true", "yes")
    )
    if is_gap:
        try:
            log_event(
                db_path,
                name,
                {
                    "event_type": "gap_scenario_start",
                    "route": data.get("kind") or "ui",
                    "method": "GAP",
                    "payload": {"origin": origin or "gap", "meta": meta},
                    "status": None,
                },
            )
        except Exception:
            pass

    # تأكيد وجود مؤشر الماوس المرئي في الصفحة المشتركة
    await ensure_cursor(page)
    _trace(f"scenario: {name} cursor ensured")
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
    _trace(f"scenario: {name} authority ready")

    print(f"[*] Scenario '{name}' start")
    learn_log = ROOT_DIR / "storage" / "logs" / "learned_events.tsv"
    learn_log.parent.mkdir(parents=True, exist_ok=True)
    # مسار لقطات الأهداف
    explored: set = set()
    steps_since_explore = 0
    exploratory_enabled = os.getenv("BGL_EXPLORATION", "1") == "1"
    stall_count = 0
    last_change_ts = time.time()
    scenario_changed = False
    scenario_started = time.time()
    try:
        stall_threshold = int(_cfg_value("stall_recovery_threshold", 2))
    except Exception:
        stall_threshold = 2
    try:
        idle_recovery_after = float(_cfg_value("idle_recovery_after_sec", 6))
    except Exception:
        idle_recovery_after = 6.0
    idle_recovery_enabled = bool(
        int(os.getenv("BGL_IDLE_RECOVERY", str(_cfg_value("idle_recovery_enabled", 1))))
    )

    async def _idle_recovery(reason: str) -> None:
        nonlocal last_change_ts
        try:
            before = await _dom_state_hash(page)
        except Exception:
            before = ""
        try:
            await exploratory_action(page, motor, explored, name, learn_log, db_path)
        except Exception:
            return
        try:
            after = await _dom_state_hash(page)
        except Exception:
            after = ""
        changed_local = bool(before and after and before != after)
        if changed_local:
            last_change_ts = time.time()
        log_event(
            db_path,
            name,
            {
                "event_type": "idle_recovery",
                "route": page.url if hasattr(page, "url") else "",
                "method": "EXPLORE",
                "payload": json.dumps(
                    {"reason": reason, "changed": changed_local}, ensure_ascii=False
                ),
                "status": 200 if changed_local else None,
            },
        )
        if not changed_local:
            log_event(
                db_path,
                name,
                {
                    "event_type": "gap_deepen",
                    "route": page.url if hasattr(page, "url") else "",
                    "method": "EXPLORE",
                    "payload": reason,
                    "status": None,
                },
            )

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

        def _extract_latency_ms(response) -> Optional[float]:
            timing = None
            try:
                if hasattr(response, "timing"):
                    timing = response.timing
                    if callable(timing):
                        timing = timing()
            except Exception:
                timing = None
            if not isinstance(timing, dict):
                try:
                    req = response.request
                    if hasattr(req, "timing"):
                        timing = req.timing
                        if callable(timing):
                            timing = timing()
                except Exception:
                    timing = None
            if isinstance(timing, dict):
                try:
                    start = timing.get("startTime")
                    end = timing.get("responseEnd") or timing.get("responseStart")
                    if end is None:
                        return None
                    if start is None:
                        return float(end)
                    return max(0.0, float(end) - float(start))
                except Exception:
                    return None
            return None

        async def handle_response(response):
            try:
                req = response.request
                resource_type = ""
                try:
                    resource_type = str(req.resource_type or "")
                except Exception:
                    resource_type = ""
                # Only log meaningful responses to avoid static asset noise.
                if resource_type and resource_type not in ("xhr", "fetch", "document"):
                    return
                latency_ms = _extract_latency_ms(response)
                if response.status >= 400:
                    log_event(
                        db_path,
                        name,
                        {
                            "event_type": "http_error",
                            "route": response.url,
                            "method": req.method,
                            "status": response.status,
                            "latency_ms": latency_ms,
                        },
                    )
                else:
                    log_event(
                        db_path,
                        name,
                        {
                            "event_type": "api_call",
                            "route": response.url,
                            "method": req.method,
                            "status": response.status,
                            "latency_ms": latency_ms,
                        },
                    )
            except Exception:
                return

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

    # Step timeout guard (prevents silent stalls)
    try:
        default_step_timeout = float(
            os.getenv("BGL_STEP_TIMEOUT_SEC", str(_cfg_value("step_timeout_sec", 45)))
        )
    except Exception:
        default_step_timeout = 45.0

    for idx, step in enumerate(steps):
        _trace(f"scenario: {name} step[{idx}] action={step.get('action')} selector={step.get('selector')}")
        # prepend base_url for relative goto
        if step.get("action") == "goto" and step.get("url", "").startswith("/"):
            step = {**step, "url": base_url.rstrip("/") + step["url"]}
        step_id = f"{name}:{idx}"
        attempt = 0
        state_retries = 0
        while True:
            try:
                if page.is_closed():
                    page = await manager.new_page()
                    await ensure_cursor(page)
                    motor = Motor(hand_profile)
                    policy = Policy(motor)
                # Handle modal/loading/error UI states before executing steps.
                try:
                    if step.get("action") not in ("wait",):
                        ui_state_result = await _handle_ui_states(
                            page, db_path, name, reason="before_step"
                        )
                        if ui_state_result and ui_state_result.get("retry") and state_retries < 1:
                            state_retries += 1
                            await page.wait_for_timeout(180)
                            continue
                except Exception:
                    pass
                # إذا مرّ خطوتان دون استكشاف، نفّذ استكشاف إجباري (يمكن تعطيله بـ BGL_EXPLORATION=0)
                if (
                    exploratory_enabled
                    and steps_since_explore >= 2
                    and step.get("action") != "wait"
                ):
                    await exploratory_action(
                        page, motor, explored, name, learn_log, db_path
                    )
                    steps_since_explore = 0
                # Precheck target element state for clearer failure reasons
                try:
                    selector = str(step.get("selector", ""))
                    action = str(step.get("action", ""))
                    if selector and action in ("click", "type", "press"):
                        precheck = await _probe_element_state(page, action, selector)
                        step["_precheck"] = precheck
                        log_event(
                            db_path,
                            name,
                            {
                                "event_type": "ui_precheck",
                                "route": selector,
                                "method": action.upper(),
                                "payload": json.dumps(precheck, ensure_ascii=False),
                                "status": 200 if precheck.get("found") else 404,
                            },
                        )
                        # Attempt self-heal for hidden/blocked elements.
                        if precheck and (
                            not precheck.get("found")
                            or precheck.get("visible") is False
                            or precheck.get("enabled") is False
                            or precheck.get("obscured") is True
                            or precheck.get("disabled") is True
                            or precheck.get("readonly") is True
                        ):
                            heal = await _attempt_self_heal(
                                page,
                                selector,
                                action,
                                precheck,
                                db_path,
                                scenario_name=name,
                            )
                            try:
                                log_event(
                                    db_path,
                                    name,
                                    {
                                        "event_type": "ui_self_heal",
                                        "route": selector,
                                        "method": action.upper(),
                                        "payload": json.dumps(
                                            {
                                                "precheck": precheck,
                                                "post": heal.get("post"),
                                                "actions": heal.get("actions"),
                                                "text_hints": heal.get("text_hints"),
                                                "success": heal.get("success"),
                                            },
                                            ensure_ascii=False,
                                        ),
                                        "status": 200 if heal.get("success") else 409,
                                    },
                                )
                            except Exception:
                                pass
                            if isinstance(heal, dict) and heal.get("post"):
                                step["_precheck"] = heal.get("post")
                except Exception:
                    pass

                # Log step start for attribution
                try:
                    log_event(
                        db_path,
                        name,
                        {
                            "event_type": "scenario_step_start",
                            "route": step.get("url") or step.get("selector"),
                            "method": str(step.get("action", "")).upper(),
                            "step_id": step_id,
                            "source": "scenario",
                            "payload": {"step_index": idx, "step": step},
                        },
                    )
                except Exception:
                    pass

                # Apply per-step timeout if configured (>0)
                step_timeout = step.get("timeout_sec", default_step_timeout)
                try:
                    step_timeout = float(step_timeout)
                except Exception:
                    step_timeout = default_step_timeout
                try:
                    precheck = step.get("_precheck") or {}
                    if precheck and (
                        not precheck.get("found")
                        or precheck.get("visible") is False
                        or precheck.get("enabled") is False
                        or precheck.get("obscured") is True
                        or precheck.get("disabled") is True
                        or precheck.get("readonly") is True
                    ):
                        pre_fail_timeout = float(
                            _cfg_value("precheck_fail_timeout_sec", 8)
                        )
                        if pre_fail_timeout > 0:
                            step_timeout = min(step_timeout, pre_fail_timeout)
                except Exception:
                    pass

                async def _run_step_guarded():
                    return await run_step(
                        page,
                        step,
                        policy,
                        db_path,
                        authority=authority,
                        scenario_name=name,
                    )

                step_result = None
                if step_timeout and step_timeout > 0:
                    try:
                        step_result = await asyncio.wait_for(
                            _run_step_guarded(), timeout=step_timeout
                        )
                    except asyncio.TimeoutError:
                        log_event(
                            db_path,
                            name,
                            {
                                "event_type": "scenario_step_timeout",
                                "route": step.get("url", "") or step.get("selector", ""),
                                "method": str(step.get("action", "")).upper(),
                                "status": None,
                                "payload": json.dumps(
                                    {
                                        "step_index": idx,
                                        "timeout_sec": step_timeout,
                                        "step": step,
                                        "precheck": step.get("_precheck"),
                                    },
                                    ensure_ascii=False,
                                ),
                            },
                        )
                        # If optional, skip; otherwise abort scenario early.
                        if step.get("optional"):
                            break
                        return
                else:
                    step_result = await _run_step_guarded()

                # Log step completion
                try:
                    log_event(
                        db_path,
                        name,
                        {
                            "event_type": "scenario_step_done",
                            "route": step.get("url") or step.get("selector"),
                            "method": str(step.get("action", "")).upper(),
                            "step_id": step_id,
                            "source": "scenario",
                            "payload": {"step_index": idx},
                        },
                    )
                except Exception:
                    pass
                steps_since_explore += 1
                if step_result and isinstance(step_result, dict):
                    if step_result.get("changed"):
                        scenario_changed = True
                        stall_count = 0
                        last_change_ts = time.time()
                    elif step_result.get("unchanged"):
                        stall_count += 1
                        if (
                            idle_recovery_enabled
                            and exploratory_enabled
                            and stall_count >= max(1, stall_threshold)
                        ):
                            if (time.time() - last_change_ts) >= idle_recovery_after:
                                await _idle_recovery(
                                    reason=f"stall_count={stall_count}"
                                )
                                stall_count = 0
                # بعد أي goto أو عند أول خطوة في صفحة جديدة، استكشاف سريع
                if exploratory_enabled and step.get("action") == "goto":
                    await exploratory_action(
                        page, motor, explored, name, learn_log, db_path
                    )
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

    # Enforce semantic change: if none detected, attempt a safe shift and record a gap signal.
    try:
        semantic_changed = _session_semantic_changed(db_path, str(name), scenario_started)
    except Exception:
        semantic_changed = False
    if not semantic_changed and exploratory_enabled:
        try:
            shifted = await _attempt_semantic_shift(page, db_path, name)
            if shifted:
                semantic_changed = True
                scenario_changed = True
        except Exception:
            pass
    if not semantic_changed:
        try:
            log_event(
                db_path,
                name,
                {
                    "event_type": "semantic_delta_missing",
                    "route": page.url if hasattr(page, "url") else "",
                    "method": "SEMANTIC",
                    "payload": {"scenario": name, "reason": "no_semantic_change"},
                    "status": None,
                },
            )
            _write_autonomy_goal(
                db_path,
                goal="gap_deepen",
                payload={
                    "uri": page.url if hasattr(page, "url") else "",
                    "kind": "semantic_delta",
                    "scenario": name,
                    "value": "semantic_delta_missing",
                },
                source="semantic_delta",
                ttl_days=2,
            )
        except Exception:
            pass

    if keep_open and is_last:
        print(
            f"[!] Scenario '{name}' finished. Browser left open for manual review. Close it to continue."
        )
        await page.wait_for_timeout(24 * 60 * 60 * 1000)  # 24h max or until user closes
    else:
        print(f"[+] Scenario '{name}' done")
    if is_gap:
        try:
            route_hint = ""
            selector_hint = ""
            try:
                route_hint = str(meta.get("route") or data.get("route") or "").strip()
            except Exception:
                route_hint = ""
            if not route_hint:
                try:
                    for s in steps:
                        if s.get("action") in ("goto", "request"):
                            route_hint = str(s.get("url") or "").strip()
                            if route_hint:
                                break
                except Exception:
                    route_hint = ""
            if steps:
                try:
                    for s in steps:
                        if s.get("action") in ("click", "type", "press", "hover"):
                            selector_hint = str(s.get("selector") or "").strip()
                            if selector_hint:
                                break
                except Exception:
                    selector_hint = ""
            log_event(
                db_path,
                name,
                {
                    "event_type": "gap_scenario_done",
                    "route": data.get("kind") or "ui",
                    "method": "GAP",
                    "payload": {
                        "origin": origin or "gap",
                        "meta": meta,
                        "changed": bool(scenario_changed),
                        "route": route_hint,
                        "selector": selector_hint,
                    },
                    "status": 200 if scenario_changed else None,
                },
            )
            if selector_hint and _record_exploration_outcome:
                try:
                    _record_exploration_outcome(
                        db_path,
                        selector=selector_hint,
                        href="",
                        route=route_hint,
                        result="changed" if scenario_changed else "no_change",
                        delta_score=1.0 if scenario_changed else -0.2,
                        selector_key=_selector_key_from_selector(selector_hint),
                    )
                except Exception:
                    pass
        except Exception:
            pass


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
        str(s.get("url", "")) for s in steps if s.get("action") in ("goto", "request")
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
    meta = data.get("meta") or {}
    origin = str(meta.get("origin") or "")
    is_gap = (
        str(name).startswith("gap_")
        or "gap" in origin.lower()
        or str(data.get("generated") or "").lower() in ("1", "true", "yes")
        or "/generated/" in str(scenario_path).replace("\\", "/").lower()
    )
    scenario_changed = False
    if is_gap:
        try:
            log_event(
                db_path,
                name,
                {
                    "event_type": "gap_scenario_start",
                    "route": data.get("kind") or "api",
                    "method": "GAP",
                    "payload": {"origin": origin or "gap", "meta": meta},
                    "status": None,
                },
            )
        except Exception:
            pass
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
                    metadata={
                        "scenario": name,
                        "step": step,
                        "method": method,
                        "url": url,
                    },
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
            req = urllib.request.Request(
                url, data=data_bytes, method=method, headers=headers
            )
            with urllib.request.urlopen(
                req, timeout=int(step.get("timeout", 8))
            ) as resp:
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
            scenario_changed = True
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
    if is_gap:
        try:
            route_hint = ""
            selector_hint = ""
            try:
                route_hint = str(meta.get("route") or data.get("route") or "").strip()
            except Exception:
                route_hint = ""
            if not route_hint:
                try:
                    for s in steps:
                        if s.get("action") in ("goto", "request"):
                            route_hint = str(s.get("url") or "").strip()
                            if route_hint:
                                break
                except Exception:
                    route_hint = ""
            if steps:
                try:
                    for s in steps:
                        if s.get("action") in ("click", "type", "press", "hover"):
                            selector_hint = str(s.get("selector") or "").strip()
                            if selector_hint:
                                break
                except Exception:
                    selector_hint = ""
            log_event(
                db_path,
                name,
                {
                    "event_type": "gap_scenario_done",
                    "route": data.get("kind") or "api",
                    "method": "GAP",
                    "payload": {
                        "origin": origin or "gap",
                        "meta": meta,
                        "changed": bool(scenario_changed),
                        "route": route_hint,
                        "selector": selector_hint,
                    },
                    "status": 200 if scenario_changed else None,
                },
            )
        except Exception:
            pass


async def main(
    base_url: str,
    headless: bool,
    keep_open: bool,
    max_pages: int = 3,
    idle_timeout: int = 120,
    include: str | None = None,
    shadow_mode: bool = False,
):
    run_started = time.time()
    _trace("main: start")
    global _CURRENT_RUN_ID
    _CURRENT_RUN_ID = f"run_{int(run_started)}_{os.getpid()}"
    os.environ["BGL_RUN_ID"] = _CURRENT_RUN_ID
    # Guard: لا تُشغّل السيناريوهات إذا كان Production Mode مفعّل
    ensure_dev_mode()
    _trace("main: dev mode ok")
    cfg = load_config(ROOT_DIR)
    ok, reason = _acquire_scenario_lock(cfg)
    if not ok:
        print(f"[!] Scenario runner already active ({reason}); skipping.")
        return
    _trace("main: lock acquired")
    # Apply config defaults for exploration/novelty if env not set
    os.environ.setdefault("BGL_EXPLORATION", str(cfg.get("scenario_exploration", "1")))
    os.environ.setdefault("BGL_NOVELTY_AUTO", str(cfg.get("novelty_auto", "1")))
    os.environ.setdefault("BGL_STORE_UI_SEMANTIC", str(cfg.get("store_ui_semantic", "1")))
    os.environ.setdefault("BGL_STORE_UI_ACTIONS", str(cfg.get("store_ui_actions", "1")))
    os.environ.setdefault("BGL_UI_ACTION_LIMIT", str(cfg.get("ui_action_limit", "80")))
    # Auto refresh route map when source routes change (or missing)
    _auto_reindex_routes(
        ROOT_DIR,
        Path(os.getenv("BGL_SANDBOX_DB", Path(".bgl_core/brain/knowledge.db"))),
    )
    _trace("main: auto_reindex_routes done")

    if not SCENARIOS_DIR.exists():
        print("[!] Scenarios directory missing; nothing to run.")
        return

    scenario_files = sorted(SCENARIOS_DIR.rglob("*.yaml"))
    _trace(f"main: found scenarios={len(scenario_files)}")
    # Prioritize generated gap scenarios to close coverage first.
    def _scenario_priority(path: Path) -> tuple[int, str]:
        try:
            p = str(path).replace("\\", "/").lower()
        except Exception:
            p = str(path)
        if "/generated/" in p:
            return (0, p)
        if "/gaps/" in p:
            return (1, p)
        if "/goals/" in p:
            return (2, p)
        if "/autonomous/" in p:
            return (3, p)
        return (10, p)
    scenario_files = sorted(scenario_files, key=_scenario_priority)
    _trace("main: priority sort complete")
    if include:
        filtered = []
        gap_keep = []
        for p in scenario_files:
            try:
                p_norm = str(p).replace("\\", "/").lower()
                if "/generated/" in p_norm or p.stem.startswith("gap_"):
                    gap_keep.append(p)
                    continue
            except Exception:
                pass
            if include.lower() in p.stem.lower():
                filtered.append(p)
        scenario_files = gap_keep + filtered
        _trace(f"main: include filter applied => {len(scenario_files)}")
    auto_flag = os.getenv("BGL_INCLUDE_AUTONOMOUS")
    if auto_flag is None:
        auto_flag = str(_cfg_value("scenario_include_autonomous", "0"))
    include_autonomous = str(auto_flag) == "1"
    if not include_autonomous:
        scenario_files = [
            p
            for p in scenario_files
            if "autonomous" not in {part.lower() for part in p.parts}
        ]
        _trace(f"main: exclude autonomous => {len(scenario_files)}")
    # Skip API-only scenarios by default unless explicitly included.
    # Allow generated gap scenarios (safe GET) even when API is disabled.
    include_api_flag = os.getenv("BGL_INCLUDE_API")
    if include_api_flag is None:
        include_api_flag = str(cfg.get("scenario_include_api", "0"))
    include_api = str(include_api_flag) == "1"
    if not include_api:
        filtered_api = []
        for p in scenario_files:
            if "api" not in p.stem:
                filtered_api.append(p)
                continue
            try:
                data = yaml.safe_load(p.read_text(encoding="utf-8")) or {}
                name = str(data.get("name") or p.stem)
                meta = data.get("meta") or {}
                origin = str(meta.get("origin") or "")
                is_gap = (
                    name.startswith("gap_")
                    or "gap" in origin.lower()
                    or str(data.get("generated") or "").lower() in ("1", "true", "yes")
                    or "/generated/" in str(p).replace("\\", "/").lower()
                )
                if is_gap:
                    filtered_api.append(p)
            except Exception:
                continue
        scenario_files = filtered_api
        _trace(f"main: exclude api (except gap) => {len(scenario_files)}")

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
    _trace(f"main: filtered => {len(scenario_files)}")

    # Cap scenario batch size to keep diagnostics bounded.
    try:
        scenario_batch_limit = int(
            os.getenv(
                "BGL_SCENARIO_BATCH_LIMIT",
                str(cfg.get("scenario_batch_limit", "40")),
            )
            or 40
        )
    except Exception:
        scenario_batch_limit = 40
    if scenario_batch_limit > 0 and len(scenario_files) > scenario_batch_limit:
        gap_first = []
        rest = []
        for p in scenario_files:
            try:
                p_norm = str(p).replace("\\", "/").lower()
                if "/generated/" in p_norm or p.stem.startswith("gap_"):
                    gap_first.append(p)
                else:
                    rest.append(p)
            except Exception:
                rest.append(p)
        scenario_files = gap_first + rest
        scenario_files = scenario_files[:scenario_batch_limit]
        _trace(f"main: scenario_batch_limit={scenario_batch_limit} => {len(scenario_files)}")

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
    _trace(f"main: autonomous_only={autonomous_only} autonomous_scenario={autonomous_scenario}")

    slow_mo = int(os.getenv("BGL_SLOW_MO_MS", str(cfg.get("slow_mo_ms", 0))))

    extra_headers = {"X-Shadow-Mode": "true"} if shadow_mode else None

    db_path = Path(os.getenv("BGL_SANDBOX_DB", Path(".bgl_core/brain/knowledge.db")))
    _trace(f"main: db_path={db_path}")
    try:
        if start_run:
            start_run(db_path, run_id=_CURRENT_RUN_ID, mode="scenario_runner", started_at=run_started)
            _trace("main: start_run logged")
    except Exception:
        pass
    try:
        log_event(
            db_path,
            "agent_run",
            {
                "event_type": "agent_run_start",
                "payload": json.dumps(
                    {"run_id": _CURRENT_RUN_ID, "mode": "scenario_runner"},
                    ensure_ascii=False,
                ),
            },
        )
        _trace("main: agent_run_start logged")
    except Exception:
        pass
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
        _trace(f"main: api_scenarios={len(api_scenarios)} ui_scenarios={len(ui_scenarios)}")

        # If autonomous_only, skip predefined scenarios but keep autonomous flow.
        if autonomous_only:
            api_scenarios = []
            ui_scenarios = []

        # Run API scenarios (no browser)
        for path in api_scenarios:
            _trace(f"api: run {path}")
            try:
                _refresh_scenario_lock()
            except Exception:
                pass
            await run_api_scenario(base_url, path, db_path)
            try:
                _refresh_scenario_lock()
            except Exception:
                pass
        _trace("api: done")

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
            _trace("ui: browser manager created")
            # إنشاء صفحة واحدة يعاد استخدامها لكل السيناريوهات لمنع فتح نوافذ متعددة
            shared_page = await manager.new_page()
            _trace("ui: new page created")
            await ensure_cursor(shared_page)
            _trace("ui: cursor ensured")
            # Seed long-term goals into short-term autonomy queue
            try:
                if pick_long_term_goals:
                    lt_limit = int(_cfg_value("long_term_goal_limit", 3) or 3)
                    min_pri = float(_cfg_value("long_term_min_priority", 0.25) or 0.25)
                    lt_goals = pick_long_term_goals(
                        db_path, limit=lt_limit, min_priority=min_pri
                    )
                    for g in lt_goals:
                        payload = dict(g.get("payload") or {})
                        if g.get("title") and not payload.get("term"):
                            payload["term"] = g.get("title")
                        payload["long_term_key"] = g.get("goal_key")
                        payload["goal_type"] = g.get("goal")
                        _write_autonomy_goal(
                            db_path,
                            goal="long_term_focus",
                            payload=payload,
                            source="long_term",
                            ttl_days=7,
                        )
                _trace(f"ui: long_term_goals seeded={len(lt_goals) if 'lt_goals' in locals() else 0}")
            except Exception:
                pass
            # Run goal-driven scenarios first (if any)
            goal_limit = int(_cfg_value("autonomy_goal_limit", 6) or 6)
            goals = _read_autonomy_goals(db_path, limit=goal_limit)
            goals = _prioritize_autonomy_goals(goals)
            _trace(f"ui: goals loaded={len(goals)}")
            seen_goal_keys = set()
            seen_goal_types = set()
            single_run_goals = {"ui_self_heal_gap"}
            for g in goals:
                key = (
                    (g.get("goal") or "")
                    + "|"
                    + json.dumps(g.get("payload") or {}, sort_keys=True)
                )
                if key in seen_goal_keys:
                    continue
                seen_goal_keys.add(key)
                goal_name = str(g.get("goal") or "").strip()
                if goal_name in single_run_goals:
                    if goal_name in seen_goal_types:
                        _trace(f"ui: skip goal {goal_name} (already run this cycle)")
                        continue
                    seen_goal_types.add(goal_name)
                _trace(f"ui: run_goal_scenario {g.get('goal')}")
                await run_goal_scenario(manager, shared_page, base_url, db_path, g)
            for idx, path in enumerate(ui_scenarios):
                _trace(f"ui: run_scenario {path}")
                try:
                    _refresh_scenario_lock()
                except Exception:
                    pass
                await run_scenario(
                    manager,
                    shared_page,
                    base_url,
                    path,
                    keep_open if idx == len(ui_scenarios) - 1 else False,
                    db_path,
                    is_last=(idx == len(ui_scenarios) - 1),
                )
                try:
                    _refresh_scenario_lock()
                except Exception:
                    pass
            if autonomous_scenario:
                _trace("ui: run_autonomous_scenario")
                await run_autonomous_scenario(manager, shared_page, base_url, db_path)
            # One novel, safe navigation per run (read-only).
            _trace("ui: run_novel_probe")
            await run_novel_probe(shared_page, base_url, db_path)
            if not keep_open:
                _trace("ui: closing browser manager")
                await manager.close()
            _trace("ui: done")
    finally:
        pass
    # After scenarios, summarize runtime events into experiences
    try:
        import sys

        digest_path = ROOT_DIR / ".bgl_core" / "brain" / "context_digest.py"
        # Avoid cmd.exe quoting pitfalls on Windows; also prevents noisy "filename syntax" errors.
        digest_env = os.environ.copy()
        # Keep post-run digest fast: never auto-apply during scenario execution.
        digest_env.setdefault("BGL_AUTO_APPLY", "0")
        digest_env.setdefault("BGL_AUTO_APPLY_LIMIT", "0")
        digest_env.setdefault("BGL_AUTO_PROMOTE_TO_PROD", "0")
        digest_hours = str(_cfg_value("auto_digest_hours", 12))
        digest_limit = str(_cfg_value("auto_digest_limit", 200))
        subprocess.run(
            [sys.executable, str(digest_path), "--hours", digest_hours, "--limit", digest_limit],
            cwd=ROOT_DIR,
            env=digest_env,
            timeout=30,
        )
        _trace("post: context_digest done")
    except Exception:
        pass

    # Derive outcomes + relations + scores from exploration/runtime signals
    try:
        _derive_outcomes_from_runtime(db_path, since_ts=run_started)
        _derive_outcomes_from_learning(db_path, since_ts=run_started)
        _score_outcomes(db_path, since_ts=run_started, window_sec=300.0)
        _trace("post: outcomes scored")
        try:
            try:
                from .hypothesis import ingest_exploration_outcomes  # type: ignore
            except Exception:
                from hypothesis import ingest_exploration_outcomes  # type: ignore
            ingest_exploration_outcomes(db_path, since_ts=run_started)
            try:
                from .hypothesis import refresh_hypothesis_status  # type: ignore
            except Exception:
                from hypothesis import refresh_hypothesis_status  # type: ignore
            refresh_hypothesis_status(db_path)
        except Exception:
            pass
        _reward_exploration_from_outcomes(db_path, since_ts=run_started)
        _seed_goals_from_outcome_scores(
            db_path,
            since_ts=run_started,
            negative_threshold=float(_cfg_value("gap_score_threshold", -2.0)),
            limit=int(_cfg_value("gap_goal_limit", 10) or 10),
        )
        _trace("post: goals seeded")
    except Exception:
        pass

    # Let exploration drive route index refresh if unknown routes appeared.
    try:
        _maybe_reindex_after_exploration(ROOT_DIR, db_path)
        _trace("post: reindex_after_exploration done")
    except Exception:
        pass

    # After exploration, generate targeted insights (Dream Mode subset)
    try:
        _trigger_dream_from_exploration(db_path)
        _trace("post: dream trigger done")
    except Exception:
        pass

    try:
        log_event(
            db_path,
            "agent_run",
            {
                "event_type": "agent_run_end",
                "payload": json.dumps(
                    {"run_id": _CURRENT_RUN_ID, "mode": "scenario_runner"},
                    ensure_ascii=False,
                ),
            },
        )
        _trace("post: agent_run_end logged")
    except Exception:
        pass
    try:
        if finish_run:
            finish_run(db_path, run_id=_CURRENT_RUN_ID, ended_at=time.time())
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
