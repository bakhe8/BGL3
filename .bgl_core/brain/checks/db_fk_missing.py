import json
from pathlib import Path


def run(project_root: Path):
    """
    Detect missing foreign keys between guarantees -> banks/suppliers.
    Uses .bgl_core/brain/db_schema.json (generated from app.sqlite).
    """
    schema_path = project_root / ".bgl_core" / "brain" / "db_schema.json"
    if not schema_path.exists():
        return {"passed": False, "evidence": ["db_schema.json not found"], "scope": ["db"]}

    data = json.loads(schema_path.read_text(encoding="utf-8"))
    tables = {t["name"]: t for t in data.get("tables", [])}

    findings = []

    def fk_exists(table, from_col, to_table, to_col):
        for fk in table.get("foreign_keys", []):
            if (
                fk.get("from_col") == from_col
                and fk.get("table") == to_table
                and fk.get("to_col") == to_col
            ):
                return True
        return False

    # إذا لم توجد أعمدة الربط، لا نعتبرها فشلاً بل نبلغ فقط
    if "guarantees" in tables:
        g = tables["guarantees"]
        cols = {c["name"] for c in g.get("columns", [])}
        if "bank_id" in cols:
            if not fk_exists(g, "bank_id", "banks", "id"):
                findings.append("guarantees.bank_id missing FK -> banks.id")
        if "supplier_id" in cols:
            if not fk_exists(g, "supplier_id", "suppliers", "id"):
                findings.append("guarantees.supplier_id missing FK -> suppliers.id")
        if "bank_id" not in cols and "supplier_id" not in cols:
            findings.append("guarantees table has no bank_id/supplier_id columns (skipped FK check)")
    else:
        findings.append("table guarantees missing from schema")

    passed = len(findings) == 0
    return {
        "passed": passed,
        "evidence": findings if findings else ["foreign keys present"],
        "scope": ["db"],
    }


if __name__ == "__main__":
    print(run(Path(".")).get("evidence"))
