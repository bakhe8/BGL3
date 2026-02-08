from __future__ import annotations

import hashlib
import json
import time
import sqlite3
import os
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

try:
    from .config_loader import load_config  # type: ignore
    from .self_policy import load_self_policy, save_self_policy  # type: ignore
except Exception:
    try:
        from config_loader import load_config  # type: ignore
    except Exception:
        load_config = None  # type: ignore
    try:
        from self_policy import load_self_policy, save_self_policy  # type: ignore
    except Exception:
        load_self_policy = None  # type: ignore
        save_self_policy = None  # type: ignore


def _connect(db_path: Path) -> sqlite3.Connection:
    conn = sqlite3.connect(str(db_path), timeout=30.0)
    conn.row_factory = sqlite3.Row
    try:
        conn.execute("PRAGMA journal_mode=WAL;")
    except Exception:
        pass
    return conn


def _ensure_tables(conn: sqlite3.Connection) -> None:
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS learning_events (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            fingerprint TEXT UNIQUE,
            created_at REAL NOT NULL,
            source TEXT,
            event_type TEXT,
            item_key TEXT,
            status TEXT,
            confidence REAL,
            detail_json TEXT
        )
        """
    )
    conn.execute(
        "CREATE INDEX IF NOT EXISTS idx_learning_events_time ON learning_events(created_at DESC)"
    )


def _fingerprint(*parts: str) -> str:
    base = "|".join([str(p or "").strip().lower() for p in parts])
    return hashlib.sha1(base.encode("utf-8")).hexdigest()


def _safe_json(obj: Any) -> str:
    try:
        return json.dumps(obj, ensure_ascii=False)
    except Exception:
        return "{}"


def _root_from_db(db_path: Path) -> Path:
    try:
        return db_path.parent.parent.parent
    except Exception:
        return Path(".").resolve()


def _cfg_number(env_key: str, cfg: Dict[str, Any], key: str, default):
    env_val = os.getenv(env_key)
    if env_val is not None:
        try:
            return type(default)(env_val)
        except Exception:
            return default
    try:
        return type(default)(cfg.get(key, default))
    except Exception:
        return default


def _cfg_flag(env_key: str, cfg: Dict[str, Any], key: str, default: bool) -> bool:
    env_val = os.getenv(env_key)
    if env_val is not None:
        return str(env_val).strip() == "1"
    try:
        val = cfg.get(key, default)
        if isinstance(val, (int, float)):
            return float(val) != 0.0
        return str(val).strip() == "1"
    except Exception:
        return bool(default)


def _normalize_scope(scope_val: Any) -> str:
    if scope_val is None:
        return ""
    if isinstance(scope_val, (list, tuple)):
        return ",".join([str(s) for s in scope_val if s])
    if isinstance(scope_val, dict):
        return json.dumps(scope_val, ensure_ascii=False)
    s = str(scope_val).strip()
    if s.startswith("[") or s.startswith("{"):
        try:
            parsed = json.loads(s)
            if isinstance(parsed, list):
                return ",".join([str(p) for p in parsed if p])
            if isinstance(parsed, dict):
                return json.dumps(parsed, ensure_ascii=False)
        except Exception:
            return s
    return s


def _external_dependency_patterns() -> List[str]:
    return [
        "database connection timeout",
        "sqlstate",
        "pdoexception",
        "mysql",
        "connection refused",
        "econnrefused",
        "too many connections",
        "connection timeout",
        "db connection timeout",
    ]


def _scan_logs_for_external_dependency(
    root_dir: Path, patterns: List[str], limit: int = 200
) -> Dict[str, Any]:
    out = {"count": 0, "last_ts": 0.0, "last_message": "", "source": ""}
    sources = [
        ("backend", root_dir / "storage" / "logs" / "laravel.log"),
        ("backend", root_dir / "storage" / "logs" / "app.log"),
    ]
    for name, path in sources:
        if not path.exists():
            continue
        try:
            lines = path.read_text(encoding="utf-8", errors="ignore").splitlines()
        except Exception:
            continue
        try:
            mtime = float(path.stat().st_mtime)
        except Exception:
            mtime = 0.0
        for line in reversed(lines[-limit:]):
            msg = str(line or "").strip()
            if not msg:
                continue
            low = msg.lower()
            if any(p in low for p in patterns):
                out["count"] += 1
                if mtime >= float(out["last_ts"] or 0):
                    out["last_ts"] = mtime
                    out["last_message"] = msg[:220]
                    out["source"] = name
    return out


def _recent_external_dependency(
    db_path: Path, minutes: int = 30, root_dir: Optional[Path] = None
) -> Dict[str, Any]:
    out = {"count": 0, "last_ts": 0.0, "last_message": "", "active": False, "source": ""}
    try:
        if db_path.exists():
            db = sqlite3.connect(str(db_path), timeout=30.0)
            db.execute("PRAGMA journal_mode=WAL;")
            cutoff = time.time() - (minutes * 60)
            rows = db.execute(
                """
                SELECT timestamp, payload
                FROM runtime_events
                WHERE timestamp >= ? AND event_type='log_highlight'
                ORDER BY id DESC
                LIMIT 200
                """,
                (cutoff,),
            ).fetchall()
            db.close()
            patterns = _external_dependency_patterns()
            for ts, payload in rows:
                try:
                    if isinstance(payload, str):
                        obj = json.loads(payload)
                    else:
                        obj = payload or {}
                except Exception:
                    obj = {"message": str(payload or "")}
                msg = str((obj or {}).get("message") or "").lower()
                if not msg:
                    continue
                if any(p in msg for p in patterns):
                    out["count"] += 1
                    if float(ts or 0) >= float(out["last_ts"] or 0):
                        out["last_ts"] = float(ts or 0)
                        out["last_message"] = str((obj or {}).get("message") or "")[:220]
                        out["source"] = "runtime_events"
    except Exception:
        pass
    if out["count"] == 0 and root_dir:
        try:
            log_hit = _scan_logs_for_external_dependency(root_dir, _external_dependency_patterns())
            if int(log_hit.get("count") or 0) > 0:
                out.update(log_hit)
        except Exception:
            pass
    out["active"] = int(out.get("count") or 0) > 0
    return out


def apply_external_dependency_fallback(
    root_dir: Path,
    db_path: Path,
    *,
    ttl_minutes: int = 30,
    min_count: int = 1,
) -> Dict[str, Any]:
    """
    When external dependency issues (e.g., DB timeouts) are detected, add a short-lived
    fallback rule to block writes until the dependency stabilizes.
    """
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}
    if load_self_policy is None or save_self_policy is None:
        return {"ok": False, "error": "self_policy_unavailable"}

    cfg = {}
    if load_config is not None:
        try:
            cfg = load_config(root_dir) or {}
        except Exception:
            cfg = {}

    window_minutes = int(
        _cfg_number(
            "BGL_EXTERNAL_DEP_WINDOW_MINUTES",
            cfg,
            "external_dependency_window_minutes",
            30,
        )
    )
    ttl_minutes = int(
        _cfg_number(
            "BGL_EXTERNAL_DEP_FALLBACK_MINUTES",
            cfg,
            "external_dependency_fallback_minutes",
            ttl_minutes,
        )
    )
    min_count = int(
        _cfg_number(
            "BGL_EXTERNAL_DEP_MIN_COUNT",
            cfg,
            "external_dependency_min_count",
            min_count,
        )
    )

    detected = _recent_external_dependency(db_path, minutes=window_minutes, root_dir=root_dir)
    if int(detected.get("count") or 0) < max(1, min_count):
        return {"ok": True, "active": False, "detected": detected}

    policy = load_self_policy(root_dir)
    rules = policy.get("fallback_rules") or []
    if not isinstance(rules, list):
        rules = []

    now = time.time()
    key = "external_dependency::*"
    existing = None
    for rule in rules:
        if isinstance(rule, dict) and str(rule.get("key") or "") == key:
            existing = rule
            break

    payload = {
        "key": key,
        "operation": "*",
        "scope": "",
        "action": "block",
        "blocked_rate": 1.0,
        "count": int(detected.get("count") or 1),
        "allowed": 0,
        "blocked": int(detected.get("count") or 1),
        "last_status": "blocked_external_dependency",
        "last_mode": "",
        "last_source": str(detected.get("source") or ""),
        "source": "external_dependency",
        "reason": "external_dependency",
        "last_message": str(detected.get("last_message") or "")[:220],
        "created_at": now if not existing else existing.get("created_at", now),
        "updated_at": now,
        "expires_at": now + float(ttl_minutes) * 60.0 if ttl_minutes > 0 else None,
    }
    if existing:
        existing.update(payload)
    else:
        rules.append(payload)

    policy["fallback_rules"] = rules
    policy["last_updated"] = time.time()
    history = policy.get("history") or []
    if not isinstance(history, list):
        history = []
    history.append(
        {
            "ts": time.time(),
            "changes": ["fallback_rules:+external_dependency"],
            "context": {"rule": payload, "detected": detected},
            "source": "external_dependency",
        }
    )
    policy["history"] = history[-30:]
    save_self_policy(root_dir, policy)

    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        fp = _fingerprint("external_dependency", key, str(int(payload["updated_at"])))
        conn.execute(
            """
            INSERT OR IGNORE INTO learning_events
            (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """,
            (
                fp,
                float(payload["updated_at"]),
                "external_dependency",
                "fallback_rule",
                key,
                "block",
                None,
                _safe_json(payload),
            ),
        )
        conn.commit()
        conn.close()
    except Exception:
        pass

    return {"ok": True, "active": True, "detected": detected, "rule": payload}


def ingest_learned_events(db_path: Path, limit: int = 500) -> int:
    if not db_path.exists():
        return 0
    root = _root_from_db(db_path)
    log_path = root / "storage" / "logs" / "learned_events.tsv"
    if not log_path.exists():
        return 0
    try:
        lines = log_path.read_text(encoding="utf-8", errors="ignore").splitlines()
        if not lines:
            return 0
        lines = lines[-limit:]
        conn = _connect(db_path)
        _ensure_tables(conn)
        inserted = 0
        for line in lines:
            if "\t" not in line:
                continue
            parts = line.split("\t")
            if not parts:
                continue
            try:
                ts = float(parts[0])
            except Exception:
                ts = time.time()
            session = parts[1] if len(parts) > 1 else ""
            event_type = parts[2] if len(parts) > 2 else "learned"
            detail = parts[3] if len(parts) > 3 else ""
            item_key = f"{event_type}:{detail}" if detail else event_type
            fp = _fingerprint("learned_events_tsv", item_key, str(ts))
            payload = {"session": session, "detail": detail, "raw": line}
            try:
                conn.execute(
                    """
                    INSERT INTO learning_events
                    (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        fp,
                        ts,
                        "learned_events_tsv",
                        event_type,
                        item_key,
                        "observed",
                        None,
                        _safe_json(payload),
                    ),
                )
                inserted += 1
            except Exception:
                # likely duplicate
                continue
        conn.commit()
        conn.close()
        return inserted
    except Exception:
        return 0


