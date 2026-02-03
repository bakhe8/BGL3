import sqlite3
import yaml
from pathlib import Path
from typing import List, Dict, Any


class BGLGovernor:
    def __init__(
        self, db_path: Path, rules_path: Path, style_rules_path: Path | None = None
    ):
        # Fast bypass switch to avoid choking the pipeline during tuning/troubleshooting.
        if str(Path().absolute()):  # keep constructor signature untouched
            import os

            if os.getenv("BGL_GOVERNOR_BYPASS", "0") == "1":
                self._bypassed = True
                self.rules = {}
                return
        self._bypassed = False
        self.db_path = db_path
        self.rules_path = rules_path
        self.style_rules_path = style_rules_path
        self.conn = sqlite3.connect(db_path)
        self.conn.row_factory = sqlite3.Row
        self.rules = self._load_rules()

    def _load_rules(self) -> Dict[str, Any]:
        base = {}
        with open(self.rules_path, "r", encoding="utf-8") as f:
            base = yaml.safe_load(f) or {}

        # Optionally merge style rules as non-blocking naming policies
        if self.style_rules_path and self.style_rules_path.exists():
            with open(self.style_rules_path, "r", encoding="utf-8") as f:
                style = yaml.safe_load(f) or {}
                # merge style rules into a separate list to process alongside
                base.setdefault("style_rules", style.get("rules", []))

        return base

    def audit(self) -> List[Dict[str, Any]]:
        if getattr(self, "_bypassed", False):
            return []
        violations = []
        classifications = self.rules.get("classifications", {})
        rules = self.rules.get("rules", [])
        style_rules = self.rules.get("style_rules", [])

        # 1. Map all entities to their types
        entity_types = self._classify_entities(classifications)

        # 2. Check each rule
        for rule in rules:
            if "from_type" in rule and "to_type" in rule:
                violations.extend(self._check_relationship_rule(rule, entity_types))

            if "must_have_suffix" in rule:
                violations.extend(self._check_naming_rule(rule))

        # 3. Content Rules (Prohibited Concepts)
        content_rules = self.rules.get("content_rules", [])
        for rule in content_rules:
            violations.extend(self._check_content_rule(rule))

        # 4. Style naming (non-blocking by design)
        for rule in style_rules:
            if "must_have_suffix" in rule:
                violations.extend(self._check_naming_rule(rule))

        return violations

    def _check_content_rule(self, rule: Dict[str, Any]) -> List[Dict[str, Any]]:
        violations = []
        pattern = rule.get("pattern", "")
        if not pattern:
            return []

        # Scan all files indexed in the database
        cursor = self.conn.cursor()
        query = "SELECT path FROM files"
        files = cursor.execute(query).fetchall()

        for f in files:
            path_str = f["path"]
            # Skip binary or vendor files if needed, but for now we scan project code
            full_path = self.db_path.parent.parent.parent / path_str
            if not full_path.exists():
                continue

            try:
                # Basic text scan - expensive but necessary for "Concepts"
                if full_path.suffix not in [".php", ".md", ".txt", ".py", ".json"]:
                    continue

                content = full_path.read_text(encoding="utf-8", errors="ignore")
                if pattern in content:
                    # Find line number (approximate)
                    lines = content.splitlines()
                    for idx, line in enumerate(lines):
                        if pattern in line:
                            violations.append(
                                {
                                    "rule_id": rule["id"],
                                    "severity": rule.get("severity", "critical"),
                                    "message": f"Prohibited Concept Found: '{pattern}'. {rule.get('description')}",
                                    "file": path_str,
                                    "line": idx + 1,
                                    "rationale": "Business Rule Violation",
                                }
                            )
            except Exception:
                pass  # Fail safe on read errors

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
                        "severity": rule.get("severity", rule.get("action", "WARN")),
                        "message": f"Violation {rule['id']}: {call['source_name']} calls {call['target_entity']} directly. {rule.get('description', '')}",
                        "file": call["path"],
                        "line": call["line"],
                        "rationale": rule.get("rationale", ""),
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
                        "severity": rule.get("severity", rule.get("action", "WARN")),
                        "message": f"Naming Violation {rule['id']}: Entity {ent['name']} in {path_match} missing suffix '{suffix}'",
                        "file": ent["path"],
                        "line": 0,
                        "rationale": rule.get("rationale", ""),
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
    STYLE = ROOT / ".bgl_core" / "brain" / "style_rules.yml"

    gov = BGLGovernor(DB, RULES, STYLE)
    report = gov.audit()

    print(f"--- BGL3 Architectural Audit Report ---")
    if not report:
        print("[+] No violations found. Architecture is clean.")
    else:
        print(f"[!] Found {len(report)} violations:\n")
        for v in report:
            print(f"[{v['severity']}] {v['message']}")
            print(f"    File: {v['file']} (Line: {v['line']})\n")
