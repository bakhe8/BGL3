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
