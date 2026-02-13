from typing import Dict, Any

import time
from motor import Motor, MouseState
from perception import capture_local_context


class Policy:
    """
    طبقة القرار: تأخذ hint (من السيناريو أو من الاستكشاف) وتقرر ماذا تفعل بعد الوصول.
    """

    def __init__(self, motor: Motor):
        self.motor = motor

    async def perform_click(
        self,
        page,
        selector: str,
        danger: bool = False,
        hover_wait_ms: int = 70,
        post_click_ms: int = 150,
        learn_log=None,
        session: str = "",
        screenshot_dir=None,
        log_event_fn=None,
        db_path=None,
    ):
        """
        اقتراب → تقييم موضعي → قرار نقر أو تغيير فرضية.
        """
        # اقترب
        box = None
        try:
            el = await page.query_selector(selector)
            if el:
                box = await el.bounding_box()
        except Exception:
            pass

        if not box:
            # محاولة بديلة: أقرب عنصر قابل للنقر (زر/رابط) قرب آخر موضع للماوس
            alt = await self._find_alternative(page, selector)
            if alt:
                return await self.perform_click(
                    page,
                    alt,
                    danger=danger,
                    hover_wait_ms=hover_wait_ms,
                    post_click_ms=post_click_ms,
                    learn_log=learn_log,
                    session=session + ":alt",
                    screenshot_dir=screenshot_dir,
                    log_event_fn=log_event_fn,
                    db_path=db_path,
                )
            self.motor.mouse_state = MouseState.invalid_target
            return {"status": "invalid_target"}

        cx = box["x"] + box["width"] / 2
        cy = box["y"] + box["height"] / 2
        t_start = time.time()
        move_info = await self.motor.move_to(page, cx, cy, danger=danger)
        if move_info is None:
            # Defensive: Motor.move_to should always return a dict, but keep this to prevent regressions.
            move_info = {"status": "unknown", "correction": False}

        if self.motor.mouse_state != MouseState.at_target:
            return {"status": "invalid_target", "move": move_info}

        # تقييم موضعي
        ctx = await capture_local_context(page, selector, screenshot_dir=screenshot_dir, tag=f"{session}_{selector.replace('#','').replace('.','')}")
        tgt = ctx.get("target") or {}
        if tgt.get("disabled"):
            self.motor.mouse_state = MouseState.invalid_target
            return {"status": "target_disabled", "context": ctx, "move": move_info}

        # ابدأ مراقبة DOM قبل النقر لرصد أي تغيير يحدث فوراً أثناء الضغط
        await self._start_dom_watch(page)

        await page.wait_for_timeout(hover_wait_ms)
        await page.click(selector, timeout=5000)
        await page.wait_for_timeout(post_click_ms)
        self.motor.mouse_state = MouseState.idle
        move_to_click_ms = int((time.time() - t_start) * 1000)
        dom_change_ms = await self._wait_dom_change(page)
        # Log mouse metrics in runtime_events if available
        if log_event_fn and db_path:
            log_event_fn(
                db_path,
                session,
                {
                    "event_type": "mouse_metrics",
                    "route": selector,
                    "method": "CLICK",
                    "payload": f"move_to_click_ms={move_to_click_ms};dom_change_ms={dom_change_ms};correction={move_info.get('correction', False)}",
                },
            )
        # سجل التعلم إن وُجد سجل
        if learn_log:
            try:
                with open(learn_log, "a", encoding="utf-8") as f:
                    f.write(
                        f"{session}\tclick\t{selector}\tmove_correction={move_info.get('correction', False)}\t"
                        f"hand_seed={getattr(self.motor.profile, 'seed', '?')}\tbg={tgt.get('bg','')}\trel={tgt.get('relative','')}\t"
                        f"dom_change_ms={dom_change_ms}\n"
                    )
            except Exception:
                pass
        return {"status": "clicked", "context": ctx, "move": move_info}

    async def perform_goto(self, page, url: str, wait_until: str = "load", post_wait_ms: int = 400):
        try:
            await page.goto(url, wait_until=wait_until)
        except Exception as exc:
            msg = str(exc)
            # Treat download-triggered navigations as a soft success to avoid aborting scenarios.
            if "Download is starting" in msg:
                self.motor.mouse_state = MouseState.idle
                return {"status": "download_started", "error": msg}
            raise
        await page.wait_for_timeout(post_wait_ms)
        self.motor.mouse_state = MouseState.idle
        return {"status": "navigated"}

    async def _start_dom_watch(self, page, timeout_ms: int = 1500):
        """
        يزرع MutationObserver قبل النقر لالتقاط أول تغيير DOM بعد الحدث.
        """
        try:
            await page.evaluate(
                """(timeout) => {
                    const start = performance.now();
                    const state = { changed: false, delta: null, done: false };
                    const timer = setTimeout(() => {
                        if (state.done) return;
                        state.done = true;
                        state.delta = null;
                        state.changed = false;
                    }, timeout);
                    const obs = new MutationObserver(() => {
                        if (state.done) return;
                        state.done = true;
                        state.changed = true;
                        state.delta = Math.round(performance.now() - start);
                        clearTimeout(timer);
                        obs.disconnect();
                    });
                    obs.observe(document.body || document.documentElement, { childList: true, subtree: true, characterData: true, attributes: true });
                    window.__bgl_dom_watch = { state, obs };
                }""",
                timeout_ms,
            )
        except Exception:
            return None

    async def _wait_dom_change(self, page, poll_ms: int = 50, timeout_ms: int = 1500):
        """
        ينتظر نتيجة المراقبة المزروعة مسبقاً ويعيد delta ms أو None.
        """
        try:
            start = time.time()
            while (time.time() - start) * 1000 < timeout_ms:
                result = await page.evaluate(
                    """() => {
                        const w = window.__bgl_dom_watch;
                        if (!w || !w.state || w.state.done === undefined) return {done: true, delta: null};
                        return {done: w.state.done, delta: w.state.delta};
                    }"""
                )
                if result.get("done"):
                    return result.get("delta")
                await page.wait_for_timeout(poll_ms)
            return None
        except Exception:
            return None

    async def _find_alternative(self, page, selector: str):
        """
        يحاول العثور على عنصر بديل قريب من آخر موضع للماوس (زر/رابط) عند فشل الـ selector.
        """
        try:
            # حاول أولاً مطابقة النص/اللون/الموضع النسبي المخزن إذا وجد في الصفحة
            # (يعتمد على آخر لقطة Context تم جمعها)
            # استخدم وصف مبسط بالـ text واللون
            hints = []
            try:
                hints = await page.evaluate(
                    """() => Array.from(document.querySelectorAll('button, a, [role="button"]')).map(el => ({
                        text: (el.innerText||'').trim(),
                        bg: getComputedStyle(el).backgroundColor || '',
                        selector: el.tagName.toLowerCase() === 'a' && el.getAttribute('href')
                            ? 'a[href="'+el.getAttribute('href')+'"]' : null
                    }))"""
                )
            except Exception:
                hints = []
            last = getattr(self.motor, "last_pos", None)
            candidates = await page.query_selector_all("button, a, [role='button'], input[type='button'], input[type='submit']")
            best = None
            best_dist = 1e9
            for el in candidates:
                box = await el.bounding_box()
                if not box:
                    continue
                cx = box["x"] + box["width"] / 2
                cy = box["y"] + box["height"] / 2
                if last:
                    dist = ((cx - last[0]) ** 2 + (cy - last[1]) ** 2) ** 0.5
                else:
                    dist = cy  # fallback
                # مكافأة بسيطة إذا النص مشابه لنص الهدف الأساسي
                score = dist
                base_text = selector
                try:
                    base_text = selector.split('"')[1] if '"' in selector else selector
                except Exception:
                    pass
                t = (await el.inner_text() or "").strip()
                if base_text and t and base_text in t:
                    score *= 0.8
                if dist < best_dist:
                    best_dist = score
                    best = el
            if best:
                return await best.evaluate("e => e.tagName.toLowerCase() === 'a' && e.getAttribute('href') ? 'a[href=\"'+e.getAttribute('href')+'\"]' : null") or selector
        except Exception:
            return None
        return None
