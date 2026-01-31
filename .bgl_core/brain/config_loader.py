import yaml
from pathlib import Path
from typing import Dict, Any


def load_config(root_dir: Path) -> Dict[str, Any]:
    cfg_path = root_dir / ".bgl_core" / "config.yml"
    if cfg_path.exists():
        try:
            return yaml.safe_load(cfg_path.read_text()) or {}
        except Exception:
            return {}
    return {}
