from pathlib import Path
from typing import List, Dict, Any, Optional
import json
import os
import urllib.request
import urllib.error


class ReasoningEngine:
    """
    LLM-First Reasoning Engine.
    Implements a Plan-Act-Reflect loop for autonomous intelligence.
    """

    def __init__(self, db_path: Path, browser_sensor=None):
        self.db_path = db_path
        self.openai_key = os.getenv("OPENAI_KEY") or os.getenv("OPENAI_API_KEY")
        self.local_llm_url = os.getenv("LLM_BASE_URL", "http://localhost:11434")
        self.browser = browser_sensor

    def _get_project_structure(self) -> str:
        """Discovers real files to prevent hallucination."""
        core_paths = ["api/", "app/Services/", "app/Repositories/", "app/Support/"]
        found_files = []
        for path in core_paths:
            full_path = Path(path)
            if full_path.exists():
                files = [str(f) for f in full_path.glob("**/*.php")]
                found_files.extend(files)
        return "\n".join(found_files[:500])  # Increased for full project coverage

    async def reason(self, context: Dict[str, Any]) -> Dict[str, Any]:
        """
        Main reasoning loop: Plan -> Act -> Reflect.
        """
        print("[*] ReasoningEngine: Initiating cognitive cycle...")

        # 1. (Optional) UI Context Augmentation
        ui_insight = ""
        if self.browser and context.get("target_url"):
            print(
                f"[*] ReasoningEngine: Performing visual grounding on {context['target_url']}..."
            )
            try:
                scan = await self.browser.scan_url(context["target_url"])
                ui_insight = (
                    f"\nVISUAL UI STATE FOR {context['target_url']}:\n"
                    + json.dumps(scan, indent=2)
                )

                # [NEW] Autonomous Backend Analysis
                # The agent proactively reads the code behind the URL
                backend_insight = self._analyze_backend_logic(context["target_url"])
                ui_insight += f"\n\n[BACKEND CODE INSIGHT]\n{backend_insight}"

            except Exception as e:
                print(f"[!] ReasoningEngine: Visual grounding failed: {e}")

        # 2. State Analysis (Deep Code Inspection for targeted actions)
        # If the user query contains keywords for core actions, pre-load those files
        targeted_code = ""
        keywords = [
            "reduce",
            "extend",
            "release",
            "batch",
            "تمديد",
            "تخفيض",
            "إفراج",
            "دفعة",
        ]
        query_text = context.get("query_text", "").lower()

        if any(kw in query_text for kw in keywords):
            print(
                "[*] ReasoningEngine: Detected targeted action query. Pre-loading relevant logic..."
            )
            for kw in ["reduce", "extend", "release", "batches"]:
                if (
                    kw in query_text
                    or (kw == "reduce" and "تخفيض" in query_text)
                    or (kw == "extend" and "تمديد" in query_text)
                    or (kw == "release" and "إفراج" in query_text)
                    or (kw == "batches" and "دفعة" in query_text)
                ):
                    filename = f"api/{kw}.php"
                    file_path = Path(os.getcwd()) / filename
                    if file_path.exists():
                        content = file_path.read_text(encoding="utf-8")
                        targeted_code += f"\nFILE CONTENT FOR {filename}:\n{content}\n"

        prompt = self._build_reasoning_prompt(context, ui_insight, targeted_code)

        # 3. LLM Inference
        response = self._query_llm(prompt)

        # 3. Structured Decision Extraction
        try:
            plan = self._parse_structured_plan(response)
            print(f"[*] ReasoningEngine: Plan formulated - {plan.get('objective')}")
            return plan
        except Exception as e:
            print(f"[!] ReasoningEngine: Critical reasoning failure: {e}")
            return {
                "action": "wait",
                "reason": "Reasoning failed, falling back to safe mode.",
            }

    def _analyze_backend_logic(self, url: str) -> str:
        """
        Maps a URL to a local file and reads its key logic to understand 'False Positives' etc.
        """
        try:
            # 1. Parse URL to find potential file
            parsed = url.split("/")[-1].split("?")[0]  # e.g. statistics.php
            if not parsed:
                return ""

            # 2. Heuristic File Search
            root = Path(r"c:\Users\Bakheet\Documents\Projects\BGL3")
            candidates = [
                root / "views" / parsed,
                root / "agentfrontend" / parsed,
                root / parsed,
            ]

            target_file = None
            for cand in candidates:
                if cand.exists():
                    target_file = cand
                    break

            if not target_file:
                return f"No source file found for {parsed}"

            # 3. Smart Read (Extract SQL & Logic)
            content = target_file.read_text(encoding="utf-8", errors="ignore")
            lines = content.splitlines()
            extracted = []

            for i, line in enumerate(lines[:800]):
                txt = line.strip().upper()
                # Capture SQL, Comments, assignments
                if any(
                    x in txt
                    for x in [
                        "SELECT",
                        "FROM",
                        "JOIN",
                        "WHERE",
                        "$DB->QUERY",
                        "CALCULATION",
                        "NOTE:",
                        "TODO:",
                        "SECTION",
                    ]
                ):
                    extracted.append(f"{i + 1}: {line.strip()}")

            return f"Source File: {target_file.name}\n" + "\n".join(extracted[:60])

        except Exception as e:
            return f"Backend analysis error: {e}"

    def _build_reasoning_prompt(
        self, context: Dict[str, Any], ui_insight: str = "", code_insight: str = ""
    ) -> str:
        structure = self._get_project_structure()

        # [NEW] Load Domain Map for Grounding
        domain_map_path = Path("docs/domain_map.yml")
        domain_context = ""
        if domain_map_path.exists():
            domain_context = f"\nDOMAIN RULES (FROM domain_map.yml):\n{domain_map_path.read_text(encoding='utf-8')}\n"

        return f"""
        You are the **Senior BGL3 Specialist & Lead Developer**. 
        
        BGL3 DOMAIN (STRICT):
        - It is a **Document Issuance System** for Bank Guarantees.
        {domain_context}
        - **الدفعات (Batches)**: In this system, "الدفعات" refers to **Document Batches** (logical groups of guarantees imported/processed together). It does NOT mean financial payments.
        - **ABSOLUTELY NO MONEY**: The system handles documentation and letters ONLY. There are no fees, no financial payments, and no banking account logic. Any mention of "fees" is a domain hallucination.
        
        Current System State: {json.dumps(context, indent=2)}
        
        {ui_insight}
        
        {code_insight}
        
        VERIFIED PROJECT FILES:
        {structure}
        
        EXPERT REASONING PROTOCOL:
        1. **DEEP CODE INSPECTION**: Must search for backend files (api/reduce.php) for actions.
        2. **STRICT SCOPE**: Every analysis must be based on Document Issuance workflows.
        3. **SELF-EVOLUTION (NEW)**: If the user asks to "improve", "add widget", or "modify" the dashboard:
           - You are authorized to generate a `WRITE_FILE` action.
           - Target specific partial files like `agentfrontend/partials/extra_widget.php`.
           - Generate valid PHP/HTML content.
        
        Output a JSON object with:
        - "objective": high-level goal.
        - "expert_synthesis": Explanation of what you are doing.
        - "response": The chat message to the user.
        - "action": "WRITE_FILE" (only if explicitly requested to modify code).
        - "params": {{"path": "relative/path/to/file.php", "content": "..."}}
        """

    def _query_llm(self, prompt: str) -> str:
        """Queries LLM (Local Ollama or OpenAI) with zero external dependencies."""

        ollama_url = os.getenv(
            "LLM_BASE_URL", "http://localhost:11434/v1/chat/completions"
        )
        ollama_model = os.getenv("LLM_MODEL", "llama3.1")

        try:
            headers = {"Content-Type": "application/json"}
            payload = {
                "model": ollama_model,
                "messages": [
                    {
                        "role": "system",
                        "content": "You are the BGL3 Smart Agent Brain. Output valid JSON only.",
                    },
                    {"role": "user", "content": prompt},
                ],
                "response_format": {"type": "json_object"},
                "temperature": 0.1,
                "stream": False,
            }

            req = urllib.request.Request(
                ollama_url, json.dumps(payload).encode(), headers
            )
            with urllib.request.urlopen(req, timeout=30) as response:
                res = json.loads(response.read().decode())
                return res["choices"][0]["message"]["content"]
        except Exception:
            pass

        if not self.openai_key:
            return "{}"

        url = "https://api.openai.com/v1/chat/completions"
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.openai_key}",
        }
        payload = {
            "model": "gpt-4o",
            "messages": [
                {
                    "role": "system",
                    "content": "You are the BGL3 Smart Agent Brain. Output valid JSON only.",
                },
                {"role": "user", "content": prompt},
            ],
            "response_format": {"type": "json_object"},
            "temperature": 0.1,
        }

        try:
            req = urllib.request.Request(url, json.dumps(payload).encode(), headers)
            with urllib.request.urlopen(req, timeout=10) as response:
                res = json.loads(response.read().decode())
                return res["choices"][0]["message"]["content"]
        except Exception as e:
            print(f"[!] ReasoningEngine: LLM call failed: {e}")
            return "{}"

    async def chat(
        self, messages: List[Dict[str, Any]], target_url: Optional[str] = None
    ) -> Dict[str, Any]:
        """
        Conversational entry point with grounding.
        Returns the FULL PLAN (Dict) so the server can execute actions.
        """
        user_msg = messages[-1]["content"] if messages else ""

        # We reuse the heavy 'reason' logic for deep grounding even in chat
        context = {
            "query_text": user_msg,
            "target_url": target_url,
            "messages": messages,
        }

        plan = await self.reason(context)
        return plan

    def _parse_structured_plan(self, response_text: str) -> Dict[str, Any]:
        """Robustly parses JSON even if wrapped in markdown or containing garbage."""
        clean_text = response_text.strip()
        if "```json" in clean_text:
            clean_text = clean_text.split("```json")[1].split("```")[0].strip()
        elif "```" in clean_text:
            clean_text = clean_text.split("```")[1].split("```")[0].strip()

        # Remove any leading/trailing non-JSON characters
        start = clean_text.find("{")
        end = clean_text.rfind("}")
        if start != -1 and end != -1:
            clean_text = clean_text[start : end + 1]

        return json.loads(clean_text)


if __name__ == "__main__":
    # Test
    os.environ["LLM_MODEL"] = "llama3.1:latest"
    engine = ReasoningEngine(Path(".bgl_core/brain/knowledge.db"))
    plan = engine.reason({"test_mode": True, "status": "Ready"})
    print(json.dumps(plan, indent=2))
