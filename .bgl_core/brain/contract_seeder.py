from __future__ import annotations

import re
from pathlib import Path
import os
from typing import Dict, Any, List, Tuple
import time

import yaml  # type: ignore

ROOT = Path(__file__).resolve().parents[2]
MANUAL = ROOT / "docs" / "openapi.manual.yaml"
MERGED = ROOT / "docs" / "openapi.yaml"
LOG_PATH = ROOT / ".bgl_core" / "logs" / "contract_changes.log"


def _load_yaml(path: Path) -> Dict[str, Any]:
    if not path.exists():
        return {"openapi": "3.0.0", "info": {"title": "BGL3 API (Manual Seed)", "version": "0.1"}, "paths": {}}
    try:
        return yaml.safe_load(path.read_text(encoding="utf-8")) or {"paths": {}}
    except Exception:
        return {"paths": {}}


def _guess_value(key: str) -> Any:
    k = key.lower()
    if k == "index":
        return 1
    if k == "page":
        return 1
    if k == "history_id":
        # Use a synthetic import ID which is supported by /api/get-history-snapshot.php
        return "import_synthetic_1"
    if k == "is_test_data" or k.startswith("is_"):
        return False
    if k == "related_to":
        return "contract"
    if k in ("type", "guarantee_type"):
        return "Initial"
    if "id" in k:
        return 1
    if "amount" in k or "value" in k:
        return 1000
    if "date" in k or "expiry" in k or "issue" in k:
        return "2026-02-03"
    if "action" in k:
        return "summary"
    if "reason" in k:
        return "probe"
    if "name" in k:
        return "Probe {{ts}}"
    if "note" in k:
        return "probe"
    if "source" in k:
        return "manual_paste_{{date}}"
    if "number" in k:
        return "PROBE-{{ts}}"
    return "probe"


def _extract_post_fields(text: str) -> List[str]:
    fields = set()
    for pat in (
        r"Input::string\(\s*\$input\s*,\s*'([^']+)'",
        r"Input::int\(\s*\$input\s*,\s*'([^']+)'",
        r"Input::array\(\s*\$input\s*,\s*'([^']+)'",
        r"\$input\[['\"]([^'\"]+)['\"]\]",
    ):
        for m in re.findall(pat, text):
            fields.add(m)
    return sorted(fields)


def _extract_get_params(text: str) -> List[Tuple[str, bool]]:
    params = {}
    for m in re.findall(r"\$_GET\[['\"]([^'\"]+)['\"]\]", text):
        params[m] = False
    # crude required detection: if variable checked with "!$var" after assignment
    for var in re.findall(r"\$(\w+)\s*=\s*\$_GET\[['\"]([^'\"]+)['\"]\]", text):
        vname, key = var
        if re.search(rf"if\s*\(\s*!\s*\${vname}\b", text):
            params[key] = True
    return [(k, params[k]) for k in params]


def _param_example_is_placeholder(name: str, example: Any, force: bool = False) -> bool:
    """
    Decide whether an existing OpenAPI parameter example should be updated.
    We keep this conservative to avoid rewriting curated specs.
    """
    if force:
        return True
    n = (name or "").lower()
    if example is None:
        return True
    # Common placeholder inserted by older seeders
    if isinstance(example, str) and example.strip().lower() in {"probe"}:
        return True
    # index/page must be numeric in several endpoints; "probe" or other non-digits breaks PHP 8 (TypeError).
    if n in {"index", "page"}:
        if isinstance(example, str) and not example.strip().isdigit():
            return True
    # history_id example "123" is almost always invalid; prefer synthetic import id.
    if n == "history_id":
        if isinstance(example, str) and example.strip().isdigit():
            return True
    return False


def _ensure_method(manual_paths: Dict[str, Any], uri: str, method: str) -> Dict[str, Any]:
    manual_paths.setdefault(uri, {})
    manual_paths[uri].setdefault(method, {})
    return manual_paths[uri][method]


def _post_only(text: str) -> bool:
    return bool(
        re.search(r"REQUEST_METHOD'\]\s*!==?\s*'POST'", text)
        or re.search(r"REQUEST_METHOD'\]\s*!=\s*'POST'", text)
    )


def _example_is_placeholder(example: Any, force: bool = False) -> bool:
    if force:
        return True
    if not isinstance(example, dict):
        return True
    for v in example.values():
        if isinstance(v, str) and v.strip() in {"probe", "Probe Name", "PROBE-001"}:
            return True
    return False