def ingest_learning_confirmations(db_path: Path, limit: int = 200) -> int:
    if not db_path.exists():
        return 0
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        rows = conn.execute(
            """
            SELECT item_key, item_type, action, notes, timestamp
            FROM learning_confirmations
            ORDER BY timestamp DESC
            LIMIT ?
            """,
            (int(limit),),
        ).fetchall()
        inserted = 0
        for r in rows:
            ts = float(r["timestamp"] or time.time())
            item_key = str(r["item_key"] or "")
            item_type = str(r["item_type"] or "")
            action = str(r["action"] or "")
            notes = str(r["notes"] or "")
            fp = _fingerprint("learning_confirmations", item_key, action, str(ts))
            payload = {"item_type": item_type, "notes": notes}
            try:
                conn.execute(
                    """
                    INSERT INTO learning_events
                    (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                    """,
                    (
                        fp,
                        ts,
                        "learning_confirmations",
                        "confirmation",
                        item_key,
                        action,
                        None,
                        _safe_json(payload),
                    ),
                )
                inserted += 1
            except Exception:
                continue
        conn.commit()
        conn.close()
        return inserted
    except Exception:
        return 0


def list_learning_events(db_path: Path, limit: int = 8) -> List[Dict[str, Any]]:
    if not db_path.exists():
        return []
    try:
        conn = _connect(db_path)
        _ensure_tables(conn)
        rows = conn.execute(
            "SELECT * FROM learning_events ORDER BY created_at DESC LIMIT ?",
            (int(limit),),
        ).fetchall()
        conn.close()
        out: List[Dict[str, Any]] = []
        for r in rows:
            item = dict(r)
            try:
                item["detail"] = json.loads(item.get("detail_json") or "{}")
            except Exception:
                item["detail"] = {}
            out.append(item)
        return out
    except Exception:
        return []


