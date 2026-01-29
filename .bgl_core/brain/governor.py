import sqlite3
import yaml
from pathlib import Path
from typing import List, Dict, Any


class BGLGovernor:
    def __init__(self, db_path: Path, rules_path: Path):
        self.db_path = db_path
        self.rules_path = rules_path
        self.conn = sqlite3.connect(db_path)
        self.conn.row_factory = sqlite3.Row
        self.rules = self._load_rules()

    def _load_rules(self) -> Dict[str, Any]:
        with open(self.rules_path, "r", encoding="utf-8") as f:
            return yaml.safe_load(f)

    def audit(self) -> List[Dict[str, Any]]:
        violations = []
        classifications = self.rules.get("classifications", {})
        rules = self.rules.get("rules", [])

        # 1. Map all entities to their types
        entity_types = self._classify_entities(classifications)

        # 2. Check each rule
        for rule in rules:
            if "from_type" in rule and "to_type" in rule:
                violations.extend(self._check_relationship_rule(rule, entity_types))

            if "must_have_suffix" in rule:
                violations.extend(self._check_naming_rule(rule))

        return violations

    def _classify_entities(self, specs: Dict[str, Any]) -> Dict[int, str]:
        """Maps entity_id to its classified type (controller, service, etc)"""
        mapping = {}
        cursor = self.conn.cursor()

        query = """
            SELECT e.id, e.name, f.path 
            FROM entities e 
            JOIN files f ON e.file_id = f.id
        """
        entities = cursor.execute(query).fetchall()

        for ent in entities:
            ent_id, name, path = ent["id"], ent["name"], ent["path"]
            norm_path = path.replace("\\", "/")  # Normalize for cross-platform matching
            for type_name, criteria in specs.items():
                match = True
                if (
                    "path_contains" in criteria
                    and criteria["path_contains"] not in norm_path
                ):
                    match = False
                if "suffix" in criteria and not name.endswith(criteria["suffix"]):
                    match = False

                if match:
                    mapping[ent_id] = type_name
                    break  # Assign first matching type
        return mapping

    def _check_relationship_rule(
        self, rule: Dict[str, Any], entity_types: Dict[int, str]
    ) -> List[Dict[str, Any]]:
        violations = []
        from_type = rule["from_type"]
        to_type = rule["to_type"]
        cursor = self.conn.cursor()

        # Find all calls from entities of from_type
        # We need to find entities that HAVE the from_type
        source_entity_ids = [eid for eid, t in entity_types.items() if t == from_type]

        if not source_entity_ids:
            return []

        # Target types identified by their names (for static/method calls)
        target_entity_names = [
            e["name"] for e in self._get_entities_by_type(to_type, entity_types)
        ]

        placeholders = ", ".join(["?"] * len(source_entity_ids))
        query = f"""
            SELECT e.name as source_name, m.name as method_name, c.target_entity, c.target_method, c.line, f.path
            FROM calls c
            JOIN methods m ON c.source_method_id = m.id
            JOIN entities e ON m.entity_id = e.id
            JOIN files f ON e.file_id = f.id
            WHERE e.id IN ({placeholders})
        """

        calls = cursor.execute(query, source_entity_ids).fetchall()

        for call in calls:
            # Check if target_entity is in the forbidden list
            if call["target_entity"] in target_entity_names:
                violations.append(
                    {
                        "rule_id": rule["id"],
                        "severity": rule["action"],
                        "message": f"Violation {rule['id']}: {call['source_name']} calls {call['target_entity']} directly. {rule['description']}",
                        "file": call["path"],
                        "line": call["line"],
                    }
                )

        return violations

    def _check_naming_rule(self, rule: Dict[str, Any]) -> List[Dict[str, Any]]:
        violations = []
        cursor = self.conn.cursor()
        path_match = rule.get("path_match")
        suffix = rule.get("must_have_suffix")

        query = """
            SELECT e.name, f.path 
            FROM entities e 
            JOIN files f ON e.file_id = f.id
            WHERE REPLACE(f.path, '\\', '/') LIKE ?
        """
        entities = cursor.execute(query, (f"%{path_match}%",)).fetchall()

        for ent in entities:
            norm_path = ent["path"].replace("\\", "/")
            if not ent["name"].endswith(suffix):
                violations.append(
                    {
                        "rule_id": rule["id"],
                        "severity": rule["action"],
                        "message": f"Naming Violation {rule['id']}: Entity {ent['name']} in {path_match} missing suffix '{suffix}'",
                        "file": ent["path"],
                        "line": 0,
                    }
                )
        return violations

    def _get_entities_by_type(
        self, type_name: str, entity_types: Dict[int, str]
    ) -> List[Dict[str, Any]]:
        cursor = self.conn.cursor()
        target_ids = [eid for eid, t in entity_types.items() if t == type_name]
        if not target_ids:
            return []

        placeholders = ", ".join(["?"] * len(target_ids))
        return cursor.execute(
            f"SELECT name FROM entities WHERE id IN ({placeholders})", target_ids
        ).fetchall()


if __name__ == "__main__":
    import os

    ROOT = Path(__file__).parent.parent.parent
    DB = ROOT / ".bgl_core" / "brain" / "knowledge.db"
    RULES = ROOT / ".bgl_core" / "brain" / "domain_rules.yml"

    gov = BGLGovernor(DB, RULES)
    report = gov.audit()

    print(f"--- BGL3 Architectural Audit Report ---")
    if not report:
        print("[+] No violations found. Architecture is clean.")
    else:
        print(f"[!] Found {len(report)} violations:\n")
        for v in report:
            print(f"[{v['severity']}] {v['message']}")
            print(f"    File: {v['file']} (Line: {v['line']})\n")
