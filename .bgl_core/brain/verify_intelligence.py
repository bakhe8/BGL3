import time
import json
import os
from pathlib import Path
from orchestrator import BGLOrchestrator

ROOT = Path(__file__).parent.parent.parent
orchestrator = BGLOrchestrator(ROOT)


class AgentBenchmark:
    def __init__(self):
        self.results = []

    def run_case(self, name, spec):
        print(f"[*] Running Case: {name}...")
        start_time = time.time()

        try:
            report = orchestrator.execute_task(spec)
            latency = time.time() - start_time

            result = {
                "case": name,
                "status": report["status"],
                "latency_sec": round(latency, 3),
                "checks": report.get("checks", []),
                "rollback": report.get("rollback_performed", False),
                "message": report.get("message", ""),
            }
            self.results.append(result)
            print(f"    - Status: {report['status']} ({latency:.2f}s)")
            return report
        except Exception as e:
            print(f"[!] Case Crashed: {e}")
            self.results.append({"case": name, "status": "CRASHED", "error": str(e)})

    def save_report(self):
        report_path = ROOT / ".bgl_core" / "logs" / "benchmark_results.json"
        with open(report_path, "w") as f:
            json.dump(self.results, f, indent=2)
        print(f"\n[+] Benchmark complete. Data saved to {report_path}")


def benchmark_intelligence():
    bench = AgentBenchmark()

    # Case 1: Structural Rename (High Confidence)
    bench.run_case(
        "Structural_Rename",
        {
            "task": "rename_class",
            "target": {"path": "app/Services/MatchEngine.php"},
            "params": {
                "old_name": "MatchEngine",
                "new_name": "MatchEngine_Bench",
                "dry_run": False,
            },
        },
    )

    # Case 2: Logic Addition (Contextual Integration)
    bench.run_case(
        "Logic_Addition",
        {
            "task": "add_method",
            "target": {"path": "app/Services/MatchEngine.php"},
            "params": {
                "target_class": "MatchEngine_Bench",
                "method_name": "verifyAlgorithm",
                "dry_run": False,
            },
        },
    )

    # Case 3: Integrity Test (Guardrail Violation)
    # Attempting to modify a config file which is blocklisted
    bench.run_case(
        "Integrity_Violation",
        {
            "task": "rename_class",
            "target": {"path": "config/app.php"},
            "params": {"old_name": "App", "new_name": "MaliciousApp", "dry_run": False},
        },
    )

    # Case 4: Architectural Wall (Governor Audit)
    # We will simulate a failure by forcing a check on a violation we know exists or by injecting one
    # For now, we test if the governor is called
    bench.run_case(
        "Architectural_Audit",
        {
            "task": "rename_class",
            "target": {"path": "app/Services/AuthManager.php"},
            "params": {
                "old_name": "AuthManager",
                "new_name": "AuthManager_V2",
                "dry_run": True,
            },
        },
    )

    bench.save_report()


if __name__ == "__main__":
    benchmark_intelligence()
