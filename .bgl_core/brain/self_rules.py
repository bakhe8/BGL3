from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any, Dict, List

try:
    from .brain_types import Rule, OperationalMode  # type: ignore
except Exception:
    from brain_types import Rule, OperationalMode

ALLOWED_CONDITIONS = {"ui_semantic_changed", "intent_is_evolve"}
ALLOWED_ACTIONS = {"set_mode"}

DEFAULT_SELF_RULES: Dict[str, Any] = {
    "rules": [
        {
            "name": "UI Semantic Change -> Analysis",
            "condition": "ui_semantic_changed",
            "action_type": "set_mode",
            "params": {"mode": "analysis"},
            "priority": 75,
            "enabled": True,
        }
    ],
    "overrides": {},
    "last_updated": None,
    "history": [],
}


def load_self_rules(root_dir: Path) -> Dict[str, Any]:
    path = root_dir / ".bgl_core" / "brain" / "self_rules.json"
    if not path.exists():
        return dict(DEFAULT_SELF_RULES)
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(data, dict):
            return dict(DEFAULT_SELF_RULES)
        merged = dict(DEFAULT_SELF_RULES)
        merged.update(data)
        merged.setdefault("rules", DEFAULT_SELF_RULES["rules"])
        merged.setdefault("overrides", {})
        merged.setdefault("history", [])
        return merged
    except Exception:
        return dict(DEFAULT_SELF_RULES)


def save_self_rules(root_dir: Path, data: Dict[str, Any]) -> None:
    path = root_dir / ".bgl_core" / "brain" / "self_rules.json"
    path.write_text(json.dumps(data, ensure_ascii=False, indent=2), encoding="utf-8")


def safe_rules_from_data(
    data: Dict[str, Any],
    *,
    protected_names: set[str],
    existing_names: set[str],
) -> List[Rule]:
    rules: List[Rule] = []
    raw_rules = data.get("rules") or []
    if not isinstance(raw_rules, list):
        return rules
    for raw in raw_rules:
        if not isinstance(raw, dict):
            continue
        name = str(raw.get("name") or "").strip()
        if not name or name in protected_names or name in existing_names:
            continue
        if raw.get("enabled") is False:
            continue
        condition = str(raw.get("condition") or "").strip()
        action_type = str(raw.get("action_type") or "").strip()
        if condition not in ALLOWED_CONDITIONS or action_type not in ALLOWED_ACTIONS:
            continue
        params = raw.get("params") or {}
        if not isinstance(params, dict):
            params = {}
        mode = params.get("mode")
        if isinstance(mode, str):
            try:
                mode = OperationalMode(mode)
            except Exception:
                continue
        if mode not in (OperationalMode.ANALYSIS, OperationalMode.AUDIT, OperationalMode.EXECUTION):
            continue
        params["mode"] = mode
        try:
            priority = int(raw.get("priority", 60))
        except Exception:
            priority = 60
        priority = max(1, min(200, priority))
        rules.append(
            Rule(
                name=name,
                condition=condition,
                action_type=action_type,
                params=params,
                priority=priority,
            )
        )
    return rules


def update_self_rules(root_dir: Path, findings: Dict[str, Any]) -> Dict[str, Any]:
    """
    Auto-update self rules (safe subset only).
    Currently adjusts the priority of UI semantic rule based on deltas.
    """
    data = load_self_rules(root_dir)
    rules = data.get("rules") or []
    if not isinstance(rules, list):
        rules = []
    ui_delta = findings.get("ui_semantic_delta") or {}
    self_policy = findings.get("self_policy") or {}
    thresholds = (self_policy.get("semantic_thresholds") or {}) if isinstance(self_policy, dict) else {}
    propose_thr = int(thresholds.get("propose_fix_change", 6) or 6)

    changed = bool(ui_delta.get("changed"))
    try:
        change_count = int(ui_delta.get("change_count") or 0)
    except Exception:
        change_count = 0

    # Outcome patterns
    false_pos = 0
    blocked = 0
    for o in (findings.get("recent_outcomes") or []):
        if not isinstance(o, dict):
            continue
        result = str(o.get("outcome_result") or "")
        if result == "false_positive":
            false_pos += 1
        if result == "blocked":
            blocked += 1

    target_name = "UI Semantic Change -> Analysis"
    target_rule = None
    for r in rules:
        if isinstance(r, dict) and r.get("name") == target_name:
            target_rule = r
            break
    if target_rule is None:
        target_rule = dict(DEFAULT_SELF_RULES["rules"][0])
        rules.append(target_rule)

    try:
        current_priority = int(target_rule.get("priority", 75))
    except Exception:
        current_priority = 75
    new_priority = current_priority

    if changed and change_count >= propose_thr:
        new_priority = max(new_priority, 85)
    elif changed and change_count >= 3:
        new_priority = max(new_priority, 75)
    elif not changed and (false_pos + blocked) >= 2:
        new_priority = min(new_priority, 60)

    new_priority = max(40, min(120, new_priority))
    changed_flag = new_priority != current_priority

    if changed_flag:
        target_rule["priority"] = new_priority
        entry = {
            "ts": time.time(),
            "changes": [f"{target_name}.priority:{current_priority}->{new_priority}"],
            "context": {
                "ui_changed": changed,
                "change_count": change_count,
                "false_positive": false_pos,
                "blocked": blocked,
            },
        }
        history = data.get("history") or []
        if not isinstance(history, list):
            history = []
        history.append(entry)
        data["history"] = history[-30:]
        data["last_updated"] = entry["ts"]
        data["rules"] = rules
        save_self_rules(root_dir, data)

    return data

