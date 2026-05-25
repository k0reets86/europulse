#!/usr/bin/env python3
from __future__ import annotations

import json
import os
import signal
import subprocess
import sys
import threading
import time
import urllib.error
import urllib.parse
import urllib.request
from datetime import datetime, timezone
from typing import Any


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
BREAKING_SCAN_TIMEOUT_SECONDS = max(60, int(os.getenv("EPV2_BREAKING_SCAN_TIMEOUT_SECONDS", "180")))
PUBLISH_RETRY_COOLDOWN_SECONDS = max(15, int(os.getenv("EPV2_PUBLISH_RETRY_COOLDOWN_SECONDS", "30")))
MIN_PROCESS_MEM_AVAILABLE_KB = max(256, int(os.getenv("EPV2_MIN_PROCESS_MEM_AVAILABLE_MB", "768"))) * 1024
MIN_COLLECT_MEM_AVAILABLE_KB = max(256, int(os.getenv("EPV2_MIN_COLLECT_MEM_AVAILABLE_MB", "768"))) * 1024
MIN_BREAKING_MEM_AVAILABLE_KB = max(256, int(os.getenv("EPV2_MIN_BREAKING_MEM_AVAILABLE_MB", "768"))) * 1024
MIN_PUBLISH_MEM_AVAILABLE_KB = max(128, int(os.getenv("EPV2_MIN_PUBLISH_MEM_AVAILABLE_MB", "384"))) * 1024
MIN_JOB_SWAP_FREE_KB = max(0, int(os.getenv("EPV2_MIN_JOB_SWAP_FREE_MB", "256"))) * 1024
WORKER_HEALTH_URL = os.getenv("EPV2_WORKER_HEALTH_URL", "http://127.0.0.1:8765/health").strip()
WORKER_HEALTH_TIMEOUT_SECONDS = max(1, int(os.getenv("EPV2_WORKER_HEALTH_TIMEOUT_SECONDS", "3")))
WORKER_UNHEALTHY_COOLDOWN_SECONDS = max(15, int(os.getenv("EPV2_WORKER_UNHEALTHY_COOLDOWN_SECONDS", "60")))


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


def memory_snapshot() -> dict[str, int]:
    wanted = {"MemAvailable", "SwapFree"}
    values: dict[str, int] = {}
    try:
        with open("/proc/meminfo", "r", encoding="utf-8") as handle:
            for line in handle:
                name, _, rest = line.partition(":")
                if name in wanted:
                    parts = rest.strip().split()
                    if parts:
                        values[name] = int(parts[0])
    except (OSError, ValueError):
        return {}
    return values


def memory_guard_ok(kind: str, min_mem_available_kb: int) -> bool:
    snapshot = memory_snapshot()
    mem_available = snapshot.get("MemAvailable", 0)
    swap_free = snapshot.get("SwapFree", 0)
    ok = mem_available >= min_mem_available_kb and swap_free >= MIN_JOB_SWAP_FREE_KB
    if not ok:
        log(
            f"{kind} skipped low memory",
            mem_available_mb=round(mem_available / 1024, 1),
            min_mem_available_mb=round(min_mem_available_kb / 1024, 1),
            swap_free_mb=round(swap_free / 1024, 1),
            min_swap_free_mb=round(MIN_JOB_SWAP_FREE_KB / 1024, 1),
        )
    return ok


_worker_unhealthy_until = 0.0


def worker_guard_ok(kind: str) -> bool:
    """Short-circuit expensive WP-CLI jobs when the AI worker is down.

    If we run process/collect while FastAPI is wedged, PHP burns minutes in
    cURL timeouts and queue items accrue technical attempts until watchdogs
    reject them as chronic recyclers. Treat worker health as a prerequisite
    for all jobs that can call /process or /analyze_story.
    """
    global _worker_unhealthy_until
    now = time.time()
    if now < _worker_unhealthy_until:
        log(
            f"{kind} skipped worker unhealthy cooldown",
            retry_after_s=max(0, int(_worker_unhealthy_until - now)),
        )
        return False
    try:
        req = urllib.request.Request(WORKER_HEALTH_URL, headers={"Accept": "application/json"})
        with urllib.request.urlopen(req, timeout=WORKER_HEALTH_TIMEOUT_SECONDS) as response:
            status = response.getcode()
            body = response.read(2048).decode("utf-8", errors="replace")
        if status != 200:
            raise RuntimeError(f"worker health HTTP {status}: {body[:300]}")
        payload = json.loads(body or "{}")
        if payload.get("status") != "ok":
            raise RuntimeError(f"worker health status={payload.get('status')}")
        return True
    except Exception as exc:  # noqa: BLE001
        _worker_unhealthy_until = time.time() + WORKER_UNHEALTHY_COOLDOWN_SECONDS
        log(
            f"{kind} skipped worker unhealthy",
            error=str(exc)[:300],
            cooldown_s=WORKER_UNHEALTHY_COOLDOWN_SECONDS,
        )
        return False


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


