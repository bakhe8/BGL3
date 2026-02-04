from __future__ import annotations

"""
autonomous_policy.py
--------------------
Allow the agent to propose and apply policy_expectations changes directly
when autonomous mode is enabled.
"""

import json
from pathlib import Path
from typing import Any, Dict, List

try:
    from .llm_client import LLMClient  # type: ignore
except Exception:
    from llm_client import LLMClient


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

    try:
        client = LLMClient()
        patch = client.chat_json(prompt, temperature=0.2)
    except Exception:
        # If LLM fails, do nothing.
        return {"status": "skipped", "reason": "llm_failed"}

    if not isinstance(patch, dict):
        return {"status": "skipped", "reason": "invalid_patch"}

    applied = _apply_patch(rules, patch)
    _save_rules(rules_path, applied["rules"])
    return {"status": "applied", **applied}

