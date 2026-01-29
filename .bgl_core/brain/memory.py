import sqlite3
from pathlib import Path
from typing import Dict, Any, List, Optional
import json


class StructureMemory:
    def __init__(self, db_path: Path):
        self.db_path = db_path
        self.conn = None
        self._connect()
        self._init_schema()

    def _connect(self):
        self.conn = sqlite3.connect(str(self.db_path), check_same_thread=False)
        self.conn.row_factory = sqlite3.Row

    def _init_schema(self):
        cursor = self.conn.cursor()

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

        self.conn.commit()

    def _ensure_conn(self) -> sqlite3.Connection:
        if self.conn is None:
            self._connect()
        if self.conn is None:
            raise ConnectionError("Failed to connect to database")
        return self.conn

    def register_file(self, rel_path: str, mtime: float) -> int:
        conn = self._ensure_conn()
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
        return row["id"]

    def clear_file_data(self, file_id: int):
        conn = self._ensure_conn()
        cursor = conn.cursor()
        # Dependencies cascade delete entities -> methods -> calls
        cursor.execute("DELETE FROM entities WHERE file_id=?", (file_id,))
        conn.commit()

    def store_nested_symbols(self, file_id: int, symbols: List[Dict[str, Any]]):
        conn = self._ensure_conn()
        cursor = conn.cursor()

        for item in symbols:
            if item["type"] == "class":
                cursor.execute(
                    """
                    INSERT INTO entities (file_id, name, type, extends, line)
                    VALUES (?, ?, 'class', ?, ?)
                """,
                    (file_id, item["name"], item["extends"], item["line"]),
                )

                entity_id = cursor.lastrowid

                # Store methods
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

                    method_id = cursor.lastrowid

                    # 1. Store Constructor Params as HIGH confidence dependencies
                    if method["name"] == "__construct":
                        for param in method.get("params", []):
                            cursor.execute(
                                """
                                INSERT INTO calls (source_method_id, target_entity, type, confidence, evidence, line)
                                VALUES (?, ?, 'dependency_injection', 'HIGH', ?, ?)
                            """,
                                (
                                    method_id,
                                    param["type"],
                                    param["evidence"],
                                    method["line"],
                                ),
                            )

                    # 2. Store calls inside methods
                    for call in method.get("calls", []):
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
                            if call["type"] == "static_call"
                            else (
                                call.get("target")
                                if call["type"] == "app_helper"
                                else None
                            )
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

        conn.commit()

    def close(self):
        if self.conn:
            self.conn.close()
            self.conn = None
