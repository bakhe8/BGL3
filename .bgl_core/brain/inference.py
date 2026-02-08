from pathlib import Path
from typing import List, Dict, Any, Optional, Union
import json
import os
import hashlib
import urllib.request
import urllib.error
import sys


if "inference" in __name__:  # Local vs module import hacks
    try:
        from .orchestrator import BGLOrchestrator  # type: ignore
        from .brain_rules import RuleEngine  # type: ignore
        from .brain_types import Context, CognitiveState  # type: ignore
    except ImportError:
        # Fallback for when running from a different root
        sys.path.append(str(Path(__file__).parent))
        from orchestrator import BGLOrchestrator  # type: ignore
        from brain_rules import RuleEngine  # type: ignore
        from brain_types import Context, CognitiveState  # type: ignore
else:
    try:
        from .orchestrator import BGLOrchestrator  # type: ignore
        from .brain_rules import RuleEngine  # type: ignore
        from .brain_types import Context, CognitiveState  # type: ignore
    except ImportError:
        sys.path.append(str(Path(__file__).parent))
        from orchestrator import BGLOrchestrator  # type: ignore
        from brain_rules import RuleEngine  # type: ignore
        from brain_types import Context, CognitiveState  # type: ignore

try:
    from .auto_insights import should_include_insight  # type: ignore
except Exception:
    try:
        sys.path.append(str(Path(__file__).parent))
        from auto_insights import should_include_insight  # type: ignore
    except Exception:
        should_include_insight = None  # type: ignore

try:
    from .knowledge_curation import load_knowledge_status  # type: ignore
except Exception:
    try:
        sys.path.append(str(Path(__file__).parent))
        from knowledge_curation import load_knowledge_status  # type: ignore
    except Exception:
        load_knowledge_status = None  # type: ignore


