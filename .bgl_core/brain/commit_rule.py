import sys
from pathlib import Path
from agency_core import AgencyCore


def main():
    if len(sys.argv) < 2:
        print("Usage: python commit_rule.py <rule_id>")
        sys.exit(1)

    rule_id = sys.argv[1]
    ROOT = Path(__file__).parent.parent.parent
    core = AgencyCore(ROOT)

    if core.commit_proposed_rule(rule_id):
        core.log_activity(
            "RULE_COMMIT",
            f"Successfully deployed architectural rule: {rule_id}",
            "SUCCESS",
        )
        print(f"SUCCESS: Rule {rule_id} committed.")
        # Optionally delete from proposals table
        import sqlite3

        conn = sqlite3.connect(str(core.db_path))
        cursor = conn.cursor()
        cursor.execute("DELETE FROM agent_proposals WHERE id = ?", (rule_id,))
        conn.commit()
        conn.close()
    else:
        core.log_activity("RULE_COMMIT", f"Failed to commit rule: {rule_id}", "ERROR")
        print(f"FAILURE: Could not commit rule {rule_id}.")
        sys.exit(1)


if __name__ == "__main__":
    main()
