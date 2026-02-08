"""
Metrics guard: يقارن ملخص mouse_metrics بحدود مطلوبة.
يُستخدم في CI لإيقاف البناء إذا خرجت القياسات عن النطاق.

متغيرات البيئة للتحكم:
- BGL_TARGET_MOVE_MIN_MS, BGL_TARGET_MOVE_MAX_MS (القيم الافتراضية 2000..6000)
- BGL_REQUIRE_DOM_CHANGE (1/0) يفرض وجود عينات click→DOM
"""

import json
import os
import sys
from pathlib import Path

SUMMARY_FILE = Path("analysis/metrics_summary.json")


def load_summary():
    if not SUMMARY_FILE.exists():
        return {}
    try:
        return json.loads(SUMMARY_FILE.read_text(encoding="utf-8"))
    except Exception:
        return {}


def main():
    if os.getenv("BGL_METRICS_GUARD_BYPASS", "0") == "1":
        print("METRICS_GUARD_BYPASS=1 → skipping metrics enforcement")
        sys.exit(0)

    summary = load_summary()
    overall = summary.get("overall", summary)
    move = overall.get("move_to_click_ms", {})
    dom = overall.get("click_to_dom_ms", {})

    min_ok = float(os.getenv("BGL_TARGET_MOVE_MIN_MS", "2000"))
    max_ok = float(os.getenv("BGL_TARGET_MOVE_MAX_MS", "6000"))
    require_dom = os.getenv("BGL_REQUIRE_DOM_CHANGE", "0") == "1"

    errors = []

    if move:
        avg = move.get("avg")
        if avg is None or avg < min_ok or avg > max_ok:
            errors.append(f"move_to_click avg {avg}ms outside [{min_ok},{max_ok}]")
    else:
        errors.append("no move_to_click data")

    if require_dom:
        count_dom = dom.get("count", 0)
        if count_dom == 0:
            errors.append("no click_to_dom samples while BGL_REQUIRE_DOM_CHANGE=1")

    if errors:
        print("METRICS_GUARD_FAIL:", "; ".join(errors))
        sys.exit(1)
    print("METRICS_GUARD_OK")


if __name__ == "__main__":
    main()
