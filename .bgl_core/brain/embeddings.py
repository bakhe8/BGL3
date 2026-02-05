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
    conn = sqlite3.connect(DB, timeout=30.0)
    conn.execute("PRAGMA journal_mode=WAL;")
    conn.execute(
        """
        CREATE TABLE IF NOT EXISTS embeddings(
          id INTEGER PRIMARY KEY AUTOINCREMENT,
          label TEXT UNIQUE,
          text TEXT,
          vector TEXT
        )
        """
    )
    conn.execute("CREATE INDEX IF NOT EXISTS idx_embeddings_label ON embeddings(label)")
    conn.commit()
    conn.close()


def add_text(label: str, text: str):
    _ensure_table()
    vec = _vectorize(text)
    conn = sqlite3.connect(DB, timeout=30.0)
    conn.execute("PRAGMA journal_mode=WAL;")
    # Use INSERT OR REPLACE (UPSERT) to prevent Silent Duplication
    conn.execute(
        "INSERT OR REPLACE INTO embeddings (label, text, vector) VALUES (?, ?, ?)",
        (label, text, json.dumps(vec)),
    )
    conn.commit()
    conn.close()


# Simple LRU-like cache: { "query:top_k": results_list }
_search_cache: Dict[str, List[Tuple[str, float, str]]] = {}


def search(query: str, top_k: int = 5) -> List[Tuple[str, float, str]]:
    cache_key = f"{query}:{top_k}"
    if cache_key in _search_cache:
        # Move key to end to mark as recently used
        val = _search_cache.pop(cache_key)
        _search_cache[cache_key] = val
        return val

    _ensure_table()
    qv = _vectorize(query)
    conn = sqlite3.connect(DB, timeout=30.0)
    conn.execute("PRAGMA journal_mode=WAL;")
    # TODO: In the future, load full table into memory only once if small, or use proper Vector DB
    rows = conn.execute("SELECT label, vector, text FROM embeddings").fetchall()
    conn.close()
    scored = []
    for label, vjson, text in rows:
        try:
            v = json.loads(vjson)
        except Exception:
            continue
        scored.append((label, _cosine(qv, v), text))

    results = sorted(scored, key=lambda x: x[1], reverse=True)[:top_k]

    # Store in cache
    _search_cache[cache_key] = results
    # Prune if too big
    if len(_search_cache) > 100:
        # Remove first item (oldest inserted/accessed)
        _search_cache.pop(next(iter(_search_cache)))

    return results


if __name__ == "__main__":
    import sys
    import json

    if len(sys.argv) > 2 and sys.argv[1] == "add":
        add_text(sys.argv[2], " ".join(sys.argv[3:]))
    elif len(sys.argv) > 1:
        print(json.dumps(search(" ".join(sys.argv[1:])), ensure_ascii=False, indent=2))
