import yaml
from pathlib import Path
from typing import Dict, Any


def load_config(root_dir: Path) -> Dict[str, Any]:
    cfg_path = root_dir / ".bgl_core" / "config.yml"
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
            return cfg
        except Exception:
            return {}
    return {}