def run_breaking_scan_job() -> dict:
    code = (
        "if (class_exists('EPV2_Lock_Manager') && EPV2_Lock_Manager::is_active('collect')) { "
        "echo wp_json_encode(['ok'=>true,'action'=>'breaking_scan','runner'=>'wp-cli','skipped'=>'active_lock'], JSON_UNESCAPED_UNICODE); "
        "return; "
        "} "
        "$started = microtime(true); "
        "$result = EPV2_Collector::run_breaking_scan(); "
        "echo wp_json_encode(['ok'=>true,'action'=>'breaking_scan','runner'=>'wp-cli','duration_ms'=>(int)round((microtime(true)-$started)*1000),'result'=>$result], JSON_UNESCAPED_UNICODE);"
    )
    process = subprocess.Popen(
        [WP_CLI, "--path=" + WP_PATH, "--allow-root", "eval", code],
        text=True,
        stdout=subprocess.PIPE,
        stderr=subprocess.PIPE,
        start_new_session=True,
    )
    try:
        stdout, stderr = process.communicate(timeout=BREAKING_SCAN_TIMEOUT_SECONDS)
    except subprocess.TimeoutExpired as exc:
        try:
            os.killpg(process.pid, signal.SIGKILL)
        except ProcessLookupError:
            pass
        stdout, stderr = process.communicate()
        recovery = recover_timed_out_collect_job()
        log("breaking_scan timeout recovered", recovery=recovery)
        raise TimeoutError(
            f"wp-cli breaking_scan timed out after {BREAKING_SCAN_TIMEOUT_SECONDS} seconds and process group was killed"
        ) from exc
    stdout = (stdout or "").strip()
    stderr = (stderr or "").strip()
    try:
        payload = json.loads(stdout.rsplit("\n", 1)[-1]) if stdout else {}
    except json.JSONDecodeError:
        payload = {"ok": False, "action": "breaking_scan", "runner": "wp-cli", "raw_stdout": stdout[-1000:]}
    payload["returncode"] = process.returncode
    if stderr:
        payload["stderr"] = stderr[-1000:]
    if process.returncode != 0:
        raise RuntimeError(f"wp-cli breaking_scan failed: rc={process.returncode} stderr={stderr[-1000:]}")
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


# 2026-05-12 W4.1: separate publish thread.
# Раньше main loop делал process (30-120s wp-cli subprocess) → publish ждал
# завершения process. Publish fires every 20-30s; если process активен 120s,
# publish пропускает 4-6 окон. Result: ready_publish items сидят 10+ мин когда
# pipeline должен публиковать с 25s interval.
#
# Solution: publish runs в отдельном Thread. Thread не блокирует main loop's
# process/collect/maintenance. PHP-side publish_lock уже предотвращает double-publish
# (EPV2_Lock_Manager::is_active('publish') check).

_publish_thread_stop = threading.Event()
# 2026-05-13 hardening:
#   _publish_thread holds singleton ref → idempotent start, restartable on death.
#   _publish_thread_last_beat = wall-clock seconds of last completed iteration.
#   Main loop reads this; if > 5×interval old, considers thread dead and respawns.
_publish_thread: threading.Thread | None = None
_publish_thread_last_beat: float = 0.0


