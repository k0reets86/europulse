#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import signal
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
IDLE_PROCESS_COOLDOWN_SECONDS = max(60, int(os.getenv("EPV2_IDLE_PROCESS_COOLDOWN_SECONDS", "120")))
ACTIVE_PROCESS_COOLDOWN_SECONDS = max(60, int(os.getenv("EPV2_ACTIVE_PROCESS_COOLDOWN_SECONDS", "120")))
WP_PATH = os.getenv("EPV2_WP_PATH", "/var/www/europulse/public").strip()
WP_CLI = os.getenv("EPV2_WP_CLI", "/usr/local/bin/wp").strip()
PROCESS_TIMEOUT_SECONDS = max(180, int(os.getenv("EPV2_PROCESS_TIMEOUT_SECONDS", "900")))
COLLECT_TIMEOUT_SECONDS = max(300, int(os.getenv("EPV2_COLLECT_TIMEOUT_SECONDS", "1200")))
PUBLISH_RETRY_COOLDOWN_SECONDS = max(15, int(os.getenv("EPV2_PUBLISH_RETRY_COOLDOWN_SECONDS", "30")))


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


def run_wp_eval(code: str, timeout: int = 45) -> dict:
    process = subprocess.run(
        [WP_CLI, "--path=" + WP_PATH, "--allow-root", "eval", code],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        timeout=timeout,
        check=False,
    )
    stdout = (process.stdout or "").strip()
    stderr = (process.stderr or "").strip()
    try:
        payload = json.loads(stdout.rsplit("\n", 1)[-1]) if stdout else {}
    except json.JSONDecodeError:
        payload = {"raw_stdout": stdout[-1000:]}
    payload["returncode"] = process.returncode
    if stderr:
        payload["stderr"] = stderr[-1000:]
    return payload


def recover_timed_out_process_job() -> dict:
    code = r"""
$active = (int) get_option('epv2_active_automation_item', 0);
$closed_runs = 0;
delete_option('epv2_lock_process');
if (class_exists('EPV2_Runs')) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}epv2_runs WHERE job_name = %s AND status = %s AND started_at <= %s",
        'process',
        'started',
        gmdate('Y-m-d H:i:s', time() - 10)
    ));
    foreach ((array) $rows as $row) {
        EPV2_Runs::finish((int) $row->id, 'finished_with_errors', 0, 1, [
            'result' => 'process_timeout_recovered',
            'last_item_id' => $active,
            'processed_item_id' => $active,
            'recovery_source' => 'external_orchestrator_timeout',
        ]);
        $closed_runs++;
    }
}
if ($active > 0 && class_exists('EPV2_Queue')) {
    $item = EPV2_Queue::get_item($active);
    $notes = $item ? json_decode((string) $item->admin_notes, true) : [];
    $notes = is_array($notes) ? $notes : [];
    $notes['_system'] = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
    $notes['_system']['workflow_step'] = sanitize_key((string) ($notes['_system']['workflow_step'] ?? 'build_de_master')) ?: 'build_de_master';
    $notes['_system']['workflow_step_status'] = 'pending';
    $notes['_system']['workflow_owner_token'] = '';
    $notes['_system']['workflow_heartbeat_at'] = '';
    $notes['_system']['workflow_last_error'] = 'Previous process exceeded external orchestrator timeout and was recovered automatically.';
    unset($notes['_system']['workflow_not_before'], $notes['_system']['retry_after']);
    EPV2_Queue::mark_state($active, 'retry_process', [
        'admin_notes' => wp_json_encode($notes, JSON_UNESCAPED_UNICODE),
        'error_message' => 'Восстановлено автоматикой: предыдущий процесс превысил timeout, материал возвращён в bounded retry.',
    ]);
}
delete_option('epv2_active_automation_item');
echo wp_json_encode([
    'ok' => true,
    'active_item' => $active,
    'closed_runs' => $closed_runs,
], JSON_UNESCAPED_UNICODE);
"""
    return run_wp_eval(code)


