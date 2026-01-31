from pathlib import Path
from patcher import BGLPatcher
import json

ROOT = Path(__file__).parent.parent.parent
patcher = BGLPatcher(ROOT)
test_file = ROOT / "app/Services/NamingViolation.php"

print(f"[*] Attempting to fix naming violation in {test_file.name}")
res = patcher.rename_class(test_file, "NamingViolation", "NamingViolationService")

print(json.dumps(res, indent=2))

if res.get("status") == "success":
    print("\n[*] Verifying file content after patch:")
    with open(test_file, "r") as f:
        print(f.read())