def publish_thread_loop() -> None:
    """Independent publish loop — fires every PUBLISH_RETRY_COOLDOWN_SECONDS
    regardless of what main loop is doing.

    Uses PHP-side publish_lock for mutex; if lock active, endpoint returns
    quickly без actual publish (no harm от double-fire).

    2026-05-13: hardened — outer try/except wraps the whole body so any
    exception (including from wait() / time.sleep / system-level) gets logged
    and loop continues. Previous version had per-iteration try/except but
    a stray exception in the wait() would still kill the thread.
    """
    global _publish_thread_last_beat
    log("publish_thread starting", interval=PUBLISH_RETRY_COOLDOWN_SECONDS)
    consecutive_errors = 0
    while not _publish_thread_stop.is_set():
        try:
            # 2026-05-13 (revised): heartbeat ставится ПОСЛЕ успешного state-fetch,
            # не перед. Иначе если /bridge/state виснет 60+ сек, watchdog видит
            # свежий heartbeat и не respawn'ит застрявший поток.
            state = request_json("/bridge/state")
            _publish_thread_last_beat = time.time()
            if state.get("automation_paused"):
                _publish_thread_stop.wait(PUBLISH_RETRY_COOLDOWN_SECONDS)
                continue
            if not should_publish(state, utc_now()):
                _publish_thread_stop.wait(PUBLISH_RETRY_COOLDOWN_SECONDS)
                continue
            if not memory_guard_ok("publish", MIN_PUBLISH_MEM_AVAILABLE_KB):
                _publish_thread_last_beat = time.time()
                _publish_thread_stop.wait(min(60, PUBLISH_RETRY_COOLDOWN_SECONDS * 2))
                continue
            try:
                result = request_json("/bridge/publish", method="POST", payload={})
                _publish_thread_last_beat = time.time()  # confirm work-completion
                log("publish thread executed", next_ready_publish=state.get("next_ready_publish"), result=result.get("result", result))
                consecutive_errors = 0
            except urllib.error.HTTPError as exc:
                detail = exc.read().decode("utf-8", errors="replace")
                log("publish thread http error", status=exc.code, detail=detail[:500])
                # 2026-05-13: 503 от WordPress (maintenance mode, plugin updating)
                # обычно проходит за секунды. Одна inline-повторная попытка через
                # 3 сек до следующего нормального интервала.
                if exc.code in (502, 503, 504):
                    _publish_thread_stop.wait(3)
                    if not _publish_thread_stop.is_set():
                        try:
                            result = request_json("/bridge/publish", method="POST", payload={})
                            log("publish thread retry succeeded", status=exc.code, result=result.get("result", result))
                            consecutive_errors = 0
                        except Exception as retry_exc:  # noqa: BLE001
                            log("publish thread retry failed", error=str(retry_exc))
                            consecutive_errors += 1
                else:
                    consecutive_errors += 1
        except Exception as exc:  # noqa: BLE001
            # Catches network errors, JSON decode errors, anything from wait/sleep,
            # KeyError on state dict, etc. Critical: must NOT crash the thread.
            log("publish thread iteration error", error=str(exc), error_type=type(exc).__name__)
            consecutive_errors += 1
        # Back-off если консекутивные ошибки накопились (5+ = system in pain,
        # дать ему передохнуть подольше).
        backoff = PUBLISH_RETRY_COOLDOWN_SECONDS
        if consecutive_errors >= 5:
            backoff = min(60, PUBLISH_RETRY_COOLDOWN_SECONDS * 4)
            log("publish thread backing off", consecutive_errors=consecutive_errors, sleep=backoff)
        try:
            _publish_thread_stop.wait(backoff)
        except Exception as exc:  # noqa: BLE001
            log("publish thread wait error", error=str(exc))
            time.sleep(1)
    log("publish_thread stopped")


def start_publish_thread() -> threading.Thread:
    """Idempotent: returns existing alive thread or starts a fresh one."""
    global _publish_thread, _publish_thread_last_beat
    if _publish_thread is not None and _publish_thread.is_alive():
        log("publish_thread already alive, skipping start")
        return _publish_thread
    _publish_thread_last_beat = time.time()
    _publish_thread = threading.Thread(target=publish_thread_loop, name="epv2_publish_loop", daemon=True)
    _publish_thread.start()
    return _publish_thread


