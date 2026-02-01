"""
Experience replay store for LLM:
- save(prompt, response, domain, outcome)
- fetch top N by domain (most recent)
"""
import sqlite3
from pathlib import Path
from typing import List, Dict
import json
import time

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"


def _ensure():
    conn = sqlite3.connect(DB)
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS llm_replay(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          timestamp REAL,
          domain TEXT,
          prompt TEXT,
          response TEXT,
          outcome TEXT
        )
        """
    )
    conn.commit()
    conn.close()


def save(prompt: str, response: str, domain: str = "general", outcome: str = "unknown"):
    _ensure()
    conn = sqlite3.connect(DB)
    conn.execute(
        "INSERT INTO llm_replay (timestamp, domain, prompt, response, outcome) VALUES (?, ?, ?, ?, ?)",
        (time.time(), domain, prompt[:4000], response[:4000], outcome),
    )
    conn.commit()
    conn.close()


def fetch(domain: str = "general", limit: int = 5) -> List[Dict]:
    _ensure()
    conn = sqlite3.connect(DB)
    conn.row_factory = sqlite3.Row
    rows = conn.execute(
        "SELECT * FROM llm_replay WHERE domain=? ORDER BY id DESC LIMIT ?",
        (domain, limit),
    ).fetchall()
    conn.close()
    return [dict(r) for r in rows]


if __name__ == "__main__":
    import sys
    if len(sys.argv) > 1 and sys.argv[1] == "save":
        save("prompt", "resp", "demo", "ok")
    else:
        print(json.dumps(fetch("demo"), ensure_ascii=False, indent=2))
