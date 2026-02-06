import sqlite3
from pathlib import Path
import sys


ROOT = Path(__file__).resolve().parents[2]
sys.path.insert(0, str(ROOT / ".bgl_core" / "brain"))

from schema_check import check_schema  # type: ignore


def test_schema_check_missing_tables(tmp_path: Path):
    db = tmp_path / "k.db"
    conn = sqlite3.connect(str(db))
    conn.execute(
        "CREATE TABLE entities (id INTEGER, file_id INTEGER, name TEXT, type TEXT)"
    )
    conn.commit()
    conn.close()

    result = check_schema(db)
    assert result["ok"] is False
    assert "methods" in result["missing_tables"]


def test_schema_check_missing_columns(tmp_path: Path):
    db = tmp_path / "k2.db"
    conn = sqlite3.connect(str(db))
    conn.execute("CREATE TABLE routes (id INTEGER)")
    conn.commit()
    conn.close()

    result = check_schema(db)
    assert result["ok"] is False
    assert "routes" in result["missing_columns"]