def publish_thread_healthy() -> bool:
    """Returns True if thread is alive AND has emitted a heartbeat recently
    (≤ 5× interval ago). False signals dead/stuck — caller should respawn.
    """
    if _publish_thread is None or not _publish_thread.is_alive():
        return False
    stale_threshold = PUBLISH_RETRY_COOLDOWN_SECONDS * 5
    return (time.time() - _publish_thread_last_beat) < stale_threshold


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
    if state.get("publish_window_open") is False:
        return False
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


def _install_signal_handlers() -> None:
    """2026-05-13: graceful shutdown. SIGTERM (systemd stop) and SIGINT (Ctrl-C)
    set the publish_thread stop event and exit with status 0. Without this,
    daemon publish_thread получает SIGKILL через TimeoutStop и может оставить
    висящий 'publishing' row в БД. Watchdog подбирает, но это маскирует баг.
    """
    def _handler(signum: int, frame: Any) -> None:  # noqa: ARG001
        log("shutdown signal received", signum=signum)
        _publish_thread_stop.set()
        sys.exit(0)
    signal.signal(signal.SIGTERM, _handler)
    signal.signal(signal.SIGINT, _handler)


def main() -> int:
    if not BRIDGE_TOKEN:
        print("Missing EPV2_BRIDGE_TOKEN", file=sys.stderr)
        return 1

    log("orchestrator starting", site_url=SITE_URL, loop_seconds=LOOP_SECONDS)
    # 2026-05-13: signal handlers for graceful shutdown.
    _install_signal_handlers()
    # 2026-05-12 W4.1: start parallel publish thread.
    start_publish_thread()
    last_collect = time.time()
    last_process = 0.0
    last_publish = 0.0
    last_maintenance = 0.0
    last_breaking_scan = 0.0
    last_breaking_scan_minute = -1  # track which minute we already fired in
    last_publish_thread_check = 0.0  # track when we last verified thread health

    while True:
        now = utc_now()
        now_ts = time.time()
        # 2026-05-13: publish_thread watchdog. Раз в минуту проверяем, что
        # поток жив и эмитит heartbeat. Если умер или завис (stale heartbeat) —
        # респавним. Так молчаливая смерть потока больше не блокирует pipeline
        # на часы (как было сегодня утром — 25 минут без публикаций после
        # 08:46:14 пока я ручкой не рестартанул весь оркестратор).
        if now_ts - last_publish_thread_check >= 60:
            thread_alive_before_check = publish_thread_healthy()
            if not thread_alive_before_check:
                log("publish_thread dead or stale, respawning")
                # 2026-05-13 (revised): proper teardown sequence — set stop event,
                # try to join existing thread (2 sec timeout), затем clear+spawn.
                # Без этого stuck thread в долгом urlopen остаётся зомби: is_alive=True
                # после `_publish_thread_stop.clear()`, idempotent guard в
                # `start_publish_thread()` (is_alive check) пропускает спавн — застряли.
                _publish_thread_stop.set()
                if _publish_thread is not None and _publish_thread.is_alive():
                    try:
                        _publish_thread.join(timeout=2.0)
                    except Exception as join_exc:  # noqa: BLE001
                        log("publish_thread join error", error=str(join_exc))
                _publish_thread_stop.clear()
                start_publish_thread()
            # R7 2026-05-14: эмитим heartbeat в WP option для PHP-side visibility.
            # Admin notice ловит stale heartbeat >90s.
            last_beat_age = max(0, int(now_ts - _publish_thread_last_beat))
            try:
                request_json(
                    "/bridge/heartbeat",
                    method="POST",
                    payload={
                        "thread_alive": bool(thread_alive_before_check),
                        "last_beat_age_s": last_beat_age,
                    },
                )
            except Exception as hb_exc:  # noqa: BLE001
                log("heartbeat post failed", error=str(hb_exc))
            last_publish_thread_check = now_ts

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
                if not worker_guard_ok("collect"):
                    last_collect = time.time()
                    time.sleep(LOOP_SECONDS)
                    continue
                if not memory_guard_ok("collect", MIN_COLLECT_MEM_AVAILABLE_KB):
                    last_collect = time.time()
                    time.sleep(LOOP_SECONDS)
                    continue
                result = run_collect_job()
                log("collect executed", result=result)
                last_collect = time.time()

            # Breaking-scan: каждые :00 и :30 каждого часа — днём и ночью.
            # 2026-05-12 operator-feedback: если regular collect отфильтровал
            # breaking слабо (backpressure отложил, hard cap на новые), scan
            # подберёт его. Runs 24/7 with privilege bypass (looser cap).
            breaking_minutes = state.get("breaking_watch_minutes") or [0, 30]
            current_minute = now.minute
            # 2026-05-12 — overshoot guard. Previously condition was strictly
            # `current_minute in breaking_minutes`. Если loop iteration совпала
            # с длительным wp-cli process subprocess (item recycle 60+s), :00 или
            # :30 minute boundary могло пройти ВНУТРИ subprocess'а. К моменту
            # когда top of loop запускается снова, current_minute уже =1 или 31,
            # и breaking_scan молча скипается на этот цикл. Поднял bridge state
            # `has_breaking_watch=false` диагностика подтвердила: 18:00 scan не
            # fired, плагин не зарегистрировал. Добавляю overshoot: fire если
            # с последнего scan прошло >= 32 минут (стандартный интервал 30m +
            # запас) даже если не на :00/:30 boundary.
            elapsed_since_last_breaking = now_ts - last_breaking_scan
            breaking_overshoot = elapsed_since_last_breaking >= 32 * 60
            if (
                not state.get("collect_paused")
                and (current_minute in breaking_minutes or breaking_overshoot)
                and current_minute != last_breaking_scan_minute
                and elapsed_since_last_breaking >= 120  # safety debounce
            ):
                if not worker_guard_ok("breaking_scan"):
                    last_breaking_scan = now_ts
                    last_breaking_scan_minute = current_minute
                    time.sleep(LOOP_SECONDS)
                    continue
                if not memory_guard_ok("breaking_scan", MIN_BREAKING_MEM_AVAILABLE_KB):
                    last_breaking_scan = now_ts
                    last_breaking_scan_minute = current_minute
                    time.sleep(LOOP_SECONDS)
                    continue
                try:
                    result = run_breaking_scan_job()
                    log("breaking scan executed", minute=current_minute, result=result.get("result", result))
                    last_breaking_scan = now_ts
                    last_breaking_scan_minute = current_minute
                except Exception as exc:
                    log("breaking scan error", error=str(exc))

            # 2026-05-13 (revised): main-loop publish call УДАЛЁН. publish_thread
            # покрывает все publish-кейсы каждые 15 сек с inline retry на 503/504.
            # Двойной publish из main loop + publish_thread = 3 канал и race за PHP
            # publish_lock. Лог замусоривался "publish executed" дважды на каждый
            # реальный publish event.

            if should_process(state) and (
                now_ts - last_process >= process_every
                or should_process_from_idle(state, now_ts, last_process)
            ):
                if not worker_guard_ok("process"):
                    last_process = time.time()
                    time.sleep(LOOP_SECONDS)
                    continue
                if not memory_guard_ok("process", MIN_PROCESS_MEM_AVAILABLE_KB):
                    last_process = time.time()
                    time.sleep(LOOP_SECONDS)
                    continue
                result = run_process_job()
                log("process executed", active_item=state.get("active_automation_item"), result=result)
                last_process = time.time()
                refreshed_state = request_json("/bridge/state")
                if should_process_immediately(state, refreshed_state):
                    if not worker_guard_ok("process_handoff"):
                        state = refreshed_state
                        time.sleep(LOOP_SECONDS)
                        continue
                    if not memory_guard_ok("process_handoff", MIN_PROCESS_MEM_AVAILABLE_KB):
                        state = refreshed_state
                        time.sleep(LOOP_SECONDS)
                        continue
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

            # 2026-05-13 (revised): второй post-process publish-block тоже удалён.
            # publish_thread проверяет ready_publish independently каждые 15 сек.
        except urllib.error.HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")
            log("bridge http error", status=exc.code, detail=detail[:1000])
        except Exception as exc:  # noqa: BLE001
            log("orchestrator error", error=str(exc))

        time.sleep(LOOP_SECONDS)


if __name__ == "__main__":
    raise SystemExit(main())