def recover_timed_out_collect_job() -> dict:
    code = r"""
$closed_runs = 0;
delete_option('epv2_lock_collect');
update_option('epv2_collect_progress', [
    'status' => 'finished_with_errors',
    'total_sources' => 0,
    'processed_sources' => 0,
    'current_source' => '',
    'collected_items' => 0,
    'errors' => 1,
    'updated_at' => current_time('mysql'),
    'updated_at_ts' => time(),
], false);
if (class_exists('EPV2_Runs')) {
    global $wpdb;
    $rows = $wpdb->get_results($wpdb->prepare(
        "SELECT id FROM {$wpdb->prefix}epv2_runs WHERE job_name = %s AND status = %s AND started_at <= %s",
        'collect',
        'started',
        gmdate('Y-m-d H:i:s', time() - 10)
    ));
    foreach ((array) $rows as $row) {
        EPV2_Runs::finish((int) $row->id, 'finished_with_errors', 0, 1, [
            'result' => 'collect_timeout_recovered',
            'recovery_source' => 'external_orchestrator_timeout',
        ]);
        $closed_runs++;
    }
}
echo wp_json_encode([
    'ok' => true,
    'closed_runs' => $closed_runs,
], JSON_UNESCAPED_UNICODE);
"""
    return run_wp_eval(code)


def run_collect_job() -> dict:
    code = (
        "if (class_exists('EPV2_Lock_Manager') && EPV2_Lock_Manager::is_active('collect')) { "
        "echo wp_json_encode(['ok'=>true,'action'=>'collect','runner'=>'wp-cli','skipped'=>'active_lock'], JSON_UNESCAPED_UNICODE); "
        "return; "
        "} "
        "EPV2_Collector::run_scheduled(true); "
        "echo wp_json_encode(['ok'=>true,'action'=>'collect','runner'=>'wp-cli'], JSON_UNESCAPED_UNICODE);"
    )
    process = subprocess.Popen(
        [WP_CLI, "--path=" + WP_PATH, "--allow-root", "eval", code],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        start_new_session=True,
    )
    try:
        stdout, stderr = process.communicate(timeout=COLLECT_TIMEOUT_SECONDS)
    except subprocess.TimeoutExpired as exc:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        stdout, stderr = process.communicate()
        recovery = recover_timed_out_collect_job()
        log("collect timeout recovered", recovery=recovery)
        raise TimeoutError(
            f"wp-cli collect timed out after {COLLECT_TIMEOUT_SECONDS} seconds and process group was killed"
        ) from exc
    stdout = (stdout or "").strip()
    stderr = (stderr or "").strip()
    try:
        payload = json.loads(stdout.rsplit("\n", 1)[-1]) if stdout else {}
    except json.JSONDecodeError:
        payload = {"ok": False, "action": "collect", "runner": "wp-cli", "raw_stdout": stdout[-1000:]}
    payload["returncode"] = process.returncode
    if stderr:
        payload["stderr"] = stderr[-1000:]
    if process.returncode != 0:
        raise RuntimeError(f"wp-cli collect failed: rc={process.returncode} stderr={stderr[-1000:]}")
    return payload


def run_process_job(ignore_retry_after: bool = False) -> dict:
    code = (
        f"EPV2_AI_Processor::process_scheduled(true, {'true' if ignore_retry_after else 'false'}); "
        "echo wp_json_encode(['ok'=>true,'action'=>'process','runner'=>'wp-cli'], JSON_UNESCAPED_UNICODE);"
    )
    process = subprocess.Popen(
        [WP_CLI, "--path=" + WP_PATH, "--allow-root", "eval", code],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        start_new_session=True,
    )
    try:
        stdout, stderr = process.communicate(timeout=PROCESS_TIMEOUT_SECONDS)
    except subprocess.TimeoutExpired as exc:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        stdout, stderr = process.communicate()
        recovery = recover_timed_out_process_job()
        log("process timeout recovered", recovery=recovery)
        raise TimeoutError(
            f"wp-cli process timed out after {PROCESS_TIMEOUT_SECONDS} seconds and process group was killed"
        ) from exc
    stdout = (stdout or "").strip()
    stderr = (stderr or "").strip()
    try:
        payload = json.loads(stdout.rsplit("\n", 1)[-1]) if stdout else {}
    except json.JSONDecodeError:
        payload = {"ok": False, "action": "process", "runner": "wp-cli", "raw_stdout": stdout[-1000:]}
    payload["returncode"] = process.returncode
    if stderr:
        payload["stderr"] = stderr[-1000:]
    if process.returncode != 0:
        raise RuntimeError(f"wp-cli process failed: rc={process.returncode} stderr={stderr[-1000:]}")
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
    has_processable = state.get("has_processable_items")
    if isinstance(has_processable, bool):
        return has_processable
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
        return now_ts - last_process >= ACTIVE_PROCESS_COOLDOWN_SECONDS
    has_processable = state.get("has_processable_items")
    if isinstance(has_processable, bool):
        if not has_processable:
            return False
    elif state_count(state, "new") <= 0 and state_count(state, "retry_process") <= 0:
        return False
    return now_ts - last_process >= IDLE_PROCESS_COOLDOWN_SECONDS


