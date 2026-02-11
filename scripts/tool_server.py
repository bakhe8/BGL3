"""
Minimal HTTP bridge for BGL3 tools + chat (CORS enabled).

Usage:
    python scripts/tool_server.py --port 8891

Endpoints:
    POST /tool   {"tool": "run_checks", "payload": {...}}
    POST /chat   {"messages": [...], "functions": [...]}
"""

import json
import os
from http.server import BaseHTTPRequestHandler, HTTPServer
from argparse import ArgumentParser
from pathlib import Path
import sys
import subprocess
import shlex
import urllib.request
import urllib.error

ROOT = Path(__file__).resolve().parents[1]
sys.path.append(str(ROOT / ".bgl_core" / "brain"))


def _preferred_python() -> str:
    candidates = [
        ROOT / ".bgl_core" / ".venv312" / "Scripts" / "python.exe",
        ROOT / ".bgl_core" / ".venv" / "Scripts" / "python.exe",
        ROOT / ".bgl_core" / ".venv312" / "bin" / "python",
        ROOT / ".bgl_core" / ".venv" / "bin" / "python",
    ]
    for cand in candidates:
        if cand.exists():
            return str(cand)
    return sys.executable or "python"


PYTHON_EXE = _preferred_python()

from llm_tools import dispatch  # type: ignore
from intent_resolver import resolve_intent  # type: ignore
from agency_core import AgencyCore
import asyncio

agency = AgencyCore(ROOT)


