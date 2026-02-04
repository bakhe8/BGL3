import math
import random
from typing import Tuple

from hand_profile import HandProfile


class MouseState:
    idle = "idle"
    approaching = "approaching"
    at_target = "at_target"
    invalid_target = "invalid_target"


class Motor:
    """
    طبقة الحركة الفيزيائية: تحوّل أمر "اذهب هنا" إلى مسار بشري نسبي.
    """

    def __init__(self, profile: HandProfile):
        self.profile = profile
        self.mouse_state = MouseState.idle
        self.last_pos: Tuple[float, float] | None = None

    async def move_to(self, page, x: float, y: float, danger: bool = False):
        """
        ينفّذ حركة محسوبة مع تسارع/تباطؤ، overshoot بسيط، وجيتّر خفيف.
        """
        try:
            # احصل على آخر موضع معروف أو مركز الشاشة
            if self.last_pos is None:
                pos = await page.evaluate("() => ({ x: window.innerWidth/2, y: window.innerHeight/2 })")
                self.last_pos = (float(pos["x"]), float(pos["y"]))
            sx, sy = self.last_pos
            dx, dy = x - sx, y - sy
            dist = math.hypot(dx, dy)

            if dist < 5:
                self.mouse_state = MouseState.at_target
                # Always return a dict so callers never get None (prevents NoneType.get crashes).
                return {"status": "at_target", "correction": False}

            self.mouse_state = MouseState.approaching

            # overshoot & correction (بشرية)
            overshoot = self.profile.overshoot_px if dist > 120 else 0
            jitter = self.profile.jitter_px

            tx = x + random.uniform(-jitter, jitter)
            ty = y + random.uniform(-jitter, jitter)
            if overshoot:
                angle = math.atan2(dy, dx)
                tx += math.cos(angle) * overshoot
                ty += math.sin(angle) * overshoot

            # عدد الخطوات نسبة للمسافة وقطر الشاشة (يُحسب داخل المتصفح)
            diag = await page.evaluate("() => Math.hypot(window.innerWidth, window.innerHeight)")
            diag = max(diag, 1)
            # 4..10 خطوات حسب نسبة المسافة للقطر
            ratio = dist / diag
            steps = max(4, min(10, int(4 + ratio * 12)))

            # منحنى سرعة بسيط ease-in-out: t^2*(3-2t)
            used_correction = False
            for i in range(1, steps + 1):
                t = i / steps
                ease = t * t * (3 - 2 * t)
                nx = sx + (tx - sx) * ease
                ny = sy + (ty - sy) * ease
                await page.mouse.move(nx, ny, steps=1)
                # زمن خطوة مشتق من المسافة والسرعة الأساسية
                step_time_ms = max(4, int(1000 * (dist / self.profile.base_speed_px_per_s) / steps))
                await page.wait_for_timeout(step_time_ms)

            # تصحيح رجوع للهدف الحقيقي مع جيتّر بسيط
            await page.mouse.move(x, y, steps=1)
            await page.wait_for_timeout(max(6, int(self.profile.hesitation_ms / 2)))
            used_correction = overshoot > 0 or danger

            # hesitation إضافي أمام عناصر خطرة
            if danger:
                await page.wait_for_timeout(self.profile.hesitation_ms)

            self.last_pos = (x, y)
            self.mouse_state = MouseState.at_target
            return {"status": "at_target", "correction": used_correction}
        except Exception:
            self.mouse_state = MouseState.invalid_target
            return {"status": "invalid_target", "correction": False}
