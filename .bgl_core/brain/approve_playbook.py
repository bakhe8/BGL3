import sys
import shutil
from pathlib import Path
import yaml
from datetime import date


def append_rule(runtime_file: Path, playbook_id: str, description: str):
    if not runtime_file.exists():
        return
    data = yaml.safe_load(runtime_file.read_text(encoding="utf-8")) or {}
    rules = data.get("rules", [])
    rule_id = f"RS_{playbook_id}"
    if any(r.get("id") == rule_id for r in rules):
        return
    rules.append(
        {
            "id": rule_id,
            "name": f"Auto rule for {playbook_id}",
            "description": description,
            "action": "WARN",
            "rationale": "Auto-generated from approved playbook",
            "scope": ["*"],
            "origin": "auto_generated",
            "created": date.today().isoformat(),
        }
    )
    data["rules"] = rules
    runtime_file.write_text(yaml.safe_dump(data, allow_unicode=True, sort_keys=False), encoding="utf-8")


def approve(playbook_id: str, project_root: Path):
    brain = project_root / ".bgl_core" / "brain"
    src = brain / "playbooks_proposed" / f"{playbook_id}.md"
    dst = brain / "playbooks" / f"{playbook_id}.md"
    if not src.exists():
        print(f"[!] proposed playbook not found: {src}")
        return False
    shutil.move(src, dst)
    # append rule
    runtime_file = brain / "runtime_safety.yml"
    append_rule(runtime_file, playbook_id, f"Ensure playbook {playbook_id} is applied")
    return True


if __name__ == "__main__":
    if len(sys.argv) < 2:
        print("Usage: python approve_playbook.py PB_ID")
        sys.exit(1)
    root = Path(__file__).resolve().parents[2]
    pid = sys.argv[1]
    ok = approve(pid, root)
    sys.exit(0 if ok else 1)
