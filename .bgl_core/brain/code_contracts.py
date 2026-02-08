from __future__ import annotations

import ast
import json
import os
import re
import time
import sqlite3
from pathlib import Path
from typing import Any, Dict, List, Optional, Set, Tuple

try:
    import yaml  # type: ignore
except Exception:  # pragma: no cover
    yaml = None  # type: ignore

ROOT_DIR = Path(__file__).resolve().parents[2]
ANALYSIS_DIR = ROOT_DIR / "analysis"
DOCS_DIR = ROOT_DIR / "docs"
DB_PATH = ROOT_DIR / ".bgl_core" / "brain" / "knowledge.db"
SCENARIOS_DIR = ROOT_DIR / ".bgl_core" / "brain" / "scenarios"


def _risk_tier(path: str) -> str:
    norm = path.replace("\\", "/").lower()
    if norm.startswith("api/"):
        return "high"
    if norm.startswith(".bgl_core/brain/"):
        return "high"
    if norm.startswith("app/") or norm.startswith("views/") or norm.startswith("partials/"):
        return "medium"
    if norm.startswith("agentfrontend/") or norm.startswith("public/js/"):
        return "medium"
    if norm.startswith("scripts/") or norm.startswith("tests/"):
        return "low"
    return "low"


def _load_json(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {}
    try:
        return json.loads(path.read_text(encoding="utf-8"))
    except Exception:
        return {}


def _load_yaml(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {}
    if yaml is None:
        return {}
    try:
        return yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _normalize_route(route: str) -> str:
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


def _load_callgraph(root: Path) -> Dict[str, Any]:
    out = root / "docs" / "api_callgraph.json"
    if not out.exists():
        try:
            from .callgraph_builder import build_callgraph  # type: ignore
        except Exception:
            try:
                from callgraph_builder import build_callgraph  # type: ignore
            except Exception:
                build_callgraph = None  # type: ignore
        if build_callgraph:
            try:
                build_callgraph(root)
            except Exception:
                pass
    return _load_json(out)


def _read_log_tail(path: Path, max_bytes: int = 200000, max_lines: int = 200) -> List[str]:
    if not path.exists():
        return []
    try:
        with open(path, "rb") as f:
            f.seek(0, 2)
            size = f.tell()
            f.seek(max(0, size - max_bytes))
            data = f.read()
    except Exception:
        try:
            return path.read_text(encoding="utf-8", errors="ignore").splitlines()[-max_lines:]
        except Exception:
            return []
    text = data.decode("utf-8", errors="ignore")
    return text.splitlines()[-max_lines:]


def _load_log_hints(root: Path) -> Dict[str, Dict[str, List[str]]]:
    log_paths = [
        root / "storage" / "logs" / "laravel.log",
        root / "storage" / "logs" / "app.log",
    ]
    hints_by_file: Dict[str, List[str]] = {}
    hints_by_route: Dict[str, List[str]] = {}
    root_norm = str(root).replace("\\", "/").rstrip("/")
    for p in log_paths:
        lines = _read_log_tail(p)
        for line in lines:
            if not line:
                continue
            low = line.lower()
            if "error" not in low and "exception" not in low and "critical" not in low:
                continue
            msg = re.sub(r"\s+", " ", line).strip()
            if not msg:
                continue
            for m in re.findall(r"(/api/[A-Za-z0-9_/-]+)", msg):
                route = _normalize_route(m)
                if not route:
                    continue
                hints_by_route.setdefault(route, []).append(msg[:200])
            for m in re.findall(r"([A-Za-z0-9_./\\\\-]+\\.php)", msg):
                norm = m.replace("\\", "/")
                if norm.startswith(root_norm):
                    norm = norm[len(root_norm) :].lstrip("/")
                if not norm:
                    continue
                hints_by_file.setdefault(norm, []).append(msg[:200])
    # dedupe + trim
    for bucket in (hints_by_route, hints_by_file):
        for k, items in list(bucket.items()):
            seen = set()
            uniq: List[str] = []
            for it in items:
                if it in seen:
                    continue
                seen.add(it)
                uniq.append(it)
            bucket[k] = uniq[:6]
    return {"routes": hints_by_route, "files": hints_by_file}


def _load_runtime_stats(db_path: Path) -> Dict[str, Dict[str, Any]]:
    if not db_path.exists():
        return {}
    stats: Dict[str, Dict[str, Any]] = {}
    try:
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        rows = conn.execute(
            """
            SELECT route,
                   COUNT(*) as cnt,
                   MAX(timestamp) as last_ts,
                   AVG(latency_ms) as avg_latency,
                   SUM(CASE WHEN error IS NOT NULL AND error != '' THEN 1 ELSE 0 END) as err_cnt
            FROM runtime_events
            WHERE route IS NOT NULL AND route != ''
            GROUP BY route
            """
        ).fetchall()
        last_errors: Dict[str, Dict[str, Any]] = {}
        err_rows = conn.execute(
            """
            SELECT route, error, timestamp
            FROM runtime_events
            WHERE error IS NOT NULL AND error != ''
            ORDER BY timestamp DESC
            """
        ).fetchall()
        for r in err_rows:
            route = _normalize_route(str(r["route"] or ""))
            if not route or route in last_errors:
                continue
            last_errors[route] = {
                "last_error": (r["error"] or "")[:200],
                "last_error_ts": float(r["timestamp"] or 0),
            }
        for r in rows:
            route = _normalize_route(str(r["route"] or ""))
            if not route:
                continue
            cnt = int(r["cnt"] or 0)
            err = int(r["err_cnt"] or 0)
            stats[route] = {
                "event_count": cnt,
                "error_count": err,
                "error_rate": round(err / cnt, 3) if cnt else 0.0,
                "last_ts": float(r["last_ts"] or 0),
                "avg_latency_ms": round(float(r["avg_latency"] or 0.0), 2),
            }
            if route in last_errors:
                stats[route].update(last_errors[route])
    except Exception:
        stats = {}
    finally:
        try:
            conn.close()
        except Exception:
            pass
    return stats


def _extract_test_refs(text: str) -> Dict[str, Set[str]]:
    modules: Set[str] = set()
    files: Set[str] = set()
    routes: Set[str] = set()

    for m in re.findall(r"from\\s+([A-Za-z0-9_\\.]+)\\s+import", text):
        modules.add(m.split(".")[-1])
    for m in re.findall(r"import\\s+([A-Za-z0-9_\\.]+)", text):
        modules.add(m.split(".")[-1])

    for m in re.findall(r"([A-Za-z0-9_\\/\\-]+\\.php)", text):
        files.add(m.replace("\\\\", "/"))

    for m in re.findall(r"(/api/[A-Za-z0-9_\\/\\-\\.\\?=&%]+)", text):
        routes.add(_normalize_route(m.split("?")[0]))
    for m in re.findall(r"(https?://[^\\s'\\\"]+)", text):
        routes.add(_normalize_route(m))

    return {"modules": modules, "files": files, "routes": routes}


def _extract_php_inputs(text: str) -> Dict[str, List[str]]:
    query: Set[str] = set()
    post: Set[str] = set()
    body: Set[str] = set()
    headers: Set[str] = set()
    for m in re.findall(r"\$_GET\[['\"]([^'\"]+)['\"]\]", text):
        query.add(m)
    for m in re.findall(r"Input::(?:get|string|int|array)\(\s*\$input\s*,\s*'([^']+)'", text):
        post.add(m)
    for m in re.findall(r"\$_POST\[['\"]([^'\"]+)['\"]\]", text):
        post.add(m)
    for m in re.findall(r"\$input\[['\"]([^'\"]+)['\"]\]", text):
        post.add(m)
    for m in re.findall(r"json_decode\\([^)]*\\)", text):
        body.add("json_body")
    for m in re.findall(r"getallheaders\\(\\)", text):
        headers.add("all")
    for m in re.findall(r"\$_SERVER\[['\"]HTTP_([^'\"]+)['\"]\]", text):
        headers.add(m.lower())
    return {
        "query": sorted(query),
        "post": sorted(post),
        "body": sorted(body),
        "headers": sorted(headers),
    }


def _extract_php_outputs(text: str) -> Dict[str, Any]:
    outputs: Dict[str, Any] = {"types": [], "status_codes": [], "echo": []}
    if re.search(r"json_encode\(", text) or re.search(r"Content-Type'\s*=>\s*'application/json", text):
        outputs["types"].append("json")
    if re.search(r"Content-Type:\\s*application/json", text, re.IGNORECASE):
        outputs["types"].append("json")
    for m in re.findall(r"http_response_code\\((\\d+)\\)", text):
        outputs["status_codes"].append(int(m))
    for m in re.findall(r"echo\\s+['\\\"]([^'\\\"]+)['\\\"]", text):
        outputs["echo"].append(m[:120])
    for m in re.findall(r"print\\s+['\\\"]([^'\\\"]+)['\\\"]", text):
        outputs["echo"].append(m[:120])
    outputs["types"] = sorted(set(outputs["types"]))
    outputs["status_codes"] = sorted(set(outputs["status_codes"]))
    outputs["echo"] = sorted(set(outputs["echo"]))
    return outputs


def _split_identifier(name: str) -> List[str]:
    if not name:
        return []
    parts = re.findall(r"[A-Za-z][a-z]+|[A-Z]+(?=[A-Z]|$)|\\d+", name)
    if not parts:
        parts = re.split(r"[_\\W]+", name)
    tokens = []
    for p in parts:
        t = str(p).strip().lower()
        if len(t) < 2:
            continue
        tokens.append(t)
    return tokens


def _tokens_from_identifiers(names: List[str], limit: int = 24) -> List[str]:
    freq: Dict[str, int] = {}
    for name in names:
        for t in _split_identifier(name):
            freq[t] = freq.get(t, 0) + 1
    stop = {
        "id",
        "num",
        "data",
        "info",
        "get",
        "set",
        "temp",
        "tmp",
        "value",
        "values",
        "list",
        "item",
        "items",
        "flag",
        "count",
        "index",
        "result",
        "response",
        "request",
        "input",
        "output",
        "ctx",
        "meta",
        "obj",
        "dict",
    }
    for s in stop:
        freq.pop(s, None)
    tokens = sorted(freq.items(), key=lambda kv: kv[1], reverse=True)
    return [t for t, _ in tokens[:limit]]


def _tokenize_route(route: str) -> List[str]:
    route = _normalize_route(route)
    if not route:
        return []
    parts = re.split(r"[\\/\\-_]+", route.strip("/"))
    tokens: List[str] = []
    for p in parts:
        tokens.extend(_split_identifier(p))
    stop = {"api", "v1", "v2", "v3", "v4", "v5", "index", "list"}
    return [t for t in tokens if t and t not in stop]


def _test_token_candidates(test_row: Dict[str, Any]) -> List[str]:
    tokens = test_row.get("test_tokens") or []
    if tokens:
        return [str(t) for t in tokens]
    names = []
    names.extend(test_row.get("test_names") or [])
    names.extend(test_row.get("test_classes") or [])
    names.extend(test_row.get("test_tags") or [])
    return _tokens_from_identifiers([str(n) for n in names], limit=18)


def _token_overlap(target: List[str], candidates: List[str], min_hits: int) -> bool:
    if not target or not candidates:
        return False
    tset = {str(t).lower() for t in target if t}
    cset = {str(c).lower() for c in candidates if c}
    return len(tset & cset) >= max(1, min_hits)


def _temporal_profile_text(text: str, file_path: str, *, lang: str) -> Dict[str, Any]:
    low = (text or "").lower()
    markers: List[str] = []
    cache_markers: List[str] = []
    startup_exec = False
    startup_reasons: List[str] = []
    first_request_writes = False
    accumulates = False
    stateful = False

    if not text:
        return {}

    if lang == "php":
        if any(k in file_path.replace("\\", "/") for k in ("bootstrap/", "config/", "routes/", "app/Providers/")):
            startup_exec = True
            startup_reasons.append("bootstrap_path")
        if "register" in low or "boot(" in low:
            startup_exec = True
            startup_reasons.append("provider_hook")
        if "$_session" in low or "session()" in low or "session::" in low:
            markers.append("session")
            stateful = True
        if "cache::" in low or "cache()->" in low or "redis::" in low or "memcached" in low or "apcu_" in low:
            cache_markers.extend(["cache", "redis"])
            first_request_writes = True
            accumulates = True
            stateful = True
        if "file_put_contents" in low or "fwrite(" in low or "fopen(" in low or "unlink(" in low:
            markers.append("file_io")
            accumulates = True
            stateful = True
        if "setcookie" in low or "cookie(" in low:
            markers.append("cookie")
            stateful = True
        if "static $" in low or "static::" in low:
            markers.append("static")
            stateful = True
    elif lang == "js":
        if "localstorage" in low or "sessionstorage" in low or "indexeddb" in low:
            cache_markers.append("browser_storage")
            accumulates = True
            stateful = True
        if "document.cookie" in low:
            markers.append("cookie")
            stateful = True
        if "window." in low or "globalthis" in low:
            markers.append("global")
            stateful = True
        if "fetch(" in low and ("cache" in low or "etag" in low):
            cache_markers.append("http_cache")
            accumulates = True
            stateful = True

    if cache_markers:
        markers.extend(cache_markers)
        first_request_writes = True

    return {
        "startup_exec": startup_exec,
        "startup_reasons": startup_reasons,
        "first_request_writes": first_request_writes,
        "accumulates": accumulates,
        "stateful": stateful,
        "stateless_likely": not stateful,
        "markers": sorted(set(markers)),
    }


def _temporal_profile_python_module(path: Path) -> Dict[str, Any]:
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        return {}
    try:
        tree = ast.parse(text)
    except Exception:
        return {}
    startup_exec = False
    startup_reasons: List[str] = []
    stateful = False
    accumulates = False
    first_request_writes = False
    markers: List[str] = []

    for node in tree.body:
        if isinstance(node, (ast.Import, ast.ImportFrom, ast.FunctionDef, ast.AsyncFunctionDef, ast.ClassDef)):
            continue
        startup_exec = True
        if isinstance(node, (ast.Assign, ast.AnnAssign, ast.AugAssign)):
            startup_reasons.append("module_assign")
        elif isinstance(node, ast.Expr) and isinstance(node.value, ast.Call):
            fn = ""
            try:
                if isinstance(node.value.func, ast.Name):
                    fn = node.value.func.id
                elif isinstance(node.value.func, ast.Attribute):
                    fn = node.value.func.attr
            except Exception:
                fn = ""
            if fn:
                startup_reasons.append(f"call:{fn}")
            else:
                startup_reasons.append("call")
        else:
            startup_reasons.append(node.__class__.__name__)

    has_global = any(isinstance(n, (ast.Global, ast.Nonlocal)) for n in ast.walk(tree))
    if has_global:
        markers.append("global")
        stateful = True

    for n in ast.walk(tree):
        if isinstance(n, ast.FunctionDef):
            for dec in n.decorator_list:
                name = ""
                if isinstance(dec, ast.Name):
                    name = dec.id
                elif isinstance(dec, ast.Attribute):
                    name = dec.attr
                if name in ("lru_cache", "cache", "cached", "memoize"):
                    markers.append("cache_decorator")
                    first_request_writes = True
                    accumulates = True
                    stateful = True
        if isinstance(n, ast.Assign):
            for t in n.targets:
                if isinstance(t, ast.Attribute):
                    markers.append("class_attr_assign")
                    stateful = True

    low = text.lower()
    if "cache" in low or "redis" in low or "memcache" in low:
        markers.append("cache")
        first_request_writes = True
        accumulates = True
        stateful = True
    if "session" in low:
        markers.append("session")
        stateful = True

    return {
        "startup_exec": startup_exec,
        "startup_reasons": startup_reasons[:6],
        "first_request_writes": first_request_writes,
        "accumulates": accumulates,
        "stateful": stateful,
        "stateless_likely": not stateful,
        "markers": sorted(set(markers)),
    }


def _extract_comment_hints(text: str, line_start: Optional[int] = None) -> List[str]:
    if not text:
        return []
    lines = text.splitlines()
    hints: List[str] = []
    if line_start and line_start > 1:
        idx = line_start - 2
        while idx >= 0:
            raw = lines[idx].strip()
            if raw.startswith("#") or raw.startswith("//"):
                hints.append(raw.lstrip("#/ ").strip())
                idx -= 1
                continue
            if raw.startswith("/*") or raw.startswith("*") or raw.endswith("*/"):
                hints.append(raw.strip("/* ").strip())
                idx -= 1
                continue
            break
    if not hints:
        for raw in lines[:120]:
            r = raw.strip()
            if r.startswith("#") or r.startswith("//"):
                hints.append(r.lstrip("#/ ").strip())
            if "/*" in r and "*/" in r:
                hints.append(r.split("/*", 1)[1].split("*/", 1)[0].strip())
            if len(hints) >= 6:
                break
    out: List[str] = []
    for h in hints:
        h = re.sub(r"\\s+", " ", h).strip()
        if h:
            out.append(h[:140])
    return out[:6]


def _extract_comment_tags(hints: List[str]) -> List[str]:
    if not hints:
        return []
    tags: Set[str] = set()
    for h in hints:
        t = str(h or "").lower()
        for key in ("todo", "fixme", "bug", "hack", "note", "xxx", "workaround", "temporary", "temp"):
            if key in t:
                tags.add(key)
    return sorted(tags)


def _infer_intent_keywords(tokens: List[str], comments: List[str], tests: List[str]) -> Dict[str, Any]:
    text = " ".join(tokens + comments + tests).lower()
    buckets = {
        "stabilize": [
            "fix",
            "error",
            "retry",
            "recover",
            "rollback",
            "fallback",
            "timeout",
            "validate",
            "sanitize",
            "bug",
            "fail",
            "regression",
        ],
        "unblock": [
            "approve",
            "permission",
            "auth",
            "access",
            "token",
            "grant",
            "deny",
            "unlock",
            "blocked",
        ],
        "evolve": [
            "policy",
            "rule",
            "schema",
            "migrate",
            "refactor",
            "feature",
            "improve",
            "optimize",
            "upgrade",
        ],
        "observe": ["log", "trace", "metric", "inspect", "report", "monitor", "telemetry"],
    }
    scores: Dict[str, int] = {k: 0 for k in buckets}
    for intent, words in buckets.items():
        for w in words:
            if w in text:
                scores[intent] += 1
    best = max(scores.items(), key=lambda kv: kv[1])[0] if scores else "observe"
    return {"suggested_intent": best, "scores": scores}


def _tests_for_intent(tests_signals: Dict[str, Any], tests_linked: List[str]) -> List[str]:
    if tests_signals:
        items = []
        items.extend(tests_signals.get("test_names") or [])
        items.extend(tests_signals.get("test_tags") or [])
        items.extend(tests_signals.get("test_tokens") or [])
        if items:
            return items
    return tests_linked[:6]


def _extract_python_function_contracts(path: Path) -> List[Dict[str, Any]]:
    contracts: List[Dict[str, Any]] = []
    try:
        text = path.read_text(encoding="utf-8", errors="ignore")
        node = ast.parse(text)
    except Exception:
        return contracts

    class FuncVisitor(ast.NodeVisitor):
        def __init__(self):
            self.class_stack: List[str] = []

        def visit_ClassDef(self, n: ast.ClassDef):
            self.class_stack.append(n.name)
            self.generic_visit(n)
            self.class_stack.pop()

        def visit_FunctionDef(self, n: ast.FunctionDef):
            self._record(n)

        def visit_AsyncFunctionDef(self, n: ast.AsyncFunctionDef):
            self._record(n)

        def _record(self, n: ast.AST):
            name = getattr(n, "name", "")
            args = []
            try:
                for a in n.args.args:  # type: ignore
                    if a.arg != "self":
                        args.append(a.arg)
            except Exception:
                args = []
            doc = ast.get_docstring(n) or ""
            identifiers: List[str] = []
            comments = _extract_comment_hints(text, getattr(n, "lineno", None))
            comment_tags = _extract_comment_tags(comments)
            try:
                for x in ast.walk(n):
                    if isinstance(x, ast.Name):
                        identifiers.append(x.id)
                    elif isinstance(x, ast.Attribute):
                        identifiers.append(x.attr)
                    elif isinstance(x, ast.arg):
                        identifiers.append(x.arg)
            except Exception:
                identifiers = []
            tokens = _tokens_from_identifiers(identifiers)
            has_return = any(isinstance(x, ast.Return) for x in ast.walk(n))
            returns_value = any(
                isinstance(x, ast.Return) and x.value is not None for x in ast.walk(n)
            )
            raises = any(isinstance(x, ast.Raise) for x in ast.walk(n))
            calls = []
            for x in ast.walk(n):
                if isinstance(x, ast.Call):
                    fn = None
                    if isinstance(x.func, ast.Name):
                        fn = x.func.id
                    elif isinstance(x.func, ast.Attribute):
                        fn = x.func.attr
                    if fn:
                        calls.append(fn)
            class_name = self.class_stack[-1] if self.class_stack else ""
            intent_hint = _infer_intent_keywords(tokens, comments, [])
            contracts.append(
                {
                    "name": name,
                    "class": class_name,
                    "args": args,
                    "doc": doc.splitlines()[0] if doc else "",
                    "intent_signals": {
                        "tokens": tokens[:12],
                        "comments": comments[:4],
                        "comment_tags": comment_tags,
                        "intent_hint": intent_hint,
                    },
                    "has_return": has_return,
                    "returns_value": returns_value,
                    "raises": raises,
                    "calls": sorted(set(calls))[:25],
                    "line_start": getattr(n, "lineno", None),
                    "line_end": getattr(n, "end_lineno", None),
                }
            )

    FuncVisitor().visit(node)
    return contracts


def _extract_js_function_contracts(text: str) -> List[Dict[str, Any]]:
    contracts: List[Dict[str, Any]] = []
    patterns = [
        r"function\\s+([A-Za-z0-9_$]+)\\s*\\(([^)]*)\\)",
        r"const\\s+([A-Za-z0-9_$]+)\\s*=\\s*\\(([^)]*)\\)\\s*=>",
        r"const\\s+([A-Za-z0-9_$]+)\\s*=\\s*function\\s*\\(([^)]*)\\)",
    ]
    for pat in patterns:
        for m in re.findall(pat, text):
            name = m[0]
            args = [a.strip() for a in m[1].split(",") if a.strip()]
            tokens = _tokens_from_identifiers([name] + args)
            comments = _extract_comment_hints(text, None)
            comment_tags = _extract_comment_tags(comments)
            intent_hint = _infer_intent_keywords(tokens, comments, [])
            contracts.append(
                {
                    "name": name,
                    "args": args,
                    "intent_signals": {
                        "tokens": tokens[:10],
                        "comments": comments[:3],
                        "comment_tags": comment_tags,
                        "intent_hint": intent_hint,
                    },
                }
            )
    return contracts


def _read_php_file(path: str) -> str:
    if not path:
        return ""
    p = ROOT_DIR / path
    if not p.exists():
        # Try stripping leading slash
        p = ROOT_DIR / path.lstrip("/")
    if not p.exists():
        return ""
    try:
        return p.read_text(encoding="utf-8", errors="ignore")
    except Exception:
        return ""


def _resolve_route_file(route: str, file_hint: str) -> str:
    if file_hint:
        return file_hint
    if not route:
        return ""
    candidate = route.lstrip("/")
    if candidate:
        p = ROOT_DIR / candidate
        if p.exists():
            return candidate
    return ""


def _index_tests(root: Path) -> List[Dict[str, Any]]:
    tests_dir = root / "tests"
    out: List[Dict[str, Any]] = []
    if not tests_dir.exists():
        return out
    for p in tests_dir.rglob("*.py"):
        try:
            text = p.read_text(encoding="utf-8", errors="ignore")
        except Exception:
            continue
        try:
            mtime = float(p.stat().st_mtime)
        except Exception:
            mtime = 0.0
        age_days = round((time.time() - mtime) / 86400, 1) if mtime else None
        test_names = re.findall(r"def\s+(test_[A-Za-z0-9_]+)\s*\(", text)
        class_names = re.findall(r"class\s+(Test[A-Za-z0-9_]+)\s*[:\(]", text)
        mark_tags = re.findall(r"@pytest\.mark\.([A-Za-z0-9_]+)", text)
        inline_comments = []
        for raw in text.splitlines():
            r = raw.strip()
            if r.startswith("#"):
                inline_comments.append(r.lstrip("# ").strip())
            if len(inline_comments) >= 6:
                break
        test_tokens = _tokens_from_identifiers(test_names + class_names + mark_tags, limit=18)
        test_intent = _infer_intent_keywords(test_tokens, inline_comments, test_names)
        refs = _extract_test_refs(text)
        out.append(
            {
                "path": str(p.relative_to(root)),
                "modules": sorted(refs["modules"]),
                "files": sorted(refs["files"]),
                "routes": sorted(refs["routes"]),
                "mtime": mtime,
                "age_days": age_days,
                "test_names": sorted(set(test_names))[:12],
                "test_classes": sorted(set(class_names))[:6],
                "test_tags": sorted(set(mark_tags))[:8],
                "test_tokens": test_tokens[:12],
                "test_intent_hint": test_intent,
                "test_comments": inline_comments[:4],
            }
        )
    return out


def _index_scenarios(root: Path) -> List[Dict[str, Any]]:
    out: List[Dict[str, Any]] = []
    if not SCENARIOS_DIR.exists():
        return out
    for p in SCENARIOS_DIR.rglob("*.yaml"):
        route = ""
        url = ""
        try:
            if yaml is not None:
                data = yaml.safe_load(p.read_text(encoding="utf-8")) or {}
                if isinstance(data, dict):
                    steps = data.get("steps") or []
                    if isinstance(steps, list):
                        for s in steps:
                            if not isinstance(s, dict):
                                continue
                            if s.get("url"):
                                url = s.get("url")
                                break
                            if s.get("route"):
                                route = s.get("route")
                                break
        except Exception:
            url = ""
        target = route or url
        out.append(
            {
                "path": str(p.relative_to(root)),
                "route": _normalize_route(target) if target else "",
            }
        )
    return out


def _match_tests_for_route(route: str, tests: List[Dict[str, Any]]) -> List[str]:
    if not route:
        return []
    hits = []
    target_tokens = _tokenize_route(route)
    min_hits = 1 if len(target_tokens) <= 2 else 2
    for t in tests:
        if route in (t.get("routes") or []):
            hits.append(t["path"])
            continue
        test_tokens = _test_token_candidates(t)
        if _token_overlap(target_tokens, test_tokens, min_hits):
            hits.append(t["path"])
    return sorted(set(hits))


def _match_tests_for_module(module_name: str, tests: List[Dict[str, Any]]) -> List[str]:
    if not module_name:
        return []
    hits = []
    target_tokens = _tokens_from_identifiers([module_name], limit=12)
    for t in tests:
        if module_name in (t.get("modules") or []):
            hits.append(t["path"])
            continue
        test_tokens = _test_token_candidates(t)
        if _token_overlap(target_tokens, test_tokens, 1):
            hits.append(t["path"])
    return sorted(set(hits))


def _match_tests_for_file(file_path: str, tests: List[Dict[str, Any]]) -> List[str]:
    if not file_path:
        return []
    base = Path(file_path).name
    stem = Path(file_path).stem
    target_tokens = _tokens_from_identifiers(
        [stem] + list(Path(file_path).parts),
        limit=14,
    )
    min_hits = 1 if len(target_tokens) <= 2 else 2
    hits = []
    for t in tests:
        for f in t.get("files") or []:
            if f.endswith(base):
                hits.append(t["path"])
                break
        else:
            test_tokens = _test_token_candidates(t)
            if _token_overlap(target_tokens, test_tokens, min_hits):
                hits.append(t["path"])
    return sorted(set(hits))


def _tests_meta(test_paths: List[str], tests_index: List[Dict[str, Any]]) -> Dict[str, Any]:
    if not test_paths:
        return {}
    ages = []
    for t in tests_index:
        if t.get("path") in test_paths and t.get("age_days") is not None:
            ages.append(float(t.get("age_days") or 0))
    if not ages:
        return {}
    meta = {
        "age_days_min": round(min(ages), 1),
        "age_days_max": round(max(ages), 1),
        "age_days_avg": round(sum(ages) / max(1, len(ages)), 1),
    }
    try:
        meta["stale"] = bool(meta["age_days_max"] >= 180)
    except Exception:
        meta["stale"] = False
    return meta


def _tests_signals(test_paths: List[str], tests_index: List[Dict[str, Any]]) -> Dict[str, Any]:
    if not test_paths:
        return {}
    names: List[str] = []
    classes: List[str] = []
    tags: List[str] = []
    tokens: List[str] = []
    comments: List[str] = []
    intent_scores: Dict[str, int] = {}
    for t in tests_index:
        if t.get("path") not in test_paths:
            continue
        names.extend(t.get("test_names") or [])
        classes.extend(t.get("test_classes") or [])
        tags.extend(t.get("test_tags") or [])
        tokens.extend(t.get("test_tokens") or [])
        comments.extend(t.get("test_comments") or [])
        hint = t.get("test_intent_hint") or {}
        for k, v in (hint.get("scores") or {}).items():
            try:
                intent_scores[k] = intent_scores.get(k, 0) + int(v or 0)
            except Exception:
                pass
    if not (names or classes or tags or tokens or comments):
        return {}
    suggested = None
    if intent_scores:
        suggested = max(intent_scores.items(), key=lambda kv: kv[1])[0]
    return {
        "test_names": sorted(set(names))[:10],
        "test_classes": sorted(set(classes))[:6],
        "test_tags": sorted(set(tags))[:8],
        "test_tokens": sorted(set(tokens))[:12],
        "test_comments": comments[:4],
        "intent_hint": {"suggested_intent": suggested, "scores": intent_scores} if suggested else {},
    }


def _load_experience_stats(db_path: Path) -> Dict[str, Dict[str, Any]]:
    if not db_path.exists():
        return {}
    stats: Dict[str, Dict[str, Any]] = {}
    try:
        conn = sqlite3.connect(str(db_path))
        conn.row_factory = sqlite3.Row
        rows = conn.execute(
            """
            SELECT related_files, scenario, summary, evidence_count, seen_count, source_type
            FROM experiences
            WHERE suppressed = 0
            ORDER BY COALESCE(updated_at, created_at) DESC
            """
        ).fetchall()
        for r in rows:
            rel = str(r["related_files"] or "").replace("\\", "/")
            if not rel:
                continue
            bucket = stats.setdefault(
                rel,
                {"count": 0, "top": [], "sources": {}},
            )
            try:
                count = int(r["evidence_count"] or 0)
            except Exception:
                count = 0
            bucket["count"] += max(1, count)
            source_type = str(r["source_type"] or "")
            if source_type:
                bucket["sources"][source_type] = bucket["sources"].get(source_type, 0) + 1
            bucket["top"].append(
                {
                    "scenario": str(r["scenario"] or ""),
                    "summary": str(r["summary"] or "")[:160],
                    "evidence_count": count,
                }
            )
        for rel in list(stats.keys()):
            stats[rel]["top"] = sorted(
                stats[rel]["top"],
                key=lambda x: int(x.get("evidence_count") or 0),
                reverse=True,
            )[:4]
    except Exception:
        stats = {}
    finally:
        try:
            conn.close()
        except Exception:
            pass
    return stats


def _derive_issue_questions(
    comment_hints: List[str],
    comment_tags: List[str],
    tests_signals: Dict[str, Any],
    repeat_signal: Dict[str, Any],
    runtime: Dict[str, Any],
    log_hints: Optional[List[str]] = None,
) -> List[str]:
    questions: List[str] = []
    if repeat_signal:
        top = repeat_signal.get("top") or []
        for t in top:
            summary = str(t.get("summary") or "").strip()
            if summary:
                questions.append(f"ما المشكلة التي سبّبت: {summary}?")
    for h in comment_hints:
        if any(tag in h.lower() for tag in ("fix", "bug", "todo", "fixme", "hack", "workaround")):
            questions.append(f"ما المشكلة التي كان الهدف منها: {h}?")
    test_names = tests_signals.get("test_names") or []
    for name in test_names[:4]:
        if any(k in name.lower() for k in ("regression", "bug", "issue", "fix", "fail")):
            questions.append(f"ما المشكلة التي أدت لإضافة الاختبار {name}?")
    last_error = runtime.get("last_error")
    if last_error:
        questions.append(f"ما سبب الخطأ الأخير: {str(last_error)[:120]}?")
    if log_hints:
        for h in log_hints[:3]:
            questions.append(f"ما سبب سجل اللوق: {str(h)[:140]}?")
    if comment_tags and not questions:
        questions.append(f"ما سبب إضافة هذا الجزء؟ (tags: {', '.join(comment_tags)})")
    return questions[:6]


def _match_scenarios_for_route(route: str, scenarios: List[Dict[str, Any]]) -> List[str]:
    if not route:
        return []
    hits = []
    for s in scenarios:
        if s.get("route") == route:
            hits.append(s["path"])
    return sorted(set(hits))


def _confidence_score(risk: str, has_tests: bool, has_scenarios: bool) -> float:
    base = 0.5
    if risk == "high":
        base = 0.4
    elif risk == "medium":
        base = 0.6
    elif risk == "low":
        base = 0.8
    if has_tests:
        base += 0.1
    if has_scenarios:
        base += 0.05
    if risk == "high" and not has_tests:
        base -= 0.1
    return max(0.1, min(0.95, round(base, 2)))


def build_code_contracts(root: Path = ROOT_DIR) -> Dict[str, Any]:
    start = time.time()
    code_index = _load_json(ANALYSIS_DIR / "code_index.json")
    if not code_index:
        return {"ok": False, "error": "code_index_missing"}

    openapi = _load_yaml(DOCS_DIR / "openapi.yaml")
    openapi_paths = openapi.get("paths") or {}

    callgraph = _load_callgraph(root)
    callgraph_by_route = {}
    if isinstance(callgraph, list):
        for item in callgraph:
            uri = _normalize_route(item.get("uri") or "")
            if uri:
                callgraph_by_route[uri] = item

    runtime_stats = _load_runtime_stats(DB_PATH)
    runtime_by_file: Dict[str, Dict[str, Any]] = {}
    for r in code_index.get("routes", []):
        uri = _normalize_route(r.get("uri") or r.get("url") or "")
        if not uri:
            continue
        file_path = r.get("file_path") or r.get("controller") or ""
        file_path = _resolve_route_file(uri, file_path)
        if not file_path:
            continue
        stats = runtime_stats.get(uri)
        if not stats:
            continue
        agg = runtime_by_file.setdefault(
            file_path,
            {
                "event_count": 0,
                "error_count": 0,
                "last_ts": 0.0,
                "avg_latency_ms": 0.0,
                "_latency_sum": 0.0,
                "last_error": "",
                "last_error_ts": 0.0,
            },
        )
        cnt = int(stats.get("event_count") or 0)
        agg["event_count"] += cnt
        agg["error_count"] += int(stats.get("error_count") or 0)
        agg["last_ts"] = max(agg["last_ts"], float(stats.get("last_ts") or 0))
        agg["_latency_sum"] += float(stats.get("avg_latency_ms") or 0.0) * cnt
        if float(stats.get("last_error_ts") or 0) > float(agg.get("last_error_ts") or 0):
            agg["last_error"] = stats.get("last_error") or ""
            agg["last_error_ts"] = float(stats.get("last_error_ts") or 0)
    for agg in runtime_by_file.values():
        cnt = agg.get("event_count") or 0
        if cnt:
            agg["avg_latency_ms"] = round(float(agg.get("_latency_sum") or 0.0) / cnt, 2)
            agg["error_rate"] = round(float(agg.get("error_count") or 0) / cnt, 3)
        else:
            agg["avg_latency_ms"] = 0.0
            agg["error_rate"] = 0.0
        agg.pop("_latency_sum", None)

    log_hints = _load_log_hints(root)
    log_hints_by_route = log_hints.get("routes") or {}
    log_hints_by_file = log_hints.get("files") or {}

    tests = _index_tests(root)
    scenarios = _index_scenarios(root)
    experience_stats = _load_experience_stats(DB_PATH)

    contracts: List[Dict[str, Any]] = []

    # API contracts
    enriched_api = 0
    for r in code_index.get("routes", []):
        uri = _normalize_route(r.get("uri") or r.get("url") or "")
        method = (r.get("http_method") or "GET").lower()
        file_path = r.get("file_path") or r.get("controller") or ""
        file_path = _resolve_route_file(uri, file_path)
        risk = _risk_tier(file_path)
        op = (openapi_paths.get(uri) or {}).get(method) or {}
        params = []
        for p in op.get("parameters") or []:
            if not isinstance(p, dict):
                continue
            params.append({"name": p.get("name"), "in": p.get("in"), "required": p.get("required")})
        php_text = _read_php_file(file_path)
        php_inputs = _extract_php_inputs(php_text) if php_text else {}
        php_outputs = _extract_php_outputs(php_text) if php_text else {}
        if php_inputs or php_outputs:
            enriched_api += 1
        tests_linked = _match_tests_for_route(uri, tests)
        scenarios_linked = _match_scenarios_for_route(uri, scenarios)
        tests_meta = _tests_meta(tests_linked, tests)
        tests_signals = _tests_signals(tests_linked, tests)
        repeat_signal = experience_stats.get(file_path.replace("\\", "/")) if file_path else None
        intent_tokens = _tokens_from_identifiers([uri.replace("/", " "), file_path])
        comment_hints = _extract_comment_hints(php_text) if php_text else []
        comment_tags = _extract_comment_tags(comment_hints)
        intent_hint = _infer_intent_keywords(
            intent_tokens,
            comment_hints,
            _tests_for_intent(tests_signals, tests_linked),
        )
        runtime = runtime_stats.get(uri) or {}
        runtime_hint = {}
        try:
            if runtime and runtime.get("error_count") or runtime.get("avg_latency_ms"):
                deps = (callgraph_by_route.get(uri) or {}).get("dependencies") or []
                if isinstance(deps, list):
                    dep_names = []
                    for d in deps:
                        if isinstance(d, dict):
                            name = d.get("name")
                            if name:
                                dep_names.append(str(name))
                    if dep_names:
                        runtime_hint = {
                            "hint_type": "dependency_hotspot",
                            "suspects": dep_names[:6],
                            "error_rate": runtime.get("error_rate", 0.0),
                            "avg_latency_ms": runtime.get("avg_latency_ms", 0.0),
                        }
        except Exception:
            runtime_hint = {}
        issue_context = {}
        if repeat_signal:
            issue_context = {
                "top_issues": repeat_signal.get("top") or [],
                "experience_count": repeat_signal.get("count") or 0,
            }
        log_hints = []
        try:
            log_hints = (log_hints_by_route.get(uri) or [])
            if not log_hints and file_path:
                log_hints = log_hints_by_file.get(file_path) or []
        except Exception:
            log_hints = []
        temporal_profile = _temporal_profile_text(php_text or "", file_path, lang="php")
        issue_questions = _derive_issue_questions(
            comment_hints, comment_tags, tests_signals, repeat_signal or {}, runtime, log_hints
        )
        contracts.append(
            {
                "id": f"api:{method}:{uri}",
                "kind": "api",
                "route": uri,
                "method": method.upper(),
                "file": file_path,
                "summary": op.get("summary") or op.get("description") or "",
                "params": params,
                "inputs": php_inputs,
                "outputs": php_outputs,
                "risk": risk,
                "dependencies": (callgraph_by_route.get(uri) or {}).get("dependencies") or [],
                "runtime": runtime,
                "runtime_causality": runtime_hint,
                "intent_signals": {
                    "tokens": intent_tokens[:10],
                    "comments": comment_hints[:4],
                    "comment_tags": comment_tags,
                    "tests": tests_linked[:4],
                    "tests_signals": tests_signals,
                    "intent_hint": intent_hint,
                },
                "repeat_signals": repeat_signal or {},
                "issue_context": issue_context,
                "issue_questions": issue_questions,
                "log_hints": log_hints,
                "temporal_profile": temporal_profile,
                "tests_meta": tests_meta,
                "tests": tests_linked,
                "scenarios": scenarios_linked,
                "confidence": _confidence_score(risk, bool(tests_linked), bool(scenarios_linked)),
            }
        )

    # PHP modules
    enriched_php = 0
    for path in code_index.get("php", {}).keys():
        risk = _risk_tier(path)
        php_text = _read_php_file(path)
        php_inputs = _extract_php_inputs(php_text) if php_text else {}
        php_outputs = _extract_php_outputs(php_text) if php_text else {}
        if php_inputs or php_outputs:
            enriched_php += 1
        tests_linked = _match_tests_for_file(path, tests)
        tests_meta = _tests_meta(tests_linked, tests)
        tests_signals = _tests_signals(tests_linked, tests)
        repeat_signal = experience_stats.get(path.replace("\\", "/")) if path else None
        comment_hints = _extract_comment_hints(php_text) if php_text else []
        comment_tags = _extract_comment_tags(comment_hints)
        php_vars = re.findall(r"\\$([A-Za-z_][A-Za-z0-9_]*)", php_text or "")
        intent_tokens = _tokens_from_identifiers(php_vars + [path])
        intent_hint = _infer_intent_keywords(
            intent_tokens,
            comment_hints,
            _tests_for_intent(tests_signals, tests_linked),
        )
        runtime = runtime_by_file.get(path) or {}
        issue_context = {}
        if repeat_signal:
            issue_context = {
                "top_issues": repeat_signal.get("top") or [],
                "experience_count": repeat_signal.get("count") or 0,
            }
        log_hints = []
        try:
            log_hints = log_hints_by_file.get(path) or []
        except Exception:
            log_hints = []
        issue_questions = _derive_issue_questions(
            comment_hints, comment_tags, tests_signals, repeat_signal or {}, runtime, log_hints
        )
        contracts.append(
            {
                "id": f"php:{path}",
                "kind": "php_module",
                "file": path,
                "inputs": php_inputs,
                "outputs": php_outputs,
                "risk": risk,
                "runtime": runtime,
                "intent_signals": {
                    "tokens": intent_tokens[:10],
                    "comments": comment_hints[:4],
                    "comment_tags": comment_tags,
                    "tests": tests_linked[:4],
                    "tests_signals": tests_signals,
                    "intent_hint": intent_hint,
                },
                "repeat_signals": repeat_signal or {},
                "issue_context": issue_context,
                "issue_questions": issue_questions,
                "log_hints": log_hints,
                "temporal_profile": temporal_profile,
                "tests_meta": tests_meta,
                "tests": tests_linked,
                "scenarios": _match_scenarios_for_route(_normalize_route(path), scenarios),
                "confidence": _confidence_score(risk, bool(tests_linked), False),
            }
        )

    # Python modules (agent core)
    function_contracts: List[Dict[str, Any]] = []
    for path in code_index.get("python", {}).keys():
        risk = _risk_tier(path)
        module = Path(path).stem
        tests_linked = _match_tests_for_module(module, tests)
        tests_meta = _tests_meta(tests_linked, tests)
        tests_signals = _tests_signals(tests_linked, tests)
        repeat_signal = experience_stats.get(path.replace("\\", "/")) if path else None
        temporal_profile = _temporal_profile_python_module(root / path)
        py_funcs = _extract_python_function_contracts(root / path)
        for fn in py_funcs:
            issue_context = {}
            if repeat_signal:
                issue_context = {
                    "top_issues": repeat_signal.get("top") or [],
                    "experience_count": repeat_signal.get("count") or 0,
                }
            intent_sig = fn.get("intent_signals") or {}
            comment_hints = intent_sig.get("comments") or []
            comment_tags = intent_sig.get("comment_tags") or []
            log_hints = []
            try:
                log_hints = log_hints_by_file.get(path) or []
            except Exception:
                log_hints = []
            issue_questions = _derive_issue_questions(
                comment_hints, comment_tags, tests_signals, repeat_signal or {}, {}, log_hints
            )
            function_contracts.append(
                {
                    "id": f"pyfunc:{path}:{fn.get('class') or ''}:{fn.get('name')}",
                    "kind": "python_function",
                    "file": path,
                    "module": module,
                    "class": fn.get("class") or "",
                    "name": fn.get("name"),
                    "args": fn.get("args") or [],
                    "doc": fn.get("doc") or "",
                    "has_return": fn.get("has_return"),
                    "returns_value": fn.get("returns_value"),
                    "raises": fn.get("raises"),
                    "calls": fn.get("calls") or [],
                    "line_start": fn.get("line_start"),
                    "line_end": fn.get("line_end"),
                    "risk": risk,
                    "intent_signals": intent_sig,
                    "repeat_signals": repeat_signal or {},
                    "issue_context": issue_context,
                    "issue_questions": issue_questions,
                    "log_hints": log_hints,
                    "tests_meta": tests_meta,
                    "tests_signals": tests_signals,
                    "temporal_profile": temporal_profile,
                    "tests": tests_linked,
                    "confidence": _confidence_score(risk, bool(tests_linked), False),
                }
            )
        contracts.append(
            {
                "id": f"py:{path}",
                "kind": "python_module",
                "file": path,
                "module": module,
                "risk": risk,
                "repeat_signals": repeat_signal or {},
                "tests_meta": tests_meta,
                "temporal_profile": temporal_profile,
                "tests": tests_linked,
                "confidence": _confidence_score(risk, bool(tests_linked), False),
            }
        )

    # JS modules
    for path in code_index.get("js", {}).keys():
        risk = _risk_tier(path)
        module = Path(path).stem
        tests_linked = _match_tests_for_module(module, tests)
        tests_meta = _tests_meta(tests_linked, tests)
        tests_signals = _tests_signals(tests_linked, tests)
        repeat_signal = experience_stats.get(path.replace("\\", "/")) if path else None
        js_text = _read_php_file(path)
        temporal_profile = _temporal_profile_text(js_text or "", path, lang="js")
        js_funcs = _extract_js_function_contracts(js_text) if js_text else []
        for fn in js_funcs:
            issue_context = {}
            if repeat_signal:
                issue_context = {
                    "top_issues": repeat_signal.get("top") or [],
                    "experience_count": repeat_signal.get("count") or 0,
                }
            intent_sig = fn.get("intent_signals") or {}
            comment_hints = intent_sig.get("comments") or []
            comment_tags = intent_sig.get("comment_tags") or []
            log_hints = []
            try:
                log_hints = log_hints_by_file.get(path) or []
            except Exception:
                log_hints = []
            issue_questions = _derive_issue_questions(
                comment_hints, comment_tags, tests_signals, repeat_signal or {}, {}, log_hints
            )
            function_contracts.append(
                {
                    "id": f"jsfunc:{path}:{fn.get('name')}",
                    "kind": "js_function",
                    "file": path,
                    "module": module,
                    "name": fn.get("name"),
                    "args": fn.get("args") or [],
                    "risk": risk,
                    "intent_signals": intent_sig,
                    "repeat_signals": repeat_signal or {},
                    "issue_context": issue_context,
                    "issue_questions": issue_questions,
                    "log_hints": log_hints,
                    "tests_meta": tests_meta,
                    "tests_signals": tests_signals,
                    "temporal_profile": temporal_profile,
                    "tests": tests_linked,
                    "confidence": _confidence_score(risk, bool(tests_linked), False),
                }
            )
        contracts.append(
            {
                "id": f"js:{path}",
                "kind": "js_module",
                "file": path,
                "module": module,
                "risk": risk,
                "repeat_signals": repeat_signal or {},
                "tests_meta": tests_meta,
                "temporal_profile": temporal_profile,
                "tests": tests_linked,
                "confidence": _confidence_score(risk, bool(tests_linked), False),
            }
        )

    high_risk_untested = [
        c for c in contracts if c.get("risk") == "high" and not (c.get("tests") or c.get("scenarios"))
    ]

    intent_signal_count = sum(1 for c in contracts if c.get("intent_signals"))
    repeat_signal_count = sum(1 for c in contracts if c.get("repeat_signals"))
    tests_meta_count = sum(1 for c in contracts if c.get("tests_meta"))
    temporal_stateful = sum(
        1
        for c in contracts
        if isinstance(c.get("temporal_profile"), dict)
        and c["temporal_profile"].get("stateful")
    )
    temporal_startup = sum(
        1
        for c in contracts
        if isinstance(c.get("temporal_profile"), dict)
        and c["temporal_profile"].get("startup_exec")
    )

    summary = {
        "total": len(contracts),
        "high_risk_untested": len(high_risk_untested),
        "tests_indexed": len(tests),
        "scenarios_indexed": len(scenarios),
        "api_enriched": enriched_api,
        "php_enriched": enriched_php,
        "function_contracts": len(function_contracts),
        "runtime_routes": len(runtime_stats),
        "runtime_files": len(runtime_by_file),
        "intent_signals": intent_signal_count,
        "repeat_signals": repeat_signal_count,
        "tests_meta": tests_meta_count,
        "temporal_stateful": temporal_stateful,
        "temporal_startup": temporal_startup,
    }

    ANALYSIS_DIR.mkdir(parents=True, exist_ok=True)
    DOCS_DIR.mkdir(parents=True, exist_ok=True)
    (ANALYSIS_DIR / "code_contracts.json").write_text(
        json.dumps(
            {"summary": summary, "contracts": contracts, "function_contracts": function_contracts},
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )

    report_lines = [
        "# تقرير العقود وربط الاختبارات",
        "",
        f"- إجمالي العقود: {summary['total']}",
        f"- عالي المخاطر بلا اختبارات/سيناريوهات: {summary['high_risk_untested']}",
        f"- اختبارات مفهرسة: {summary['tests_indexed']}",
        f"- سيناريوهات مفهرسة: {summary['scenarios_indexed']}",
        f"- عقود API مُثرّاة بمدخلات/مخرجات: {summary['api_enriched']}",
        f"- ملفات PHP مُثرّاة بمدخلات/مخرجات: {summary['php_enriched']}",
        f"- عقود الدوال (Python/JS): {summary['function_contracts']}",
        f"- Routes لديها Runtime evidence: {summary['runtime_routes']}",
        f"- Files لديها Runtime evidence: {summary['runtime_files']}",
        f"- Runtime causality hints: {sum(1 for c in contracts if c.get('runtime_causality'))}",
        f"- Intent signals attached: {summary['intent_signals']}",
        f"- Repeat signals attached: {summary['repeat_signals']}",
        f"- Tests meta attached: {summary['tests_meta']}",
        f"- Temporal stateful contracts: {summary['temporal_stateful']}",
        f"- Startup-executed contracts: {summary['temporal_startup']}",
        "",
        "## مخرجات",
        "- `analysis/code_contracts.json` خريطة العقود وربط الاختبارات.",
    ]
    (DOCS_DIR / "code_contracts_report.md").write_text("\n".join(report_lines), encoding="utf-8")

    return {
        "ok": True,
        "summary": summary,
        "output": {
            "contracts": str(ANALYSIS_DIR / "code_contracts.json"),
            "report": str(DOCS_DIR / "code_contracts_report.md"),
        },
        "duration_sec": round(time.time() - start, 3),
    }


if __name__ == "__main__":
    print(json.dumps(build_code_contracts(), ensure_ascii=False, indent=2))
