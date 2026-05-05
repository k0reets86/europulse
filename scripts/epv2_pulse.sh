#!/usr/bin/env bash
#
# EPV2 manual pulse helpers.
#
# Pulse-mode operating runbook (collect → process → publish, between
# idle periods with both pause flags ON). Each subcommand bypasses the
# pause flag for a single direct call into the canonical handler:
#
#   collect   EPV2_Collector::run_scheduled(true)
#   process   EPV2_AI_Processor::process_scheduled(true, true)
#   publish   EPV2_Publisher::publish_scheduled(true)
#   status    scripts/epv2_pulse_status.php (read-only digest)
#
# The pause options remain unchanged. To resume regular automation,
# update epv2_automation_paused / epv2_collect_paused via WP admin.

set -euo pipefail

WP_PATH=${WP_PATH:-/var/www/europulse/public}
WP_USER=${WP_USER:-www-data}
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

usage() {
    cat <<EOF
Usage: $0 <command>

Commands:
  collect          Run one collect cycle (sources -> queue), bypassing
                   pause; respects per-source/lock guards.
  process          Run one process_scheduled tick (queue -> ready_publish).
  publish          Run one publish_scheduled cycle (ready_publish -> WP posts).
  status           Print pulse-status digest (read-only).
  status-json      Same digest as JSON.
  recent N         Show last N runs from ep_epv2_runs (default 10).
  audit-summary    Selection audit outcome breakdown for the last 24h.

Environment:
  WP_PATH=$WP_PATH
  WP_USER=$WP_USER
EOF
}

run_eval() {
    local snippet="$1"
    sudo -u "$WP_USER" wp --path="$WP_PATH" eval "$snippet"
}

run_eval_file() {
    local source="$1"
    local tmp
    tmp="$(mktemp /tmp/epv2-pulse-XXXXXX.php)"
    cp "$source" "$tmp"
    chmod 644 "$tmp"
    sudo -u "$WP_USER" wp --path="$WP_PATH" eval-file "$tmp"
    rm -f "$tmp"
}

run_eval_file_with_env() {
    local source="$1"
    shift
    local tmp
    tmp="$(mktemp /tmp/epv2-pulse-XXXXXX.php)"
    cp "$source" "$tmp"
    chmod 644 "$tmp"
    sudo -u "$WP_USER" "$@" wp --path="$WP_PATH" eval-file "$tmp"
    rm -f "$tmp"
}

cmd=${1:-help}
case "$cmd" in
    collect)
        echo ">>> EPV2_Collector::run_scheduled(true)"
        run_eval 'EPV2_Collector::run_scheduled(true); echo "collect done";'
        ;;
    process)
        echo ">>> EPV2_AI_Processor::process_scheduled(true, true)"
        run_eval 'EPV2_AI_Processor::process_scheduled(true, true); echo "process tick done";'
        ;;
    publish)
        echo ">>> EPV2_Publisher::publish_scheduled(true)"
        run_eval 'EPV2_Publisher::publish_scheduled(true); echo "publish cycle done";'
        ;;
    status)
        run_eval_file "$SCRIPT_DIR/epv2_pulse_status.php"
        ;;
    status-json)
        run_eval_file_with_env "$SCRIPT_DIR/epv2_pulse_status.php" EPV2_PULSE_JSON=1
        ;;
    recent)
        n=${2:-10}
        run_eval "global \$wpdb; foreach((array)\$wpdb->get_results(\"SELECT id, job_name, status, item_count, error_count, started_at, finished_at FROM \" . \$wpdb->prefix . \"epv2_runs ORDER BY started_at DESC LIMIT $n\") as \$r) { printf(\"%d %s [%s] items=%d errors=%d %s..%s\n\", \$r->id, \$r->job_name, \$r->status, \$r->item_count, \$r->error_count, \$r->started_at ?: '-', \$r->finished_at ?: '-'); }"
        ;;
    audit-summary)
        run_eval 'global $wpdb; $rows = $wpdb->get_results("SELECT outcome, reject_class, COUNT(*) c FROM " . $wpdb->prefix . "epv2_selection_audit WHERE created_at >= UTC_TIMESTAMP() - INTERVAL 24 HOUR GROUP BY outcome, reject_class ORDER BY c DESC"); foreach((array)$rows as $r) printf("%s %s = %d\n", $r->outcome, $r->reject_class ?: "-", $r->c);'
        ;;
    help|-h|--help|"")
        usage
        ;;
    *)
        echo "unknown command: $cmd" >&2
        usage
        exit 2
        ;;
esac
