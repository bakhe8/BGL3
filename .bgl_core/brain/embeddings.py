"""
Simple embedding cache using bag-of-words hashing stored in SQLite.
Avoids external dependencies; not high-precision but accelerates similarity search.
"""
import re
import json
import math
import sqlite3
from collections import Counter
from pathlib import Path
from typing import List, Tuple, Dict

ROOT = Path(__file__).resolve().parents[2]
DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"


def _tokenize(text: str) -> Counter:
    toks = re.findall(r"[A-Za-z_]{2,}", text.lower())
    return Counter(toks)


def _vectorize(text: str) -> Dict[str, float]:
    c = _tokenize(text)
    total = sum(c.values()) or 1
    return {k: v / total for k, v in c.items()}


def _cosine(a: Dict[str, float], b: Dict[str, float]) -> float:
    keys = set(a) & set(b)
    num = sum(a[k] * b[k] for k in keys)
    da = math.sqrt(sum(v * v for v in a.values()))
    db = math.sqrt(sum(v * v for v in b.values()))
    if da == 0 or db == 0:
        return 0.0
    return num / (da * db)


def _ensure_table():
    conn = sqlite3.connect(DB)
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS embeddings(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          label TEXT,
          text TEXT,
          vector TEXT
        )
        """
    )
    conn.commit()
    conn.close()


def add_text(label: str, text: str):
    _ensure_table()
    vec = _vectorize(text)
    conn = sqlite3.connect(DB)
    conn.execute(
        "INSERT INTO embeddings (label, text, vector) VALUES (?, ?, ?)",
        (label, text, json.dumps(vec)),
    )
    conn.commit()
    conn.close()


def search(query: str, top_k: int = 5) -> List[Tuple[str, float, str]]:
    _ensure_table()
    qv = _vectorize(query)
    conn = sqlite3.connect(DB)
    rows = conn.execute("SELECT label, vector, text FROM embeddings").fetchall()
    conn.close()
    scored = []
    for label, vjson, text in rows:
        try:
            v = json.loads(vjson)
        except Exception:
            continue
        scored.append((label, _cosine(qv, v), text))
    return sorted(scored, key=lambda x: x[1], reverse=True)[:top_k]


if __name__ == "__main__":
    import sys, json
    if len(sys.argv) > 2 and sys.argv[1] == "add":
        add_text(sys.argv[2], " ".join(sys.argv[3:]))
    elif len(sys.argv) > 1:
        print(json.dumps(search(" ".join(sys.argv[1:])), ensure_ascii=False, indent=2))
