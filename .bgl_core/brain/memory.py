import sqlite3
import os
from pathlib import Path
from typing import Dict, Any, List


class StructureMemory:
    def __init__(self, db_path: Path):
        self.db_path = db_path
        self._init_schema_once()

    def _connect(self) -> sqlite3.Connection:
        conn = sqlite3.connect(str(self.db_path), timeout=30.0, check_same_thread=False)
        conn.row_factory = sqlite3.Row
        try:
            conn.execute("PRAGMA journal_mode=WAL;")
            if "AppData\\Local\\Temp" in str(self.db_path) or os.environ.get(
                "BGL_SANDBOX_DB"
            ):
                conn.execute("PRAGMA synchronous=OFF;")
        except Exception:
            pass
        return conn

    def _init_schema_once(self):
        conn = self._connect()
        cursor = conn.cursor()

        # Files table
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS files (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                path TEXT UNIQUE NOT NULL,
                last_modified REAL DEFAULT 0
            )
        """)

        # Entities (Classes, Traits, Interfaces)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS entities (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                file_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                type TEXT NOT NULL, -- class, trait, interface
                extends TEXT,
                line INTEGER,
                FOREIGN KEY (file_id) REFERENCES files (id) ON DELETE CASCADE,
                UNIQUE(file_id, name)
            )
        """)

        # Methods
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS methods (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                entity_id INTEGER NOT NULL,
                name TEXT NOT NULL,
                visibility TEXT,
                line INTEGER,
                FOREIGN KEY (entity_id) REFERENCES entities (id) ON DELETE CASCADE
            )
        """)

        # Calls (Dependencies)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS calls (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                source_method_id INTEGER NOT NULL,
                target_entity TEXT, -- e.g. "BankRepository"
                target_method TEXT, -- e.g. "find"
                type TEXT NOT NULL, -- method_call, static_call, app_helper, dependency_injection
                confidence TEXT DEFAULT 'MED', -- HIGH, MED, LOW
                evidence TEXT, -- e.g. "constructor_typehint", "app_helper_call"
                line INTEGER,
                FOREIGN KEY (source_method_id) REFERENCES methods (id) ON DELETE CASCADE
            )
        """)

        # Routes (Web/API Mapping)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS routes (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uri TEXT NOT NULL,
                http_method TEXT NOT NULL,
                controller TEXT,
                action TEXT,
                file_path TEXT,
                last_validated REAL DEFAULT 0,
                status_score INTEGER DEFAULT 100,
                UNIQUE(uri, http_method)
            )
        """)

        # Runtime events captured from the browser bridge
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS runtime_events (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                timestamp REAL NOT NULL,
                session TEXT,
                event_type TEXT NOT NULL,
                route TEXT,
                method TEXT,
                target TEXT,
                payload TEXT,
                status INTEGER,
                latency_ms REAL,
                error TEXT
            )
        """)

        # Experiential memory (summaries derived from runtime events)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS experiences (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                created_at REAL NOT NULL,
                updated_at REAL,
                scenario TEXT,
                summary TEXT,
                related_files TEXT,
                exp_hash TEXT UNIQUE,
                seen_count INTEGER DEFAULT 0,
                last_seen REAL,
                confidence REAL,
                evidence_count INTEGER DEFAULT 0,
                value_score REAL,
                suppressed INTEGER DEFAULT 0
            )
        """)

        # Unified memory index (cross-cutting view over experiences/logs/scenarios/etc.)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS memory_index (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                kind TEXT NOT NULL,
                key_hash TEXT UNIQUE NOT NULL,
                key_text TEXT,
                summary TEXT,
                created_at REAL NOT NULL,
                updated_at REAL NOT NULL,
                last_seen REAL,
                seen_count INTEGER DEFAULT 0,
                evidence_count INTEGER DEFAULT 0,
                confidence REAL,
                value_score REAL,
                suppressed INTEGER DEFAULT 0,
                source_table TEXT,
                source_id INTEGER,
                meta_json TEXT
            )
        """)
        cursor.execute(
            "CREATE INDEX IF NOT EXISTS idx_memory_index_kind ON memory_index(kind)"
        )
        cursor.execute(
            "CREATE INDEX IF NOT EXISTS idx_memory_index_last_seen ON memory_index(last_seen DESC)"
        )
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS memory_relations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                parent_hash TEXT NOT NULL,
                child_hash TEXT NOT NULL,
                relation TEXT NOT NULL,
                created_at REAL NOT NULL,
                notes TEXT
            )
        """)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS memory_actions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                key_hash TEXT NOT NULL,
                action TEXT NOT NULL,
                actor TEXT,
                created_at REAL NOT NULL,
                notes TEXT
            )
        """)

        # Learning Confirmations (False Positives / Anomalies)
        cursor.execute("""
            CREATE TABLE IF NOT EXISTS learning_confirmations (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                item_key TEXT NOT NULL,      -- Unique identifier for the anomaly
                item_type TEXT NOT NULL,     -- 'route', 'log', 'conflict'
                action TEXT NOT NULL,        -- 'confirm' (is anomaly), 'reject' (false positive)
                notes TEXT,
                timestamp REAL,
                UNIQUE(item_key, item_type)
            )
        """)

        conn.commit()
        conn.close()

    def register_file(self, rel_path: str, mtime: float) -> int:
        conn = self._connect()
        cursor = conn.cursor()
        cursor.execute(
            """
            INSERT INTO files (path, last_modified) 
            VALUES (?, ?)
            ON CONFLICT(path) DO UPDATE SET last_modified=?
        """,
            (rel_path, mtime, mtime),
        )
        conn.commit()
        cursor.execute("SELECT id FROM files WHERE path=?", (rel_path,))
        row = cursor.fetchone()
        conn.close()
        return row["id"]

    def get_file_info(self, path: str) -> Dict[str, Any]:
        conn = self._connect()
        row = conn.execute(
            "SELECT id, path, last_modified FROM files WHERE path = ?", (path,)
        ).fetchone()
        conn.close()
        return dict(row) if row else {}

    def clear_file_data(self, file_id: int):
        conn = self._connect()
        cursor = conn.cursor()
        # Dependencies cascade delete entities -> methods -> calls
        cursor.execute("DELETE FROM entities WHERE file_id=?", (file_id,))
        conn.commit()
        conn.close()

    def store_nested_symbols(self, file_id: int, symbols: List[Dict[str, Any]]):
        conn = self._connect()
        cursor = conn.cursor()

        for item in symbols:
            if item["type"] in ["class", "root"]:
                entity_name = item.get("name", "global")
                cursor.execute(
                    """
                    INSERT INTO entities (file_id, name, type, extends, line)
                    VALUES (?, ?, ?, ?, ?)
                """,
                    (
                        file_id,
                        entity_name,
                        item["type"],
                        item.get("extends"),
                        item["line"],
                    ),
                )

                entity_id = cursor.lastrowid

                # Store methods and calls
                if item["type"] == "class":
                    for method in item.get("methods", []):
                        cursor.execute(
                            """
                            INSERT INTO methods (entity_id, name, visibility, line)
                            VALUES (?, ?, ?, ?)
                        """,
                            (
                                entity_id,
                                method["name"],
                                method["visibility"],
                                method["line"],
                            ),
                        )

                        if cursor.lastrowid is not None:
                            method_id = int(cursor.lastrowid)
                            self._store_calls(
                                cursor, method_id, method.get("calls", [])
                            )

                elif item["type"] == "root":
                    # For root level scripts, we create a pseudo method "main"
                    cursor.execute(
                        """
                        INSERT INTO methods (entity_id, name, visibility, line)
                        VALUES (?, 'main', 'public', 1)
                    """,
                        (entity_id,),
                    )
                    if cursor.lastrowid is not None:
                        method_id = int(cursor.lastrowid)
                        self._store_calls(cursor, method_id, item.get("calls", []))

        conn.commit()
        conn.close()

    def _store_calls(
        self, cursor: sqlite3.Cursor, method_id: int, calls: List[Dict[str, Any]]
    ):
        for call in calls:
            # Calculate confidence based on evidence
            confidence = "MED"
            evidence = call.get("evidence", "inferred")

            if evidence == "app_helper_call":
                confidence = "MED"
            elif call.get("caller") == "$this":
                confidence = "HIGH"
                evidence = "internal_reference"

            target_entity = (
                call.get("class")
                if call["type"] in ["static_call", "instantiation"]
                else (call.get("target") if call["type"] == "app_helper" else None)
            )

            cursor.execute(
                """
                INSERT INTO calls (source_method_id, target_entity, target_method, type, confidence, evidence, line)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            """,
                (
                    method_id,
                    target_entity,
                    call.get("method"),
                    call["type"],
                    confidence,
                    evidence,
                    call["line"],
                ),
            )

    def close(self):
        # Kept for compatibility; connections are short-lived now
        pass
