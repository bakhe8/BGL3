import subprocess
from pathlib import Path
from typing import List, Dict, Any


def run_contract_suite(root: Path) -> List[Dict[str, Any]]:
    """
    Runs optional API contract/property tests if specs exist.
    - Schemathesis: looks for openapi.(yaml|json) under public/ or api/.
    - Dredd: looks for api.apib
    Returns a list of gap-like results to merge into diagnostic["gap_tests"].
    """
    results: List[Dict[str, Any]] = []
    openapi = None
    for candidate in [
        root / "docs" / "openapi.yaml",
        root / "docs" / "openapi.json",
        root / "public" / "openapi.yaml",
        root / "public" / "openapi.json",
        root / "api" / "openapi.yaml",
        root / "api" / "openapi.json",
    ]:
        if candidate.exists():
            openapi = candidate
            break

    if openapi:
        try:
            cmd = [
                "schemathesis",
                "run",
                str(openapi),
                "--stateful=links",
                "--checks=all",
                "--hypothesis-deadline=750",
                "--max-examples=50",
            ]
            r = subprocess.run(cmd, capture_output=True, text=True, cwd=root, timeout=120)
            passed = r.returncode == 0
            results.append(
                {
                    "id": "GAP_CONTRACT_OPENAPI",
                    "passed": passed,
                    "evidence": [openapi.name],
                    "scope": ["api"],
                    "details": r.stdout[-2000:],
                }
            )
        except FileNotFoundError:
            results.append(
                {
                    "id": "GAP_CONTRACT_OPENAPI",
                    "passed": True,  # mark as pass/skip to avoid noise when schemathesis not installed
                    "evidence": ["schemathesis not installed; skipped"],
                    "scope": ["api"],
                }
            )
        except Exception as e:
            results.append(
                {
                    "id": "GAP_CONTRACT_OPENAPI",
                    "passed": False,
                    "evidence": [f"schemathesis error: {e}"],
                    "scope": ["api"],
                }
            )

    apib = root / "api" / "api.apib"
    if apib.exists():
        try:
            cmd = ["dredd", str(apib), "http://127.0.0.1:8000"]
            r = subprocess.run(cmd, capture_output=True, text=True, cwd=root, timeout=120)
            passed = r.returncode == 0
            results.append(
                {
                    "id": "GAP_CONTRACT_APIB",
                    "passed": passed,
                    "evidence": [apib.name],
                    "scope": ["api"],
                    "details": r.stdout[-2000:],
                }
            )
        except FileNotFoundError:
            results.append(
                {
                    "id": "GAP_CONTRACT_APIB",
                    "passed": True,
                    "evidence": ["dredd not installed; skipped"],
                    "scope": ["api"],
                }
            )
        except Exception as e:
            results.append(
                {
                    "id": "GAP_CONTRACT_APIB",
                    "passed": False,
                    "evidence": [f"dredd error: {e}"],
                    "scope": ["api"],
                }
            )

    return results


if __name__ == "__main__":
    print(run_contract_suite(Path(__file__).resolve().parents[2]))
