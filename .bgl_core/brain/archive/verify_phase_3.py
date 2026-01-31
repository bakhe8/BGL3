import json
from pathlib import Path
from safety import SafetyNet

ROOT = Path(__file__).parent.parent.parent


def test_fault_localization():
    """
    Verifies that SafetyNet can map a failing URL to a suspect PHP file.
    """
    print("[*] Testing Fault Localization (Phase 3)...")

    safety = SafetyNet(ROOT)

    # Simulate a browser report with a failing network request
    simulated_report = {
        "status": "FAILED",
        "console_errors": [],
        "network_failures": [
            {
                "url": "http://localhost:8000/api/get-record.php?id=123",
                "error": "net::ERR_CONNECTION_REFUSED",
            }
        ],
    }

    import time

    start_time = time.time()

    print("    - Running _gather_unified_logs with simulated network failure...")
    logs = safety._gather_unified_logs(start_time, simulated_report)

    # Find the network failure log entry
    network_entry = next(
        (log for log in logs if log["source"] == "frontend_network"), None
    )

    if network_entry:
        print("    [SUCCESS] Network failure log captured.")
        suspect = network_entry.get("suspect_code")
        if suspect:
            print("    [SUCCESS] Fault Localization active! Suspect Code identified:")
            print(f"      - File: {suspect['file']}")
            print(f"      - Controller: {suspect['controller']}")
            print(f"      - Action: {suspect['action']}")

            # Additional assertion: check if it correctly identified the file
            if "api/get-record.php" in suspect["file"].replace("\\", "/"):
                print(
                    "    [MATCH] Correct mapping: /api/get-record.php -> api/get-record.php"
                )
            else:
                print(
                    f"    [MISMATCH] Expected api/get-record.php, got {suspect['file']}"
                )
        else:
            print("    [FAILURE] Suspect code localization missing from log entry.")
    else:
        print("    [FAILURE] Network failure log entry missing.")

    print("\n--- Detailed Log Entry ---")
    print(json.dumps(network_entry, indent=2))


if __name__ == "__main__":
    test_fault_localization()
