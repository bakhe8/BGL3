import sys
import sqlite3
import json
from pathlib import Path

# Add brain to path
sys.path.append(str(Path(__file__).resolve().parents[1] / ".bgl_core" / "brain"))
from embeddings import add_text


def index_all_insights():
    print("🚀 Re-indexing all Auto Insights into Semantic Memory...")
    insight_dir = Path(
        r"c:\Users\Bakheet\Documents\Projects\BGL3\.bgl_core\knowledge\auto_insights"
    )

    # Also index business rules
    biz_rules_path = Path(
        r"c:\Users\Bakheet\Documents\Projects\BGL3\.bgl_core\knowledge\business_rules.md"
    )
    if biz_rules_path.exists():
        print(f"[*] Indexing Business Rules...")
        add_text("business_rules", biz_rules_path.read_text(encoding="utf-8"))

    count = 0
    for insight_file in insight_dir.glob("*.insight.md"):
        label = insight_file.name.replace(".insight.md", "")
        content = insight_file.read_text(encoding="utf-8")

        # Add to semantic memory
        add_text(label, content)
        count += 1

    print(f"✅ Indexed {count} insights into knowledge.db.")


if __name__ == "__main__":
    index_all_insights()
