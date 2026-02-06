from pathlib import Path
import json
from typing import Dict, Any, List
from jinja2 import Template  # type: ignore
from encoding_utils import read_text_utf8, write_text_utf8
import time


def build_report(data: Dict[str, Any], template_path: Path, output_path: Path):
    tmpl = Template(read_text_utf8(template_path))
    html = tmpl.render(**data)
    write_text_utf8(output_path, html)
    return output_path


def load_latest_health(log_path: Path) -> Dict[str, Any]:
    # Expected to be passed directly from master_verify; fallback to empty
    if log_path.exists():
        try:
            return json.loads(log_path.read_text())
        except Exception:
            pass
    return {}


if __name__ == "__main__":
    template = Path(__file__).parent / "report_template.html"
    out = Path(".bgl_core/logs/latest_report.html")
    data = {
        "timestamp": time.time(),
        "health_score": 0,
        "route_scan_limit": 0,
        "route_scan_mode": "auto",
        "scan_duration_seconds": 0,
        "target_duration_seconds": 0,
        "vitals": {"infrastructure": True, "business_logic": True, "architecture": True},
        "permission_issues": [],
        "failing_routes": [],
        "experiences": [],
        "suggestions": [],
    }
    build_report(data, template, out)
    print(f"Wrote {out}")
