from __future__ import annotations

import json
import re
import sqlite3
from pathlib import Path
from typing import Dict, Any, List


def _find_app_db(root: Path) -> Path | None:
    candidates = [
        root / "storage" / "database" / "app.sqlite",
        root / "storage" / "database.sqlite",
        root / "database" / "database.sqlite",
        root / "database.sqlite",
    ]
    for c in candidates:
        if c.exists():
            return c
    return None


def _read_file(path: Path, limit: int = 4000) -> str:
    try:
        return path.read_text(encoding="utf-8", errors="ignore")[:limit]
    except Exception:
        return ""


def _method_guard(text: str) -> str | None:
    if re.search(r"REQUEST_METHOD'\]\s*!==?\s*'POST'", text):
        return "POST-only"
    if re.search(r"REQUEST_METHOD'\]\s*!==?\s*'GET'", text):
        return "GET-only"
    return None


def _required_fields(text: str) -> List[str]:
    req = re.findall(r"\$required\s*=\s*\[([^\]]+)\]", text)
    if not req:
        return []
    fields = re.findall(r"'([^']+)'", req[0])
    return fields


def _foreign_keys_for(table: str, db_path: Path) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    try:
        conn = sqlite3.connect(str(db_path))
        cur = conn.cursor()
        cur.execute(f"PRAGMA foreign_key_list({table})")
        rows = cur.fetchall()
        for r in rows:
            out.append(
                {
                    "table": table,
                    "from": r[3],
                    "to": r[4],
                    "ref_table": r[2],
                }
            )
        conn.close()
    except Exception:
        return []
    return out


def verify_failure(
    root: Path, uri: str, method: str, api_res: Dict[str, Any]
) -> Dict[str, Any]:
    """
    Build evidence for whether a failure is policy-expected.
    Returns candidate with confidence and evidence.
    """
    evidence: List[str] = []
    confidence = 0.2
    method = (method or "").upper()
    error = str(api_res.get("error") or "")
    body = str(api_res.get("error_body") or "")

    # Read endpoint file
    file_path = root / uri.lstrip("/")
    text = _read_file(file_path)
    guard = _method_guard(text)
    if guard:
        evidence.append(f"method_guard:{guard}")
        if guard == "POST-only" and method == "GET":
            confidence += 0.3
        if guard == "GET-only" and method == "POST":
            confidence += 0.3

    req_fields = _required_fields(text)
    if req_fields:
        evidence.append(f"required_fields:{','.join(req_fields)}")
        if "Bad Request" in error:
            confidence += 0.1

    if re.search(r"foreign key mismatch|FOREIGN KEY", body, re.IGNORECASE) or re.search(
        r"foreign key mismatch|FOREIGN KEY", error, re.IGNORECASE
    ):
        evidence.append("error:foreign_key")
        confidence += 0.3

    if re.search(r"Missing|مطلوب|Invalid", body, re.IGNORECASE) or re.search(
        r"Missing|مطلوب|Invalid", error, re.IGNORECASE
    ):
        evidence.append("error:missing_required")
        confidence += 0.4

    if re.search(r"Method not allowed|405", body, re.IGNORECASE) or re.search(
        r"Method not allowed|405", error, re.IGNORECASE
    ):
        evidence.append("error:method_not_allowed")
        confidence += 0.4

    if re.search(r"not found|غير موجود", body, re.IGNORECASE):
        evidence.append("error:not_found")
        confidence += 0.3

    # DB schema check (best effort)
    db_path = _find_app_db(root)
    if db_path and "guarantee" in uri:
        fks = _foreign_keys_for("guarantees", db_path)
        if fks:
            evidence.append("db:guarantees_fk:" + ",".join(f"{f['from']}->{f['ref_table']}.{f['to']}" for f in fks))
            confidence += 0.2

    # Clamp
    if confidence > 1.0:
        confidence = 1.0

    return {
        "uri": uri,
        "method": method,
        "status": api_res.get("status"),
        "confidence": round(confidence, 2),
        "evidence": evidence,
        "error": error,
        "error_body": body[:300],
    }
