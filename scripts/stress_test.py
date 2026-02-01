import threading
import time
import urllib.request
import urllib.error
import urllib.parse
import json
import random

BASE_URL = "http://localhost:8000"
NUM_WORKERS = 5
REQUESTS_PER_WORKER = 10
ERRORS = 0
LOCK = threading.Lock()


def make_request(worker_id, is_shadow):
    global ERRORS

    # 1. READ (index.php) - Get a valid ID (simulated)
    try:
        url = f"{BASE_URL}/index.php"
        headers = {}
        if is_shadow:
            headers["X-Shadow-Mode"] = "true"

        req = urllib.request.Request(url, headers=headers)
        with urllib.request.urlopen(req) as response:
            # Simply check if we got 200 OK
            if response.status != 200:
                print(f"[Worker {worker_id}] READ Failed: {response.status}")
                return

    except Exception as e:
        with LOCK:
            ERRORS += 1
        print(f"[Worker {worker_id}] READ Error: {e}")
        return

    # 2. WRITE (Shadow Only) to api/save-and-next.php
    # We only write if it's shadow mode to verify shadow DB writes don't block/break
    if is_shadow:
        try:
            url = f"{BASE_URL}/api/save-and-next.php"
            # We need a dummy payload. We'll pick ID 1 (assuming it exists in shadow DB)
            data = {
                "guarantee_id": 1,
                "supplier_id": 1,
                "supplier_name": "Test Supplier",
                "decided_by": "stress_test",
            }
            json_data = json.dumps(data).encode("utf-8")
            headers["Content-Type"] = "application/json"

            req = urllib.request.Request(url, data=json_data, headers=headers)  # POST
            with urllib.request.urlopen(req) as response:
                if response.status != 200:
                    print(
                        f"[Worker {worker_id}] SHADOW WRITE Failed: {response.status}"
                    )
        except Exception as e:
            # Check for 500 which often means DB Locked
            with LOCK:
                ERRORS += 1
            print(f"[Worker {worker_id}] SHADOW WRITE Error: {e}")


def worker(worker_id):
    # Mixed workload:
    # Workers 0-2: Main DB Readers (Real users)
    # Workers 3-4: Shadow DB Writers (Agent)
    is_shadow = worker_id >= 3
    type_str = "SHADOW" if is_shadow else "MAIN"

    print(f"Worker {worker_id} ({type_str}) started.")

    for i in range(REQUESTS_PER_WORKER):
        make_request(worker_id, is_shadow)
        time.sleep(random.uniform(0.1, 0.5))

    print(f"Worker {worker_id} done.")


if __name__ == "__main__":
    print(f"--- Starting Concurrency Test ({NUM_WORKERS} workers) ---")
    start_time = time.time()

    threads = []
    for i in range(NUM_WORKERS):
        t = threading.Thread(target=worker, args=(i,))
        threads.append(t)
        t.start()

    for t in threads:
        t.join()

    duration = time.time() - start_time
    print(f"\nTest Completed in {duration:.2f}s")
    print(f"Total Errors: {ERRORS}")

    if ERRORS > 0:
        print("❌ Concurrency issues detected.")
        exit(1)
    else:
        print("✅ No locking issues detected.")
        exit(0)
