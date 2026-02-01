import random
from dataclasses import dataclass
import os


@dataclass
class HandProfile:
    """
    يمثل "هوية يد" ثابتة طوال الجلسة: سرعات وجيتّر وتصحيحات بسيطة.
    تتغير فقط بين الجلسات.
    """
    base_speed_px_per_s: float
    jitter_px: float
    overshoot_px: float
    hesitation_ms: int
    seed: int

    @staticmethod
    def generate(seed: int | None = None) -> "HandProfile":
        if seed is None:
            seed = random.randint(0, 10_000_000)
        if seed is not None:
            random.seed(seed)
        # يمكن ضبط القيم عبر متغيرات بيئة بدون تغيير الكود
        base_speed_min = float(os.getenv("BGL_BASE_SPEED_MIN", "800"))
        base_speed_max = float(os.getenv("BGL_BASE_SPEED_MAX", "1400"))
        jitter_min = float(os.getenv("BGL_JITTER_MIN", "0.5"))
        jitter_max = float(os.getenv("BGL_JITTER_MAX", "2.0"))
        overshoot_min = float(os.getenv("BGL_OVERSHOOT_MIN", "2.0"))
        overshoot_max = float(os.getenv("BGL_OVERSHOOT_MAX", "6.0"))
        hesitation_min = int(os.getenv("BGL_HESITATION_MIN_MS", "40"))
        hesitation_max = int(os.getenv("BGL_HESITATION_MAX_MS", "120"))
        # قيم مشتقة نسبياً، يمكن التحكم بنطاقها فقط
        base_speed = random.uniform(base_speed_min, base_speed_max)
        jitter = random.uniform(jitter_min, jitter_max)
        overshoot = random.uniform(overshoot_min, overshoot_max)
        hesitation = random.randint(hesitation_min, hesitation_max)
        return HandProfile(base_speed, jitter, overshoot, hesitation, seed)
