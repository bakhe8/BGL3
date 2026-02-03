import os
import json
import time
import psutil
import subprocess


def get_gpu_info():
    """Attempts to get GPU load and memory using nvidia-smi."""
    try:
        # Run nvidia-smi with specific format
        cmd = "nvidia-smi --query-gpu=utilization.gpu,memory.used,memory.total --format=csv,noheader,nounits"
        result = subprocess.check_output(cmd, shell=True).decode("utf-8").strip()
        if result:
            parts = result.split(",")
            return {
                "load": float(parts[0]),
                "mem_used": float(parts[1]),
                "mem_total": float(parts[2]),
            }
    except Exception:
        return None


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument("--once", action="store_true", help="Run once and exit")
    args = parser.parse_args()

    log_dir = os.path.join(os.path.dirname(os.path.dirname(__file__)), "logs")
    if not os.path.exists(log_dir):
        os.makedirs(log_dir)

    log_file = os.path.join(log_dir, "hardware_vitals.json")
    lock_file = os.path.join(log_dir, ".hardware_sensor.lock")

    # Singleton check for loop mode
    if not args.once and os.path.exists(lock_file):
        try:
            with open(lock_file, "r") as f:
                old_pid = int(f.read().strip())
                if psutil.pid_exists(old_pid):
                    print(f"Sensor already running (PID {old_pid}). Exiting.")
                    return
        except Exception:
            pass

    if not args.once:
        with open(lock_file, "w") as f:
            f.write(str(os.getpid()))

    print(f"Hardware Sensor started. Mode: {'Once' if args.once else 'Loop'}")

    try:
        while True:
            cpu_usage = psutil.cpu_percent(interval=1 if not args.once else 0.1)
            mem = psutil.virtual_memory()
            gpu = get_gpu_info()

            data = {
                "timestamp": time.time(),
                "cpu": {"usage_percent": cpu_usage},
                "memory": {
                    "available_gb": round(mem.available / (1024**3), 2),
                    "used_gb": round(mem.used / (1024**3), 2),
                    "total_gb": round(mem.total / (1024**3), 2),
                    "percent": mem.percent,
                },
                "gpu": gpu,
            }

            with open(log_file, "w") as f:
                json.dump(data, f, indent=2)

            if args.once:
                break
            time.sleep(2)
    except Exception as e:
        print(f"Sensor error: {e}")
    finally:
        if not args.once and os.path.exists(lock_file):
            try:
                os.remove(lock_file)
            except Exception:
                pass


if __name__ == "__main__":
    import argparse

    main()
