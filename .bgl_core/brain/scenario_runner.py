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
from route_indexer import LaravelRouteIndexer  # type: ignore

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


async def _dom_state_hash(page) -> str:
    try:
        return await page.evaluate(
            """() => {
                const txt = (document.body && document.body.innerText) ? document.body.innerText.slice(0, 2000) : '';
                return (location.pathname + '|' + location.search + '|' + txt.length);
            }"""
        )
    except Exception:
        return ""


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
                tag = (await el.evaluate("el => (el.tagName || '').toLowerCase()")) or ""
                meta = {
                    "element": el,
                    "tag": tag,
                    "href": await el.get_attribute("href") or "",
                    "selector": _selector_from_element(
                        {
                            "tag": tag,
                            "id": await el.get_attribute("id") or "",
                            "name": await el.get_attribute("name") or "",
                            "classes": await el.get_attribute("class") or "",
                            "href": await el.get_attribute("href") or "",
                            "type": await el.get_attribute("type") or "",
                        }
                    )
                    or "",
                }
                candidate_meta.append(meta)
            except Exception:
                continue

        explored = _load_explored_selectors(ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db")
        candidate_meta.sort(key=lambda m: _rank_exploration_candidate(m, explored), reverse=True)
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
                    if before_hash and after_hash and before_hash == after_hash:
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
                    _record_exploration_outcome(
                        db_path,
                        selector="input[type='search']",
                        href="",
                        route=page.url if hasattr(page, "url") else "",
                        result="search",
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
            if href_norm in ("/", "/index.php", "index.php") or "index.php" in href_norm:
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
                )
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
    is_interactive = action in ("click", "type", "press")
    before_hash = ""
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
        try:
            await page.fill(
                step["selector"], step.get("text", ""), timeout=step.get("timeout", 5000)
            )
        except Exception:
            if not step.get("optional"):
                raise
    elif action == "press":
        await ensure_cursor(page)
        try:
            await page.press(
                step["selector"],
                step.get("key", "Enter"),
                timeout=step.get("timeout", 5000),
            )
        except Exception:
            if not step.get("optional"):
                raise
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
            if before_hash and after_hash and before_hash == after_hash:
                log_event(
                    db_path,
                    step.get("session", ""),
                    {
                        "event_type": "dom_no_change",
                        "route": page.url if hasattr(page, "url") else "",
                        "method": str(action).upper(),
                        "payload": f"selector:{step.get('selector','')}",
                        "status": None,
                    },
                )
        except Exception:
            pass


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


def _ensure_outcomes_tables(db: sqlite3.Connection) -> None:
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS outcomes (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            timestamp REAL NOT NULL,
            source TEXT,
            kind TEXT,
            value TEXT,
            route TEXT,
            payload_json TEXT,
            session TEXT
        )
        """
    )
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS outcome_relations (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            created_at REAL NOT NULL,
            outcome_id_a INTEGER NOT NULL,
            outcome_id_b INTEGER NOT NULL,
            relation TEXT NOT NULL,
            score REAL NOT NULL,
            reason TEXT,
            FOREIGN KEY(outcome_id_a) REFERENCES outcomes(id),
            FOREIGN KEY(outcome_id_b) REFERENCES outcomes(id)
        )
        """
    )
    db.execute(
        """
        CREATE TABLE IF NOT EXISTS outcome_scores (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            outcome_id INTEGER NOT NULL,
            created_at REAL NOT NULL,
            base_score REAL NOT NULL,
            relation_score REAL NOT NULL,
            total_score REAL NOT NULL,
            FOREIGN KEY(outcome_id) REFERENCES outcomes(id)
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
        db = sqlite3.connect(str(db_path))
        _ensure_outcomes_tables(db)
        payload_json = json.dumps(payload or {}, ensure_ascii=False)
        cur = db.execute(
            "INSERT INTO outcomes (timestamp, source, kind, value, route, payload_json, session) VALUES (?, ?, ?, ?, ?, ?, ?)",
            (ts or time.time(), source, kind, value, route, payload_json, session),
        )
        oid = cur.lastrowid
        db.commit()
        db.close()
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
        db = sqlite3.connect(str(db_path))
        _ensure_outcomes_tables(db)
        db.execute(
            "INSERT INTO outcome_relations (created_at, outcome_id_a, outcome_id_b, relation, score, reason) VALUES (?, ?, ?, ?, ?, ?)",
            (time.time(), a_id, b_id, relation, score, reason),
        )
        db.commit()
        db.close()
    except Exception:
        return


def _derive_outcomes_from_runtime(db_path: Path, since_ts: float, limit: int = 400) -> List[int]:
    ids: List[int] = []
    try:
        if not db_path.exists():
            return ids
        db = sqlite3.connect(str(db_path))
        cur = db.cursor()
        rows = cur.execute(
            "SELECT timestamp, event_type, route, method, status, payload, session FROM runtime_events WHERE timestamp >= ? ORDER BY id DESC LIMIT ?",
            (since_ts, int(limit)),
        ).fetchall()
        db.close()
        for ts, event_type, route, method, status, payload, session in rows:
            kind = ""
            value = ""
            source = "runtime"
            if event_type in ("http_error", "network_fail", "console_error"):
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
                kind = "gap"
                value = "filechooser_blocked"
            elif event_type in ("search_no_change", "dom_no_change"):
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
                payload={"event_type": event_type, "method": method, "status": status, "payload": payload},
                session=str(session or ""),
                ts=float(ts or time.time()),
            )
            if oid:
                ids.append(oid)
    except Exception:
        return ids
    return ids


def _derive_outcomes_from_learning(db_path: Path, since_ts: float, limit: int = 120) -> List[int]:
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
            action = parts[2]
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
    )


def _reward_exploration_from_outcomes(db_path: Path, since_ts: float) -> None:
    """
    Positive reward for useful outcomes; penalty for no-effect outcomes.
    """
    try:
        if not db_path.exists():
            return
        db = sqlite3.connect(str(db_path))
        cur = db.cursor()
        rows = cur.execute(
            "SELECT event_type, payload FROM runtime_events WHERE timestamp >= ? AND event_type IN ('dom_no_change','search_no_change','http_error','network_fail','api_call') ORDER BY id DESC LIMIT 200",
            (since_ts,),
        ).fetchall()
        db.close()
        for event_type, payload in rows:
            sel = _last_selector_from_payload(str(payload or ""))
            if event_type in ("dom_no_change", "search_no_change"):
                _apply_exploration_reward(db_path, sel, -0.6)
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
        db = sqlite3.connect(str(db_path))
        _ensure_outcomes_tables(db)
        cur = db.cursor()
        rows = cur.execute(
            "SELECT id, timestamp, kind, value, route FROM outcomes WHERE timestamp >= ? ORDER BY id DESC LIMIT 400",
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
                if (kind_a == "error" and kind_b == "api_result") or (kind_b == "error" and kind_a == "api_result"):
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
                "SELECT COALESCE(SUM(score),0) FROM outcome_relations WHERE outcome_id_a=? OR outcome_id_b=?",
                (oid, oid),
            ).fetchone()[0]
            total = float(base_scores[oid]) + float(rel_sum or 0)
            db.execute(
                "INSERT INTO outcome_scores (outcome_id, created_at, base_score, relation_score, total_score) VALUES (?, ?, ?, ?, ?)",
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
        db = sqlite3.connect(str(db_path))
        _ensure_outcomes_tables(db)
        rows = db.execute(
            """
            SELECT o.id, o.kind, o.value, o.route, o.payload_json, s.total_score
            FROM outcome_scores s
            JOIN outcomes o ON o.id = s.outcome_id
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


def _routes_table_count(db_path: Path) -> int:
    try:
        if not db_path.exists():
            return 0
        db = sqlite3.connect(str(db_path))
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
        db = sqlite3.connect(str(db_path))
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
    missing = _unknown_routes_from_runtime(db_path, limit=int(_cfg_value("routes_refresh_probe_limit", 60) or 60))
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
                    "payload": json.dumps({"missing_routes": missing[:10]}, ensure_ascii=False),
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

def _recent_routes_within_days(db_path: Path, days: int = 7, limit: int = 1500) -> List[str]:
    try:
        if not db_path.exists():
            return []
        cutoff = time.time() - (days * 86400)
        db = sqlite3.connect(str(db_path))
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
    out = []
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
        db = sqlite3.connect(str(db_path))
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
        if str(_cfg_value("dream_mode_on_exploration", "1")) != "1":
            return False
    except Exception:
        pass
    last_path = ROOT_DIR / ".bgl_core" / "logs" / "dream_mode.last"
    min_minutes = int(_cfg_value("dream_mode_min_interval_minutes", 60))
    now = time.time()
    try:
        if last_path.exists() and (now - last_path.stat().st_mtime) < (min_minutes * 60):
            return False
    except Exception:
        pass
    pid_path = ROOT_DIR / ".bgl_core" / "logs" / "dream_mode.pid"
    try:
        if pid_path.exists() and (now - pid_path.stat().st_mtime) < max(min_minutes * 60, 1800):
            return False
    except Exception:
        pass
    return True


def _trigger_dream_from_exploration(db_path: Path) -> None:
    if not _should_trigger_dream():
        return
    targets = _collect_dream_targets(db_path, limit=int(_cfg_value("dream_mode_batch_limit", 24) or 24))
    if not targets:
        return
    started = time.time()
    try:
        import sys
        dream_path = ROOT_DIR / "scripts" / "dream_mode.py"
        max_insights = min(len(targets), int(_cfg_value("dream_mode_max_insights", 24) or 24))
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
        (ROOT_DIR / ".bgl_core" / "logs" / "dream_mode.last").write_text(str(time.time()))
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
            href_base TEXT,
            route TEXT,
            seen_count INTEGER DEFAULT 0,
            last_seen REAL,
            last_result TEXT,
            last_score_delta REAL
        )
        """
    )


def _load_explored_selectors(db_path: Path, limit: int = 2000) -> set:
    try:
        if not db_path.exists():
            return set()
        db = sqlite3.connect(str(db_path))
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


def _record_explored_selector(db_path: Path, selector: str, href: str, tag: str) -> None:
    try:
        if not db_path.exists():
            return
        db = sqlite3.connect(str(db_path))
        _ensure_exploration_table(db)
        db.execute(
            "INSERT INTO exploration_history (selector, href, tag, created_at) VALUES (?, ?, ?, ?)",
            (selector, href, tag, time.time()),
        )
        db.commit()
        db.close()
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
) -> None:
    try:
        if not db_path.exists():
            return
        db = sqlite3.connect(str(db_path))
        _ensure_exploration_novelty_table(db)
        href_base = _href_basename(href or "") if href else ""
        row = db.execute(
            "SELECT id, seen_count FROM exploration_novelty WHERE selector=?",
            (selector,),
        ).fetchone()
        if row:
            rid, sc = row
            sc = int(sc or 0) + 1
            db.execute(
                "UPDATE exploration_novelty SET seen_count=?, last_seen=?, last_result=?, href_base=?, route=?, last_score_delta=? WHERE id=?",
                (sc, time.time(), result, href_base, route, delta_score, rid),
            )
        else:
            db.execute(
                "INSERT INTO exploration_novelty (selector, href_base, route, seen_count, last_seen, last_result, last_score_delta) VALUES (?, ?, ?, ?, ?, ?, ?)",
                (selector, href_base, route, 1, time.time(), result, delta_score),
            )
        db.commit()
        db.close()
    except Exception:
        return


def _novelty_score(db_path: Path, selector: str) -> float:
    try:
        if not db_path.exists() or not selector:
            return 1.0
        db = sqlite3.connect(str(db_path))
        _ensure_exploration_novelty_table(db)
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
        return max(0.3, 1.4 / max(1, seen_count)) + min(1.2, age / 3600.0) + recent_boost
    except Exception:
        return 1.0


def _rank_exploration_candidate(meta: Dict[str, Any], explored: set) -> float:
    tag = str(meta.get("tag") or "").lower()
    selector = str(meta.get("selector") or "")
    href = str(meta.get("href") or "")
    novelty = 1.0 if selector not in explored and href not in explored else 0.2
    novelty += _novelty_score(
        ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db", selector
    )
    tag_bonus = 0.4 if tag in ("button", "a") else 0.1
    return novelty + tag_bonus


def _ensure_autonomy_goals_table(db: sqlite3.Connection) -> None:
    db.execute(
        "CREATE TABLE IF NOT EXISTS autonomy_goals (id INTEGER PRIMARY KEY AUTOINCREMENT, goal TEXT, payload TEXT, source TEXT, created_at REAL, expires_at REAL)"
    )


def _cleanup_autonomy_goals(db: sqlite3.Connection) -> None:
    try:
        db.execute("DELETE FROM autonomy_goals WHERE expires_at IS NOT NULL AND expires_at < ?", (time.time(),))
        db.commit()
    except Exception:
        pass


def _read_autonomy_goals(db_path: Path, limit: int = 8) -> List[Dict[str, Any]]:
    try:
        if not db_path.exists():
            return []
        db = sqlite3.connect(str(db_path))
        _ensure_autonomy_goals_table(db)
        _cleanup_autonomy_goals(db)
        rows = db.execute(
            "SELECT goal, payload, source, created_at, expires_at FROM autonomy_goals ORDER BY created_at DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        db.close()
        out = []
        for goal, payload, source, created_at, expires_at in rows:
            try:
                payload_obj = json.loads(payload) if payload else {}
            except Exception:
                payload_obj = {}
            out.append(
                {
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


def _write_autonomy_goal(db_path: Path, goal: str, payload: Dict[str, Any], source: str, ttl_days: int = 7) -> None:
    try:
        if not db_path.exists():
            return
        db = sqlite3.connect(str(db_path))
        _ensure_autonomy_goals_table(db)
        _cleanup_autonomy_goals(db)
        # Avoid duplicating identical goals
        try:
            payload_hash = hashlib.sha1(json.dumps(payload, sort_keys=True).encode("utf-8")).hexdigest()
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
                existing_hash = hashlib.sha1(json.dumps(existing, sort_keys=True).encode("utf-8")).hexdigest()
            except Exception:
                existing_hash = ""
            if payload_hash and payload_hash == existing_hash:
                db.close()
                return
        expires = time.time() + (ttl_days * 86400)
        db.execute(
            "INSERT INTO autonomy_goals (goal, payload, source, created_at, expires_at) VALUES (?, ?, ?, ?, ?)",
            (goal, json.dumps(payload, ensure_ascii=False), source, time.time(), expires),
        )
        db.commit()
        db.close()
    except Exception:
        return


def _read_latest_delta(db_path: Path) -> Dict[str, Any]:
    try:
        if not db_path.exists():
            return {}
        db = sqlite3.connect(str(db_path))
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


def _read_recent_routes_from_db(db_path: Path, days: int = 7, limit: int = 12) -> List[Dict[str, Any]]:
    try:
        if not db_path.exists():
            return []
        cutoff = time.time() - (days * 86400)
        db = sqlite3.connect(str(db_path))
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

def _recent_error_routes(db_path: Path, limit: int = 6) -> List[str]:
    try:
        if not db_path.exists():
            return []
        db = sqlite3.connect(str(db_path))
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
            payload={"uri": r.get("uri"), "method": r.get("method"), "file": r.get("file_path")},
            source="routes",
            ttl_days=7,
        )
    # Log highlights -> goals
    error_routes = _recent_error_routes(db_path, limit=6)
    for l in _read_log_highlights(limit=8):
        msg = l.get("message") or ""
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
        payload = {"source": l.get("source"), "message": msg}
        if route_hint:
            payload["uri"] = route_hint
        _write_autonomy_goal(
            db_path,
            goal="log_error",
            payload=payload,
            source="logs",
            ttl_days=7,
        )


def _goal_to_scenario(goal: Dict[str, Any], base_url: str) -> Optional[Dict[str, Any]]:
    g = (goal.get("goal") or "").lower()
    payload = goal.get("payload") or {}
    steps: List[Dict[str, Any]] = [{"action": "goto", "url": base_url.rstrip("/") + "/"}]
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
        key = payload.get("key") or ""
        steps.append({"action": "wait", "ms": 600})
        name = "goal_delta_change"
    elif g == "log_error":
        steps.append({"action": "wait", "ms": 600})
        name = "goal_log_error"
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
                    url = base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri)
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
                {"action": "type", "selector": search_selector, "text": search_term, "optional": True},
                {"action": "press", "selector": search_selector, "key": "Enter", "optional": True},
                {"action": "wait", "ms": 800},
            ]
        # Depth escalation: scroll + try switching tabs/filters
        steps += [
            {"action": "scroll", "dy": 700},
            {"action": "click", "selector": "[role='tab']", "optional": True},
            {"action": "click", "selector": "[data-filter]", "optional": True},
            {"action": "click", "selector": "button.filter, .filter button, .filters button", "optional": True},
            {"action": "scroll", "dy": 600},
        ]
        name = "goal_gap_deepen"
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


def _record_goal_strategy_result(db_path: Path, goal: str, route_kind: str, strategy: str, ok: bool) -> None:
    try:
        if not db_path.exists():
            return
        db = sqlite3.connect(str(db_path))
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
                (goal, route_kind, strategy, 1 if ok else 0, 0 if ok else 1, time.time()),
            )
        db.commit()
        db.close()
    except Exception:
        return


def _pick_goal_strategy(db_path: Path, goal: str, route_kind: str, default_strategy: str) -> str:
    try:
        if not db_path.exists():
            return default_strategy
        db = sqlite3.connect(str(db_path))
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


async def run_goal_scenario(
    manager: BrowserManager,
    page,
    base_url: str,
    db_path: Path,
    goal: Dict[str, Any],
):
    scenario = _goal_to_scenario(goal, base_url)
    payload = goal.get("payload") or {}
    uri = payload.get("uri") or payload.get("href") or ""
    route_kind = _goal_route_kind(uri)
    goal_name = (goal.get("goal") or "unknown").lower()

    default_strategy = "api" if route_kind == "api" else "ui"
    strategy = _pick_goal_strategy(db_path, goal_name, route_kind, default_strategy)
    tried = []

    async def _run_ui() -> bool:
        if not scenario:
            return False
        out_dir = SCENARIOS_DIR / "goals"
        out_dir.mkdir(parents=True, exist_ok=True)
        path = out_dir / f"goal_{int(time.time())}.yaml"
        try:
            with path.open("w", encoding="utf-8") as f:
                yaml.safe_dump(scenario, f, sort_keys=False, allow_unicode=True)
        except Exception:
            path.write_text(json.dumps(scenario, ensure_ascii=False, indent=2), encoding="utf-8")
        log_event(
            db_path,
            "autonomy_goal_scenario",
            {
                "event_type": "autonomy_goal_scenario",
                "route": str(path),
                "method": "AUTO",
                "payload": json.dumps(goal, ensure_ascii=False),
                "status": 200,
            },
        )
        try:
            await run_scenario(
                manager,
                page,
                base_url,
                path,
                keep_open=False,
                db_path=db_path,
                is_last=False,
            )
            return True
        except Exception:
            return False

    def _run_api() -> bool:
        if not uri:
            return False
        if uri.startswith("http"):
            url = uri
        else:
            url = base_url.rstrip("/") + (uri if uri.startswith("/") else "/" + uri)
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
    recent_week_routes: List[str],
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
    recent_set = set(recent_week_routes or recent_routes or [])
    fresh = [c.get("selector") for c in candidates if c.get("selector") and c.get("selector") not in recent_set]
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
    recent_week_routes = _recent_routes_within_days(db_path, days=7, limit=1200)
    insight_names = _load_insight_basenames()
    gap_candidates = []
    for c in candidates:
        href_base = _href_basename(c.get("href"))
        if href_base and href_base not in insight_names:
            gap_candidates.append(href_base)
    goals = _read_autonomy_goals(db_path, limit=8)
    # Shuffle candidates to avoid deterministic repetition
    random.shuffle(candidates)
    # Soft-bias candidates using goals (do not block open exploration)
    if goals:
        goal_hrefs = set()
        for g in goals:
            href = (g.get("payload") or {}).get("href")
            if href:
                goal_hrefs.add(str(href).lower())
        if goal_hrefs:
            preferred = []
            others = []
            for c in candidates:
                href = str(c.get("href") or "").lower()
                if href and href in goal_hrefs:
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
- Prefer selectors that are not used in the last 7 days.
- Prefer hrefs whose filename is missing in auto_insights (gap_candidates).
- Prefer goal targets in autonomy_goals when available, but do not ignore open exploration.

Context JSON:
{json.dumps({"base_url": base_url, "available_uploads": uploads, "recent_routes": recent_routes, "recent_routes_7d": recent_week_routes, "gap_candidates": gap_candidates, "autonomy_goals": goals, "candidates": candidates}, ensure_ascii=False)}
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
        steps = _fallback_autonomous_steps(candidates, recent_routes, recent_week_routes, uploads, allow_upload)
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

    # Seed goals from system signals (logs/snapshots/routes) without blocking open exploration.
    _seed_goals_from_system_signals(db_path)

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
                    await exploratory_action(page, motor, explored, name, learn_log, db_path)
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
                    await exploratory_action(page, motor, explored, name, learn_log, db_path)
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
    run_started = time.time()
    # Guard: لا تُشغّل السيناريوهات إذا كان Production Mode مفعّل
    ensure_dev_mode()
    cfg = load_config(ROOT_DIR)
    # Apply config defaults for exploration/novelty if env not set
    os.environ.setdefault("BGL_EXPLORATION", str(cfg.get("scenario_exploration", "1")))
    os.environ.setdefault("BGL_NOVELTY_AUTO", str(cfg.get("novelty_auto", "1")))
    # Auto refresh route map when source routes change (or missing)
    _auto_reindex_routes(ROOT_DIR, Path(os.getenv("BGL_SANDBOX_DB", Path(".bgl_core/brain/knowledge.db"))))

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
            # Run goal-driven scenarios first (if any)
            goals = _read_autonomy_goals(db_path, limit=6)
            seen_goal_keys = set()
            for g in goals:
                key = (g.get("goal") or "") + "|" + json.dumps(g.get("payload") or {}, sort_keys=True)
                if key in seen_goal_keys:
                    continue
                seen_goal_keys.add(key)
                await run_goal_scenario(manager, shared_page, base_url, db_path, g)
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

    # Derive outcomes + relations + scores from exploration/runtime signals
    try:
        _derive_outcomes_from_runtime(db_path, since_ts=run_started)
        _derive_outcomes_from_learning(db_path, since_ts=run_started)
        _score_outcomes(db_path, since_ts=run_started, window_sec=300.0)
        _reward_exploration_from_outcomes(db_path, since_ts=run_started)
        _seed_goals_from_outcome_scores(
            db_path,
            since_ts=run_started,
            negative_threshold=float(_cfg_value("gap_score_threshold", -2.0)),
            limit=int(_cfg_value("gap_goal_limit", 10) or 10),
        )
    except Exception:
        pass

    # Let exploration drive route index refresh if unknown routes appeared.
    try:
        _maybe_reindex_after_exploration(ROOT_DIR, db_path)
    except Exception:
        pass

    # After exploration, generate targeted insights (Dream Mode subset)
    try:
        _trigger_dream_from_exploration(db_path)
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
