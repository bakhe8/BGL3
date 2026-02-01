import sqlite3
import random
import time
from pathlib import Path

DB_PATH = Path(".bgl_core/brain/knowledge.db")


def simulate_traffic():
    if not DB_PATH.exists():
        print(f"Error: {DB_PATH} not found.")
        return

    conn = sqlite3.connect(str(DB_PATH))
    cursor = conn.cursor()

    # Schema check done:
    # id, timestamp (REAL), session, event_type, route, method, target, payload, status, latency_ms, error

    routes = [
        ("/api/create-guarantee.php", "POST"),
        ("/api/update_bank.php", "POST"),
        ("/api/import_suppliers.php", "POST"),
        ("/api/get_dashboard.php", "GET"),
    ]

    print("🚀 Generating 50 simulated requests...")

    for _ in range(50):
        route, method = random.choice(routes)

        # 90% Success, 8% Validation Error, 2% Server Error
        rand = random.random()
        if rand > 0.1:
            status = 200
            error = None
            latency = float(random.randint(50, 300))
        elif rand > 0.02:
            status = 422
            error = "Validation Failed: Invalid IBAN"
            latency = float(random.randint(20, 100))
        else:
            status = 500
            error = "Database Connection Timeout"
            latency = float(random.randint(1000, 3000))

        cursor.execute(
            """
            INSERT INTO runtime_events (
                timestamp, session, event_type, route, method, target, payload, status, latency_ms, error
            )
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        """,
            (
                time.time(),
                "sim_session_1",
                "import_banks" if "import" in route else "api_call",
                route,
                method,
                "target_system",
                "{}",
                status,
                latency,
                error,
            ),
        )

    conn.commit()
    conn.close()
    print("✅ Traffic simulation complete. KPIs should now be populated.")


if __name__ == "__main__":
    simulate_traffic()
