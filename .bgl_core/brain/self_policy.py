from __future__ import annotations

import json
import time
from pathlib import Path
from typing import Any, Dict, List


DEFAULT_SELF_POLICY: Dict[str, Any] = {
    "intent_bias": {
        "reasoning": 1.0,
        "hypothesis": 0.95,
        "purpose": 0.8,
        "signals": 0.7,
        "ui_semantic": 0.6,
    },
    "semantic_thresholds": {
        "propose_fix_change": 6,
        "auto_fix_change": 14,
    },
    "last_updated": None,
    "history": [],
}


def _clamp(val: float, lo: float, hi: float) -> float:
    return max(lo, min(hi, val))


def _coerce_int(val: Any, default: int) -> int:
    try:
        return int(val)
    except Exception:
        return default


def load_self_policy(root_dir: Path) -> Dict[str, Any]:
    path = root_dir / ".bgl_core" / "brain" / "self_policy.json"
    if not path.exists():
        return dict(DEFAULT_SELF_POLICY)
    try:
        data = json.loads(path.read_text(encoding="utf-8"))
        if not isinstance(data, dict):
            return dict(DEFAULT_SELF_POLICY)
        merged = dict(DEFAULT_SELF_POLICY)
        merged.update(data)
        merged.setdefault("intent_bias", {})
        merged.setdefault("semantic_thresholds", {})
        for k, v in DEFAULT_SELF_POLICY["intent_bias"].items():
            merged["intent_bias"].setdefault(k, v)
        for k, v in DEFAULT_SELF_POLICY["semantic_thresholds"].items():
            merged["semantic_thresholds"].setdefault(k, v)
        merged.setdefault("history", [])
        return merged
    except Exception:
        return dict(DEFAULT_SELF_POLICY)


def save_self_policy(root_dir: Path, policy: Dict[str, Any]) -> None:
    path = root_dir / ".bgl_core" / "brain" / "self_policy.json"
    path.write_text(json.dumps(policy, ensure_ascii=False, indent=2), encoding="utf-8")


def update_self_policy(root_dir: Path, findings: Dict[str, Any]) -> Dict[str, Any]:
    """
    Auto-update self policy without human approval.
    Uses UI semantic deltas + outcomes + signals for light tuning.
    """
    policy = load_self_policy(root_dir)
    changes: List[str] = []

    ui_delta = findings.get("ui_semantic_delta") or {}
    signals = findings.get("signals") or {}
    counts = signals.get("counts") or {}
    recent_outcomes = findings.get("recent_outcomes") or []

    changed = bool(ui_delta.get("changed"))
    change_count = _coerce_int(ui_delta.get("change_count"), 0)
    actionable = _coerce_int(counts.get("actionable_failures"), 0)

    # Outcome patterns
    false_pos = 0
    blocked = 0
    for o in recent_outcomes:
        if not isinstance(o, dict):
            continue
        result = str(o.get("outcome_result") or "")
        if result == "false_positive":
            false_pos += 1
        if result == "blocked":
            blocked += 1

    # Threshold tuning for semantic change
    thresholds = policy.get("semantic_thresholds") or {}
    propose_thr = _coerce_int(thresholds.get("propose_fix_change"), 6)
    auto_thr = _coerce_int(thresholds.get("auto_fix_change"), 14)

    if changed:
        if actionable > 0 and change_count >= 4:
            propose_thr = max(4, propose_thr - 1)
            changes.append("propose_fix_change:-1 (ui_change + actionable)")
        elif (false_pos + blocked) >= 2 and change_count < 10:
            propose_thr = min(20, propose_thr + 1)
            changes.append("propose_fix_change:+1 (false_positive/blocked)")

    if propose_thr >= auto_thr:
        auto_thr = propose_thr + 6
        changes.append("auto_fix_change:+ (separated from propose threshold)")

    thresholds["propose_fix_change"] = propose_thr
    thresholds["auto_fix_change"] = auto_thr
    policy["semantic_thresholds"] = thresholds

    # Intent bias tuning for ui_semantic
    bias = policy.get("intent_bias") or {}
    ui_bias = float(bias.get("ui_semantic", 0.6) or 0.6)
    if changed:
        if actionable > 0:
            ui_bias = _clamp(ui_bias + 0.05, 0.3, 1.2)
            changes.append("intent_bias.ui_semantic:+0.05")
        elif (false_pos + blocked) >= 2:
            ui_bias = _clamp(ui_bias - 0.05, 0.3, 1.2)
            changes.append("intent_bias.ui_semantic:-0.05")
    bias["ui_semantic"] = ui_bias
    policy["intent_bias"] = bias

    # Update history
    if changes:
        entry = {
            "ts": time.time(),
            "changes": changes,
            "context": {
                "ui_changed": changed,
                "change_count": change_count,
                "actionable": actionable,
                "false_positive": false_pos,
                "blocked": blocked,
            },
        }
        history = policy.get("history") or []
        if not isinstance(history, list):
            history = []
        history.append(entry)
        policy["history"] = history[-30:]
        policy["last_updated"] = entry["ts"]
        save_self_policy(root_dir, policy)

    return policy

