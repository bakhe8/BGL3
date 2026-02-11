import json
import os
import sqlite3
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Dict, List, Tuple

import yaml

from config_loader import load_config


ROOT = Path(__file__).parent.parent.parent
DB_PATH = ROOT / ".bgl_core" / "brain" / "knowledge.db"
SCENARIOS_DIR = Path(__file__).parent / "scenarios"
STATE_PATH = ROOT / ".bgl_core" / "logs" / "scenario_scheduler_state.json"


@dataclass
class ScenarioMeta:
    path: Path
    name: str
    scenario_id: str
    stem: str
    is_gap: bool
    is_generated: bool
    is_goal: bool
    is_autonomous: bool
    kind: str


def _cfg_number(cfg: Dict[str, Any], key: str, default: float) -> float:
    env_key = f"BGL_{key.upper()}"
    if env_key in os.environ:
        try:
            return float(os.getenv(env_key) or default)
        except Exception:
            return float(default)
    try:
        raw = cfg.get(key, default)
        return float(raw)
    except Exception:
        return float(default)


def _cfg_bool(cfg: Dict[str, Any], key: str, default: bool) -> bool:
    env_key = f"BGL_{key.upper()}"
    if env_key in os.environ:
        try:
            return bool(int(os.getenv(env_key) or (1 if default else 0)))
        except Exception:
            return bool(default)
    try:
        return bool(int(cfg.get(key, 1 if default else 0)))
    except Exception:
        return bool(default)


