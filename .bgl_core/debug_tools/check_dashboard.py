import urllib.request

url = "http://localhost:8000/agent-dashboard.php"
print(f"Connecting to {url}...")

try:
    with urllib.request.urlopen(url) as response:
        print(f"HTTP Status: {response.getcode()}")
        content = response.read().decode("utf-8")
        print(f"Content Length: {len(content)} bytes")

        checks = [
            ("Title 'BGL3'", "BGL3" in content),
            ("Class 'dashboard-grid'", "dashboard-grid" in content),
            ("Theme 'theme_light.css'", "theme_light.css" in content),
            ("Element 'badge-live'", "badge-live" in content),
            ("Text 'ملخص سريع'", "ملخص سريع" in content),
        ]

        passed = 0
        for name, result in checks:
            status = "✅ PASS" if result else "❌ FAIL"
            print(f"{status}: {name}")
            if result:
                passed += 1

        if passed == len(checks):
            print(
                "\nEvaluation: Excellent. The page is serving the correct structure and theme."
            )
        else:
            print(f"\nEvaluation: Warning. Only {passed}/{len(checks)} checks passed.")

except Exception as e:
    print(f"❌ Error connecting to dashboard: {e}")
    print("Ensure the local PHP server is running (e.g., 'php -S localhost:8000').")