def main() -> int:
    if not BRIDGE_TOKEN:
        print("Missing EPV2_BRIDGE_TOKEN", file=sys.stderr)
        return 1

    log("orchestrator starting", site_url=SITE_URL, loop_seconds=LOOP_SECONDS)
    last_collect = time.time()
    last_process = 0.0
    last_publish = 0.0
    last_maintenance = 0.0
    last_breaking_scan = 0.0
    last_breaking_scan_minute = -1  # track which minute we already fired in

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

            # 2026-05-12 operator-spec: respect time_planner windows. Прежде
            # orchestrator вызывал collect с force=true каждые collect_every
            # сек, обходя night_monitor mode. Это нарушало контракт «ночью не
            # собираем». Теперь учитываем collect_window_open флаг из state
            # (computed PHP-side via EPV2_Time_Planner::should_collect(false)).
            collect_window_open = state.get("collect_window_open", True)
            if (
                (not state.get("collect_paused"))
                and collect_window_open
                and now_ts - last_collect >= collect_every
            ):
                result = run_collect_job()
                log("collect executed", result=result)
                last_collect = time.time()

            # Breaking-scan: каждые :00 и :30 каждого часа — днём и ночью.
            # 2026-05-12 operator-feedback: если regular collect отфильтровал
            # breaking слабо (backpressure отложил, hard cap на новые), scan
            # подберёт его. Runs 24/7 with privilege bypass (looser cap).
            breaking_minutes = state.get("breaking_watch_minutes") or [0, 30]
            current_minute = now.minute
            if (
                current_minute in breaking_minutes
                and current_minute != last_breaking_scan_minute
                and now_ts - last_breaking_scan >= 120  # safety debounce
            ):
                try:
                    result = request_json("/bridge/breaking_scan", method="POST", payload={})
                    log("breaking scan executed", minute=current_minute, result=result.get("result", {}))
                    last_breaking_scan = now_ts
                    last_breaking_scan_minute = current_minute
                except Exception as exc:
                    log("breaking scan error", error=str(exc))

            if should_publish(state, utc_now()) and now_ts - last_publish >= PUBLISH_RETRY_COOLDOWN_SECONDS:
                result = request_json("/bridge/publish", method="POST", payload={})
                log("publish executed", next_ready_publish=state.get("next_ready_publish"), result=result)
                last_publish = time.time()
                state = request_json("/bridge/state")

            if should_process(state) and (
                now_ts - last_process >= process_every
                or should_process_from_idle(state, now_ts, last_process)
            ):
                result = run_process_job()
                log("process executed", active_item=state.get("active_automation_item"), result=result)
                last_process = time.time()
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
            elif not should_process(state) and now_ts - last_process >= process_every:
                log(
                    "process idle",
                    active_item=state.get("active_automation_item"),
                    has_processable_items=state.get("has_processable_items"),
                    queue_states=state.get("queue_states", []),
                )
                last_process = now_ts

            if should_publish(state, utc_now()) and time.time() - last_publish >= PUBLISH_RETRY_COOLDOWN_SECONDS:
                result = request_json("/bridge/publish", method="POST", payload={})
                log("publish executed", next_ready_publish=state.get("next_ready_publish"), result=result)
                last_publish = time.time()
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            log("bridge http error", status=exc.code, detail=detail[:1000])
        except Exception as exc:  # noqa: BLE001
            log("orchestrator error", error=str(exc))

        time.sleep(LOOP_SECONDS)


if __name__ == "__main__":
    raise SystemExit(main())
