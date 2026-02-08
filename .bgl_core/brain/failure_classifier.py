from __future__ import annotations

import re
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, List


def extract_failure_class(notes: str) -> str:
    if not notes:
        return ""
    m = re.search(r"failure_class[:=]([a-z0-9_\\-]+)", str(notes), re.IGNORECASE)
    if m:
        return m.group(1).strip().lower()
    return ""


def classify_failure(result: str, notes: str = "") -> str:
    """
    Heuristic failure taxonomy. Returns a normalized class name.
    """
    res = str(result or "").lower()
    text = str(notes or "").lower()

    if res in ("blocked", "skipped"):
        return "blocked"
    if "write engine" in text or "write_engine" in text:
        return "write_engine"
    if "plan error" in text or "plan_error" in text:
        return "plan_error"
    if "validation" in text or "422" in text:
        return "validation"
    if "permission" in text or "not allowed" in text or "forbidden" in text:
        return "permission"
    if "timeout" in text or "timed out" in text:
        return "timeout"
    if "network" in text or "connection" in text or "http_error" in text:
        return "network"
    if "llm" in text or "too many requests" in text or "429" in text:
        return "llm"
    if "playwright" in text or "browser" in text:
        return "browser"
    if "schema" in text or "db" in text or "database" in text:
        return "schema"
    if res in ("fail", "error"):
        return "generic_fail"
    return "unknown"


def summarize_failure_taxonomy(
    db_path: Path,
    *,
    lookback_hours: int = 24,
    limit: int = 300,
) -> Dict[str, Any]:
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}
    cutoff = time.time() - float(lookback_hours) * 3600.0 if lookback_hours > 0 else 0.0

    try:
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        rows = conn.execute(
            """
            SELECT i.intent as operation, o.result as result, o.notes as notes, o.timestamp as ts
            FROM outcomes o
            JOIN decisions d ON d.id = o.decision_id
            JOIN intents i ON i.id = d.intent_id
            WHERE strftime('%s', o.timestamp) >= ?
            ORDER BY o.timestamp DESC
            LIMIT ?
            """,
            (int(cutoff), int(limit)),
        ).fetchall()
        conn.close()
    except Exception as e:
        return {"ok": False, "error": f"db_query_failed:{e}"}

    def _is_fail(res: str) -> bool:
        r = str(res or "").lower()
        if r in ("success", "success_sandbox", "success_direct", "success_with_override"):
            return False
        if r in ("false_positive", "proposed", "skipped", "deferred"):
            return False
        return True

    by_class: Dict[str, int] = {}
    by_operation: Dict[str, int] = {}
    samples: List[Dict[str, Any]] = []
    total = 0
    for r in rows:
        if not _is_fail(r["result"]):
            continue
        total += 1
        op = str(r["operation"] or "")
        op_prefix = op.split("|")[0] if op else ""
        cls = extract_failure_class(r["notes"] or "")
        if not cls:
            cls = classify_failure(r["result"], r["notes"] or "")
        by_class[cls] = by_class.get(cls, 0) + 1
        if op_prefix:
            by_operation[op_prefix] = by_operation.get(op_prefix, 0) + 1
        if len(samples) < 8:
            samples.append(
                {
                    "operation": op_prefix or op,
                    "result": r["result"],
                    "class": cls,
                    "notes": str(r["notes"] or "")[:200],
                }
            )

    # sort summary
    top_classes = sorted(by_class.items(), key=lambda kv: kv[1], reverse=True)[:8]
    top_ops = sorted(by_operation.items(), key=lambda kv: kv[1], reverse=True)[:8]
    return {
        "ok": True,
        "lookback_hours": lookback_hours,
        "total_failures": total,
        "by_class": dict(top_classes),
        "by_operation": dict(top_ops),
        "samples": samples,
    }
