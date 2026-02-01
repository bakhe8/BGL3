import sqlite3
from pathlib import Path
from typing import List, Dict, Any
import json
import importlib
import os
import urllib.request
import urllib.error


class InferenceEngine:
    """
    Pattern Recognition & Knowledge Synthesis Engine.
    Analyzes 'agent_blockers' to propose new architectural rules.
    """

    def __init__(self, db_path: Path):
        self.db_path = db_path
        self.common_keywords = [
            "Permission",
            "Composer",
            "Database",
            "Dependency",
            "Timeout",
            "SSL",
        ]
        self.openai_key = os.getenv("OPENAI_KEY") or os.getenv("OPENAI_API_KEY")
        self.local_llm_url = os.getenv("LLM_BASE_URL", "http://localhost:11434")
        self.has_ai = bool(self.openai_key or self.local_llm_url)

    def _query_llm(self, prompt: str) -> Dict[str, Any]:
        """Queries LLM (Local Ollama or OpenAI) with zero external dependencies."""

        # 1. Try Local LLM (Ollama) first - Preferred
        ollama_url = os.getenv(
            "LLM_BASE_URL", "http://localhost:11434/v1/chat/completions"
        )
        ollama_model = os.getenv("LLM_MODEL", "llama3.1")

        try:
            # Check if we can connect to Ollama (fast check)
            # This allows failover to OpenAI if local is down
            req = urllib.request.Request(
                ollama_url.replace("/chat/completions", "/models")
            )
            with urllib.request.urlopen(req, timeout=1):
                pass

            # If reachable, use Local LLM
            headers = {"Content-Type": "application/json"}
            payload = {
                "model": ollama_model,
                "messages": [
                    {
                        "role": "system",
                        "content": "You are an expert software architect analyzing error logs. Output valid JSON only.",
                    },
                    {"role": "user", "content": prompt},
                ],
                "temperature": 0.2,
                "stream": False,
            }

            req = urllib.request.Request(
                ollama_url, json.dumps(payload).encode(), headers
            )
            # 30s timeout for local inference
            with urllib.request.urlopen(req, timeout=30) as response:
                return json.loads(response.read().decode())

        except Exception:
            # Local failed/not present, fall back to OpenAI
            pass

        # 2. Fallback to OpenAI
        if not self.openai_key:
            return {}

        url = "https://api.openai.com/v1/chat/completions"
        headers = {
            "Content-Type": "application/json",
            "Authorization": f"Bearer {self.openai_key}",
        }
        payload = {
            "model": "gpt-4",
            "messages": [
                {
                    "role": "system",
                    "content": "You are an expert software architect analyzing error logs.",
                },
                {"role": "user", "content": prompt},
            ],
            "temperature": 0.2,
        }

        try:
            req = urllib.request.Request(url, json.dumps(payload).encode(), headers)
            # 10s timeout to avoid hanging the agent
            with urllib.request.urlopen(req, timeout=10) as response:
                return json.loads(response.read().decode())
        except Exception as e:
            print(f"[!] Inference: LLM call failed: {e}")
            return {}

    def analyze_patterns(self) -> List[Dict[str, Any]]:
        """
        Scans resolved and pending blockers to find recurring issues.
        """
        if not self.db_path.exists():
            return []

        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()

            # Fetch all blockers (we learn from history too)
            cursor.execute("SELECT * FROM agent_blockers")
            blockers = [dict(r) for r in cursor.fetchall()]
            conn.close()
        except Exception as e:
            print(f"[!] Inference: Database error: {e}")
            return []

        if len(blockers) < 2:
            return []

        proposals = []

        # Detection Logic: Simple Keyword Clustering
        clusters: Dict[str, List[Dict[str, Any]]] = {
            kw: [] for kw in self.common_keywords
        }
        clusters["General"] = []

        for b in blockers:
            matched = False
            for kw in self.common_keywords:
                if (
                    kw.lower() in b["reason"].lower()
                    or kw.lower() in b["task_name"].lower()
                ):
                    clusters[kw].append(b)
                    matched = True
            if not matched:
                clusters["General"].append(b)

        # Synthesis Logic: Create proposals for clusters with >= 2 items
        for theme, items in clusters.items():
            # Skip "General" here if we have LLM capability (handled below)
            if theme == "General" and self.has_ai:
                continue

            if len(items) >= 2:
                self._synthesize_rule(theme, len(items), items)

        # Hybrid Intelligence: Ask AI about confusing "General" items
        if self.has_ai and len(clusters["General"]) >= 2:
            self._analyze_complex_cluster(clusters["General"])

        # Return ACTUAL persisted proposals from the database
        try:
            conn = sqlite3.connect(str(self.db_path))
            conn.row_factory = sqlite3.Row
            cursor = conn.cursor()
            cursor.execute("SELECT * FROM agent_proposals")
            proposals = [dict(r) for r in cursor.fetchall()]
            conn.close()
        except sqlite3.Error as e:
            print(f"[!] Inference: Analysis fetching error: {e}")

        return proposals

    def analyze_external_patterns(self, project_root: Path) -> List[Dict[str, Any]]:
        """
        Load patterns from inference_patterns.json and run plugin checks in checks/.
        """
        patterns_file = project_root / ".bgl_core" / "brain" / "inference_patterns.json"
        results: List[Dict[str, Any]] = []
        if not patterns_file.exists():
            return results
        try:
            patterns = json.loads(patterns_file.read_text(encoding="utf-8"))
        except Exception as e:
            print(f"[!] Inference: cannot load patterns file: {e}")
            return results

        for pat in patterns:
            check_name = pat.get("check")
            if not check_name:
                continue
            try:
                module = importlib.import_module(
                    f".checks.{check_name}", package="brain"
                )
            except Exception:
                try:
                    module = importlib.import_module(f"checks.{check_name}")
                except Exception as e:
                    print(f"[!] Inference: cannot import check {check_name}: {e}")
                    continue
            if not hasattr(module, "run"):
                continue
            try:
                res = module.run(project_root)
            except Exception as e:
                print(f"[!] Inference: check {check_name} failed: {e}")
                continue
            passed = bool(res.get("passed", False))
            evidence = res.get("evidence", [])
            scope = res.get("scope", [])
            results.append(
                {
                    "id": pat.get("id"),
                    "check": check_name,
                    "passed": passed,
                    "evidence": evidence,
                    "scope": scope,
                    "recommendation": pat.get("recommendation", ""),
                }
            )

        # Auto-generate proposed_patterns.json for failed checks (discovery only)
        failed = [r for r in results if not r.get("passed")]
        if failed:
            out_path = project_root / ".bgl_core" / "brain" / "proposed_patterns.json"
            try:
                out_path.parent.mkdir(parents=True, exist_ok=True)
                existing: List[Dict[str, Any]] = []
                if out_path.exists():
                    existing = json.loads(out_path.read_text(encoding="utf-8")) or []
                existing_ids = {p.get("id") for p in existing}
                for r in failed:
                    if r.get("id") in existing_ids:
                        continue
                    existing.append(
                        {
                            "id": r.get("id"),
                            "check": r.get("check"),
                            "evidence": r.get("evidence", []),
                            "scope": r.get("scope", []),
                            "recommendation": r.get("recommendation"),
                            "confidence": r.get("confidence", 0.65),
                        }
                    )
                out_path.write_text(json.dumps(existing, indent=2, ensure_ascii=False))
            except Exception as e:
                print(f"[!] Inference: failed to write proposed_patterns.json: {e}")
        return results

    def _synthesize_rule(
        self, pattern: str, count: int, stressors: List[Dict[str, Any]]
    ):
        """Creates a professional rule proposal with detailed reasoning and persists it."""

        # Determine rule details based on pattern (Arabic Localization)
        if "Permission" in pattern:
            name = "فحص تلقائي لصلاحية الكتابة"
            action = "WARN"
            description = (
                "التحقق من صلاحيات الملفات والمجلدات قبل إجراء عمليات الكتابة."
            )
            impact = "مرتفع: يمنع فشل المهام المفاجئ وفقدان البيانات الناتج عن قيود نظام التشغيل."
            solution = (
                "تفعيل مستشعر استباقي يتحقق من 'os.access' لجميع المسارات المستهدفة."
            )
            expectation = (
                "تقليل بنسبة 100% في استثناءات 'Permission Denied' أثناء التشغيل."
            )
        elif "Composer" in pattern:
            name = "منطق التراجع التلقائي للتبعيات"
            action = "WARN"
            description = (
                "يرصد إخفاقات Composer المتكررة ويقترح حلولاً بديلة أو تدخلاً يدوياً."
            )
            impact = "متوسط: يقلل من فشل المهام الناتج عن التبعيات المفقودة أو غير المتوافقة."
            solution = "دمج فحص صحة Composer وآلية تراجع في خط الإنتاج البرمجي."
            expectation = "انخفاض ملحوظ في حالات فشل المهام المتعلقة بـ 'Composer'."
        elif "Database" in pattern:
            name = "درع سلامة الاستعلامات"
            action = "WARN"
            description = (
                "يضمن اتصال قاعدة البيانات والتحقق من صحة الاستعلامات المتكررة."
            )
            impact = (
                "عالي: يمنع تلف البيانات وتوقف الخدمة الناتج عن مشاكل قاعدة البيانات."
            )
            solution = "تنفيذ التحقق المسبق من الاستعلامات وإدارة تجمعات الاتصال مع منطق إعادة المحاولة."
            expectation = (
                "تلاشي أخطاء اتصال قاعدة البيانات أو تنفيذ الاستعلامات تقريباً."
            )
        else:
            name = f"تخفيف نمط {pattern} العام"
            action = "WARN"
            description = f"مراقبة استباقية لأنماط {pattern} عبر جميع المهام."
            impact = "متوسط: يحسن استقرار الوكيل العام وتحديد مكان الأخطاء."
            solution = f"إضافة مدقق متخصص إلى شبكة الأمان (SafetyNet) لرصد {pattern}."
            expectation = f"انخفاض ملحوظ في ضغوط {pattern} المتكررة."

        evidence = ", ".join([s["task_name"] for s in stressors])

        try:
            conn = sqlite3.connect(str(self.db_path))
            cursor = conn.cursor()

            # Check if this rule name already exists to prevent duplication
            cursor.execute("SELECT id FROM agent_proposals WHERE name = ?", (name,))
            if cursor.fetchone():
                conn.close()
                return

            cursor.execute(
                """
                INSERT INTO agent_proposals (name, description, action, count, evidence, impact, solution, expectation)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            """,
                (
                    name,
                    description,
                    action,
                    count,
                    evidence,
                    impact,
                    solution,
                    expectation,
                ),
            )
            conn.commit()
            conn.close()
        except Exception as e:
            print(f"[!] Inference: Persist error: {e}")

    def _analyze_complex_cluster(self, items: List[Dict[str, Any]]):
        """Offloads complex pattern recognition to LLM."""
        print(
            f"[*] Inference: Offloading {len(items)} complex items to LLM (Local/Cloud)..."
        )
        prompt = "Analyze these software errors and propose a single architectural mitigation rule (JSON format with name, description, action=WARN/BLOCK, impact, solution, expectation):\n"
        for item in items[:5]:  # Limit to 5
            prompt += f"- {item['reason']} (Task: {item['task_name']})\n"

        resp = self._query_llm(prompt)
        # Handle Local LLM response format (usually matches OpenAI, but being safe)
        if not resp:
            print("[!] Inference: No response from LLM")
            return

        content = resp.get("choices", [{}])[0].get("message", {}).get("content", "")

        try:
            # Basic parsing attempt (LLM can return markdown)
            json_str = content.replace("```json", "").replace("```", "").strip()
            rule = json.loads(json_str)

            if "name" in rule:
                # Direct persistence of the high-quality rule from LLM
                conn = sqlite3.connect(str(self.db_path))
                cursor = conn.cursor()

                # Check for duplicates
                cursor.execute(
                    "SELECT id FROM agent_proposals WHERE name = ?", (rule["name"],)
                )
                if not cursor.fetchone():
                    cursor.execute(
                        """
                        INSERT INTO agent_proposals (name, description, action, count, evidence, impact, solution, expectation)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        """,
                        (
                            rule.get("name", "LLM Insight"),
                            rule.get("description", "AI-Generated advice"),
                            rule.get("action", "WARN"),
                            len(items),
                            "Hybrid Intelligence Analysis",
                            rule.get("impact", "Unknown"),
                            rule.get("solution", "Review logs"),
                            rule.get("expectation", "Improvement"),
                        ),
                    )
                    conn.commit()
                    print(
                        f"[*] Hybrid Inference: Persisted new rule '{rule.get('name')}'"
                    )
                conn.close()
        except Exception as e:
            print(f"[!] Inference: LLM extraction failed: {e}")


if __name__ == "__main__":
    # Test
    engine = InferenceEngine(Path(".bgl_core/brain/knowledge.db"))
    findings = engine.analyze_patterns()
    for f in findings:
        print(f"Proposed Rule: {f['name']} (Count: {f['count']})")
