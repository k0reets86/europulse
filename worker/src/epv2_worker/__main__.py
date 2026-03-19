from __future__ import annotations

import argparse
import json
from pathlib import Path

from .contracts import WorkerRequest
from .pipeline import run_pipeline


def main() -> int:
    parser = argparse.ArgumentParser(description="Run EPV2 worker pipeline for one queue item.")
    parser.add_argument("--input", required=True, help="Path to JSON request file")
    args = parser.parse_args()

    payload = json.loads(Path(args.input).read_text(encoding="utf-8"))
    request = WorkerRequest.from_dict(payload)
    response = run_pipeline(request)
    print(json.dumps(response.to_dict(), ensure_ascii=False, indent=2))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
