import json
import yaml
from pathlib import Path
from typing import Dict, Any


def _deep_merge(base: Dict[str, Any], override: Dict[str, Any]) -> Dict[str, Any]:
    merged = dict(base or {})
    for k, v in (override or {}).items():
        if isinstance(v, dict) and isinstance(merged.get(k), dict):
            merged[k] = _deep_merge(merged.get(k, {}), v)
        else:
            merged[k] = v
    return merged


def _flatten_config(data: Dict[str, Any], prefix: str = "") -> Dict[str, Any]:
    out: Dict[str, Any] = {}
    for k, v in (data or {}).items():
        key = f"{prefix}.{k}" if prefix else str(k)
        if isinstance(v, dict):
            out.update(_flatten_config(v, key))
        else:
            out[key] = v
    return out


def _has_path(data: Dict[str, Any], path: str) -> bool:
    if not path:
        return False
    cur: Any = data
    for part in path.split("."):
        if not isinstance(cur, dict) or part not in cur:
            return False
        cur = cur[part]
    return True


def _get_path(data: Dict[str, Any], path: str, default: Any = None) -> Any:
    if not path:
        return default
    cur: Any = data
    for part in path.split("."):
        if not isinstance(cur, dict) or part not in cur:
            return default
        cur = cur[part]
    return cur


def load_config(root_dir: Path) -> Dict[str, Any]:
    cfg_path = root_dir / ".bgl_core" / "config.yml"
    flags_path = root_dir / "storage" / "agent_flags.json"
    if cfg_path.exists():
        try:
            cfg = yaml.safe_load(cfg_path.read_text()) or {}
            # Light-mode: bypass heavy gating when env flag is set
            env_flag = cfg.get("agent_mode_bypass_env", "BGL_LIGHT_MODE")
            if env_flag and str(Path):
                import os
                if os.getenv(env_flag, "0") == "1":
                    if cfg.get("agent_mode"):
                        cfg["agent_mode"] = "auto"
                    if cfg.get("decision", {}).get("mode"):
                        cfg.setdefault("decision", {})["mode"] = "auto"
            # Overlay runtime flags (agent_flags.json) if present
            if flags_path.exists():
                try:
                    flags = json.loads(flags_path.read_text(encoding="utf-8"))
                    if isinstance(flags, dict):
                        cfg = _deep_merge(cfg, flags)
                except Exception:
                    pass
            return cfg
        except Exception:
            return {}
    return {}


def load_effective_config(root_dir: Path) -> Dict[str, Any]:
    cfg_path = root_dir / ".bgl_core" / "config.yml"
    flags_path = root_dir / "storage" / "agent_flags.json"
    cfg: Dict[str, Any] = {}
    flags: Dict[str, Any] = {}
    if cfg_path.exists():
        try:
            parsed = yaml.safe_load(cfg_path.read_text()) or {}
            if isinstance(parsed, dict):
                cfg = parsed
        except Exception:
            cfg = {}
    if flags_path.exists():
        try:
            parsed = json.loads(flags_path.read_text(encoding="utf-8")) or {}
            if isinstance(parsed, dict):
                flags = parsed
        except Exception:
            flags = {}
    effective = _deep_merge(cfg, flags)
    # Light-mode: bypass heavy gating when env flag is set (keep in sync with load_config)
    try:
        env_flag = effective.get("agent_mode_bypass_env", "BGL_LIGHT_MODE")
        if env_flag:
            import os
            if os.getenv(env_flag, "0") == "1":
                if effective.get("agent_mode"):
                    effective["agent_mode"] = "auto"
                if effective.get("decision", {}).get("mode"):
                    effective.setdefault("decision", {})["mode"] = "auto"
    except Exception:
        pass
    # Build sources map for transparency
    sources: Dict[str, str] = {}
    flat_cfg = _flatten_config(cfg)
    flat_flags = _flatten_config(flags)
    for k in set(list(flat_cfg.keys()) + list(flat_flags.keys())):
        if k in flat_flags:
            sources[k] = "flags"
        elif k in flat_cfg:
            sources[k] = "config"
    effective["_sources"] = sources
    effective["_raw"] = {"config": cfg, "flags": flags}
    return effective
