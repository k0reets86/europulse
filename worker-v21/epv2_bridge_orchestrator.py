#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import subprocess
import sys
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone


SITE_URL = os.getenv("EPV2_SITE_URL", "http://127.0.0.1").rstrip("/")
BRIDGE_TOKEN = os.getenv("EPV2_BRIDGE_TOKEN", "").strip()
LOOP_SECONDS = max(5, int(os.getenv("EPV2_LOOP_SECONDS", "15")))
MAINTENANCE_SECONDS = max(60, int(os.getenv("EPV2_MAINTENANCE_SECONDS", "180")))
IDLE_PROCESS_COOLDOWN_SECONDS = max(15, int(os.getenv("EPV2_IDLE_PROCESS_COOLDOWN_SECONDS", "30")))
WP_PATH = os.getenv("EPV2_WP_PATH", "/var/www/europulse/public").strip()
WP_CLI = os.getenv("EPV2_WP_CLI", "/usr/local/bin/wp").strip()
PROCESS_TIMEOUT_SECONDS = max(180, int(os.getenv("EPV2_PROCESS_TIMEOUT_SECONDS", "900")))


def utc_now() -> datetime:
    return datetime.now(timezone.utc)


def parse_gmt(value: str | None) -> datetime | None:
    if not value:
        return None
    try:
        return datetime.strptime(value, "%Y-%m-%d %H:%M:%S").replace(tzinfo=timezone.utc)
    except ValueError:
        return None


def log(message: str, **fields: object) -> None:
    payload = {
        "ts": utc_now().strftime("%Y-%m-%dT%H:%M:%SZ"),
        "message": message,
    }
    if fields:
        payload["fields"] = fields
    print(json.dumps(payload, ensure_ascii=True), flush=True)


def bridge_url(path: str) -> str:
    rest_route = f"/epv2/v1{path}"
    query = urllib.parse.urlencode(
        {
            "rest_route": rest_route,
            "_epv2_orchestrator_ts": f"{time.time_ns()}",
        }
    )
    return f"{SITE_URL}/index.php?{query}"


def request_json(path: str, method: str = "GET", payload: dict | None = None) -> dict:
    body = None
    headers = {
        "Accept": "application/json",
        "Cache-Control": "no-cache, no-store",
        "Pragma": "no-cache",
    }
    if BRIDGE_TOKEN:
        headers["X-EPV2-Bridge-Token"] = BRIDGE_TOKEN
    if payload is not None:
        body = json.dumps(payload).encode("utf-8")
        headers["Content-Type"] = "application/json"
    req = urllib.request.Request(bridge_url(path), data=body, method=method, headers=headers)
    with urllib.request.urlopen(req, timeout=120) as response:
        return json.loads(response.read().decode("utf-8"))


def run_process_job(ignore_retry_after: bool = False) -> dict:
    code = (
        f"EPV2_AI_Processor::process_scheduled(true, {'true' if ignore_retry_after else 'false'}); "
        "echo wp_json_encode(['ok'=>true,'action'=>'process','runner'=>'wp-cli'], JSON_UNESCAPED_UNICODE);"
    )
    result = subprocess.run(
        [WP_CLI, "--path=" + WP_PATH, "--allow-root", "eval", code],
        text=True,
        capture_output=True,
        timeout=PROCESS_TIMEOUT_SECONDS,
        check=False,
    )
    stdout = result.stdout.strip()
    stderr = result.stderr.strip()
    try:
        payload = json.loads(stdout.rsplit("\n", 1)[-1]) if stdout else {}
    except json.JSONDecodeError:
        payload = {"ok": False, "action": "process", "runner": "wp-cli", "raw_stdout": stdout[-1000:]}
    payload["returncode"] = result.returncode
    if stderr:
        payload["stderr"] = stderr[-1000:]
    if result.returncode != 0:
        raise RuntimeError(f"wp-cli process failed: rc={result.returncode} stderr={stderr[-1000:]}")
    return payload


def state_count(state: dict, key: str) -> int:
    for row in state.get("queue_states", []):
        if row.get("state") == key:
            try:
                return int(row.get("c", 0))
            except (TypeError, ValueError):
                return 0
    return 0


