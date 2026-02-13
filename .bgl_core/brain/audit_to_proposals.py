import argparse
import json
import os
import re
import sqlite3
import time
from pathlib import Path
from typing import List, Tuple, Dict, Any


ROOT = Path(__file__).resolve().parents[2]
DB_PATH = ROOT / ".bgl_core" / "brain" / "knowledge.db"
DEFAULT_REPORT = ROOT / "docs" / "brain_functional_audit_report.md"
RUN_AUDIT_PATH = ROOT / ".bgl_core" / "logs" / "run_audit.jsonl"


def _slugify(text: str, max_len: int = 60) -> str:
    slug = re.sub(r"[^a-z0-9]+", "_", text.lower()).strip("_")
    if not slug:
        slug = "item"
    return slug[:max_len]


def _extract_section_items(lines: List[str], heading: str) -> List[str]:
    heading_norm = heading.strip().lower()
    known_section_heads = {"aligned", "gaps", "risks / conflicts"}

    start = None
    for idx, line in enumerate(lines):
        stripped = line.strip()
        normalized = re.sub(r"^#+\s*", "", stripped).strip().lower()
        if normalized == heading_norm:
            start = idx + 1
            break
    if start is None:
        return []

    items: List[str] = []
    for line in lines[start:]:
        stripped = line.strip()
        if not stripped:
            continue
        normalized = re.sub(r"^#+\s*", "", stripped).strip().lower()
        if normalized in known_section_heads and normalized != heading_norm:
            break
        if stripped.startswith("## ") and normalized != heading_norm:
            break

        m = re.match(r"^(\d+)\.\s+(.*)$", stripped)
        if m and m.group(2).strip():
            items.append(m.group(2).strip())
            continue
        if stripped.startswith("- "):
            bullet = stripped[2:].strip()
            if bullet:
                items.append(bullet)
    return items


def _load_from_json(report_path: Path) -> Tuple[List[str], List[str]]:
    try:
        payload = json.loads(report_path.read_text(encoding="utf-8", errors="ignore"))
    except Exception:
        return [], []
    gaps = payload.get("gaps") if isinstance(payload, dict) else None
    risks = payload.get("risks") if isinstance(payload, dict) else None
    if isinstance(gaps, list) and isinstance(risks, list):
        return [str(x).strip() for x in gaps if str(x).strip()], [str(x).strip() for x in risks if str(x).strip()]
    return [], []


def _load_from_markdown(report_path: Path) -> Tuple[List[str], List[str]]:
    lines = report_path.read_text(encoding="utf-8", errors="ignore").splitlines()
    gaps = _extract_section_items(lines, "Gaps")
    risks = _extract_section_items(lines, "Risks / Conflicts")
    return gaps, risks


def _load_items(report_path: Path) -> Tuple[List[str], List[str]]:
    if report_path.suffix.lower() == ".json":
        gaps, risks = _load_from_json(report_path)
        if gaps or risks:
            return gaps, risks
    return _load_from_markdown(report_path)


def _ensure_table(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS agent_proposals (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT UNIQUE,
            description TEXT,
            action TEXT,
            count INTEGER,
            evidence TEXT,
            impact TEXT,
            solution TEXT,
            expectation TEXT
        )
        """
    )


def _insert_proposal(
    conn: sqlite3.Connection,
    name: str,
    description: str,
    action: str,
    evidence: dict,
    impact: str,
    expectation: str,
) -> Tuple[bool, int | None]:
    cur = conn.execute("SELECT id FROM agent_proposals WHERE name = ?", (name,))
    row = cur.fetchone()
    if row:
        return False, int(row[0])
    payload = json.dumps(evidence, ensure_ascii=False)
    conn.execute(
        """
        INSERT INTO agent_proposals
        (name, description, action, count, evidence, impact, solution, expectation)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        """,
        (name, description, action, 1, payload, impact, None, expectation),
    )
    cur = conn.execute("SELECT id FROM agent_proposals WHERE name = ?", (name,))
    row = cur.fetchone()
    return True, int(row[0]) if row else None


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--report", default=str(DEFAULT_REPORT), help="Audit report path")
    parser.add_argument("--dry-run", action="store_true", help="List items without writing DB")
    args = parser.parse_args()

    report_path = Path(args.report)
    if not report_path.exists():
        print(json.dumps({"ok": False, "error": "report_missing", "path": str(report_path)}))
        return

    gaps, risks = _load_items(report_path)

    created = []
    skipped = []

    if not DB_PATH.exists():
        print(json.dumps({"ok": False, "error": "db_missing", "path": str(DB_PATH)}))
        return

    conn = sqlite3.connect(str(DB_PATH))
    try:
        _ensure_table(conn)
        for idx, item in enumerate(gaps, start=1):
            slug = _slugify(item)
            name = f"audit_gap::{slug}"
            desc = f"AUDIT GAP: {item}"
            evidence = {
                "source": "audit_report",
                "kind": "gap",
                "item": item,
                "scope": "system",
                "confidence": 0.8,
                "reason": "audit_gap_detected",
                "recommended_action": "generate_patch_plan",
            }
            impact = "automation_gap"
            expectation = "Generate patch plan to close the gap."
            if args.dry_run:
                created.append({"name": name, "description": desc})
                continue
            ok, pid = _insert_proposal(conn, name, desc, "audit_gap", evidence, impact, expectation)
            if ok:
                created.append({"id": pid, "name": name})
            else:
                skipped.append({"id": pid, "name": name})

        for idx, item in enumerate(risks, start=1):
            slug = _slugify(item)
            name = f"audit_risk::{slug}"
            desc = f"AUDIT RISK: {item}"
            evidence = {
                "source": "audit_report",
                "kind": "risk",
                "item": item,
                "scope": "system",
                "confidence": 0.85,
                "reason": "audit_risk_detected",
                "recommended_action": "generate_patch_plan",
            }
            impact = "risk_conflict"
            expectation = "Generate patch plan to mitigate the risk."
            if args.dry_run:
                created.append({"name": name, "description": desc})
                continue
            ok, pid = _insert_proposal(conn, name, desc, "audit_risk", evidence, impact, expectation)
            if ok:
                created.append({"id": pid, "name": name})
            else:
                skipped.append({"id": pid, "name": name})

        if not args.dry_run:
            conn.commit()
    finally:
        try:
            conn.close()
        except Exception:
            pass

    payload: Dict[str, Any] = {
        "ok": True,
        "report": str(report_path),
        "created": created,
        "skipped": skipped,
        "total_gaps": len(gaps),
        "total_risks": len(risks),
        "ts": time.time(),
    }
    try:
        RUN_AUDIT_PATH.parent.mkdir(parents=True, exist_ok=True)
        run_entry = {
            "timestamp": time.time(),
            "source": "audit_to_proposals",
            "trigger": "auto_audit",
            "report": str(report_path),
            "total_gaps": len(gaps),
            "total_risks": len(risks),
            "created_count": len(created),
            "skipped_count": len(skipped),
            "pid": os.getpid(),
            "run_id": os.getenv("BGL_DIAGNOSTIC_RUN_ID"),
        }
        with RUN_AUDIT_PATH.open("a", encoding="utf-8") as handle:
            handle.write(json.dumps(run_entry, ensure_ascii=False) + "\n")
    except Exception:
        pass

    print(json.dumps(payload, ensure_ascii=False))


if __name__ == "__main__":
    main()
