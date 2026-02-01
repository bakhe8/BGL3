import json
import subprocess
from pathlib import Path


def run_bridge(payload: dict) -> dict:
    root = Path(__file__).resolve().parents[1]
    bridge = root / ".bgl_core" / "brain" / "logic_bridge.php"
    proc = subprocess.run(
        ["php", str(bridge)],
        input=json.dumps(payload).encode("utf-8"),
        capture_output=True,
        cwd=root,
        timeout=10,
    )
    try:
        return json.loads(proc.stdout.decode("utf-8"))
    except Exception as exc:  # pragma: no cover - defensive
        raise AssertionError(f"Non-JSON response: {proc.stdout!r}, stderr={proc.stderr!r}") from exc


def test_contract_success():
    payload = {
        "candidates": [{"id": 1, "name": "A"}],
        "record": {"id": 99, "name": "B"},
    }
    res = run_bridge(payload)
    assert res.get("status") == "SUCCESS"
    assert "conflicts" in res


def test_contract_missing_keys():
    res = run_bridge({"foo": "bar"})
    assert res.get("status") == "ERROR"
    assert "Invalid data structure" in res.get("message", "")


def test_contract_bad_json():
    root = Path(__file__).resolve().parents[1]
    bridge = root / ".bgl_core" / "brain" / "logic_bridge.php"
    proc = subprocess.run(
        ["php", str(bridge)],
        input=b"{bad json",
        capture_output=True,
        cwd=root,
        timeout=10,
    )
    assert proc.returncode != 0
    out = proc.stdout.decode("utf-8", errors="ignore")
    assert "ERROR" in out
