import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".bgl_core" / "brain"))

from guardian import BGLGuardian  # type: ignore


def test_parse_flow_docs():
    guardian = BGLGuardian(ROOT)
    flows = guardian._parse_flow_docs()
    # If flows directory exists, ensure at least one flow is parsed with endpoints.
    flows_dir = ROOT / "docs" / "flows"
    if flows_dir.exists():
        assert flows, "flows should be parsed when docs/flows exists"
        any_with_endpoint = any((f.get("endpoints") or []) for f in flows)
        assert any_with_endpoint is True
