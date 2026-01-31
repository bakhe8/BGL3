import sys
from pathlib import Path

# Add the brain directory to the path so we can import modules
sys.path.append(str(Path(__file__).parent))

from safety import SafetyNet

ROOT = Path(__file__).parent.parent.parent


def test_browser_sensor_integration():
    """
    Test that SafetyNet correctly uses BrowserSensor to detect frontend errors.
    """
    print("[*] Testing Browser Sensor Integration...")

    # We will assume a PHP server is NOT running for this test
    # and verify that it handles the 'Connection Refused' error gracefully
    # (which is a valid network failure detection).

    safety = SafetyNet(ROOT, base_url="http://localhost:9999")  # Use an unlikely port

    print("    - Running browser audit (expecting connection failure)...")
    res = safety._check_browser_audit()

    print(f"    - Valid: {res['valid']}")

    if not res["valid"] and "errors" in res:
        print(f"    - Detected Fault: {res['errors']}")
        print(
            "    [SUCCESS] Browser Sensor correctly identified an environment/access fault."
        )
    else:
        print("    [FAILURE] Browser Sensor did not report the expected fault.")


if __name__ == "__main__":
    test_browser_sensor_integration()