def _safe_yaml(path: Path) -> Dict[str, Any]:
    try:
        return yaml.safe_load(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _read_json(path: Path) -> Dict[str, Any]:
    try:
        if not path.exists():
            return {}
        return json.loads(path.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}


def _extract_meta(path: Path) -> ScenarioMeta:
    data = _safe_yaml(path)
    name = str(data.get("name") or path.stem)
    scenario_id = str(data.get("id") or "")
    meta = data.get("meta") or {}
    origin = str(meta.get("origin") or "").lower()
    path_norm = str(path).replace("\\", "/").lower()
    is_generated = "/generated/" in path_norm
    is_gap = bool(
        name.startswith("gap_")
        or "gap" in origin
        or str(data.get("generated") or "").lower() in ("1", "true", "yes")
        or is_generated
    )
    is_goal = name.startswith("goal_") or "/goals/" in path_norm
    is_autonomous = name.startswith("autonomous_") or "/autonomous/" in path_norm
    kind = str(meta.get("kind") or data.get("kind") or "").strip().lower()
    if not kind:
        steps = data.get("steps") or []
        has_request = False
        has_ui = False
        if isinstance(steps, list):
            for step in steps:
                if not isinstance(step, dict):
                    continue
                action = str(step.get("action") or "").strip().lower()
                url = str(step.get("url") or "").strip().lower()
                if action == "request" or url.startswith("/api/") or "/api/" in url:
                    has_request = True
                if action in ("click", "type", "press", "hover", "scroll", "upload"):
                    has_ui = True
                if action == "goto" and url and not url.startswith("/api/"):
                    has_ui = True
        if has_ui:
            kind = "ui"
        elif has_request:
            kind = "api"
        else:
            kind = "other"
    return ScenarioMeta(
        path=path,
        name=name,
        scenario_id=scenario_id,
        stem=path.stem,
        is_gap=is_gap,
        is_generated=is_generated,
        is_goal=is_goal,
        is_autonomous=is_autonomous,
        kind=kind,
    )


def _build_key_map(catalog: List[ScenarioMeta]) -> Dict[str, str]:
    key_map: Dict[str, str] = {}
    for item in catalog:
        canonical = item.name or item.stem
        for key in {item.name, item.scenario_id, item.stem}:
            if key:
                key_map[str(key)] = canonical
    return key_map


def _normalize_scenario_key(raw_id: str, key_map: Dict[str, str]) -> str:
    if raw_id in key_map:
        return key_map[raw_id]
    if ":" in raw_id:
        prefix = raw_id.split(":", 1)[0]
        if prefix in key_map:
            return key_map[prefix]
        return prefix
    return raw_id


def _collect_runtime_stats(
    db_path: Path,
    key_map: Dict[str, str],
    cutoff: float,
    recent_cutoff: float,
    cooldown_cutoff: float,
) -> Dict[str, Dict[str, Any]]:
    stats: Dict[str, Dict[str, Any]] = {}
    if not db_path.exists():
        return stats
    with sqlite3.connect(str(db_path)) as conn:
        conn.row_factory = sqlite3.Row
        rows = conn.execute(
            """
            SELECT scenario_id, event_type, timestamp, payload
            FROM runtime_events
            WHERE timestamp >= ? AND scenario_id != ''
            """,
            (cutoff,),
        ).fetchall()
    for row in rows:
        scenario_id = str(row["scenario_id"] or "")
        if not scenario_id:
            continue
        key = _normalize_scenario_key(scenario_id, key_map)
        ts = float(row["timestamp"] or 0)
        stat = stats.setdefault(
            key,
            {
                "last_ts": 0.0,
                "event_count": 0,
                "timeout_count": 0,
                "error_count": 0,
                "recent_timeout": 0,
                "recent_error": 0,
                "last_timeout_ts": 0.0,
                "last_error_ts": 0.0,
            },
        )
        stat["event_count"] += 1
        if ts > stat["last_ts"]:
            stat["last_ts"] = ts
        event_type = str(row["event_type"] or "")
        if event_type == "scenario_step_timeout":
            stat["timeout_count"] += 1
            if ts > stat["last_timeout_ts"]:
                stat["last_timeout_ts"] = ts
            if ts >= recent_cutoff:
                stat["recent_timeout"] += 1
        if event_type == "scenario_step_error":
            stat["error_count"] += 1
            if ts > stat["last_error_ts"]:
                stat["last_error_ts"] = ts
            if ts >= recent_cutoff:
                stat["recent_error"] += 1
    for stat in stats.values():
        stat["in_cooldown"] = bool(
            stat.get("last_timeout_ts", 0) >= cooldown_cutoff
            or stat.get("last_error_ts", 0) >= cooldown_cutoff
        )
    return stats


def _score_scenario(
    meta: ScenarioMeta,
    stat: Dict[str, Any] | None,
    now_ts: float,
    cfg: Dict[str, Any],
    recent_cutoff: float,
    cooldown_cutoff: float,
    weights: Dict[str, float],
    max_timeouts: int,
    max_errors: int,
    ui_boost: float = 0.0,
    flow_boost: float = 0.0,
) -> Tuple[float, List[str], bool]:
    reasons: List[str] = []
    score = 0.0
    cooldown = False

    if meta.is_gap:
        score += weights.get("gap", 120.0)
        reasons.append("gap")
    if meta.is_generated:
        score += weights.get("generated", 20.0)
        reasons.append("generated")
    if meta.is_goal:
        score += weights.get("goal", 40.0)
        reasons.append("goal")
    if meta.is_autonomous:
        score += weights.get("autonomous", 5.0)
        reasons.append("autonomous")
    if ui_boost and meta.kind == "ui":
        score += ui_boost
        reasons.append("ui_boost")
    if flow_boost and meta.is_gap and str(meta.name or "").startswith("gap_flow_"):
        score += flow_boost
        reasons.append("flow_boost")

    if not stat:
        score += weights.get("never_run", 40.0)
        reasons.append("never_run")
        return score, reasons, cooldown

    last_ts = float(stat.get("last_ts") or 0)
    if last_ts:
        age_hours = max(0.0, (now_ts - last_ts) / 3600.0)
        stale_hours = _cfg_number(cfg, "scenario_scheduler_stale_hours", 24.0)
        if age_hours >= stale_hours:
            score += min(weights.get("stale", 35.0), age_hours * 1.5)
            reasons.append("stale")

    recent_timeouts = int(stat.get("recent_timeout") or 0)
    recent_errors = int(stat.get("recent_error") or 0)
    if (stat.get("last_timeout_ts", 0) >= cooldown_cutoff and recent_timeouts >= max_timeouts) or (
        stat.get("last_error_ts", 0) >= cooldown_cutoff and recent_errors >= max_errors
    ):
        cooldown = True
        score -= weights.get("cooldown", 80.0)
        reasons.append("cooldown")

    if recent_timeouts:
        score -= min(30.0, recent_timeouts * weights.get("timeout_penalty", 6.0))
        reasons.append("timeouts")
    if recent_errors:
        score -= min(20.0, recent_errors * weights.get("error_penalty", 5.0))
        reasons.append("errors")

    min_events = int(_cfg_number(cfg, "scenario_scheduler_min_events", 5))
    if int(stat.get("event_count") or 0) < min_events:
        score += weights.get("low_signal", 10.0)
        reasons.append("low_signal")

    return score, reasons, cooldown


def _load_state() -> Dict[str, Any]:
    try:
        if STATE_PATH.exists():
            return json.loads(STATE_PATH.read_text(encoding="utf-8")) or {}
    except Exception:
        return {}
    return {}


def _save_state(state: Dict[str, Any]) -> None:
    try:
        STATE_PATH.parent.mkdir(parents=True, exist_ok=True)
        STATE_PATH.write_text(json.dumps(state, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass


def _base_weights(cfg: Dict[str, Any]) -> Dict[str, float]:
    return {
        "gap": _cfg_number(cfg, "scenario_scheduler_weight_gap", 120.0),
        "generated": _cfg_number(cfg, "scenario_scheduler_weight_generated", 20.0),
        "goal": _cfg_number(cfg, "scenario_scheduler_weight_goal", 40.0),
        "autonomous": _cfg_number(cfg, "scenario_scheduler_weight_autonomous", 5.0),
        "never_run": _cfg_number(cfg, "scenario_scheduler_weight_never_run", 40.0),
        "stale": _cfg_number(cfg, "scenario_scheduler_weight_stale", 35.0),
        "low_signal": _cfg_number(cfg, "scenario_scheduler_weight_low_signal", 10.0),
        "cooldown": _cfg_number(cfg, "scenario_scheduler_penalty_cooldown", 80.0),
        "timeout_penalty": _cfg_number(cfg, "scenario_scheduler_penalty_timeout", 6.0),
        "error_penalty": _cfg_number(cfg, "scenario_scheduler_penalty_error", 5.0),
    }


def _collect_global_stats(db_path: Path, cutoff: float) -> Dict[str, float]:
    stats = {
        "step_events": 0.0,
        "timeout_events": 0.0,
        "error_events": 0.0,
    }
    if not db_path.exists():
        return stats
    with sqlite3.connect(str(db_path)) as conn:
        rows = conn.execute(
            """
            SELECT event_type, COUNT(*) c
            FROM runtime_events
            WHERE timestamp >= ?
            GROUP BY event_type
            """,
            (cutoff,),
        ).fetchall()
    for event_type, count in rows:
        et = str(event_type or "")
        c = float(count or 0)
        if et in ("scenario_step_start", "scenario_step_done", "scenario_step_timeout", "scenario_step_error"):
            stats["step_events"] += c
        if et == "scenario_step_timeout":
            stats["timeout_events"] += c
        if et == "scenario_step_error":
            stats["error_events"] += c
    return stats


def _collect_durations(
    db_path: Path,
    key_map: Dict[str, str],
    cutoff: float,
) -> Dict[str, float]:
    durations: Dict[str, List[float]] = {}
    if not db_path.exists():
        return {}
    with sqlite3.connect(str(db_path)) as conn:
        rows = conn.execute(
            """
            SELECT run_id, scenario_id, MIN(timestamp) as min_ts, MAX(timestamp) as max_ts
            FROM runtime_events
            WHERE timestamp >= ? AND scenario_id != '' AND run_id != ''
            GROUP BY run_id, scenario_id
            """,
            (cutoff,),
        ).fetchall()
    for run_id, scenario_id, min_ts, max_ts in rows:
        if not scenario_id:
            continue
        key = _normalize_scenario_key(str(scenario_id), key_map)
        try:
            duration = max(0.0, float(max_ts or 0) - float(min_ts or 0))
        except Exception:
            continue
        durations.setdefault(key, []).append(duration)
    avg_durations: Dict[str, float] = {}
    for key, values in durations.items():
        if not values:
            continue
        values_sorted = sorted(values)
        mid = len(values_sorted) // 2
        median = values_sorted[mid]
        avg_durations[key] = float(median)
    return avg_durations


def _auto_tune(
    cfg: Dict[str, Any],
    base_weights: Dict[str, float],
    global_stats: Dict[str, float],
    avg_duration_s: float,
    state: Dict[str, Any],
) -> Tuple[Dict[str, float], Dict[str, Any]]:
    weights = dict(base_weights)
    tuning: Dict[str, Any] = {"actions": []}

    step_events = float(global_stats.get("step_events") or 0.0)
    timeout_events = float(global_stats.get("timeout_events") or 0.0)
    error_events = float(global_stats.get("error_events") or 0.0)
    timeout_rate = timeout_events / max(1.0, step_events)
    error_rate = error_events / max(1.0, step_events)

    cooldown_minutes = _cfg_number(cfg, "scenario_scheduler_cooldown_minutes", 45.0)
    max_timeouts = int(_cfg_number(cfg, "scenario_scheduler_cooldown_max_timeouts", 3))
    max_errors = int(_cfg_number(cfg, "scenario_scheduler_cooldown_max_errors", 3))

    # tighten when many timeouts
    if timeout_rate >= 0.08:
        weights["timeout_penalty"] *= 1.4
        cooldown_minutes += 10.0
        max_timeouts = max(1, max_timeouts - 1)
        tuning["actions"].append("tighten_timeout_penalty")
    elif timeout_rate >= 0.03:
        weights["timeout_penalty"] *= 1.2
        cooldown_minutes += 5.0
        tuning["actions"].append("mild_timeout_penalty")

    if error_rate >= 0.03:
        weights["error_penalty"] *= 1.2
        max_errors = max(1, max_errors - 1)
        tuning["actions"].append("tighten_error_penalty")

    # relax if low failures
    if timeout_rate < 0.01 and error_rate < 0.005:
        weights["timeout_penalty"] = max(
            base_weights.get("timeout_penalty", 6.0) * 0.85, weights["timeout_penalty"] * 0.9
        )
        cooldown_minutes = max(15.0, cooldown_minutes - 5.0)
        tuning["actions"].append("relax_cooldown")

    tuning["timeout_rate"] = round(timeout_rate, 4)
    tuning["error_rate"] = round(error_rate, 4)
    tuning["cooldown_minutes"] = round(cooldown_minutes, 2)
    tuning["max_timeouts"] = max_timeouts
    tuning["max_errors"] = max_errors
    tuning["avg_duration_s"] = round(avg_duration_s, 2)

    # auto budget (optional)
    auto_budget = _cfg_bool(cfg, "scenario_scheduler_auto_budget", False)
    budget_minutes = _cfg_number(cfg, "scenario_scheduler_budget_minutes", 0.0)
    if auto_budget and budget_minutes <= 0 and avg_duration_s > 0:
        # derive a conservative budget from recent median duration and limit
        limit = _cfg_number(cfg, "scenario_batch_limit", 40.0)
        budget_minutes = max(5.0, min(60.0, (avg_duration_s * max(1.0, limit)) / 60.0))
        tuning["actions"].append("auto_budget")
    tuning["budget_minutes"] = round(budget_minutes, 2)

    state_update = {
        "last_updated": time.time(),
        "timeout_rate": tuning["timeout_rate"],
        "error_rate": tuning["error_rate"],
        "cooldown_minutes": tuning["cooldown_minutes"],
        "max_timeouts": tuning["max_timeouts"],
        "max_errors": tuning["max_errors"],
        "budget_minutes": tuning["budget_minutes"],
        "weights": weights,
    }

    return (weights, {"tuning": tuning, "state": state_update})


def select_scenarios(
    scenario_files: List[Path],
    db_path: Path,
    cfg: Dict[str, Any],
    limit: int = 0,
) -> Dict[str, Any]:
    enabled = _cfg_bool(cfg, "scenario_scheduler_enabled", True)
    if not enabled or not scenario_files:
        return {
            "selected_paths": scenario_files,
            "summary": {
                "enabled": enabled,
                "selected": len(scenario_files),
                "candidates": len(scenario_files),
                "mode": "passthrough",
            },
            "ranked": [],
            "skipped": [],
        }

    now_ts = time.time()
    history_hours = _cfg_number(cfg, "scenario_scheduler_history_hours", 72.0)
    recent_minutes = _cfg_number(cfg, "scenario_scheduler_recent_minutes", 60.0)
    cooldown_minutes = _cfg_number(cfg, "scenario_scheduler_cooldown_minutes", 45.0)
    cutoff = now_ts - (history_hours * 3600.0)
    recent_cutoff = now_ts - (recent_minutes * 60.0)
    cooldown_cutoff = now_ts - (cooldown_minutes * 60.0)

    catalog = [_extract_meta(p) for p in scenario_files]
    key_map = _build_key_map(catalog)
    stats = _collect_runtime_stats(db_path, key_map, cutoff, recent_cutoff, cooldown_cutoff)
    durations = _collect_durations(db_path, key_map, cutoff)
    avg_duration_s = 0.0
    if durations:
        avg_duration_s = sorted(durations.values())[len(durations) // 2]
    default_duration_s = _cfg_number(cfg, "scenario_scheduler_default_duration_s", 6.0)
    global_stats = _collect_global_stats(db_path, recent_cutoff)
    state = _load_state()
    base_weights = _base_weights(cfg)
    weights, tuning_payload = _auto_tune(cfg, base_weights, global_stats, avg_duration_s, state)

    # UI/Flow coverage boosts: prioritize scenarios when coverage is below target.
    ui_boost = 0.0
    flow_boost = 0.0
    try:
        report = _read_json(ROOT / ".bgl_core" / "logs" / "latest_report.json")
        ui_cov = report.get("ui_action_coverage") or {}
        ratio = float(ui_cov.get("coverage_ratio") or 0.0)
        target = float(_cfg_number(cfg, "ui_action_coverage_target", 30.0))
        base_boost = float(_cfg_number(cfg, "scenario_scheduler_weight_ui_boost", 12.0))
        if target > 0 and ratio < target:
            deficit = max(0.0, target - ratio)
            ui_boost = min(base_boost * (deficit / target), base_boost * 2.0)
        flow_cov = report.get("flow_coverage") or {}
        flow_ratio = float(flow_cov.get("sequence_coverage_ratio") or 0.0)
        flow_target = float(_cfg_number(cfg, "flow_sequence_coverage_target", 60.0))
        flow_base = float(_cfg_number(cfg, "scenario_scheduler_weight_flow_boost", 10.0))
        if flow_target > 0 and flow_ratio < flow_target:
            deficit = max(0.0, flow_target - flow_ratio)
            flow_boost = min(flow_base * (deficit / flow_target), flow_base * 2.0)
    except Exception:
        ui_boost = 0.0
        flow_boost = 0.0

    cooldown_minutes = float(tuning_payload["tuning"].get("cooldown_minutes") or _cfg_number(cfg, "scenario_scheduler_cooldown_minutes", 45.0))
    cooldown_cutoff = now_ts - (cooldown_minutes * 60.0)
    max_timeouts = int(tuning_payload["tuning"].get("max_timeouts") or _cfg_number(cfg, "scenario_scheduler_cooldown_max_timeouts", 3))
    max_errors = int(tuning_payload["tuning"].get("max_errors") or _cfg_number(cfg, "scenario_scheduler_cooldown_max_errors", 3))

    budget_minutes = float(tuning_payload["tuning"].get("budget_minutes") or _cfg_number(cfg, "scenario_scheduler_budget_minutes", 0.0))
    budget_seconds = budget_minutes * 60.0 if budget_minutes > 0 else 0.0

    ranked: List[Dict[str, Any]] = []
    for meta in catalog:
        key = meta.name or meta.stem
        stat = stats.get(key)
        score, reasons, cooldown = _score_scenario(
            meta,
            stat,
            now_ts,
            cfg,
            recent_cutoff,
            cooldown_cutoff,
            weights,
            max_timeouts,
            max_errors,
            ui_boost,
            flow_boost,
        )
        expected_duration = durations.get(key) or default_duration_s
        ranked.append(
            {
                "path": meta.path,
                "name": meta.name,
                "kind": meta.kind,
                "score": round(score, 2),
                "reasons": reasons,
                "cooldown": cooldown,
                "last_ts": (stat or {}).get("last_ts"),
                "recent_timeouts": (stat or {}).get("recent_timeout"),
                "recent_errors": (stat or {}).get("recent_error"),
                "expected_duration_s": round(float(expected_duration), 2),
            }
        )

    ranked.sort(
        key=lambda r: (
            r.get("cooldown"),
            -((r.get("score") or 0) / max(1.0, float(r.get("expected_duration_s") or 1.0))),
            r.get("name"),
        )
    )

    selected: List[Dict[str, Any]] = []
    skipped: List[Dict[str, Any]] = []
    min_keep = int(_cfg_number(cfg, "scenario_scheduler_min_keep", 3))

    elapsed_budget = 0.0
    for item in ranked:
        if item.get("cooldown") and len(selected) >= min_keep:
            skipped.append(item)
            continue
        est = float(item.get("expected_duration_s") or 0.0)
        if budget_seconds > 0 and (elapsed_budget + est) > budget_seconds and len(selected) >= min_keep:
            skipped.append(item)
            continue
        selected.append(item)
        elapsed_budget += est
        if limit > 0 and len(selected) >= limit:
            break

    if not selected:
        selected = ranked[: min(limit or len(ranked), len(ranked))]

    # Ensure at least one UI scenario is present when available.
    ui_in_selected = any(str(item.get("kind") or "") == "ui" for item in selected)
    if not ui_in_selected:
        ui_candidates = [i for i in ranked if str(i.get("kind") or "") == "ui" and not i.get("cooldown")]
        if ui_candidates:
            ui_pick = ui_candidates[0]
            replaced = False
            for idx in range(len(selected) - 1, -1, -1):
                if str(selected[idx].get("kind") or "") != "ui":
                    selected[idx] = ui_pick
                    replaced = True
                    break
            if not replaced:
                if limit <= 0 or len(selected) < limit:
                    selected.append(ui_pick)

    summary = {
        "enabled": True,
        "mode": "smart",
        "candidates": len(ranked),
        "selected": len(selected),
        "skipped_cooldown": len(skipped),
        "history_hours": round(history_hours, 2),
        "recent_minutes": round(recent_minutes, 2),
        "cooldown_minutes": round(cooldown_minutes, 2),
        "limit": limit,
        "budget_minutes": round(budget_minutes, 2),
        "budget_seconds_used": round(elapsed_budget, 2),
        "ui_boost": round(float(ui_boost), 2),
        "flow_boost": round(float(flow_boost), 2),
    }
    kind_counts: Dict[str, int] = {"ui": 0, "api": 0, "other": 0}
    for item in selected:
        k = str(item.get("kind") or "other")
        if k not in kind_counts:
            k = "other"
        kind_counts[k] += 1
    summary["selected_kinds"] = kind_counts
    reason_counts: Dict[str, int] = {}
    for item in selected:
        for reason in item.get("reasons") or []:
            reason_counts[reason] = reason_counts.get(reason, 0) + 1
    summary["top_reasons"] = reason_counts
    summary["top_selected"] = [
        {
            "name": i.get("name"),
            "score": i.get("score"),
            "reasons": i.get("reasons"),
            "kind": i.get("kind"),
        }
        for i in selected[:10]
    ]
    summary["weights"] = {k: round(float(v), 2) for k, v in weights.items()}
    summary["tuning"] = tuning_payload.get("tuning") or {}

    state.update(tuning_payload.get("state") or {})
    _save_state(state)

    return {
        "selected_paths": [i["path"] for i in selected],
        "summary": summary,
        "ranked": ranked,
        "skipped": skipped,
    }


def write_selection_report(payload: Dict[str, Any], out_path: Path) -> None:
    try:
        out_path.parent.mkdir(parents=True, exist_ok=True)
        out_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    except Exception:
        pass


def main() -> None:
    cfg = load_config(ROOT)
    scenario_files = sorted(SCENARIOS_DIR.rglob("*.yaml"))
    limit = int(_cfg_number(cfg, "scenario_batch_limit", 40))
    selection = select_scenarios(scenario_files, DB_PATH, cfg, limit=limit)
    report_path = ROOT / ".bgl_core" / "logs" / "scenario_selection.json"
    payload = {
        "summary": selection.get("summary"),
        "selected": selection.get("summary", {}).get("top_selected") or [],
        "skipped_cooldown": len(selection.get("skipped") or []),
    }
    write_selection_report(payload, report_path)
    print(json.dumps(payload, ensure_ascii=False, indent=2))


if __name__ == "__main__":
    main()
