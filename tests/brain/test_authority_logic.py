from pathlib import Path
import shutil
import sys


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".bgl_core" / "brain"))

from authority import Authority  # type: ignore
from brain_types import ActionRequest, ActionKind  # type: ignore


def _make_temp_root(tmp_path: Path) -> Path:
    root = tmp_path / "agent"
    (root / ".bgl_core" / "brain").mkdir(parents=True, exist_ok=True)
    (root / ".bgl_core").mkdir(parents=True, exist_ok=True)
    # Copy schema and write_scope to allow Authority init and validation
    schema_src = ROOT / ".bgl_core" / "brain" / "decision_schema.sql"
    scope_src = ROOT / ".bgl_core" / "brain" / "write_scope.yml"
    if schema_src.exists():
        shutil.copy2(schema_src, root / ".bgl_core" / "brain" / "decision_schema.sql")
    if scope_src.exists():
        shutil.copy2(scope_src, root / ".bgl_core" / "brain" / "write_scope.yml")
    # Minimal config
    cfg_path = root / ".bgl_core" / "config.yml"
    cfg_path.write_text("execution_mode: sandbox\n", encoding="utf-8")
    return root


def test_scope_requires_human_logic(tmp_path: Path):
    root = _make_temp_root(tmp_path)
    auth = Authority(root)
    assert auth._scope_requires_human(["app/Services/Foo.php"]) is True
    assert auth._scope_requires_human(["api/foo.php"]) is True
    assert auth._scope_requires_human(["http://example.com"]) is True
    assert auth._scope_requires_human([".bgl_core/brain/foo.py"]) is False
    assert auth._scope_requires_human(["docs/readme.md"]) is False


def test_gate_allows_non_write(tmp_path: Path):
    root = _make_temp_root(tmp_path)
    auth = Authority(root)
    req = ActionRequest(
        kind=ActionKind.OBSERVE,
        operation="observe.system",
        command="observe",
        scope=[],
        reason="test",
        confidence=0.2,
        metadata={},
    )
    gate = auth.gate(req, source="test")
    assert gate.allowed is True
    assert gate.requires_human is False


def test_gate_blocks_write_prod_in_sandbox_mode(tmp_path: Path):
    root = _make_temp_root(tmp_path)
    auth = Authority(root)
    req = ActionRequest(
        kind=ActionKind.WRITE_PROD,
        operation="write.prod",
        command="write app/Services/Foo.php",
        scope=["app/Services/Foo.php"],
        reason="test",
        confidence=0.9,
        metadata={},
    )
    gate = auth.gate(req, source="test")
    assert gate.allowed is False
    assert gate.requires_human is True