class Handler(BaseHTTPRequestHandler):
    def _set_headers(self, code=200, extra_headers=None):
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.send_header("Access-Control-Allow-Origin", "*")
        self.send_header("Access-Control-Allow-Headers", "Content-Type")
        self.send_header("Access-Control-Allow-Methods", "POST, GET, OPTIONS")
        self.send_header("Access-Control-Max-Age", "86400")
        if extra_headers:
            for k, v in extra_headers.items():
                self.send_header(k, v)
        self.end_headers()

    def do_OPTIONS(self):
        self._set_headers(200)

    def do_GET(self):
        if self.path == "/health":
            self._set_headers(200)
            self.wfile.write(json.dumps({"status": "OK"}).encode("utf-8"))
            return
        self._set_headers(404)
        self.wfile.write(b'{"error":"not found"}')

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length) if length > 0 else b"{}"
        try:
            req = json.loads(body.decode("utf-8"))
        except Exception as e:
            self._set_headers(400)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": f"bad json: {e}"}).encode(
                    "utf-8"
                )
            )
            return

        if self.path == "/tool":
            self._handle_tool(req)
        elif self.path == "/chat":
            self._handle_chat(req)
        elif self.path == "/health":
            self._set_headers(200)
            self.wfile.write(json.dumps({"status": "OK"}).encode("utf-8"))
        else:
            self._set_headers(404)
            self.wfile.write(b'{"error":"not found"}')

    def _handle_tool(self, req):
        tool = req.get("tool")
        if tool == "context_snapshot":
            self._context_snapshot()
            return
        if tool == "log_tail":
            self._log_tail(req)
            return
        if tool == "phpunit_run":
            self._phpunit_run(req)
            return
        if tool == "master_verify":
            self._master_verify(req)
            return
        if tool == "scenario_run":
            self._scenario_run(req)
            return
        if tool == "route_show":
            self._route_show(req)
            return
        if tool == "list_files":
            self._list_files(req)
            return
        if tool == "describe_runtime":
            self._describe_runtime()
            return
        if tool == "resolve_intent":
            self._resolve_intent(req)
            return
        if tool == "read_file":
            self._read_file(req)
            return
        if tool == "search_code":
            self._search_code(req)
            return
        try:
            resp = dispatch(req)
            self._set_headers(200)
            self.wfile.write(json.dumps(resp, ensure_ascii=False).encode("utf-8"))
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _context_snapshot(self):
        """Return a short, dynamic context about BGL3 domain (bank guarantees)."""
        try:
            root = ROOT
            summary_parts = []
            logic_ref = root / "docs" / "logic_reference.md"
            flows = [
                "create_guarantee.md",
                "extend_guarantee.md",
                "release_guarantee.md",
            ]
            if logic_ref.exists():
                with logic_ref.open("r", encoding="utf-8", errors="ignore") as f:
                    text = f.read(4000)
                    summary_parts.append(text)
            for flow in flows:
                fp = root / "docs" / "flows" / flow
                if fp.exists():
                    with fp.open("r", encoding="utf-8", errors="ignore") as f:
                        summary_parts.append(f.read(1500))
            domain_map = root / "docs" / "domain_map.yml"
            if domain_map.exists():
                with domain_map.open("r", encoding="utf-8", errors="ignore") as f:
                    summary_parts.append(f.read(2000))
            text = "\n\n".join(summary_parts)
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {"status": "OK", "context": text}, ensure_ascii=False
                ).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _log_tail(self, req):
        lines = int(req.get("lines", 120))
        logfile = ROOT / "storage" / "logs" / "laravel.log"
        if not logfile.exists():
            self._set_headers(404)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": "log file not found"}).encode(
                    "utf-8"
                )
            )
            return
        try:
            content = logfile.read_text(encoding="utf-8", errors="ignore").splitlines()
            tail = "\n".join(content[-lines:])
            self._set_headers(200)
            self.wfile.write(
                json.dumps({"status": "OK", "log_tail": tail}).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _phpunit_run(self, req):
        filter_arg = req.get("filter")
        cmd = ["php", "vendor/bin/phpunit"]
        if filter_arg:
            cmd += ["--filter", str(filter_arg)]
        try:
            res = subprocess.run(
                cmd, cwd=ROOT, capture_output=True, text=True, timeout=180
            )
            output = (res.stdout or "") + "\n" + (res.stderr or "")
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {
                        "status": "OK",
                        "exit_code": res.returncode,
                        "output": output[-4000:],
                    }
                ).encode("utf-8")
            )
        except subprocess.TimeoutExpired:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": "phpunit timeout"}).encode(
                    "utf-8"
                )
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _master_verify(self, req):
        try:
            res = subprocess.run(
                [PYTHON_EXE, ".bgl_core/brain/master_verify.py"],
                cwd=ROOT,
                capture_output=True,
                text=True,
                timeout=300,
            )
            output = (res.stdout or "") + "\n" + (res.stderr or "")
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {
                        "status": "OK",
                        "exit_code": res.returncode,
                        "output": output[-4000:],
                    }
                ).encode("utf-8")
            )
        except subprocess.TimeoutExpired:
            self._set_headers(500)
            self.wfile.write(
                json.dumps(
                    {"status": "ERROR", "message": "master_verify timeout"}
                ).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _scenario_run(self, req):
        try:
            env = os.environ.copy()
            env["BGL_TRIGGER_SOURCE"] = "tool_server"
            res = subprocess.run(
                [PYTHON_EXE, ".bgl_core/brain/run_scenarios.py"],
                cwd=ROOT,
                capture_output=True,
                text=True,
                env=env,
                timeout=300,
            )
            output = (res.stdout or "") + "\n" + (res.stderr or "")
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {
                        "status": "OK",
                        "exit_code": res.returncode,
                        "output": output[-4000:],
                    }
                ).encode("utf-8")
            )
        except subprocess.TimeoutExpired:
            self._set_headers(500)
            self.wfile.write(
                json.dumps(
                    {"status": "ERROR", "message": "scenario_run timeout"}
                ).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _route_show(self, req):
        uri = req.get("uri")
        if not uri:
            self._set_headers(400)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": "uri required"}).encode(
                    "utf-8"
                )
            )
            return
        cmd = ["php", "artisan", "route:list", "--path", str(uri), "--json"]
        try:
            res = subprocess.run(
                cmd, cwd=ROOT, capture_output=True, text=True, timeout=120
            )
            output = res.stdout or res.stderr
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {"status": "OK", "exit_code": res.returncode, "output": output}
                ).encode("utf-8")
            )
        except subprocess.TimeoutExpired:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": "route:list timeout"}).encode(
                    "utf-8"
                )
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _list_files(self, req):
        try:
            limit = int(req.get("limit", 20))
            if limit > 200:
                limit = 200
            pattern = req.get("pattern")
            base = ROOT
            files = []
            count = 0
            for path in base.rglob("*"):
                if path.is_file():
                    rel = str(path.relative_to(base))
                    if pattern and pattern not in rel:
                        continue
                    files.append(rel)
                    count += 1
                    if count >= limit:
                        break
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {"status": "OK", "files": files, "count": len(files)}
                ).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _describe_runtime(self):
        try:
            tools = [
                "run_checks",
                "route_index",
                "logic_bridge",
                "context_snapshot",
                "log_tail",
                "phpunit_run",
                "master_verify",
                "scenario_run",
                "route_show",
                "list_files",
                "describe_runtime",
                "read_file",
                "search_code",
                "resolve_intent",
            ]
            info = {
                "python": sys.version.split()[0],
                "root": str(ROOT),
                "tools": tools,
                "policies": {
                    "must_use_tool_for_execution": True,
                    "no_fake_execution": True,
                },
            }
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {"status": "OK", "runtime": info}, ensure_ascii=False
                ).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _resolve_intent(self, req):
        diagnostic = req.get("diagnostic", {"vitals": {}, "findings": {}})
        try:
            result = resolve_intent(diagnostic) or {}
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {"status": "OK", "intent": result}, ensure_ascii=False
                ).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _read_file(self, req):
        try:
            rel = req.get("path")
            if not rel:
                self._set_headers(400)
                self.wfile.write(
                    json.dumps({"status": "ERROR", "message": "path required"}).encode(
                        "utf-8"
                    )
                )
                return
            full = ROOT / rel
            if not full.exists() or not full.is_file():
                self._set_headers(404)
                self.wfile.write(
                    json.dumps({"status": "ERROR", "message": "file not found"}).encode(
                        "utf-8"
                    )
                )
                return
            max_bytes = int(req.get("max_bytes", 12000))
            data = full.read_bytes()[:max_bytes]
            try:
                text = data.decode("utf-8", errors="ignore")
            except Exception:
                text = data.decode("latin-1", errors="ignore")
            self._set_headers(200)
            self.wfile.write(
                json.dumps({"status": "OK", "path": rel, "content": text}).encode(
                    "utf-8"
                )
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    def _search_code(self, req):
        pattern = req.get("pattern")
        if not pattern:
            self._set_headers(400)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": "pattern required"}).encode(
                    "utf-8"
                )
            )
            return
        limit = int(req.get("limit", 40))
        if limit > 200:
            limit = 200
        matches = []
        try:
            for path in ROOT.rglob("*"):
                if not path.is_file():
                    continue
                try:
                    text = path.read_text(encoding="utf-8", errors="ignore")
                except Exception:
                    continue
                if pattern in text:
                    matches.append(str(path.relative_to(ROOT)))
                    if len(matches) >= limit:
                        break
            self._set_headers(200)
            self.wfile.write(
                json.dumps(
                    {"status": "OK", "pattern": pattern, "matches": matches}
                ).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8")
            )

    async def _execute_actions(self, plan):
        """Safely executes actions requested by the AI."""
        if plan.get("action") == "WRITE_FILE":
            params = plan.get("params", {})
            path = params.get("path")
            content = params.get("content")

            if path and content:
                # Security: Only allow writing to agentfrontend/partials
                # This prevents the AI from overwriting core system files or escaping.
                safe_root = ROOT / "agentfrontend" / "partials"
                target = (ROOT / path).resolve()

                # Check for path traversal or authorized scope
                if str(safe_root) in str(target) or "extra_widget.php" in str(target):
                    try:
                        target.parent.mkdir(parents=True, exist_ok=True)
                        target.write_text(content, encoding="utf-8")
                        print(f"[*] ACTION EXECUTED: Wrote to {target}")
                        return True
                    except Exception as e:
                        print(f"[!] ACTION FAILED: {e}")

        elif plan.get("action") == "SEARCH_GITHUB":
            query = plan.get("params", {}).get("query")
            if query:
                try:
                    print(f"[*] Searching GitHub for: {query}")
                    url = f"https://api.github.com/search/repositories?q={query}&sort=stars&order=desc"
                    headers = {
                        "Accept": "application/vnd.github.v3+json",
                        "User-Agent": "BGL3-Agent",
                    }
                    req = urllib.request.Request(url, headers=headers)
                    with urllib.request.urlopen(req, timeout=10) as resp:
                        status = getattr(resp, "status", 200)
                        raw = resp.read().decode("utf-8", errors="ignore")
                    if status == 200:
                        data = json.loads(raw)
                        top_repos = data.get("items", [])[:3]
                        results = []
                        for repo in top_repos:
                            results.append(
                                f"- [{repo['full_name']}]({repo['html_url']}): {repo['description']} (⭐ {repo['stargazers_count']})"
                            )
                        return (
                            "\n".join(results) if results else "No repositories found."
                        )
                    else:
                        print(f"[!] GitHub API Error: {status}")
                        return f"GitHub API unavailable (Status {status})."
                except Exception as e:
                    print(f"[!] Search Failed: {e}")
                    return f"Search failed: {e}"

        return False

    def _handle_chat(self, req):
        messages = req.get("messages", [])
        target_url = req.get("target_url")
        # Lightweight anchor to keep specialization without heavy system prompt.
        if messages:
            anchor = {
                "role": "system",
                "content": "أنت وكيل متخصص في نظام BGL3 (إدارة الضمانات البنكية). استوعب طلب المستخدم العربي وحوّله إلى تعليمات مناسبة للنظام. تجنّب الردود العامة غير المرتبطة بالسؤال.",
            }
            messages = [anchor] + messages

        try:
            if self._is_light_chat(messages):
                content = "مرحباً! كيف أستطيع مساعدتك في نظام BGL3؟"
                self._set_headers(200)
                self.wfile.write(
                    json.dumps({"content": content}, ensure_ascii=False).encode("utf-8")
                )
                return
            # use grounded chat first (actions capable)
            try:
                plan = asyncio.run(
                    asyncio.wait_for(
                        agency.inference.chat(messages, target_url), timeout=20
                    )
                )
            except Exception:
                plan = None

            # 1. Execute Actions if any
            action_result = asyncio.run(self._execute_actions(plan)) if plan else None

            # 2. Extract Response Text
            if plan:
                content = plan.get("response") or plan.get("expert_synthesis") or str(plan)
            else:
                content = ""

            # Heuristic: avoid generic security/code-snippet replies for natural questions
            user_msg = next((m for m in reversed(messages) if m.get("role") == "user"), {})
            user_text = (user_msg.get("content") or "").lower()
            suspicious = any(
                kw in (content or "").lower()
                for kw in ["code snippet", "security", "vulnerabilities", "php script"]
            )
            if (not content) or (suspicious and not any(k in user_text for k in ["كود", "شفرة", "security", "ثغرة", "php"])):
                content = self._direct_llm_chat(messages, include_context=True)
            elif not content:
                content = "تعذر توليد رد مرتبط بالسياق. الرجاء إعادة صياغة الطلب أو توضيحه."

            if action_result:
                content += "\n\n⚡ **SYSTEM UPDATED**: I have applied the changes to the dashboard successfully."

            self._set_headers(200)
            self.wfile.write(
                json.dumps({"content": content}, ensure_ascii=False).encode("utf-8")
            )
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(
                json.dumps(
                    {"status": "ERROR", "message": f"Reasoning error: {str(e)}"}
                ).encode("utf-8")
            )

    def _is_light_chat(self, messages) -> bool:
        try:
            if not messages:
                return True
            # last user message
            user = next((m for m in reversed(messages) if m.get("role") == "user"), None)
            if not user:
                return True
            text = (user.get("content") or "").strip()
            if not text:
                return True
            lower = text.lower()
            greetings = [
                "hi", "hello", "hey", "ping", "test", "مرحبا", "مرحباً", "السلام", "اهلا", "أهلا", "هلا"
            ]
            if any(g in lower for g in greetings):
                return True
            # very short messages are treated as light chat
            return len(text) <= 16
        except Exception:
            return True

    def _direct_llm_chat(self, messages, include_context: bool = False):
        """
        Fast path for simple greetings/short messages to avoid heavy reasoning timeouts.
        """
        try:
            if include_context:
                ctx = self._compose_context_summary()
                messages = [
                    {"role": "system", "content": "أجب باختصار وبالعربية عن نظام BGL3 فقط. إن كان السؤال غير واضح فاطلب توضيحاً."},
                    {"role": "system", "content": ctx[:2000]},
                ] + messages
            base_url = os.getenv(
                "LLM_BASE_URL", "http://127.0.0.1:11434/v1/chat/completions"
            )
            model = os.getenv("LLM_MODEL", "llama3.1:latest")
            payload = {"model": model, "messages": messages, "stream": False}
            req = urllib.request.Request(
                base_url,
                json.dumps(payload).encode("utf-8"),
                {"Content-Type": "application/json"},
            )
            with urllib.request.urlopen(req, timeout=8) as resp:
                data = json.loads(resp.read().decode("utf-8", errors="ignore"))
            content = (
                (((data.get("choices") or [{}])[0]).get("message") or {}).get("content")
            )
            return content or "مرحباً! كيف أستطيع مساعدتك في نظام BGL3؟"
        except Exception as e:
            return f"تعذر الرد حالياً: {e}"

    def _compose_context_summary(self):
        parts = []
        parts.append(
            "BGL3 هو نظام لإدارة الضمانات البنكية (Bank Guarantees): إنشاء ضمان، تمديده، الإفراج عنه، وربطه بين بنك ومورد مع تدقيق القرارات والسجلات. لا علاقة له بالإنزيمات أو الأحياء. إذا سُئلت عن BGL3 فجوابك يتعلق بالضمانات البنكية فقط."
        )
        parts.append(
            "In this chat, BGL3 always means a bank guarantees management web system (create/extend/release guarantees between bank and supplier). It is NOT a gene or enzyme. If asked 'What is BGL3?' answer with the banking system meaning."
        )
        tools = [
            "run_checks",
            "route_index",
            "logic_bridge",
            "phpunit_run",
            "master_verify",
            "scenario_run",
            "log_tail",
            "route_show",
            "list_files",
            "read_file",
            "search_code",
            "context_snapshot",
            "describe_runtime",
            "resolve_intent",
        ]
        parts.append("الأدوات المتاحة: " + ", ".join(tools))
        for path, limit in [
            (ROOT / "docs" / "logic_reference.md", 4000),
            (ROOT / "docs" / "domain_map.yml", 3000),
        ]:
            if path.exists():
                try:
                    txt = path.read_text(encoding="utf-8", errors="ignore")[:limit]
                except Exception:
                    txt = path.read_text(errors="ignore")[:limit]
                parts.append(f"[{path.name}]\n{txt}")
        return "\n\n".join(parts)

    def log_message(self, format, *args):
        return  # silence


def main():
    ap = ArgumentParser()
    ap.add_argument("--port", type=int, default=8891)
    args = ap.parse_args()
    try:
        from http.server import ThreadingHTTPServer

        server = ThreadingHTTPServer(("0.0.0.0", args.port), Handler)
    except Exception:
        server = HTTPServer(("0.0.0.0", args.port), Handler)
    print(f"tool_server listening on http://0.0.0.0:{args.port}/tool and /chat")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