def update_fallback_rules_from_prod_ops(
    root_dir: Path,
    db_path: Path,
    *,
    lookback_hours: int = 24,
    min_samples: int = 3,
    block_rate_threshold: float = 0.4,
    expire_days: int = 7,
) -> Dict[str, Any]:
    """
    Derive fallback rules from prod_operations log and store them in self_policy.
    Rules are used to block or require extra caution on repeated blocked operations.
    """
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}
    if load_self_policy is None or save_self_policy is None:
        return {"ok": False, "error": "self_policy_unavailable"}

    cfg = {}
    if load_config is not None:
        try:
            cfg = load_config(root_dir) or {}
        except Exception:
            cfg = {}
    enabled = _cfg_flag("BGL_FALLBACK_RULES_ENABLED", cfg, "fallback_rules_enabled", True)
    if not enabled:
        return {"ok": False, "error": "disabled"}

    lookback_hours = int(
        _cfg_number("BGL_FALLBACK_LOOKBACK_HOURS", cfg, "fallback_rules_lookback_hours", lookback_hours)
    )
    min_samples = int(
        _cfg_number("BGL_FALLBACK_MIN_SAMPLES", cfg, "fallback_rules_min_samples", min_samples)
    )
    block_rate_threshold = float(
        _cfg_number("BGL_FALLBACK_BLOCK_RATE", cfg, "fallback_rules_block_rate", block_rate_threshold)
    )
    expire_days = int(
        _cfg_number("BGL_FALLBACK_EXPIRE_DAYS", cfg, "fallback_rules_expire_days", expire_days)
    )
    full_scan = _cfg_flag("BGL_PROD_OPS_FULL", cfg, "prod_ops_full", False)

    conn = _connect(db_path)
    _ensure_tables(conn)
    try:
        has_table = conn.execute(
            "SELECT name FROM sqlite_master WHERE type='table' AND name='prod_operations'"
        ).fetchone()
        if not has_table:
            conn.close()
            return {"ok": False, "error": "prod_operations_missing"}
    except Exception:
        conn.close()
        return {"ok": False, "error": "prod_operations_check_failed"}

    cutoff = 0.0
    if not full_scan and lookback_hours > 0:
        cutoff = time.time() - float(lookback_hours) * 3600.0

    rows = conn.execute(
        """
        SELECT timestamp, status, operation, scope, payload_json, execution_mode, source
        FROM prod_operations
        WHERE timestamp >= ?
        ORDER BY timestamp DESC
        """,
        (cutoff,),
    ).fetchall()

    grouped: Dict[str, Dict[str, Any]] = {}
    for r in rows:
        operation = str(r["operation"] or "")
        scope_val = r["scope"]
        if not scope_val and r["payload_json"]:
            try:
                scope_val = json.loads(r["payload_json"]).get("scope")
            except Exception:
                scope_val = None
        scope_str = _normalize_scope(scope_val)
        key = f"{operation}::{scope_str}"
        bucket = grouped.setdefault(
            key,
            {
                "operation": operation,
                "scope": scope_str,
                "count": 0,
                "allowed": 0,
                "blocked": 0,
                "last_status": "",
                "last_ts": 0.0,
                "last_mode": "",
                "last_source": "",
            },
        )
        bucket["count"] += 1
        status = str(r["status"] or "")
        if status == "allowed":
            bucket["allowed"] += 1
        elif status.startswith("blocked"):
            bucket["blocked"] += 1
        ts = float(r["timestamp"] or 0)
        if ts >= float(bucket["last_ts"] or 0):
            bucket["last_ts"] = ts
            bucket["last_status"] = status
            bucket["last_mode"] = str(r["execution_mode"] or "")
            bucket["last_source"] = str(r["source"] or "")

    policy = load_self_policy(root_dir)
    rules = policy.get("fallback_rules") or []
    if not isinstance(rules, list):
        rules = []

    now = time.time()
    # purge expired
    fresh_rules = []
    for rule in rules:
        if not isinstance(rule, dict):
            continue
        exp = rule.get("expires_at")
        try:
            if exp and float(exp) < now:
                continue
        except Exception:
            pass
        fresh_rules.append(rule)
    rules = fresh_rules

    created: List[Dict[str, Any]] = []
    updated: List[Dict[str, Any]] = []

    for _, data in grouped.items():
        count = int(data.get("count") or 0)
        if count < min_samples:
            continue
        blocked = int(data.get("blocked") or 0)
        allowed = int(data.get("allowed") or 0)
        blocked_rate = blocked / max(1, count)
        action = None
        if blocked_rate >= block_rate_threshold or str(data.get("last_status") or "").startswith("blocked"):
            action = "block"
        elif blocked_rate >= max(0.1, block_rate_threshold / 2):
            action = "require_human"
        if not action:
            continue
        key = f"{data.get('operation')}::{data.get('scope')}"
        existing = None
        for rule in rules:
            if not isinstance(rule, dict):
                continue
            if str(rule.get("key") or "") == key:
                existing = rule
                break
        payload = {
            "key": key,
            "operation": data.get("operation") or "",
            "scope": data.get("scope") or "",
            "action": action,
            "blocked_rate": round(blocked_rate, 3),
            "count": count,
            "allowed": allowed,
            "blocked": blocked,
            "last_status": data.get("last_status") or "",
            "last_mode": data.get("last_mode") or "",
            "last_source": data.get("last_source") or "",
            "source": "prod_operations",
            "created_at": now if not existing else existing.get("created_at", now),
            "updated_at": now,
            "expires_at": now + float(expire_days) * 86400.0 if expire_days > 0 else None,
        }
        if existing:
            existing.update(payload)
            updated.append(existing)
        else:
            rules.append(payload)
            created.append(payload)

        # Log into learning_events
        try:
            fp = _fingerprint("fallback_rule", key, str(int(payload["updated_at"])))
            conn.execute(
                """
                INSERT OR IGNORE INTO learning_events
                (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    fp,
                    float(payload["updated_at"]),
                    "fallback_rules",
                    "fallback_rule",
                    key,
                    action,
                    None,
                    _safe_json(payload),
                ),
            )
        except Exception:
            pass

    policy["fallback_rules"] = rules
    policy["last_updated"] = time.time()
    history = policy.get("history") or []
    if not isinstance(history, list):
        history = []
    if created or updated:
        history.append(
            {
                "ts": time.time(),
                "changes": [f"fallback_rules:+{len(created)}/~{len(updated)}"],
                "context": {"created": created, "updated": updated},
                "source": "fallback_rules_prod_ops",
            }
        )
        policy["history"] = history[-30:]
    save_self_policy(root_dir, policy)
    conn.commit()
    conn.close()

    return {
        "ok": True,
        "created": created,
        "updated": updated,
        "total_rules": len(rules),
    }


def update_fallback_rules_from_outcomes(
    root_dir: Path,
    db_path: Path,
    *,
    lookback_hours: int = 24,
    min_samples: int = 4,
    fail_rate_threshold: float = 0.45,
    block_rate_threshold: float = 0.75,
    expire_days: int = 7,
) -> Dict[str, Any]:
    """
    Derive fallback rules from outcomes (execution failures) and store them in self_policy.
    Intended to reduce repeated failures and improve success rate.
    """
    if not db_path.exists():
        return {"ok": False, "error": "db_missing"}
    if load_self_policy is None or save_self_policy is None:
        return {"ok": False, "error": "self_policy_unavailable"}

    cfg = {}
    if load_config is not None:
        try:
            cfg = load_config(root_dir) or {}
        except Exception:
            cfg = {}
    enabled = _cfg_flag("BGL_FALLBACK_OUTCOMES_ENABLED", cfg, "fallback_outcomes_enabled", True)
    if not enabled:
        return {"ok": False, "error": "disabled"}

    lookback_hours = int(
        _cfg_number("BGL_FALLBACK_OUTCOMES_HOURS", cfg, "fallback_outcomes_hours", lookback_hours)
    )
    min_samples = int(
        _cfg_number("BGL_FALLBACK_OUTCOMES_MIN_SAMPLES", cfg, "fallback_outcomes_min_samples", min_samples)
    )
    fail_rate_threshold = float(
        _cfg_number("BGL_FALLBACK_OUTCOMES_FAIL_RATE", cfg, "fallback_outcomes_fail_rate", fail_rate_threshold)
    )
    block_rate_threshold = float(
        _cfg_number("BGL_FALLBACK_OUTCOMES_BLOCK_RATE", cfg, "fallback_outcomes_block_rate", block_rate_threshold)
    )
    expire_days = int(
        _cfg_number("BGL_FALLBACK_OUTCOMES_EXPIRE_DAYS", cfg, "fallback_outcomes_expire_days", expire_days)
    )

    cutoff = 0.0
    if lookback_hours > 0:
        cutoff = time.time() - float(lookback_hours) * 3600.0

    conn = _connect(db_path)
    _ensure_tables(conn)
    rows = []
    try:
        rows = conn.execute(
            """
            SELECT i.intent as operation, o.result as result, o.notes as notes, o.timestamp as ts
            FROM outcomes o
            JOIN decisions d ON d.id = o.decision_id
            JOIN intents i ON i.id = d.intent_id
            WHERE strftime('%s', o.timestamp) >= ?
            ORDER BY o.timestamp DESC
            """,
            (int(cutoff),),
        ).fetchall()
    except Exception:
        rows = []

    # operations we do not want to block by default
    exclude_ops = {"run_scenarios", "reindex.full"}

    def _is_fail(res: str) -> bool:
        res = str(res or "").lower()
        if res in ("success", "success_sandbox", "success_direct", "success_with_override"):
            return False
        if res in ("false_positive", "proposed", "skipped", "deferred"):
            return False
        return True

    grouped: Dict[str, Dict[str, Any]] = {}
    for r in rows:
        op = str(r["operation"] or "")
        if not op:
            continue
        # Normalize to prefix (e.g., proposal.apply|26 -> proposal.apply)
        prefix = op.split("|")[0].strip()
        if prefix in exclude_ops:
            continue
        try:
            from .failure_classifier import extract_failure_class, classify_failure  # type: ignore
        except Exception:
            try:
                from failure_classifier import extract_failure_class, classify_failure  # type: ignore
            except Exception:
                extract_failure_class = None  # type: ignore
                classify_failure = None  # type: ignore
        failure_class = ""
        if extract_failure_class and isinstance(r["notes"], str):
            failure_class = extract_failure_class(r["notes"])
        if not failure_class and classify_failure:
            failure_class = classify_failure(r["result"], r["notes"])
        if not failure_class:
            failure_class = "unknown"
        op_key = f"{prefix}::{failure_class}"
        bucket = grouped.setdefault(
            op_key,
            {
                "operation": prefix,
                "failure_class": failure_class,
                "count": 0,
                "fail": 0,
                "last_notes": "",
                "last_ts": 0.0,
            },
        )
        bucket["count"] += 1
        if _is_fail(r["result"]):
            bucket["fail"] += 1
        ts = 0.0
        try:
            ts = time.mktime(time.strptime(str(r["ts"]), "%Y-%m-%d %H:%M:%S"))
        except Exception:
            try:
                ts = float(r["ts"] or 0)
            except Exception:
                ts = 0.0
        if ts >= float(bucket["last_ts"] or 0):
            bucket["last_ts"] = ts
            bucket["last_notes"] = str(r["notes"] or "")

    policy = load_self_policy(root_dir)
    rules = policy.get("fallback_rules") or []
    if not isinstance(rules, list):
        rules = []

    # purge expired
    now = time.time()
    fresh_rules = []
    for rule in rules:
        if not isinstance(rule, dict):
            continue
        exp = rule.get("expires_at")
        try:
            if exp and float(exp) < now:
                continue
        except Exception:
            pass
        fresh_rules.append(rule)
    rules = fresh_rules

    created: List[Dict[str, Any]] = []
    updated: List[Dict[str, Any]] = []

    for _, data in grouped.items():
        count = int(data.get("count") or 0)
        if count < min_samples:
            continue
        fail = int(data.get("fail") or 0)
        fail_rate = fail / max(1, count)
        failure_class = str(data.get("failure_class") or "")
        action = None
        severe_classes = {"write_engine", "validation", "permission", "schema"}
        soft_classes = {"timeout", "network", "llm", "browser"}
        if failure_class in severe_classes and fail_rate >= max(0.2, fail_rate_threshold / 2):
            action = "block"
        elif failure_class in soft_classes and fail_rate >= fail_rate_threshold:
            action = "require_human"
        elif fail_rate >= block_rate_threshold:
            action = "block"
        elif fail_rate >= fail_rate_threshold:
            action = "require_human"
        if not action:
            continue
        key = f"outcomes::{data.get('operation')}::{failure_class}"
        existing = None
        for rule in rules:
            if not isinstance(rule, dict):
                continue
            if str(rule.get("key") or "") == key:
                existing = rule
                break
        payload = {
            "key": key,
            "operation": f"{data.get('operation')}*",
            "scope": "",
            "action": action,
            "fail_rate": round(fail_rate, 3),
            "count": count,
            "fail": fail,
            "last_notes": data.get("last_notes") or "",
            "failure_class": failure_class,
            "source": "outcomes",
            "created_at": now if not existing else existing.get("created_at", now),
            "updated_at": now,
            "expires_at": now + float(expire_days) * 86400.0 if expire_days > 0 else None,
        }
        if existing:
            existing.update(payload)
            updated.append(existing)
        else:
            rules.append(payload)
            created.append(payload)

        try:
            fp = _fingerprint("fallback_outcome_rule", key, str(int(payload["updated_at"])))
            conn.execute(
                """
                INSERT OR IGNORE INTO learning_events
                (fingerprint, created_at, source, event_type, item_key, status, confidence, detail_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    fp,
                    float(payload["updated_at"]),
                    "fallback_rules",
                    "fallback_rule",
                    key,
                    action,
                    None,
                    _safe_json(payload),
                ),
            )
        except Exception:
            pass

    policy["fallback_rules"] = rules
    policy["last_updated"] = time.time()
    history = policy.get("history") or []
    if not isinstance(history, list):
        history = []
    if created or updated:
        history.append(
            {
                "ts": time.time(),
                "changes": [f"fallback_rules_outcomes:+{len(created)}/~{len(updated)}"],
                "context": {"created": created, "updated": updated},
                "source": "fallback_rules_outcomes",
            }
        )
        policy["history"] = history[-30:]
    save_self_policy(root_dir, policy)
    conn.commit()
    conn.close()

    return {
        "ok": True,
        "created": created,
        "updated": updated,
        "total_rules": len(rules),
    }