def seed_contract(force: bool = False, refresh: bool = False) -> dict:
    merged = _load_yaml(MERGED)
    manual = _load_yaml(MANUAL)
    force = bool(force) or os.getenv("BGL_FORCE_CONTRACT_SEED", "0") == "1"
    refresh = bool(refresh) or os.getenv("BGL_FORCE_CONTRACT_REFRESH", "0") == "1" or force
    manual_paths = manual.setdefault("paths", {})
    changes = []

    for uri, methods in (merged.get("paths") or {}).items():
        file_path = ROOT / uri.lstrip("/")
        file_text = ""
        if file_path.is_file():
            file_text = file_path.read_text(encoding="utf-8", errors="ignore")
        if file_text and _post_only(file_text):
            # Override to POST-only if endpoint explicitly blocks non-POST
            manual_paths[uri] = {"post": manual_paths.get(uri, {}).get("post", {})}
        for method, op in (methods or {}).items():
            m = method.lower()
            if m in ("post", "put", "patch", "delete"):
                current = _ensure_method(manual_paths, uri, m)
                rb = current.get("requestBody", {}).get("content", {}).get("application/json", {})
                if "example" in rb and not _example_is_placeholder(rb.get("example"), force=force):
                    continue
                fields = _extract_post_fields(file_text) if file_text else []
                if not fields:
                    continue
                example = {k: _guess_value(k) for k in fields}
                current.setdefault("requestBody", {}).setdefault("content", {}).setdefault(
                    "application/json", {}
                )["example"] = example
                changes.append(f"{uri} {m}: requestBody example seeded ({len(fields)} fields)")
            elif m in ("get", "head"):
                current = _ensure_method(manual_paths, uri, m)
                params = _extract_get_params(file_text) if file_text else []
                if not params:
                    continue

                existing_params = current.get("parameters") if isinstance(current.get("parameters"), list) else []
                # Index existing params by name for updates
                by_name: Dict[str, Dict[str, Any]] = {}
                for p in existing_params:
                    if isinstance(p, dict) and p.get("in") == "query" and p.get("name"):
                        by_name[str(p["name"])] = p

                out_params: List[Dict[str, Any]] = []
                updated = 0
                created = 0
                for key, required in params:
                    existing = by_name.get(key)
                    if existing:
                        # Update required/example if placeholder
                        existing["required"] = bool(required) or bool(existing.get("required"))
                        if _param_example_is_placeholder(key, existing.get("example"), force=force):
                            existing["example"] = _guess_value(key)
                            updated += 1
                        out_params.append(existing)
                    else:
                        out_params.append(
                            {
                                "in": "query",
                                "name": key,
                                "required": bool(required),
                                "schema": {"type": "string"},
                                "example": _guess_value(key),
                            }
                        )
                        created += 1

                # Preserve any extra parameters already present but not detected in file_text
                for p in existing_params:
                    if not isinstance(p, dict) or p.get("in") != "query" or not p.get("name"):
                        continue
                    if str(p.get("name")) not in {k for (k, _) in params}:
                        out_params.append(p)

                current["parameters"] = out_params
                if created or updated:
                    changes.append(
                        f"{uri} {m}: query params updated (created={created}, updated_examples={updated})"
                    )

    stamp = time.strftime("%Y-%m-%d %H:%M:%S")
    if refresh:
        info = manual.setdefault("info", {})
        info["x-bgl-last-refresh"] = stamp
        if "version" in info and isinstance(info.get("version"), str):
            info["version"] = info.get("version") or "0.1"
        if not changes:
            changes.append("forced_refresh")

    # Backup before overwrite
    if MANUAL.exists():
        backup = MANUAL.with_suffix(".yaml.bak")
        backup.write_text(MANUAL.read_text(encoding="utf-8"), encoding="utf-8")

    MANUAL.write_text(
        yaml.safe_dump(manual, sort_keys=False, allow_unicode=True), encoding="utf-8"
    )
    if changes:
        LOG_PATH.parent.mkdir(parents=True, exist_ok=True)
        LOG_PATH.write_text(
            LOG_PATH.read_text(encoding="utf-8") + f"\n[{stamp}]\n" + "\n".join(changes)
            if LOG_PATH.exists()
            else f"[{stamp}]\n" + "\n".join(changes),
            encoding="utf-8",
        )
    return {"changed": len(changes), "log": str(LOG_PATH)}


if __name__ == "__main__":
    import time
    seed_contract()
    print(MANUAL)
