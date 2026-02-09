import sqlite3
from pathlib import Path
from typing import Optional


def connect_db(
    db_path: Path,
    *,
    timeout: float = 30.0,
    busy_timeout_ms: int = 15000,
    wal: bool = True,
    foreign_keys: bool = True,
) -> sqlite3.Connection:
    """
    Centralized SQLite connection with sane defaults for concurrent writers.
    This reduces 'database is locked' errors without changing call sites semantics.
    """
    conn = sqlite3.connect(str(db_path), timeout=timeout, check_same_thread=False)
    try:
        if foreign_keys:
            conn.execute("PRAGMA foreign_keys=ON")
        if wal:
            conn.execute("PRAGMA journal_mode=WAL")
        if busy_timeout_ms:
            conn.execute(f"PRAGMA busy_timeout={int(busy_timeout_ms)}")
        # NORMAL keeps WAL performant while still safe for our workload.
        conn.execute("PRAGMA synchronous=NORMAL")
    except Exception:
        pass
    return conn
