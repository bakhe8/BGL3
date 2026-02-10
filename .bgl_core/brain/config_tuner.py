"""
config_tuner.py
---------------
Safe, bounded config tuning via storage/agent_flags.json.
Writes only whitelisted keys and logs changes to agent_flags_meta.json.
"""
from __future__ import annotations

import json
import os
import time
from pathlib import Path
from typing import Dict, Any

try:
    from .config_loader import load_config  # type: ignore
except Exception:
    from config_loader import load_config  # type: ignore


SAFE_KEYS = {
    # digest loop
    "auto_digest_hours",
    "auto_digest_limit",
    "auto_digest_timeout_sec",
    "context_digest_timeout_sec",
    # diagnostic controls
    "diagnostic_timeout_sec",
    "diagnostic_budget_seconds",
    "route_scan_limit",
    "route_scan_max_seconds",
    "scenario_batch_limit",
    # proposals / apply
    "auto_propose_min_conf",
    "auto_propose_min_evidence",
    "auto_propose_limit",
    "auto_apply_limit",
    "auto_apply_timeout_sec",
    # exploration coverage
    "ui_action_goal_limit",
    "ui_action_goal_min_score",
    "ui_action_sample_limit",
    "ui_action_window_days",
    "ui_action_min_snapshots",
    "flow_goal_limit",
    "flow_coverage_sample",
    "flow_window_days",
    "flow_min_events",
    "flow_require_events",
    "auto_run_gap_scenarios",
    "coverage_sample_limit",
    "coverage_window_days",
    "coverage_min_events",
    # scenario runtime
    "scenario_batch_timeout_sec",
    "page_idle_timeout",
    "max_pages",
    "autonomous_max_steps",
    # recovery tuning
    "stall_recovery_threshold",
    "idle_recovery_after_sec",
}


