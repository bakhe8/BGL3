import re
import sys
from pathlib import Path

"""
تحقق بسيط يمنع استدعاء حركات الماوس المباشرة خارج طبقة Motor/Policy.
يفشل إذا وجد page.mouse.move أو page.click أو page.hover في ملفات brain/*
باستثناء motor.py و policy.py وملف التحقق نفسه.
"""

ALLOWED = {"motor.py", "policy.py", "check_mouse_layer.py"}
PATTERNS = [r"page\.mouse\.move", r"page\.click", r"page\.hover"]


def main() -> int:
    root = Path(__file__).parent
    violations = []
    for path in root.glob("*.py"):
        if path.name in ALLOWED:
            continue
        text = path.read_text(encoding="utf-8")
        for pat in PATTERNS:
            for m in re.finditer(pat, text):
                line = text.count("\n", 0, m.start()) + 1
                violations.append(f"{path.name}:{line}: direct mouse call '{pat}'")
    if violations:
        print("Mouse layer violations detected:")
        for v in violations:
            print(" -", v)
        return 1
    print("Mouse layer check passed.")
    return 0


if __name__ == "__main__":
    sys.exit(main())
