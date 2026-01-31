import json
from pathlib import Path


def run(project_root: Path):
    """
    Detect missing indexes on common filter/sort columns for reporting.
    Uses .bgl_core/brain/db_schema.json (generated from app.sqlite).
    """
    schema_path = project_root / ".bgl_core" / "brain" / "db_schema.json"
    if not schema_path.exists():
        return {"passed": False, "evidence": ["db_schema.json not found"], "scope": ["db"]}

    data = json.loads(schema_path.read_text(encoding="utf-8"))
    tables = {t["name"]: t for t in data.get("tables", [])}

    findings = []

    def has_index(table, cols):
        idxs = table.get("indexes", [])
        cols_set = set(cols)
        for idx in idxs:
            if set(idx.get("columns", [])) >= cols_set:
                return True
        return False

    # Expected indexes for reporting/filters
    expected = {
        "guarantees": [
            ["guarantee_number"],
            ["import_source"],
            ["imported_at"],
            ["normalized_supplier_name"],
            ["is_test_data"],
            ["test_batch_id"],
        ],
        "suppliers": [["normalized_name"], ["official_name"]],
        "banks": [["normalized_name"], ["contact_email"]],
    }

    for table, cols_list in expected.items():
        if table not in tables:
            findings.append(f"table {table} missing from schema")
            continue
        t = tables[table]
        for cols in cols_list:
            if not has_index(t, cols):
                findings.append(f"{table} missing index on {','.join(cols)}")

    passed = len(findings) == 0
    return {
        "passed": passed,
        "evidence": findings if findings else ["all expected indexes present"],
        "scope": ["db"],
    }


if __name__ == "__main__":
    print(run(Path(".")).get("evidence"))
