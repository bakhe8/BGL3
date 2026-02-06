from __future__ import annotations

"""
autonomous_policy.py
--------------------
Allow the agent to propose and apply policy_expectations changes directly
when autonomous mode is enabled.
"""

import json
import os
import re
from pathlib import Path
from typing import Any, Dict, List

try:
    from .llm_client import LLMClient  # type: ignore
    from .config_loader import load_config  # type: ignore
except Exception:
    from llm_client import LLMClient
    try:
        from config_loader import load_config  # type: ignore
    except Exception:
        load_config = None  # type: ignore


def _load_rules(path: Path) -> List[Dict[str, Any]]:
    if not path.exists():
        return []
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        return data if isinstance(data, list) else []
    except Exception:
        return []


def _save_rules(path: Path, rules: List[Dict[str, Any]]) -> None:
    path.write_text(json.dumps(rules, ensure_ascii=False, indent=2), encoding="utf-8")


def _rule_key(rule: Dict[str, Any]) -> str:
    return f"{rule.get('method','').upper()} {rule.get('uri','')}"

def _infer_status_from_error(err: str, body: str) -> int | None:
    text = f"{err or ''} {body or ''}".lower()
    if "method not allowed" in text or " 405" in text or "405" in text:
        return 405
    if "bad request" in text or " 400" in text or "400" in text or "missing" in text or "مطلوب" in text:
        return 400
    if "unauthorized" in text or " 401" in text or "401" in text:
        return 401
    if "forbidden" in text or " 403" in text or "403" in text:
        return 403
    if "not found" in text or " 404" in text or "404" in text:
        return 404
    if "internal server error" in text or " 500" in text or "500" in text:
        return 500
    if "winerror 10061" in text or "connection refused" in text:
        return 503
    return None

def _candidate_to_rule(candidate: Dict[str, Any]) -> Dict[str, Any] | None:
    uri = candidate.get("uri")
    if not uri:
        return None
    method = (candidate.get("method") or "ANY").upper()
    status = candidate.get("status")
    err = str(candidate.get("error") or "")
    body = str(candidate.get("error_body") or "")
    if status is None:
        status = _infer_status_from_error(err, body)
    rule: Dict[str, Any] = {
        "uri": uri,
        "method": method,
        "expected_statuses": [status] if status is not None else [],
        "reason": "auto_fallback_from_candidate",
        "category": "policy_expected_auto",
    }
    if status is None:
        rule["allow_any_body"] = True
    if err:
        snippet = err.strip().splitlines()[0][:80]
        if snippet:
            rule["match_error_regex"] = re.escape(snippet)
    return rule

def _fallback_patch(context: Dict[str, Any]) -> Dict[str, Any] | None:
    candidates = [c for c in (context.get("policy_candidates") or []) if isinstance(c, dict)]
    expected = [e for e in (context.get("expected_failures") or []) if isinstance(e, dict)]
    max_rules = int(os.getenv("BGL_POLICY_FALLBACK_MAX", "3") or 3)
    patch_rules: List[Dict[str, Any]] = []

    candidates = sorted(candidates, key=lambda c: float(c.get("confidence", 0) or 0), reverse=True)
    for c in candidates:
        rule = _candidate_to_rule(c)
        if rule:
            patch_rules.append(rule)
        if len(patch_rules) >= max_rules:
            break

    if not patch_rules and expected:
        for e in expected[:max_rules]:
            uri = e.get("uri")
            if not uri:
                continue
            method = (e.get("method") or "ANY").upper()
            status = e.get("status")
            rule = {
                "uri": uri,
                "method": method,
                "expected_statuses": [status] if status is not None else [],
                "reason": "auto_fallback_from_expected_failure",
                "category": "policy_expected_auto",
            }
            if status is None:
                rule["allow_any_body"] = True
            patch_rules.append(rule)
            if len(patch_rules) >= max_rules:
                break

    if not patch_rules:
        return None
    return {"action": "add", "rules": patch_rules, "source": "fallback"}


def _apply_patch(rules: List[Dict[str, Any]], patch: Dict[str, Any]) -> Dict[str, Any]:
    action = str(patch.get("action", "add")).lower()
    patch_rules = patch.get("rules") or []
    if not isinstance(patch_rules, list):
        patch_rules = []

    updated = []
    removed = []
    added = []

    # Build index
    idx = { _rule_key(r): i for i, r in enumerate(rules) if isinstance(r, dict) }

    for r in patch_rules:
        if not isinstance(r, dict):
            continue
        key = _rule_key(r)
        if action == "remove":
            if key in idx:
                rules[idx[key]] = None  # mark
                removed.append(key)
        elif action == "update":
            if key in idx:
                base = rules[idx[key]] or {}
                if isinstance(base, dict):
                    base.update(r)
                    rules[idx[key]] = base
                    updated.append(key)
            else:
                rules.append(r)
                added.append(key)
        else:  # add
            if key not in idx:
                rules.append(r)
                added.append(key)

    # purge None
    rules = [r for r in rules if r is not None]
    return {
        "action": action,
        "added": added,
        "updated": updated,
        "removed": removed,
        "total_rules": len(rules),
        "rules": rules,
    }


def apply_autonomous_policy_edit(root_dir: Path, diagnostic_map: Dict[str, Any]) -> Dict[str, Any]:
    """
    Uses LLM to propose a policy_expectations patch and applies it immediately.
    Returns summary of changes.
    """
    rules_path = root_dir / ".bgl_core" / "brain" / "policy_expectations.json"
    rules = _load_rules(rules_path)

    findings = diagnostic_map.get("findings") or {}
    context = {
        "failing_routes": findings.get("failing_routes") or [],
        "worst_routes": findings.get("worst_routes") or [],
        "api_scan": findings.get("api_scan") or {},
        "signals": findings.get("signals") or {},
        "policy_candidates": findings.get("policy_candidates") or [],
        "policy_auto_promoted": findings.get("policy_auto_promoted") or [],
        "expected_failures": findings.get("expected_failures") or [],
        "current_rules_count": len(rules),
    }

    prompt = f"""
You are the BGL3 agent. Propose ONE change to policy_expectations.json.
This is autonomous and applied immediately. Output JSON ONLY:
{{
  "action": "add" | "update" | "remove",
  "rules": [{{"uri":"...", "method":"GET/POST", "expected_statuses":[400], "reason":"...", "category":"policy_expected_auto"}}]
}}

Context:
{json.dumps(context, ensure_ascii=False, indent=2)}
"""

    patch = None
    try:
        client = LLMClient()
        patch = client.chat_json(prompt, temperature=0.2)
    except Exception:
        patch = None

    # Optional fallback when LLM is unavailable.
    fallback_enabled = True
    if load_config is not None:
        try:
            cfg = load_config(root_dir) or {}
            fallback_enabled = bool(cfg.get("autonomous_policy_fallback", 1))
        except Exception:
            fallback_enabled = True
    if not isinstance(patch, dict):
        patch = _fallback_patch(context) if fallback_enabled else None
        if patch is None:
            return {"status": "skipped", "reason": "invalid_patch_or_no_fallback"}

    applied = _apply_patch(rules, patch)
    _save_rules(rules_path, applied["rules"])
    return {"status": "applied", **applied}
