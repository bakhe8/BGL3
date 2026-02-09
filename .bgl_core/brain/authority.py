from __future__ import annotations

"""
authority.py
------------
Single source of truth for:
- action taxonomy (side-effect classification),
- human approval queue (agent_permissions),
- decision logging (intents/decisions/outcomes in knowledge.db),
- deterministic gating for WRITE_* actions.

Design goals:
- Keep exploration flexible: OBSERVE/PROBE/PROPOSE should not be blocked.
- Make execution trustworthy: any WRITE_* requires explicit approval by default.
- Avoid drift: other modules should delegate here instead of re-implementing gates.
"""

import json
import os
import sqlite3
import time
from pathlib import Path
from typing import Any, Dict, Optional, List, Tuple

try:
    from .brain_types import ActionRequest, ActionKind, GateResult  # type: ignore
    from .config_loader import load_config  # type: ignore
    from .decision_db import init_db, insert_intent, insert_decision, insert_outcome, record_decision_trace  # type: ignore
    from .decision_engine import decide  # type: ignore
    from .observations import latest_env_snapshot  # type: ignore
    from .self_policy import load_self_policy  # type: ignore
except Exception:
    from brain_types import ActionRequest, ActionKind, GateResult
    from config_loader import load_config
    from decision_db import init_db, insert_intent, insert_decision, insert_outcome, record_decision_trace
    from decision_engine import decide
    from observations import latest_env_snapshot
    from self_policy import load_self_policy


