"""
Codebase Indexer for BGL3.
Scans the project structure and creates a Markdown map for the AI Agent.
"""

from pathlib import Path
import re


def index_codebase(root_dir="."):
    print("🚀 Starting Deep Codebase Indexing...")

    output_path = Path(".bgl_core/knowledge/code_map.md")
    root_path = Path(root_dir)

    sections = {
        "Core App (app/)": [],
        "API Layer (api/)": [],
        "Interfaces (views/ & index.php)": [],
        "Agent Core (.bgl_core/)": [],
        "Scripts & Tools (scripts/)": [],
        "Tests (tests/)": [],
        "Others": [],
    }

    # Find all relevant files
    extensions = ["*.php", "*.py", "*.sql", "*.md", "*.yml", "*.json"]
    all_files = []
    for ext in extensions:
        all_files.extend(list(root_path.rglob(ext)))

    print(f"found {len(all_files)} total files. Analyzing...")

    for file_path in all_files:
        rel_str = str(file_path.relative_to(root_path))

        # Skip noisy areas
        if any(
            x in rel_str
            for x in [
                "vendor",
                "storage",
                "node_modules",
                ".git",
                ".venv",
                ".mypy_cache",
                ".pytest_cache",
                "__pycache__",
            ]
        ):
            continue

        try:
            content = file_path.read_text(encoding="utf-8", errors="ignore")

            # Basic extraction
            classes = re.findall(r"class\s+(\w+)", content)
            functions = re.findall(r"function\s+(\w+)\s*\(", content)
            python_defs = re.findall(r"def\s+(\w+)\s*\(", content)

            # Combine all functions/defs
            all_funcs = functions + python_defs

            entry = [f"### `{rel_str}`\n"]
            if classes:
                entry.append(f"- **Classes**: {', '.join(classes)}")
            if all_funcs:
                if len(all_funcs) > 10:
                    entry.append(
                        f"- **Functions**: {', '.join(all_funcs[:10])}... (+{len(all_funcs) - 10} more)"
                    )
                else:
                    entry.append(f"- **Functions**: {', '.join(all_funcs)}")

            if not classes and not all_funcs:
                entry.append("- *Type*: Script / Data / Documentation")

            entry.append("")  # Blank line after list

            # Categorize
            if rel_str.startswith("app"):
                sections["Core App (app/)"].append("\n".join(entry))
            elif rel_str.startswith("api"):
                sections["API Layer (api/)"].append("\n".join(entry))
            elif rel_str.startswith("views") or rel_str in [
                "index.php",
                "agent-dashboard.php",
            ]:
                sections["Interfaces (views/ & index.php)"].append("\n".join(entry))
            elif rel_str.startswith(".bgl_core"):
                sections["Agent Core (.bgl_core/)"].append("\n".join(entry))
            elif rel_str.startswith("scripts"):
                sections["Scripts & Tools (scripts/)"].append("\n".join(entry))
            elif rel_str.startswith("tests"):
                sections["Tests (tests/)"].append("\n".join(entry))
            else:
                sections["Others"].append("\n".join(entry))

        except Exception:
            pass

    # Build final markdown
    final_output = [
        "# BGL3 Codebase Map\n",
        "Generated on demand to ensure 100% visibility.\n",
    ]
    for section_name, entries in sections.items():
        if entries:
            final_output.append(f"\n## {section_name}")
            final_output.extend(entries)

    output_path.write_text("\n".join(final_output), encoding="utf-8")
    print(f"✅ Indexing Complete! Written to {output_path}")


if __name__ == "__main__":
    import sys

    sys.stdout.reconfigure(encoding="utf-8")  # type: ignore
    index_codebase()