def _read_json(path: Path) -> Dict[str, Any]:
    try:
        if not path.exists():
            return {}
        return json.loads(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _write_json(path: Path, payload: Dict[str, Any]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")


def _auto_tune_enabled(cfg: Dict[str, Any]) -> bool:
    try:
        env_flag = os.getenv("BGL_AUTO_TUNE_CONFIG")
        if env_flag is not None:
            return str(env_flag).strip().lower() in ("1", "true", "yes", "on")
    except Exception:
        pass
    val = cfg.get("auto_tune_config", 1)
    if isinstance(val, bool):
        return val
    if isinstance(val, (int, float)):
        return float(val) != 0.0
    if isinstance(val, str):
        return val.strip().lower() in ("1", "true", "yes", "on")
    return True


def _clamp(val: int, lo: int, hi: int) -> int:
    try:
        return max(lo, min(hi, int(val)))
    except Exception:
        return lo


def apply_safe_config_tuning(root_dir: Path, diagnostic_map: Dict[str, Any]) -> Dict[str, Any]:
    """
    Update safe config flags based on diagnostic findings.
    Writes only to storage/agent_flags.json (safe path).
    """
    cfg = load_config(root_dir) or {}
    if not _auto_tune_enabled(cfg):
        return {"status": "skipped", "reason": "auto_tune_disabled"}

    findings = (diagnostic_map or {}).get("findings") or {}
    route_stats = (diagnostic_map or {}).get("route_scan_stats") or {}
    updates: Dict[str, Any] = {}
    reasons: Dict[str, str] = {}

    # Context digest timeout handling
    ctx = findings.get("context_digest") or {}
    if isinstance(ctx, dict) and not ctx.get("ok", True):
        err = str(ctx.get("error") or ctx.get("stderr") or "").lower()
        if "timed out" in err:
            current_limit = int(cfg.get("auto_digest_limit", 400) or 400)
            new_limit = _clamp(int(current_limit * 0.6), 200, 600)
            if new_limit < current_limit:
                updates["auto_digest_limit"] = new_limit
                reasons["auto_digest_limit"] = "context_digest_timeout"
            # Ensure digest has an explicit internal timeout
            cur_timeout = int(cfg.get("context_digest_timeout_sec", 110) or 110)
            updates["context_digest_timeout_sec"] = _clamp(cur_timeout, 90, 140)
            reasons["context_digest_timeout_sec"] = "context_digest_timeout"
            # Align subprocess timeout for auto digest
            auto_timeout = int(cfg.get("auto_digest_timeout_sec", 120) or 120)
            updates["auto_digest_timeout_sec"] = _clamp(auto_timeout, 90, 160)
            reasons["auto_digest_timeout_sec"] = "context_digest_timeout"

    # UI action coverage tuning
    ui_cov = findings.get("ui_action_coverage") or {}
    try:
        ui_ratio = float(
            ui_cov.get("operational_coverage_ratio", ui_cov.get("coverage_ratio") or 0.0)
        )
    except Exception:
        ui_ratio = 0.0
    try:
        min_ui = float(cfg.get("min_ui_action_coverage", 30) or 30)
    except Exception:
        min_ui = 30.0
    if ui_cov and ui_ratio < min_ui:
        cur_limit = int(cfg.get("ui_action_goal_limit", 4) or 4)
        if cur_limit < 6:
            updates["ui_action_goal_limit"] = 6
            reasons["ui_action_goal_limit"] = "low_ui_action_coverage"
        cur_sample = int(cfg.get("ui_action_sample_limit", 12) or 12)
        if cur_sample < 16:
            updates["ui_action_sample_limit"] = 16
            reasons["ui_action_sample_limit"] = "low_ui_action_coverage"

    # Flow coverage tuning
    flow_cov = findings.get("flow_coverage") or {}
    try:
        flow_ratio = float(
            flow_cov.get("operational_coverage_ratio", flow_cov.get("coverage_ratio") or 0.0)
        )
    except Exception:
        flow_ratio = 0.0
    try:
        min_flow = float(cfg.get("min_flow_coverage", 35) or 35)
    except Exception:
        min_flow = 35.0
    if flow_cov and flow_ratio < min_flow:
        cur_flow_limit = int(cfg.get("flow_goal_limit", 3) or 3)
        if cur_flow_limit < 4:
            updates["flow_goal_limit"] = 4
            reasons["flow_goal_limit"] = "low_flow_coverage"
        cur_flow_sample = int(cfg.get("flow_coverage_sample", 8) or 8)
        if cur_flow_sample < 12:
            updates["flow_coverage_sample"] = 12
            reasons["flow_coverage_sample"] = "low_flow_coverage"

    # Ensure gap scenarios keep running when available
    if cfg.get("auto_run_gap_scenarios", 1) in (0, "0", False):
        updates["auto_run_gap_scenarios"] = 1
        reasons["auto_run_gap_scenarios"] = "ensure_gap_execution"

    # Route scan reliability tuning (avoid 0% health due to no checked routes)
    try:
        checked = int(route_stats.get("checked") or 0)
        attempted = int(route_stats.get("attempted") or 0)
    except Exception:
        checked = 0
        attempted = 0
    if attempted > 0 and checked == 0:
        cur_limit = int(cfg.get("route_scan_limit", 0) or 0)
        new_limit = _clamp(max(cur_limit, 25), 10, 120)
        if new_limit != cur_limit:
            updates["route_scan_limit"] = new_limit
            reasons["route_scan_limit"] = "no_checked_routes"
        cur_max = int(cfg.get("route_scan_max_seconds", 60) or 60)
        new_max = _clamp(max(cur_max, 90), 60, 180)
        if new_max != cur_max:
            updates["route_scan_max_seconds"] = new_max
            reasons["route_scan_max_seconds"] = "no_checked_routes"
        cur_diag = int(cfg.get("diagnostic_timeout_sec", 600) or 600)
        new_diag = _clamp(max(cur_diag, 900), 600, 1800)
        if new_diag != cur_diag:
            updates["diagnostic_timeout_sec"] = new_diag
            reasons["diagnostic_timeout_sec"] = "no_checked_routes"

    # Scenario batch sizing when coverage is very low
    scen_cov = (findings.get("scenario_coverage") or {})
    try:
        cov_ratio = float(scen_cov.get("coverage_ratio") or 0.0)
    except Exception:
        cov_ratio = 0.0
    if cov_ratio and cov_ratio < 30.0:
        cur_batch = int(cfg.get("scenario_batch_limit", 40) or 40)
        new_batch = _clamp(max(cur_batch, 50), 20, 80)
        if new_batch != cur_batch:
            updates["scenario_batch_limit"] = new_batch
            reasons["scenario_batch_limit"] = "low_scenario_coverage"

    # Filter to safe keys only
    updates = {k: v for k, v in updates.items() if k in SAFE_KEYS}

    if not updates:
        return {"status": "noop", "reason": "no_safe_updates"}

    flags_path = root_dir / "storage" / "agent_flags.json"
    meta_path = root_dir / "storage" / "agent_flags_meta.json"
    existing = _read_json(flags_path)
    for k, v in updates.items():
        existing[k] = v
    _write_json(flags_path, existing)

    meta = _read_json(meta_path)
    history = meta.get("history") or []
    history.append(
        {
            "timestamp": time.time(),
            "updates": updates,
            "reasons": reasons,
            "source": "auto_tune",
        }
    )
    meta["history"] = history[-40:]
    _write_json(meta_path, meta)
    return {"status": "applied", "updates": updates, "reasons": reasons}