def _ensure_agent_permissions_table(conn: sqlite3.Connection):
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS agent_permissions (
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          operation TEXT,
          command TEXT,
          status TEXT DEFAULT 'PENDING',
          timestamp REAL
        )
        """
    )
    conn.commit()


def _safe_json(val: Any) -> str:
    try:
        return json.dumps(val, ensure_ascii=False)
    except Exception:
        return "{}"


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


def _compact_fallback_context(rule: Optional[Dict[str, Any]]) -> Dict[str, Any]:
    if not isinstance(rule, dict):
        return {}
    temporal = rule.get("temporal_profile") or {}
    temporal_summary: Dict[str, Any] = {}
    if isinstance(temporal, dict):
        for key in ("stateful", "startup_exec", "accumulates", "first_request_writes"):
            if key in temporal:
                temporal_summary[key] = temporal.get(key)
    ctx: Dict[str, Any] = {
        "key": rule.get("key"),
        "source": rule.get("source"),
        "action": rule.get("action"),
        "intent": rule.get("intent"),
        "risk": rule.get("risk"),
        "reason": rule.get("reason"),
        "repeat_count": rule.get("repeat_count"),
        "tests_stale": rule.get("tests_stale"),
        "scope": rule.get("scope"),
        "operation": rule.get("operation"),
    }
    if temporal_summary:
        ctx["temporal"] = temporal_summary
    log_hints = rule.get("log_hints")
    if isinstance(log_hints, list) and log_hints:
        ctx["log_hints"] = log_hints[:2]
    return {k: v for k, v in ctx.items() if v not in (None, "", [], {})}


class Authority:
    def __init__(self, root_dir: Path):
        self.root_dir = root_dir
        self.config = load_config(root_dir) or {}
        self.db_path = root_dir / ".bgl_core" / "brain" / "knowledge.db"
        self.decision_schema = root_dir / ".bgl_core" / "brain" / "decision_schema.sql"
        if self.decision_schema.exists():
            init_db(self.db_path, self.decision_schema)
        # execution_mode: sandbox | direct | auto_trial (historical)
        env_mode = os.getenv("BGL_EXECUTION_MODE")
        self.execution_mode = str(env_mode or self.config.get("execution_mode", "sandbox")).lower()
        # Cache decisions to avoid repeated LLM calls / repeated decision row spam
        # during a single verification run (master_verify can call the gate many times).
        self._decision_cache: Dict[str, Tuple[float, GateResult]] = {}
        self._decision_cache_ttl_s: float = float(
            os.getenv("BGL_DECISION_CACHE_TTL", "600") or 600
        )
        self._runtime_contracts_cache: Dict[str, Any] = {
            "ts": 0.0,
            "routes": {},
            "files": {},
        }
        self._runtime_contracts_ttl_s: float = float(
            os.getenv("BGL_RUNTIME_CONTRACTS_TTL", "600") or 600
        )

    def _cache_key(self, request: ActionRequest) -> str:
        """
        Stable fingerprint for deduping decisions within a run.
        Keep it coarse: operation + kind (+ a few metadata keys) is enough.
        """
        try:
            meta = request.metadata or {}
        except Exception:
            meta = {}
        # Keep only a small subset of metadata to avoid huge keys / instability.
        meta_small = {}
        for k in ("policy_key", "scenario", "url", "method"):
            if k in meta:
                meta_small[k] = meta.get(k)
        return _safe_json(
            {
                "kind": request.kind.value,
                "operation": request.operation,
                "scope": request.scope,
                "meta": meta_small,
            }
        )

    def _cache_get(self, key: str) -> Optional[GateResult]:
        try:
            ts, res = self._decision_cache.get(key, (0.0, None))  # type: ignore
            if not res:
                return None
            if (time.time() - float(ts)) > float(self._decision_cache_ttl_s):
                return None
            return res
        except Exception:
            return None

    def _cache_set(self, key: str, res: GateResult) -> None:
        try:
            self._decision_cache[key] = (time.time(), res)
        except Exception:
            pass

    def _normalize_route(self, route: str) -> str:
        if not route:
            return ""
        r = str(route).strip()
        if r.startswith("http"):
            try:
                r = "/" + r.split("://", 1)[1].split("/", 1)[1]
            except Exception:
                pass
        if not r.startswith("/"):
            r = "/" + r
        return r

    def _normalize_path(self, path: str) -> str:
        if not path:
            return ""
        p = str(path).replace("\\", "/").lstrip("./")
        return p

    def _load_runtime_contracts(self) -> Dict[str, Any]:
        now = time.time()
        if (now - float(self._runtime_contracts_cache.get("ts") or 0)) < float(
            self._runtime_contracts_ttl_s
        ):
            return self._runtime_contracts_cache

        contracts_path = self.root_dir / "analysis" / "code_contracts.json"
        if not contracts_path.exists():
            try:
                from .code_contracts import build_code_contracts  # type: ignore
            except Exception:
                try:
                    from code_contracts import build_code_contracts  # type: ignore
                except Exception:
                    build_code_contracts = None  # type: ignore
            if build_code_contracts:
                try:
                    build_code_contracts(self.root_dir)
                except Exception:
                    pass

        routes: Dict[str, Dict[str, Any]] = {}
        files: Dict[str, Dict[str, Any]] = {}
        try:
            data = json.loads(contracts_path.read_text(encoding="utf-8"))
            for c in data.get("contracts", []):
                if not isinstance(c, dict):
                    continue
                runtime = c.get("runtime") or {}
                if not runtime:
                    continue
                kind = str(c.get("kind") or "")
                if kind == "api":
                    route = self._normalize_route(str(c.get("route") or ""))
                    if route:
                        routes[route] = runtime
                    file_path = self._normalize_path(str(c.get("file") or ""))
                    if file_path:
                        files[file_path] = runtime
                elif kind == "php_module":
                    file_path = self._normalize_path(str(c.get("file") or ""))
                    if file_path:
                        files[file_path] = runtime
        except Exception:
            routes = {}
            files = {}

        self._runtime_contracts_cache = {
            "ts": now,
            "routes": routes,
            "files": files,
        }
        return self._runtime_contracts_cache

    def _runtime_hint(self, scope: List[str], metadata: Dict[str, Any]) -> Dict[str, Any]:
        cache = self._load_runtime_contracts()
        routes = cache.get("routes") or {}
        files = cache.get("files") or {}

        scope_items = []
        for item in scope or []:
            p = self._normalize_path(str(item))
            if p:
                scope_items.append(p)

        route_candidates: List[str] = []
        for key in ("route", "url", "uri", "target"):
            val = metadata.get(key)
            if not val:
                continue
            route = self._normalize_route(str(val))
            if route:
                route_candidates.append(route)

        matched: List[Tuple[str, Dict[str, Any]]] = []
        for p in scope_items:
            if p in files:
                matched.append((f"file:{p}", files[p]))
            else:
                # try suffix match
                for fpath, stats in files.items():
                    if fpath.endswith(p):
                        matched.append((f"file:{fpath}", stats))
                        break

        for r in route_candidates:
            if r in routes:
                matched.append((f"route:{r}", routes[r]))

        if not matched:
            return {"has_evidence": False, "event_count": 0, "error_count": 0}

        total_events = 0
        total_errors = 0
        latency_sum = 0.0
        last_ts = 0.0
        last_error = ""
        last_error_ts = 0.0
        sources: List[str] = []
        for source, stats in matched:
            sources.append(source)
            try:
                cnt = int(stats.get("event_count") or 0)
            except Exception:
                cnt = 0
            total_events += cnt
            try:
                total_errors += int(stats.get("error_count") or 0)
            except Exception:
                pass
            try:
                lat = float(stats.get("avg_latency_ms") or 0.0)
                latency_sum += lat * max(cnt, 1)
            except Exception:
                pass
            try:
                ts = float(stats.get("last_ts") or 0.0)
                if ts > last_ts:
                    last_ts = ts
            except Exception:
                pass
            try:
                err_ts = float(stats.get("last_error_ts") or 0.0)
                if err_ts > last_error_ts:
                    last_error_ts = err_ts
                    last_error = str(stats.get("last_error") or "")
            except Exception:
                pass

        avg_latency = round(latency_sum / max(total_events, 1), 2)
        error_rate = round(total_errors / max(total_events, 1), 3)
        return {
            "has_evidence": True,
            "event_count": total_events,
            "error_count": total_errors,
            "error_rate": error_rate,
            "avg_latency_ms": avg_latency,
            "last_ts": last_ts,
            "last_error": last_error,
            "last_error_ts": last_error_ts,
            "sources": sources[:20],
        }

    def _eligible_for_direct(self, required_successes: int = 5) -> bool:
        """
        Auto-trial policy: allow "direct" mode only after N consecutive successes.
        Matches the historical behavior used by patcher/guardian.
        """
        if not self.db_path.exists():
            return False
        try:
            conn = sqlite3.connect(str(self.db_path))
            cur = conn.cursor()
            cur.execute(
                """
                SELECT result FROM outcomes
                WHERE result IN ('success','fail','blocked','mode_direct')
                ORDER BY id DESC LIMIT ?
                """,
                (required_successes,),
            )
            rows = [r[0] for r in cur.fetchall()]
            conn.close()
            if len(rows) < required_successes:
                return False
            return all(r == "success" for r in rows)
        except Exception:
            return False

    def effective_execution_mode(self) -> str:
        """
        Compute effective execution mode:
        - sandbox: never allow WRITE_PROD
        - direct: allow WRITE_PROD with explicit approval
        - auto_trial: becomes direct only when eligible; otherwise sandbox
        - autonomous: allow all writes without approval (unsafe)
        """
        if self.execution_mode == "autonomous":
            return "autonomous"
        if self.execution_mode == "auto_trial":
            return "direct" if self._eligible_for_direct() else "sandbox"
        return self.execution_mode

    def _autonomous_enabled(self) -> bool:
        return self.effective_execution_mode() == "autonomous" or os.getenv("BGL_AUTONOMOUS", "0") == "1"

    def _approvals_enabled(self) -> bool:
        env_flag = os.getenv("BGL_APPROVALS_ENABLED")
        if env_flag is not None:
            return str(env_flag).strip().lower() in ("1", "true", "yes", "on")
        cfg_val = self.config.get("approvals_enabled", 1)
        if isinstance(cfg_val, bool):
            return cfg_val
        if isinstance(cfg_val, (int, float)):
            return float(cfg_val) != 0.0
        return str(cfg_val).strip().lower() in ("1", "true", "yes", "on")

    def _force_no_human_approvals(self) -> bool:
        env_flag = os.getenv("BGL_FORCE_NO_HUMAN_APPROVALS")
        if env_flag is not None:
            return str(env_flag).strip().lower() in ("1", "true", "yes", "on")
        cfg_val = self.config.get("force_no_human_approvals", 0)
        if isinstance(cfg_val, bool):
            return cfg_val
        if isinstance(cfg_val, (int, float)):
            return float(cfg_val) != 0.0
        return str(cfg_val).strip().lower() in ("1", "true", "yes", "on")

    def _fallback_block_disabled(self) -> bool:
        env_flag = os.getenv("BGL_DISABLE_FALLBACK_BLOCKS")
        if env_flag is not None:
            return str(env_flag).strip().lower() in ("1", "true", "yes", "on")
        cfg_val = self.config.get("fallback_block_disabled", 0)
        if isinstance(cfg_val, bool):
            return cfg_val
        if isinstance(cfg_val, (int, float)):
            return float(cfg_val) != 0.0
        return str(cfg_val).strip().lower() in ("1", "true", "yes", "on")

    def _fallback_require_human_disabled(self) -> bool:
        env_flag = os.getenv("BGL_DISABLE_FALLBACK_REQUIRE_HUMAN")
        if env_flag is not None:
            return str(env_flag).strip().lower() in ("1", "true", "yes", "on")
        cfg_val = self.config.get("fallback_require_human_disabled", 0)
        if isinstance(cfg_val, bool):
            return cfg_val
        if isinstance(cfg_val, (int, float)):
            return float(cfg_val) != 0.0
        return str(cfg_val).strip().lower() in ("1", "true", "yes", "on")

    def _fallback_guard(self, request: ActionRequest) -> Optional[Dict[str, Any]]:
        """
        Check self_policy fallback_rules derived from prod_operations.
        Returns the matched rule or None.
        """
        if self._fallback_block_disabled() and self._fallback_require_human_disabled():
            return None
        try:
            env_flag = os.getenv("BGL_FALLBACK_GUARD_ENABLED")
            if env_flag is not None:
                if str(env_flag).strip().lower() in ("0", "false", "no", "off"):
                    return None
            cfg_val = self.config.get("fallback_guard_enabled", 1)
            if isinstance(cfg_val, bool) and not cfg_val:
                return None
            if isinstance(cfg_val, (int, float)) and float(cfg_val) == 0.0:
                return None
            if isinstance(cfg_val, str) and cfg_val.strip().lower() in ("0", "false", "no", "off"):
                return None
        except Exception:
            pass
        try:
            policy = load_self_policy(self.root_dir)
        except Exception:
            policy = {}
        rules = policy.get("fallback_rules") or []
        if not isinstance(rules, list):
            return None
        now = time.time()
        req_scope = _normalize_scope(request.scope)
        req_op = str(request.operation or "")
        # Internal sandbox maintenance should not be blocked by fallback rules.
        try:
            if request.kind == ActionKind.WRITE_SANDBOX:
                if req_op in {"run_scenarios", "reindex_full"} or req_op.startswith("reindex.full"):
                    return None
        except Exception:
            pass
        for rule in rules:
            if not isinstance(rule, dict):
                continue
            try:
                action = str(rule.get("action") or "").lower()
                if action not in ("block", "require_human"):
                    continue
            except Exception:
                pass
            try:
                rule_source = str(rule.get("source") or "")
                if (
                    rule_source == "external_dependency"
                    and request.kind == ActionKind.WRITE_SANDBOX
                    and (req_op in {"run_scenarios", "reindex_full"} or req_op.startswith("reindex.full"))
                ):
                    # Allow safe sandbox maintenance even during external dependency incidents.
                    continue
            except Exception:
                pass
            try:
                exp = rule.get("expires_at")
                if exp and float(exp) < now:
                    continue
            except Exception:
                pass
            op = str(rule.get("operation") or "")
            if op:
                if op == "*":
                    pass
                elif "*" in op:
                    prefix = op.replace("*", "")
                    if prefix and not req_op.startswith(prefix):
                        continue
                elif op != req_op:
                    continue
            rule_scope = str(rule.get("scope") or "")
            if rule_scope and req_scope:
                if rule_scope not in req_scope and req_scope not in rule_scope:
                    continue
            return rule
        return None

    def _log_prod_operation(
        self,
        request: ActionRequest,
        gate_res: GateResult,
        *,
        status: str,
        source: str,
    ) -> None:
        if request.kind != ActionKind.WRITE_PROD:
            return
        try:
            log_dir = self.root_dir / ".bgl_core" / "logs"
            log_dir.mkdir(parents=True, exist_ok=True)
            log_path = log_dir / "prod_operations.jsonl"
        except Exception:
            return
        payload = {
            "timestamp": time.time(),
            "status": status,
            "operation": request.operation,
            "command": request.command or "",
            "kind": request.kind.value,
            "scope": request.scope,
            "reason": request.reason,
            "confidence": request.confidence,
            "metadata": request.metadata or {},
            "decision_id": gate_res.decision_id,
            "intent_id": gate_res.intent_id,
            "allowed": gate_res.allowed,
            "requires_human": gate_res.requires_human,
            "execution_mode": self.effective_execution_mode(),
            "source": source,
        }
        try:
            with open(log_path, "a", encoding="utf-8") as f:
                f.write(json.dumps(payload, ensure_ascii=False) + "\n")
        except Exception:
            pass
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.execute(
                """
                CREATE TABLE IF NOT EXISTS prod_operations (
                  id INTEGER PRIMARY KEY AUTOINCREMENT,
                  timestamp REAL NOT NULL,
                  status TEXT,
                  operation TEXT,
                  command TEXT,
                  kind TEXT,
                  scope TEXT,
                  decision_id INTEGER,
                  intent_id INTEGER,
                  allowed INTEGER,
                  requires_human INTEGER,
                  execution_mode TEXT,
                  source TEXT,
                  payload_json TEXT
                )
                """
            )
            conn.execute(
                """
                INSERT INTO prod_operations
                (timestamp, status, operation, command, kind, scope, decision_id, intent_id, allowed, requires_human, execution_mode, source, payload_json)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                """,
                (
                    payload["timestamp"],
                    status,
                    payload["operation"],
                    payload["command"],
                    payload["kind"],
                    _safe_json(payload["scope"]),
                    int(payload.get("decision_id") or 0),
                    int(payload.get("intent_id") or 0),
                    1 if payload.get("allowed") else 0,
                    1 if payload.get("requires_human") else 0,
                    payload.get("execution_mode"),
                    payload.get("source"),
                    _safe_json(payload),
                ),
            )
            conn.commit()
            conn.close()
        except Exception:
            try:
                conn.close()
            except Exception:
                pass
        try:
            record_decision_trace(
                self.db_path,
                kind="prod_op",
                decision_id=int(gate_res.decision_id or 0),
                intent_id=int(gate_res.intent_id or 0),
                operation=str(request.operation or ""),
                risk_level=str(gate_res.risk_level or ""),
                result=str(status or ""),
                source=source,
                details={
                    "status": status,
                    "operation": request.operation,
                    "scope": request.scope,
                    "allowed": gate_res.allowed,
                    "requires_human": gate_res.requires_human,
                    "execution_mode": self.effective_execution_mode(),
                },
            )
        except Exception:
            pass

    def _scope_requires_human(self, scope: List[str]) -> bool:
        """
        Human approval policy for WRITE_PROD:
        - Require human when touching core product (guarantee system) paths.
        - Allow autonomous for agent-core/supporting areas (e.g. .bgl_core, docs, tests).
        """
        if not scope:
            return True
        protected_prefixes = (
            "app/",
            "api/",
            "templates/",
            "views/",
            "partials/",
            "public/",
            "storage/database/",
        )
        for item in scope:
            try:
                raw = str(item)
            except Exception:
                return True
            path = raw.replace("\\", "/").lstrip("./")
            # If scope is not a file path, default to requiring approval.
            if "://" in path or path.startswith("http"):
                return True
            # Core product surface (guarantee system) requires human.
            if path.startswith(protected_prefixes):
                return True
        return False

    def validate_gating_matrix(self) -> Dict[str, Any]:
        """
        Validate that Authority's protected prefixes align with write_scope.yml.
        Returns a small status payload for diagnostics/reporting.
        """
        status: Dict[str, Any] = {
            "ok": True,
            "warnings": [],
            "protected_prefixes": [
                "app/",
                "api/",
                "templates/",
                "views/",
                "partials/",
                "public/",
                "storage/database/",
            ],
        }
        scope_path = self.root_dir / ".bgl_core" / "brain" / "write_scope.yml"
        if not scope_path.exists():
            status["ok"] = False
            status["warnings"].append("write_scope.yml missing")
            return status
        try:
            import yaml  # type: ignore

            data = yaml.safe_load(scope_path.read_text(encoding="utf-8")) or {}
            scopes = data.get("scopes", []) or []
            policy = data.get("policy", {}) or {}
            forbid = policy.get("forbid_paths", []) or []
            # Flatten allowed scope paths
            scope_paths = []
            for s in scopes:
                scope_paths.extend(s.get("paths", []) or [])
            scope_paths = [str(p).replace("\\", "/").lstrip("./") for p in scope_paths]
            forbid_paths = [str(p).replace("\\", "/").lstrip("./") for p in forbid]

            missing = []
            for prefix in status["protected_prefixes"]:
                covered = any(p.startswith(prefix) for p in scope_paths)
                if not covered:
                    # If explicitly forbidden, consider it covered by policy.
                    covered = any(p.startswith(prefix) for p in forbid_paths)
                if not covered:
                    missing.append(prefix)
            if missing:
                status["ok"] = False
                status["warnings"].append(
                    f"protected prefixes not represented in write_scope.yml: {', '.join(missing)}"
                )
            status["scope_paths_checked"] = len(scope_paths)
            status["forbid_paths_checked"] = len(forbid_paths)
        except Exception as exc:
            status["ok"] = False
            status["warnings"].append(f"failed_to_parse_write_scope: {exc}")
        return status

    # ---- permission queue (compatibility) ----

    def dedupe_permissions(self):
        """Keep only the latest PENDING permission per operation to avoid queue spam."""
        if not self.db_path.exists():
            return
        conn = sqlite3.connect(str(self.db_path))
        try:
            _ensure_agent_permissions_table(conn)
            cur = conn.cursor()
            cur.execute(
                """
                DELETE FROM agent_permissions
                WHERE status='PENDING'
                  AND id NOT IN (
                    SELECT MAX(id) FROM agent_permissions
                    WHERE status='PENDING'
                    GROUP BY operation
                  )
                """
            )
            conn.commit()
        finally:
            conn.close()

    def has_permission(self, operation: str) -> bool:
        if not self._approvals_enabled():
            return True
        if not self.db_path.exists():
            return False
        conn = sqlite3.connect(str(self.db_path))
        try:
            _ensure_agent_permissions_table(conn)
            cur = conn.cursor()
            row = cur.execute(
                "SELECT status FROM agent_permissions WHERE operation=? ORDER BY id DESC LIMIT 1",
                (operation,),
            ).fetchone()
            return bool(row and row[0] == "GRANTED")
        finally:
            conn.close()

    def request_permission(self, operation: str, command: str) -> int:
        """
        Upserts a PENDING permission request and returns its id.
        """
        if not self._approvals_enabled():
            return 0
        if not self.db_path.exists():
            self.db_path.parent.mkdir(parents=True, exist_ok=True)
        conn = sqlite3.connect(str(self.db_path))
        try:
            _ensure_agent_permissions_table(conn)
            cur = conn.cursor()
            row = cur.execute(
                "SELECT id, status FROM agent_permissions WHERE operation=? ORDER BY id DESC LIMIT 1",
                (operation,),
            ).fetchone()
            if row and row[1] == "PENDING":
                cur.execute(
                    "UPDATE agent_permissions SET timestamp=? WHERE id=?",
                    (time.time(), row[0]),
                )
                perm_id = int(row[0])
            else:
                cur.execute(
                    "INSERT INTO agent_permissions (operation, command, status, timestamp) VALUES (?, ?, 'PENDING', ?)",
                    (operation, command, time.time()),
                )
                perm_id = int(cur.lastrowid or 0)
            conn.commit()
            return perm_id
        finally:
            conn.close()

    def permission_status(self, perm_id: int) -> Optional[str]:
        """Return the status for a permission id (GRANTED/PENDING/REJECTED/...)."""
        if not self.db_path.exists():
            return None
        conn = sqlite3.connect(str(self.db_path))
        try:
            _ensure_agent_permissions_table(conn)
            cur = conn.cursor()
            row = cur.execute(
                "SELECT status FROM agent_permissions WHERE id=?",
                (int(perm_id),),
            ).fetchone()
            return str(row[0]) if row else None
        finally:
            conn.close()

    def is_permission_granted(self, perm_id: int) -> bool:
        return self.permission_status(perm_id) == "GRANTED"

    # ---- decision logging ----

    def _log_decision(
        self,
        request: ActionRequest,
        decision_payload: Dict[str, Any],
        source: str,
    ) -> GateResult:
        """
        Log intent + decision rows and return a GateResult with linkage ids.
        """
        env_snapshot = None
        env_delta = None
        try:
            env_snapshot = latest_env_snapshot(self.db_path, kind="diagnostic")
        except Exception:
            env_snapshot = None
        try:
            env_delta = latest_env_snapshot(self.db_path, kind="diagnostic_delta")
        except Exception:
            env_delta = None

        try:
            meta = request.metadata or {}
        except Exception:
            meta = {}

        intent_payload: Dict[str, Any] = {
            "intent": request.operation,
            "confidence": float(request.confidence or 0.5),
            "reason": request.reason or request.command or request.operation,
            "scope": request.scope,
            "context_snapshot": {
                "action_kind": request.kind.value,
                "operation": request.operation,
                "command": request.command,
                "metadata": meta,
                "execution_mode": self.execution_mode,
                # Unified environment awareness at decision time (may be None on fresh DBs).
                "env_snapshot": env_snapshot,
                "env_delta": env_delta,
                "run_id": meta.get("run_id") or os.getenv("BGL_RUN_ID") or "",
                "scenario_id": meta.get("scenario_id") or os.getenv("BGL_SCENARIO_ID") or "",
                "scenario_name": meta.get("scenario_name") or os.getenv("BGL_SCENARIO_NAME") or "",
                "goal_id": meta.get("goal_id") or os.getenv("BGL_GOAL_ID") or "",
                "goal_name": meta.get("goal_name") or os.getenv("BGL_GOAL_NAME") or "",
            },
        }

        intent_id = insert_intent(
            self.db_path,
            str(intent_payload["intent"]),
            float(intent_payload["confidence"]),
            str(intent_payload["reason"]),
            _safe_json(intent_payload.get("scope", [])),
            _safe_json(intent_payload.get("context_snapshot", {})),
            source=source,
        )

        decision_id = insert_decision(
            self.db_path,
            intent_id,
            str(decision_payload.get("decision", "observe")),
            str(decision_payload.get("risk_level", "low")),
            bool(decision_payload.get("requires_human", False)),
            "; ".join(list(decision_payload.get("justification", []))[:10]),
        )
        try:
            record_decision_trace(
                self.db_path,
                kind="decision",
                decision_id=int(decision_id or 0),
                intent_id=int(intent_id or 0),
                operation=str(request.operation or ""),
                risk_level=str(decision_payload.get("risk_level", "")),
                result=str(decision_payload.get("decision", "")),
                source=source,
                details={"decision": decision_payload, "metadata": meta},
            )
        except Exception:
            pass

        return GateResult(
            allowed=False,
            requires_human=bool(decision_payload.get("requires_human", False)),
            intent_id=int(intent_id or 0),
            decision_id=int(decision_id or 0),
            decision=str(decision_payload.get("decision", "")),
            risk_level=str(decision_payload.get("risk_level", "low")),
            justification=list(decision_payload.get("justification", []))[:10],
        )

    def record_outcome(
        self, decision_id: int, result: str, notes: str = "", backup_path: str = ""
    ):
        try:
            # Append failure classification for non-success outcomes.
            note_text = notes or ""
            failure_class = ""
            try:
                from .failure_classifier import classify_failure, extract_failure_class  # type: ignore
            except Exception:
                try:
                    from failure_classifier import classify_failure, extract_failure_class  # type: ignore
                except Exception:
                    classify_failure = None  # type: ignore
                    extract_failure_class = None  # type: ignore
            if classify_failure and extract_failure_class:
                try:
                    res = str(result or "").lower()
                    if res not in ("success", "success_sandbox", "success_direct", "success_with_override"):
                        if not extract_failure_class(note_text):
                            fclass = classify_failure(result, note_text)
                            if fclass:
                                note_text = f"{note_text} failure_class={fclass}".strip()
                    try:
                        failure_class = extract_failure_class(note_text) or ""
                    except Exception:
                        failure_class = ""
                except Exception:
                    pass
            outcome_id = insert_outcome(
                self.db_path, decision_id, result, note_text, backup_path=backup_path
            )
            try:
                record_decision_trace(
                    self.db_path,
                    kind="outcome",
                    decision_id=int(decision_id or 0),
                    outcome_id=int(outcome_id or 0),
                    result=str(result or ""),
                    failure_class=str(failure_class or ""),
                    source="authority",
                    details={"notes": note_text, "backup_path": backup_path},
                )
            except Exception:
                pass
            return outcome_id
        except Exception:
            # Never crash the caller because of audit logging.
            pass
        return None

    # ---- main gate ----

    def gate(self, request: ActionRequest, source: str = "authority") -> GateResult:
        """
        Gate an action.
        - OBSERVE/PROBE/PROPOSE: allowed immediately.
        - WRITE_*: requires explicit approval unless explicitly overridden by env/config.
        """
        # Determine whether this action can have side effects.
        write_action = request.kind in (ActionKind.WRITE_SANDBOX, ActionKind.WRITE_PROD)
        fallback_rule = None
        if write_action:
            try:
                fallback_rule = self._fallback_guard(request)
            except Exception:
                fallback_rule = None
        if write_action and fallback_rule and str(fallback_rule.get("action") or "").lower() == "block":
            if self._fallback_block_disabled():
                try:
                    if request.metadata is None:
                        request.metadata = {}
                    if isinstance(request.metadata, dict):
                        request.metadata["fallback_block_ignored"] = _compact_fallback_context(fallback_rule)
                except Exception:
                    pass
                fallback_rule = None
            else:
                fallback_ctx = _compact_fallback_context(fallback_rule)
                fallback_note = "fallback_rule:block"
                if fallback_ctx:
                    fallback_note = f"{fallback_note} ctx={_safe_json(fallback_ctx)}"
                decision_payload = {
                    "decision": "block",
                    "risk_level": "high",
                    "requires_human": True,
                    "justification": [
                        "fallback_rule:block",
                        str(fallback_rule.get("reason") or ""),
                        str(fallback_ctx.get("intent") or ""),
                    ],
                    "maturity": "stable",
                }
                gate_res = self._log_decision(request, decision_payload, source=source)
                gate_res.allowed = False
                gate_res.requires_human = True
                gate_res.message = "Blocked by fallback rule derived from prod operations."
                try:
                    if request.metadata is None:
                        request.metadata = {}
                    if isinstance(request.metadata, dict) and fallback_ctx:
                        request.metadata["fallback_ctx"] = fallback_ctx
                except Exception:
                    pass
                self._log_prod_operation(request, gate_res, status="blocked_fallback", source=source)
                self.record_outcome(
                    gate_res.decision_id or 0,
                    "blocked",
                    fallback_note,
                )
                return gate_res

        # For non-write actions, do NOT call the LLM at all. It's pure overhead and causes
        # repetitive "SmartDecisionEngine" calls during audits.
        if not write_action:
            decision_payload = {
                "decision": "observe" if request.kind in (ActionKind.OBSERVE, ActionKind.PROBE) else "propose_fix",
                "risk_level": "low",
                "requires_human": False,
                "justification": ["deterministic: non-write action"],
                "maturity": "stable",
            }
            gate_res = self._log_decision(request, decision_payload, source=source)
            gate_res.allowed = True
            gate_res.requires_human = False
            gate_res.message = "Allowed (non-write action)."
            return gate_res

        # For some internal sandbox writes, skip the LLM entirely (deterministic gate),
        # while still preserving approval requirements and audit logs.
        deterministic_gate = False
        meta: Dict[str, Any] = {}
        try:
            meta = dict(request.metadata or {})
            deterministic_gate = bool(meta.get("deterministic_gate", False))
            policy_key = str(meta.get("policy_key", "") or "")
            if request.kind == ActionKind.WRITE_SANDBOX and policy_key in {"reindex_full", "run_scenarios"}:
                deterministic_gate = True
        except Exception:
            meta = {}
            deterministic_gate = False

        runtime_hint: Dict[str, Any] = {}
        try:
            runtime_hint = self._runtime_hint(request.scope, meta)
            if runtime_hint:
                meta["runtime_hint"] = runtime_hint
        except Exception:
            runtime_hint = {}
        try:
            request.metadata = meta
        except Exception:
            pass

        # For write actions, de-duplicate within a run to avoid repeated LLM calls and decision spam.
        cache_key = self._cache_key(request)
        decision_payload = {}
        cached = self._cache_get(cache_key)
        if cached:
            gate_res = cached
        else:
            # Provide decision payload (risk/justification). We treat it as advisory for writes.
            policy = self.config.get("decision", {})
            env_snapshot_payload = None
            env_delta_payload = None
            try:
                snap = latest_env_snapshot(self.db_path, kind="diagnostic")
                env_snapshot_payload = (snap or {}).get("payload")
            except Exception:
                env_snapshot_payload = None
            try:
                delta = latest_env_snapshot(self.db_path, kind="diagnostic_delta")
                env_delta_payload = (delta or {}).get("payload")
            except Exception:
                env_delta_payload = None
            try:
                code_temporal_signals = {}
                code_intent_signals = {}
                try:
                    if isinstance(env_snapshot_payload, dict):
                        code_temporal_signals = env_snapshot_payload.get("code_temporal_signals") or {}
                        code_intent_signals = env_snapshot_payload.get("code_intent_signals") or {}
                except Exception:
                    code_temporal_signals = {}
                    code_intent_signals = {}
                if code_temporal_signals:
                    meta["code_temporal_signals"] = code_temporal_signals
                if code_intent_signals:
                    meta["code_intent_signals"] = code_intent_signals

                if deterministic_gate:
                    requires_human = True
                    try:
                        policy_key = str(meta.get("policy_key", "") or "")
                        if request.kind == ActionKind.WRITE_SANDBOX and policy_key in {"reindex_full", "run_scenarios"}:
                            requires_human = False
                    except Exception:
                        requires_human = True
                    decision_payload = {
                        "decision": "observe",
                        "risk_level": "low",
                        "requires_human": requires_human,
                        "justification": ["deterministic_gate: internal sandbox write"],
                        "maturity": "stable",
                    }
                else:
                    decision_payload = decide(
                        {
                            "intent": request.operation,
                            "confidence": request.confidence,
                            "reason": request.reason,
                            "scope": request.scope,
                            "metadata": request.metadata,
                            "action_kind": request.kind.value,
                            "runtime_hint": runtime_hint,
                            "code_temporal_signals": code_temporal_signals,
                            "code_intent_signals": code_intent_signals,
                            # Give decision engine a canonical view of the environment.
                            "env_snapshot": env_snapshot_payload,
                            "env_delta": env_delta_payload,
                        },
                        policy,
                    )
            except Exception:
                decision_payload = {
                    "decision": "observe",
                    "risk_level": "medium",
                    "requires_human": True,
                    "justification": ["decision_engine failed; using deterministic gate"],
                    "maturity": "experimental",
                }

            gate_res = self._log_decision(request, decision_payload, source=source)
            self._cache_set(cache_key, gate_res)

        # Deterministic override: any WRITE_* requires human approval by default.

        effective_mode = self.effective_execution_mode()
        force_requires_human = False
        force_no_human = self._force_no_human_approvals()
        try:
            force_requires_human = bool(decision_payload.get("force_requires_human", False))
        except Exception:
            force_requires_human = False
        if force_no_human:
            force_requires_human = False
            decision_payload["requires_human"] = False
        if fallback_rule and str(fallback_rule.get("action") or "").lower() == "require_human" and not force_no_human:
            if not self._fallback_require_human_disabled():
                force_requires_human = True

        if write_action:
            # Explicit override: allow production writes without human approval.
            allow_prod_no_human = False
            try:
                allow_prod_no_human = (
                    os.getenv("BGL_ALLOW_PROD_NO_HUMAN", "0") == "1"
                    or str(self.config.get("allow_prod_without_human", "0")).strip() == "1"
                )
            except Exception:
                allow_prod_no_human = False
            if self._autonomous_enabled() or (allow_prod_no_human and request.kind == ActionKind.WRITE_PROD):
                gate_res.allowed = True
                gate_res.requires_human = False
                gate_res.message = "Autonomous execution enabled (production override)."
                self._log_prod_operation(request, gate_res, status="allowed", source=source)
                return gate_res
            if force_no_human or not self._approvals_enabled():
                # No human approval path; allow by policy/mode only.
                if request.kind == ActionKind.WRITE_PROD and effective_mode != "direct":
                    gate_res.allowed = False
                    gate_res.requires_human = False
                    gate_res.message = f"WRITE_PROD blocked (execution_mode={effective_mode})."
                    self._log_prod_operation(request, gate_res, status="blocked_mode", source=source)
                    self.record_outcome(gate_res.decision_id or 0, "blocked", gate_res.message)
                    return gate_res
                gate_res.allowed = True
                gate_res.requires_human = False
                gate_res.message = "Approvals disabled; allowed."
                self._log_prod_operation(request, gate_res, status="allowed", source=source)
                return gate_res
            # Allow limited policy overrides for sandbox writes (internal maintenance).
            # WRITE_PROD stays strictly human-gated.
            requires_human = True if force_requires_human else False
            if request.kind == ActionKind.WRITE_SANDBOX:
                requires_human = True if force_requires_human else False
            elif request.kind == ActionKind.WRITE_PROD:
                requires_human = True if force_requires_human else self._scope_requires_human(request.scope)

            policy_key = None
            try:
                policy_key = request.metadata.get("policy_key")
            except Exception:
                policy_key = None
            if policy_key:
                cfg_block = policy.get(str(policy_key))
                if isinstance(cfg_block, dict) and "requires_human" in cfg_block:
                    requires_human = bool(cfg_block.get("requires_human", True))

            gate_res.requires_human = requires_human

            if requires_human:
                permitted = self.has_permission(request.operation)
                if not permitted:
                    perm_id = self.request_permission(
                        request.operation,
                        request.command or request.reason or request.operation,
                    )
                    gate_res.permission_id = perm_id
                    gate_res.allowed = False
                    gate_res.requires_human = True
                    gate_res.message = f"Pending human approval for {request.operation} (permission_id={perm_id})."
                    self._log_prod_operation(request, gate_res, status="blocked_pending", source=source)
                    self.record_outcome(
                        gate_res.decision_id or 0,
                        "blocked",
                        "Awaiting human approval",
                    )
                    return gate_res

            # Permission granted: now decide if config/env allows this category.
            if request.kind == ActionKind.WRITE_PROD and effective_mode != "direct":
                gate_res.allowed = False
                gate_res.requires_human = True
                gate_res.message = f"WRITE_PROD blocked (execution_mode={effective_mode})."
                self._log_prod_operation(request, gate_res, status="blocked_mode", source=source)
                self.record_outcome(gate_res.decision_id or 0, "blocked", gate_res.message)
                return gate_res

            gate_res.allowed = True
            gate_res.requires_human = False
            gate_res.message = "Approved and allowed."
            self._log_prod_operation(request, gate_res, status="allowed", source=source)
            return gate_res

        # Non-write actions: allow immediately.
        decision_payload["requires_human"] = False
        gate_res = self._log_decision(request, decision_payload, source=source)
        gate_res.allowed = True
        gate_res.requires_human = False
        gate_res.message = "Allowed (non-write action)."
        return gate_res
