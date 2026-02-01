"""
Minimal HTTP bridge to expose llm_tools via a simple POST API for Open WebUI or any client.
Usage:
    python scripts/tool_server.py --port 8891
Request:
    POST /tool  with JSON {"tool": "run_checks", "payload": {...}}
Response:
    JSON result from llm_tools.dispatch
"""
import json
from http.server import BaseHTTPRequestHandler, HTTPServer
from argparse import ArgumentParser
from pathlib import Path
import sys

ROOT = Path(__file__).resolve().parents[1]
sys.path.append(str(ROOT / ".bgl_core" / "brain"))

from llm_tools import dispatch  # type: ignore


class Handler(BaseHTTPRequestHandler):
    def _set_headers(self, code=200):
        self.send_response(code)
        self.send_header("Content-Type", "application/json; charset=utf-8")
        self.end_headers()

    def do_POST(self):
        if self.path != "/tool":
            self._set_headers(404)
            self.wfile.write(b'{"error":"not found"}')
            return
        length = int(self.headers.get("Content-Length", 0))
        body = self.rfile.read(length) if length > 0 else b"{}"
        try:
            req = json.loads(body.decode("utf-8"))
        except Exception as e:
            self._set_headers(400)
            self.wfile.write(json.dumps({"status": "ERROR", "message": f"bad json: {e}"}).encode("utf-8"))
            return
        try:
            resp = dispatch(req)
            self._set_headers(200)
            self.wfile.write(json.dumps(resp, ensure_ascii=False).encode("utf-8"))
        except Exception as e:
            self._set_headers(500)
            self.wfile.write(json.dumps({"status": "ERROR", "message": str(e)}).encode("utf-8"))

    def log_message(self, format, *args):
        return  # silence


def main():
    ap = ArgumentParser()
    ap.add_argument("--port", type=int, default=8891)
    args = ap.parse_args()
    server = HTTPServer(("0.0.0.0", args.port), Handler)
    print(f"tool_server listening on http://0.0.0.0:{args.port}/tool")
    try:
        server.serve_forever()
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()


if __name__ == "__main__":
    main()
