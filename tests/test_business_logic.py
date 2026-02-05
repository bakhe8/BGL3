import sys
import json
import urllib.request
from pathlib import Path


def ask_business_logic():
    print("[*] Testing Model Business Logic Understanding...")

    # Paths to relevant source files
    reduce_api_path = Path(r"c:\Users\Bakheet\Documents\Projects\BGL3\api\reduce.php")
    letter_builder_path = Path(
        r"c:\Users\Bakheet\Documents\Projects\BGL3\app\Services\LetterBuilder.php"
    )

    # Read context
    context = ""
    try:
        with open(reduce_api_path, "r", encoding="utf-8") as f:
            context += f"File: api/reduce.php\n{f.read()}\n\n"
        with open(letter_builder_path, "r", encoding="utf-8") as f:
            context += f"File: app/Services/LetterBuilder.php\n{f.read()}\n\n"
    except Exception as e:
        print(f"[!] Error reading context: {e}")
        return

    # Formulate the business question
    prompt = f"""
I am working on the BGL3 project (Bank Guarantee Logic). 
Based on the provided PHP source code, please answer the following question in Arabic:
ما هي شروط التخفيض للضمان البنكي في هذا النظام؟ (برمجياً ومنطقياً)

Code Context:
{context[:6000]} ... (truncated)
"""

    payload = {
        "model": "qwen2.5-coder:7b",
        "prompt": prompt,
        "stream": False,
        "options": {"temperature": 0.1},
    }

    print("[*] Sending business logic query to model...")
    try:
        req = urllib.request.Request(
            "http://localhost:11434/api/generate",
            json.dumps(payload).encode(),
            {"Content-Type": "application/json"},
        )
        with urllib.request.urlopen(req, timeout=60) as response:
            res = json.loads(response.read().decode())
            explanation = res.get("response")
            print("\n" + "=" * 50)
            print("إجابة النموذج:")
            print("=" * 50)
            print(explanation)
            print("=" * 50)

            # Save to markdown for better viewing
            with open("tests/business_logic_report.md", "w", encoding="utf-8") as md:
                md.write("# تحليل المنطق التجاري (تخفيض الضمان)\n\n")
                md.write(explanation)
            print("[+] Report saved to tests/business_logic_report.md")
    except Exception as e:
        print(f"[!] Error: {e}")


if __name__ == "__main__":
    ask_business_logic()