class ReasoningEngine:
    """
    LLM-First Reasoning Engine.
    Implements a Plan-Act-Reflect loop for autonomous intelligence.
    """

    def __init__(self, db_path: Path, browser_sensor=None):
        self.db_path = db_path
        self.openai_key = os.getenv("OPENAI_KEY") or os.getenv("OPENAI_API_KEY")
        # Use 127.0.0.1 to avoid Windows IPv6/localhost resolution issues
        self.local_llm_url = os.getenv("LLM_BASE_URL", "http://127.0.0.1:11434")
        self.browser = browser_sensor
        try:
            self.orchestrator = BGLOrchestrator(
                db_path.parent.parent.parent
            )  # Root dir from DB path
        except Exception:
            self.orchestrator = None  # Fallback if path derivation fails

        # [NEW] Phase 2: Structural Intelligence
        self.rule_engine = RuleEngine()

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

    def _get_file_hash(self, path: Path) -> str:
        """Calculates SHA-256 hash of a file."""
        hasher = hashlib.sha256()
        try:
            with open(path, "rb") as f:
                while chunk := f.read(8192):
                    hasher.update(chunk)
            return hasher.hexdigest()
        except Exception:
            return ""

    async def reason(
        self, context_input: Union[Dict[str, Any], Context]
    ) -> Dict[str, Any]:
        """
        Main reasoning loop: Plan -> Act -> Reflect.
        Args:
            context_input: Either a raw dict (Level 0) or structured Context (Level 3).
        """
        # Level 3 Enforcement: Upgrade to Schema if needed
        if isinstance(context_input, dict):
            context = Context(
                query_text=context_input.get("query_text", ""),
                target_url=context_input.get("target_url"),
                messages=context_input.get("messages", []),
            )
        else:
            context = context_input

        print(
            f"[*] ReasoningEngine: Initiating cognitive cycle (Intent: {context.intent.value})..."
        )

        # [NEW] Phase 2: Rule Evaluation
        state = CognitiveState()
        state, rule_instructions = self.rule_engine.evaluate(context, state)

        if rule_instructions:
            print(f"[*] Rule Engine Active: {rule_instructions}")

            # [NEW] Phase 2: Immediate Blocking (Safety Layer)
            for instr in rule_instructions:
                if instr.startswith("BLOCK_IMMEDIATE:"):
                    reason = instr.split(":", 1)[1].strip()
                    print(f"[!] BLOCKED by Policy: {reason}")
                    return {
                        "objective": "Policy Enforcement",
                        "action": "OBSERVE",
                        "params": {},
                        "response": f"عذراً، لا يمكنني تنفيذ هذا الطلب. السبب: {reason}",
                    }

        # 1. (Optional) UI Context Augmentation
        ui_insight = ""
        if self.browser and context.target_url:
            print(
                f"[*] ReasoningEngine: Performing visual grounding on {context.target_url}..."
            )
            try:
                scan = await self.browser.scan_url(context.target_url)
                ui_insight = (
                    f"\nVISUAL UI STATE FOR {context.target_url}:\n"
                    + json.dumps(scan, indent=2)
                )

                # [NEW] Autonomous Backend Analysis
                # The agent proactively reads the code behind the URL
                backend_insight = self._analyze_backend_logic(context.target_url)
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
        query_text = context.query_text.lower()

        if any(kw in query_text for kw in keywords):
            print(
                "[*] ReasoningEngine: Detected core action keywords. Scanning for relevant code..."
            )
            # Simple heuristic: find files matching keywords
            # (In a real system, this would be a vector search)
            # For now, we rely on the Orchestrator to know the exact path often,
            # or we just list the structure.
            pass

        # [NEW] Explicit File Focus (Level 3 Context)
        if context.file_focus:
            fp = Path(context.file_focus)
            if not fp.is_absolute():
                fp = self.db_path.parent.parent.parent / context.file_focus

            if fp.exists():
                print(f"[*] ReasoningEngine: Focusing on file {fp.name}...")
                try:
                    content = fp.read_text(encoding="utf-8", errors="ignore")
                    if len(content) > 12000:
                        content = content[:12000] + "\n...[TRUNCATED]"
                    targeted_code += f"\n\n[FOCUS FILE: {context.file_focus}]\n```php\n{content}\n```"
                except Exception as e:
                    print(f"[!] Failed to read focused file: {e}")
            else:
                print(f"[!] Focused file does not exist: {fp}")

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

        prompt = self._build_reasoning_prompt(
            context, ui_insight, targeted_code, rule_instructions, state
        )

        # 3. LLM Reasoning
        raw_response = self._query_llm(prompt)

        # [NEW] Phase 3: Robust JSON Extraction
        clean_json_str = self._extract_json_from_text(raw_response)

        # 3. Structured Decision Extraction
        try:
            plan = json.loads(clean_json_str)
            print(f"[*] ReasoningEngine: Plan formulated - {plan.get('objective')}")

            # [NEW] The Wiring: Connect Mind to Body
            # If the plan dictates an action, we ask the Orchestrator to execute/simulate it.
            action = plan.get("action")
            if action in ["WRITE_FILE", "RENAME_CLASS", "ADD_METHOD"]:
                print(f"[*] ReasoningEngine: Delegating {action} to Orchestrator...")

                # Convert plan to Orchestrator Task Spec
                task_spec = {
                    "task": action.lower(),
                    "target": {
                        "path": plan.get("params", {}).get("path")
                        or plan.get("params", {}).get("target_class")
                    },  # Best effort mapping
                    "params": plan.get("params", {}),
                }

                # Execute (Body)
                execution_report = self.orchestrator.execute_task(task_spec)
                plan["execution_report"] = execution_report

                if execution_report["status"] == "SUCCESS":
                    print(
                        f"[*] Orchestrator: Action Successful. Message: {execution_report.get('message')}"
                    )
                else:
                    print(
                        f"[!] Orchestrator: Action Failed. Reason: {execution_report.get('message')}"
                    )

            return plan
        except json.JSONDecodeError as e:
            print(f"[!] JSON PARSE ERROR: {e}")
            print(
                f"[!] RAW OUTPUT: {raw_response[:500]}..."
            )  # Log first 500 chars to debug

            return {
                "objective": "Error Recovery",
                "action": "OBSERVE",
                "params": {},
                "response": "عذراً، حدث خطأ داخلي في معالجة الاستجابة. (JSON Error)",
            }
        except Exception as e:
            print(f"[!] ReasoningEngine: Critical reasoning failure: {e}")
            return {
                "action": "wait",
                "reason": "Reasoning failed, falling back to safe mode.",
                "result": "error",
                "response": "عذراً، واجهت مشكلة تقنية في الخادم. (System Error)",
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
            return f"Source File: {target_file.name}\n" + "\n".join(
                extracted[:60]
            )  # Restored return statement
        except Exception as e:
            return f"Backend analysis error: {e}"

    def _json_serializer(self, obj):
        if hasattr(obj, "value"):  # Enums
            return obj.value
        if hasattr(obj, "__dict__"):  # Dataclasses/Objects
            return obj.__dict__
        if isinstance(obj, Path):  # Path objects
            return str(obj)
        raise TypeError(f"Type {type(obj)} not serializable")

    def _build_reasoning_prompt(
        self,
        context_input: Union[Dict[str, Any], Context],
        ui_insight: str = "",
        code_insight: str = "",
        rule_instructions: List[str] = [],
        state: Optional[CognitiveState] = None,
    ) -> str:
        # Convert Context to dict for template usage
        if isinstance(context_input, Context):
            from dataclasses import asdict

            context_dict = asdict(context_input)
        else:
            context_dict = context_input

        # [NEW] Phase 2: Structural Injection
        structural_directives = ""
        if rule_instructions:
            structural_directives = (
                "\n**CRITICAL STRUCTURAL RULES (MUST OBEY):**\n"
                + "\n".join([f"- {instr}" for instr in rule_instructions])
            )

        # [NEW] Phase 4: Operational Mode Injection
        mode_directives = ""
        if state:
            from brain_types import OperationalMode

            if state.active_mode == OperationalMode.ANALYSIS:
                mode_directives = (
                    "\n**MODE: ANALYSIS (DEEP THOUGHT)**\n"
                    "- You MUST use First Principles Thinking.\n"
                    "- Breakdown complex problems step-by-step.\n"
                    "- Cite specific files and lines of code.\n"
                    "- Do NOT concern yourself with speed. Accuracy is paramount.\n"
                )
            elif state.active_mode == OperationalMode.EXECUTION:
                mode_directives = (
                    "\n**MODE: EXECUTION (FAST ACTION)**\n"
                    "- Be extremely concise. No fluff.\n"
                    "- Focus ONLY on the 'action' and 'params'.\n"
                    "- Skip explanations unless critical for safety.\n"
                )
            elif state.active_mode == OperationalMode.AUDIT:
                mode_directives = (
                    "\n**MODE: AUDIT (PASS-THROUGH)**\n"
                    "- You are a transparent pipe. Do not add personality.\n"
                    "- Return exactly what is asked.\n"
                )

        # Determine language for the preamble
        lang_directive = ""
        if state and state.language_lock == "ar":
            lang_directive = "You MUST answer in ARABIC only."

        # [NEW] Load Domain Map for Grounding
        domain_map_path = Path("docs/domain_map.yml")
        domain_context = ""
        if domain_map_path.exists():
            domain_context = f"\nDOMAIN RULES (FROM domain_map.yml):\n{domain_map_path.read_text(encoding='utf-8')}\n"

        # [NEW] Dynamic Knowledge Ingestion (Continuous Learning)
        # Scans 'knowledge/' AND 'docs/' to build the "Brain"
        knowledge_context = "\n*** SYSTEM KNOWLEDGE BASE (DYNAMIC) ***\n"

        # 1. Load SQL Schema if exists
        schema_path = Path(".bgl_core/knowledge/schema.sql")
        if schema_path.exists():
            knowledge_context += f"\n--- DATABASE SCHEMA ---\n{schema_path.read_text(encoding='utf-8')}\n"

        # 2. Dynamic Recursive Scan of docs/ and knowledge/
        search_paths = [Path(".bgl_core/knowledge"), Path("docs")]
        loaded_files = set()
        try:
            project_root = self.db_path.parent.parent.parent
        except Exception:
            project_root = Path(os.getcwd())

        allow_legacy = os.getenv("BGL_ALLOW_LEGACY_INSIGHTS", "0") == "1"
        try:
            max_insights = int(os.getenv("BGL_MAX_AUTO_INSIGHTS", "0") or "0")
        except Exception:
            max_insights = 0
        try:
            max_docs = int(os.getenv("BGL_MAX_DOCS_FILES", "140") or "140")
        except Exception:
            max_docs = 140
        try:
            max_knowledge = int(os.getenv("BGL_MAX_KNOWLEDGE_FILES", "200") or "200")
        except Exception:
            max_knowledge = 200
        auto_insights_counts = {
            "total": 0,
            "loaded": 0,
            "duplicate": 0,
            "missing_meta": 0,
            "nested": 0,
            "missing_source": 0,
            "stale": 0,
            "expired": 0,
            "skipped_limit": 0,
        }
        auto_insights_loaded = 0

        knowledge_filter = None
        if load_knowledge_status is not None:
            try:
                knowledge_filter = load_knowledge_status(self.db_path)
            except Exception:
                knowledge_filter = None

        for root_path in search_paths:
            if root_path.exists():
                file_cap = max_docs if root_path.name == "docs" else max_knowledge
                loaded_for_root = 0
                # Find all .md and .txt files recursively
                for doc_file in root_path.rglob("*"):
                    if doc_file.suffix in [".md", ".txt"]:
                        doc_key = str(doc_file.resolve())
                        if doc_key in loaded_files:
                            continue
                        try:
                            is_insight = doc_file.name.endswith(
                                ".insight.md"
                            ) or doc_file.name.endswith(".insight.md.insight.md")
                            if is_insight:
                                auto_insights_counts["total"] += 1
                                if should_include_insight is None:
                                    auto_insights_counts["missing_meta"] += 1
                                    continue
                                ok, reason = should_include_insight(
                                    doc_file, project_root, allow_legacy=allow_legacy
                                )
                                if not ok:
                                    auto_insights_counts[reason] = (
                                        auto_insights_counts.get(reason, 0) + 1
                                    )
                                    continue
                                if max_insights and auto_insights_loaded >= max_insights:
                                    auto_insights_counts["skipped_limit"] += 1
                                    continue
                                auto_insights_loaded += 1
                                auto_insights_counts["loaded"] += 1

                            if file_cap and loaded_for_root >= file_cap:
                                continue
                            if knowledge_filter is not None:
                                try:
                                    rel_path = doc_file.resolve().relative_to(project_root.resolve()).as_posix()
                                except Exception:
                                    rel_path = doc_file.as_posix()
                                status = None
                                try:
                                    status = (knowledge_filter.get(rel_path) or {}).get("status")
                                except Exception:
                                    status = None
                                if status and status not in ("active", "legacy"):
                                    continue
                            content = doc_file.read_text(encoding="utf-8")
                            # Truncate very large files to avoid blowing up context (limit to 10KB per file)
                            if len(content) > 15000:
                                content = (
                                    content[:15000] + "\n...[TRUNCATED_DUE_TO_SIZE]..."
                                )

                            knowledge_context += (
                                f"\n--- DOCUMENT: {doc_file.name} ---\n{content}\n"
                            )
                            loaded_files.add(doc_key)
                            loaded_for_root += 1
                        except Exception:
                            pass  # Skip unreadable files

        if auto_insights_counts["total"] > 0:
            knowledge_context += (
                "\n--- AUTO_INSIGHTS STATUS ---\n"
                f"loaded={auto_insights_counts['loaded']} "
                f"total={auto_insights_counts['total']} "
                f"stale={auto_insights_counts['stale']} "
                f"missing_meta={auto_insights_counts['missing_meta']} "
                f"missing_source={auto_insights_counts['missing_source']} "
                f"nested={auto_insights_counts['nested']} "
                f"duplicate={auto_insights_counts['duplicate']} "
                f"expired={auto_insights_counts['expired']} "
                f"skipped_limit={auto_insights_counts['skipped_limit']}\n"
            )

        knowledge_context += "\n*****************************\n"

        # [FIX] Get structure string
        structure = self._get_project_structure()

        return f"""
{structural_directives}
{mode_directives}
{lang_directive}

You are the **Senior BGL3 Specialist & Lead Developer**. 

{knowledge_context}

BGL3 DOMAIN (STRICT):
- It is a **Document Issuance System** for Bank Guarantees.
{domain_context}
- **الدفعات (Batches)**: In this system, "الدفعات" refers to **Document Batches** (logical groups of guarantees imported/processed together). It does NOT mean financial payments.
- **ABSOLUTELY NO MONEY**: The system handles documentation and letters ONLY. There are no fees, no financial payments, and no banking account logic. Any mention of "fees" is a domain hallucination.

Current System State: {json.dumps(context_dict, indent=2, default=self._json_serializer)}

{ui_insight}

{code_insight}

VERIFIED PROJECT FILES:
{structure}

EXPERT REASONING PROTOCOL:
1. **DEEP CODE INSPECTION**: Must search for backend files (api/reduce.php) for actions.
2. **STRICT SCOPE**: Every analysis must be based on Document Issuance workflows.
3. {lang_directive}

[RESPONSE FORMAT]
Return ONLY a JSON object:
{{
  "objective": "Brief summary",
  "action": "OBSERVE | WRITE_FILE | RENAME_CLASS | SEARCH_GITHUB",
  "params": {{ ...args... }},
  "response": "Final text response to user (in requested language)"
}}
"""

    def _extract_json_from_text(self, text: str) -> str:
        """Robustly extracts JSON object from text (e.g. Markdown code fences or preambles)."""
        text = text.strip()

        # 1. Try stripping markdown code blocks
        if "```" in text:
            import re

            match = re.search(r"```(?:\w+)?\s*(\{.*?\})\s*```", text, re.DOTALL)
            if match:
                return match.group(1)

        # 2. Try finding the first '{' and last '}'
        start = text.find("{")
        end = text.rfind("}")
        if start != -1 and end != -1 and end > start:
            return text[start : end + 1]

        return text

    def _query_llm(self, prompt: str) -> str:
        """Queries LLM (Local Ollama or OpenAI) with zero external dependencies."""

        # Use 127.0.0.1 to avoid Windows IPv6/localhost resolution issues
        ollama_url = os.getenv(
            "LLM_BASE_URL", "http://127.0.0.1:11434/v1/chat/completions"
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
                "temperature": 0.0,
                "stream": False,
            }

            req = urllib.request.Request(
                ollama_url, json.dumps(payload).encode(), headers
            )
            # Timeout increased to 30s to allow for Model VRAM loading (Cold Start)
            with urllib.request.urlopen(req, timeout=30) as response:
                res = json.loads(response.read().decode())
                return res["choices"][0]["message"]["content"]
        except Exception as e:
            print(f"[!] Local LLM Failed ({e}). Attempting Failover...")
            # Fall through to OpenAI logic below
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

    def _get_brain_state(self, base_url: str) -> str:
        """
        Diagnose Brain State: "HOT" (Ready), "COLD" (Loading), or "OFFLINE".
        """
        try:
            fs_url = base_url.replace("/v1/chat/completions", "/api/ps")
            req = urllib.request.Request(fs_url)
            with urllib.request.urlopen(req, timeout=0.5) as response:
                dat = json.loads(response.read().decode())
                return "HOT" if dat.get("models") else "COLD"
        except Exception:
            return "OFFLINE"

    async def chat(
        self, messages: List[Dict[str, Any]], target_url: Optional[str] = None
    ) -> Dict[str, Any]:
        """
        Conversational entry point with grounding.
        Returns the FULL PLAN (Dict) so the server can execute actions.
        """
        # [NEW] Active Warm-up Protocol
        ollama_url = os.getenv(
            "LLM_BASE_URL", "http://127.0.0.1:11434/v1/chat/completions"
        )
        base_api = ollama_url.replace("/v1/chat/completions", "/api")
        model = os.getenv("LLM_MODEL", "llama3.1")

        # 1. Initial Check
        status = self._get_brain_state(ollama_url)
        if status == "COLD":
            print(f"[*] Brain is COLD. Sending wake-up signal to {model}...")
            # Fire-and-forget wake-up call (0 tokens)
            try:
                trigger_url = f"{base_api}/generate"
                # keep_alive ensures it stays loaded
                payload = json.dumps({"model": model, "prompt": "", "keep_alive": "5m"})
                req = urllib.request.Request(
                    trigger_url, payload.encode(), {"Content-Type": "application/json"}
                )
                # We expect this to hang while loading, so we set a tiny timeout just to fire it
                # OR we wait properly. Better to wait properly here so the loop below confirms.
                # Actually, blocking here for a bit is fine.
                try:
                    urllib.request.urlopen(req, timeout=1)
                except (TimeoutError, urllib.error.URLError):
                    pass  # Expected timeout, the loading started in background
            except Exception as e:
                print(f"[!] Warm-up signal failed: {e}")

        # 2. Polling Loop (Now expecting it to turn HOT)
        for i in range(15):  # Wait up to 30s
            status = self._get_brain_state(ollama_url)
            print(f"[*] Brain Status: {status} (Mode: {model}) - Waiting for load...")
            if status == "HOT":
                print("[*] Brain is HOT and Ready.")
                break
            if status == "OFFLINE":
                print("[!] Brain is OFFLINE.")
                break
            import time

            time.sleep(2)

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