def due(next_ts: datetime | None, now: datetime) -> bool:
    return next_ts is not None and next_ts <= now


def ensure_server_orchestrator(state: dict) -> None:
    if not state.get("server_orchestrator_enabled"):
        result = request_json("/bridge/server-orchestrator", method="POST", payload={"enabled": True})
        log("server orchestrator enabled", result=result)


def should_process(state: dict) -> bool:
    return (
        bool(state.get("active_automation_item"))
        or state_count(state, "new") > 0
        or state_count(state, "retry_process") > 0
    )


def should_publish(state: dict, now: datetime) -> bool:
    ready = state_count(state, "ready_publish")
    if ready <= 0:
        return False
    return due(parse_gmt(state.get("next_ready_publish")), now)


def should_process_immediately(previous_state: dict, current_state: dict) -> bool:
    previous_active = int(previous_state.get("active_automation_item") or 0)
    current_active = int(current_state.get("active_automation_item") or 0)
    if previous_active <= 0:
        return False
    if current_active > 0:
        return False
    return state_count(current_state, "new") > 0 or state_count(current_state, "retry_process") > 0


def should_process_from_idle(state: dict, now_ts: float, last_process: float) -> bool:
    if int(state.get("active_automation_item") or 0) > 0:
        return False
    if state_count(state, "new") <= 0 and state_count(state, "retry_process") <= 0:
        return False
    return now_ts - last_process >= IDLE_PROCESS_COOLDOWN_SECONDS


def main() -> int:
    if not BRIDGE_TOKEN:
        print("Missing EPV2_BRIDGE_TOKEN", file=sys.stderr)
        return 1

    log("orchestrator starting", site_url=SITE_URL, loop_seconds=LOOP_SECONDS)
    last_collect = 0.0
    last_process = 0.0
    last_publish = 0.0
    last_maintenance = 0.0

    while True:
        now = utc_now()
        now_ts = time.time()
        try:
            state = request_json("/bridge/state")
            if state.get("automation_paused"):
                log("automation paused")
                time.sleep(LOOP_SECONDS)
                continue

            ensure_server_orchestrator(state)

            if now_ts - last_maintenance >= MAINTENANCE_SECONDS:
                result = request_json("/bridge/maintenance", method="POST", payload={})
                log("maintenance executed", result=result.get("result", {}))
                last_maintenance = now_ts

            collect_every = max(300, int(state.get("collect_interval_minutes", 60)) * 60)
            process_every = max(60, int(state.get("process_interval_minutes", 5)) * 60)
            publish_every = max(60, int(state.get("publish_interval_minutes", 5)) * 60)

            if (not state.get("collect_paused")) and now_ts - last_collect >= collect_every:
                result = request_json("/bridge/collect", method="POST", payload={})
                log("collect executed", result=result)
                last_collect = now_ts

            if should_process(state) and (
                now_ts - last_process >= process_every
                or should_process_from_idle(state, now_ts, last_process)
            ):
                result = run_process_job()
                log("process executed", active_item=state.get("active_automation_item"), result=result)
                last_process = now_ts
                refreshed_state = request_json("/bridge/state")
                if should_process_immediately(state, refreshed_state):
                    handoff_result = run_process_job()
                    log(
                        "process immediate handoff executed",
                        previous_active=state.get("active_automation_item"),
                        next_active=refreshed_state.get("active_automation_item"),
                        result=handoff_result,
                    )
                    last_process = time.time()
                state = refreshed_state

            if should_publish(state, now) and now_ts - last_publish >= publish_every:
                result = request_json("/bridge/publish", method="POST", payload={})
                log("publish executed", next_ready_publish=state.get("next_ready_publish"), result=result)
                last_publish = now_ts
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            log("bridge http error", status=exc.code, detail=detail[:1000])
        except Exception as exc:  # noqa: BLE001
            log("orchestrator error", error=str(exc))

        time.sleep(LOOP_SECONDS)


if __name__ == "__main__":
    raise SystemExit(main())
