from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import Any, Dict, List, Optional, Tuple

ROOT = Path(__file__).resolve().parents[2]
REPORT_PATH = ROOT / ".bgl_core" / "logs" / "latest_report.json"
SELECTION_PATH = ROOT / ".bgl_core" / "logs" / "scenario_selection.json"
STRATEGY_STATE_PATH = ROOT / ".bgl_core" / "logs" / "strategy_state.json"
SCENARIO_SCORES_PATH = ROOT / ".bgl_core" / "logs" / "scenario_value_scores.json"
AUTO_GATE_POLICY_PATH = ROOT / ".bgl_core" / "logs" / "auto_gate_policy.json"


def _safe_json(path: Path) -> Dict[str, Any]:
    try:
        if not path.exists():
            return {}
        return json.loads(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _write_json(path: Path, payload: Dict[str, Any]) -> None:
    try:
        path.parent.mkdir(parents=True, exist_ok=True)
        path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass


def _derive_failure_typing(report: Dict[str, Any], cfg: Dict[str, Any]) -> List[Dict[str, Any]]:
    actions: List[Dict[str, Any]] = []
    ui_cov = (report.get("ui_action_coverage") or {}).get("coverage_ratio")
    gap_runs = report.get("gap_runs")
    gap_changed = report.get("gap_changed")
    min_ui = float(cfg.get("min_ui_action_coverage", 30) or 30)

    if ui_cov is not None:
        try:
            ui_cov = float(ui_cov)
        except Exception:
            ui_cov = None
    if ui_cov is not None and ui_cov < min_ui:
        actions.append(
            {
                "signal": "ui_action_coverage_low",
                "severity": "warn",
                "value": ui_cov,
                "target": min_ui,
                "decision": "synthetic_or_safe_actions",
                "reason": "coverage below target; prioritize non-write actions and synthetic coverage",
            }
        )
    if gap_runs is not None and gap_changed is not None:
        try:
            gap_runs = int(gap_runs)
            gap_changed = int(gap_changed)
        except Exception:
            gap_runs = None
            gap_changed = None
    if gap_runs and gap_changed == 0:
        actions.append(
            {
                "signal": "gap_stall",
                "severity": "warn",
                "value": gap_runs,
                "decision": "rewrite_or_merge_gaps",
                "reason": "gaps repeating without change; merge/rewrite scenarios",
            }
        )
    canary = report.get("canary_status") or {}
    if int(canary.get("evaluated") or 0) == 0:
        reason = str(canary.get("reason") or "skipped")
        actions.append(
            {
                "signal": "canary_skipped",
                "severity": "warn",
                "decision": "conditional_gate_policy",
                "reason": reason,
            }
        )
    digest = report.get("context_digest") or {}
    if digest and not bool(digest.get("ok", True)):
        actions.append(
            {
                "signal": "context_digest_timeout",
                "severity": "blocked",
                "decision": "increase_budget_or_reduce_window",
                "reason": "digest not ok",
            }
        )
    return actions


def _derive_scenario_scores(selection: Dict[str, Any]) -> Dict[str, Any]:
    selected = selection.get("selected") or []
    scores: List[Dict[str, Any]] = []
    total = 0.0
    for item in selected:
        if not isinstance(item, dict):
            continue
        name = str(item.get("name") or "")
        score = float(item.get("score") or 0.0)
        scores.append(
            {
                "name": name,
                "score": round(score, 4),
                "kind": item.get("kind"),
                "reasons": item.get("reasons") or [],
            }
        )
        total += score
    avg = round(total / len(scores), 4) if scores else 0.0
    return {
        "count": len(scores),
        "avg_score": avg,
        "max_score": max((s["score"] for s in scores), default=0.0),
        "min_score": min((s["score"] for s in scores), default=0.0),
        "scores": scores,
        "source": "scenario_selection",
    }


def _derive_auto_gate_policy(report: Dict[str, Any], cfg: Dict[str, Any], prev: Dict[str, Any]) -> Dict[str, Any]:
    canary = report.get("canary_status") or {}
    reason = str(canary.get("reason") or "")
    skipped = bool(canary.get("skipped")) or int(canary.get("evaluated") or 0) == 0
    gate_skip = skipped and reason in ("integrity_gate_not_ok", "event_delta_zero", "skipped")

    skip_count = int(prev.get("skipped_gate_count") or 0)
    if gate_skip:
        skip_count += 1
    else:
        skip_count = 0

    threshold = int(cfg.get("auto_gate_relax_after", 3) or 3)
    integrity_status = ""
    try:
        gate = report.get("integrity_gate") or {}
        overall = gate.get("overall") or {}
        integrity_status = str(overall.get("status") or overall.get("decision") or "").lower()
    except Exception:
        integrity_status = ""
    scen_stats = report.get("scenario_run_stats") or {}
    event_delta = scen_stats.get("event_delta_total")
    if event_delta is None:
        event_delta = scen_stats.get("event_delta")
    try:
        event_delta = int(event_delta or 0)
    except Exception:
        event_delta = 0

    mode = "strict"
    allow_if: Dict[str, Any] = {}
    if gate_skip and skip_count >= threshold:
        allowed_statuses = ["ok", "pass", "warn"]
        if integrity_status in allowed_statuses and event_delta > 0:
            mode = "conditional"
            allow_if = {
                "override_require_integrity": True,
                "override_require_event_delta": False,
                "min_event_delta": 1,
                "integrity_status_allow": allowed_statuses,
            }

    return {
        "generated_at": time.time(),
        "run_id": report.get("diagnostic_run_id"),
        "mode": mode,
        "reason": reason,
        "skipped_gate_count": skip_count,
        "allow_if": allow_if,
        "integrity_status": integrity_status,
        "event_delta": event_delta,
    }


def run_self_strategy(root_dir: Path, cfg: Dict[str, Any], run_id: Optional[str] = None) -> Dict[str, Any]:
    report = _safe_json(root_dir / REPORT_PATH.relative_to(ROOT))
    selection = _safe_json(root_dir / SELECTION_PATH.relative_to(ROOT))
    failures = _derive_failure_typing(report, cfg)

    strategy_state = {
        "generated_at": time.time(),
        "run_id": run_id or report.get("diagnostic_run_id"),
        "report_ts": report.get("timestamp"),
        "signals": {
            "success_rate": (report.get("execution_stats") or {}).get("success_rate"),
            "ui_action_coverage": (report.get("ui_action_coverage") or {}).get("coverage_ratio"),
            "flow_sequence_coverage": (report.get("flow_coverage") or {}).get("sequence_coverage_ratio"),
            "context_digest_ok": bool((report.get("context_digest") or {}).get("ok", True)),
            "event_delta": (report.get("scenario_run_stats") or {}).get("event_delta_total")
            or (report.get("scenario_run_stats") or {}).get("event_delta"),
        },
        "actions": failures,
        "notes": [
            "auto-generated strategy summary",
        ],
    }
    _write_json(root_dir / STRATEGY_STATE_PATH.relative_to(ROOT), strategy_state)

    scenario_scores = _derive_scenario_scores(selection)
    scenario_scores.update(
        {
            "generated_at": time.time(),
            "run_id": run_id or report.get("diagnostic_run_id"),
        }
    )
    _write_json(root_dir / SCENARIO_SCORES_PATH.relative_to(ROOT), scenario_scores)

    prev_policy = _safe_json(root_dir / AUTO_GATE_POLICY_PATH.relative_to(ROOT))
    auto_gate_policy = _derive_auto_gate_policy(report, cfg, prev_policy)
    _write_json(root_dir / AUTO_GATE_POLICY_PATH.relative_to(ROOT), auto_gate_policy)

    return {
        "ok": True,
        "strategy_state": STRATEGY_STATE_PATH.as_posix(),
        "scenario_value_scores": SCENARIO_SCORES_PATH.as_posix(),
        "auto_gate_policy": AUTO_GATE_POLICY_PATH.as_posix(),
        "actions": len(failures),
    }


def main() -> None:
    cfg_path = ROOT / ".bgl_core" / "config.yml"
    cfg = {}
    if cfg_path.exists():
        try:
            import yaml  # type: ignore

            cfg = yaml.safe_load(cfg_path.read_text()) or {}
        except Exception:
            cfg = {}
    result = run_self_strategy(ROOT, cfg, run_id=os.getenv("BGL_DIAGNOSTIC_RUN_ID"))
    print(json.dumps(result, ensure_ascii=False))


if __name__ == "__main__":
    main()
