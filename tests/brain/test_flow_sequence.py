import sys
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".bgl_core" / "brain"))

from guardian import BGLGuardian  # type: ignore


def test_sequence_matches_in_order():
    guardian = BGLGuardian(Path('.'))
    assert guardian._sequence_matches(['/a', '/b', '/c'], ['/a', '/c']) is True
    assert guardian._sequence_matches(['/a', '/b', '/c'], ['/a', '/b', '/c']) is True


def test_sequence_matches_missing():
    guardian = BGLGuardian(Path('.'))
    assert guardian._sequence_matches(['/a', '/c'], ['/a', '/b', '/c']) is False
    assert guardian._sequence_matches([], ['/a']) is False
