#!/usr/bin/env bash
set -euo pipefail

OUT_DIR="/root/projects/europulse/logs"
OUT_FILE="$OUT_DIR/epv2_runtime_watch.log"
mkdir -p "$OUT_DIR"

{
  echo "=== $(date -u '+%F %T UTC') ==="
  php <<'PHP'
<?php
require_once '/var/www/europulse/public/wp-load.php';
global $wpdb;

$preview = EPV2_Queue::workflow_v2_preview_selection(false);
echo 'preview=' . wp_json_encode($preview, JSON_UNESCAPED_UNICODE) . PHP_EOL;

foreach ([567, 568, 562] as $id) {
    $row = EPV2_Queue::get_item($id);
    if (! $row) {
        echo "item={$id} missing" . PHP_EOL;
        continue;
    }
    $notes = json_decode((string) ($row->admin_notes ?? ''), true);
    $notes = is_array($notes) ? $notes : [];
    $system = is_array($notes['_system'] ?? null) ? $notes['_system'] : [];
    echo implode("\t", [
        'item=' . $id,
        'state=' . (string) ($row->state ?? ''),
        'updated=' . (string) ($row->updated_at ?? ''),
        'step=' . (string) ($system['workflow_step'] ?? ''),
        'retry_after=' . (string) ($system['retry_after'] ?? ''),
        'publish_not_before=' . (string) ($system['publish_not_before'] ?? ''),
        'post_id=' . (string) ($row->post_id ?? ''),
        'error=' . (string) ($row->error_message ?? ''),
    ]) . PHP_EOL;
}

$rows = $wpdb->get_results(
    "SELECT id,state,updated_at,post_id FROM {$wpdb->prefix}epv2_queue
     WHERE state IN ('new','ready_publish','published')
     ORDER BY updated_at DESC
     LIMIT 10",
    ARRAY_A
);
foreach ($rows as $row) {
    echo 'queue=' . implode(':', [
        (string) ($row['id'] ?? ''),
        (string) ($row['state'] ?? ''),
        (string) ($row['updated_at'] ?? ''),
        (string) ($row['post_id'] ?? ''),
    ]) . PHP_EOL;
}

$runs = $wpdb->get_results(
    "SELECT id,job_name,status,started_at,finished_at,payload
     FROM {$wpdb->prefix}epv2_runs
     ORDER BY id DESC
     LIMIT 8",
    ARRAY_A
);
foreach ($runs as $run) {
    echo 'run=' . implode(':', [
        (string) ($run['id'] ?? ''),
        (string) ($run['job_name'] ?? ''),
        (string) ($run['status'] ?? ''),
        (string) ($run['started_at'] ?? ''),
        (string) ($run['finished_at'] ?? ''),
    ]) . PHP_EOL;
    echo 'payload=' . substr((string) ($run['payload'] ?? ''), 0, 300) . PHP_EOL;
}

$posts = get_posts([
    'numberposts' => 5,
    'post_status' => 'publish',
    'orderby' => 'date',
    'order' => 'DESC',
]);
foreach ($posts as $post) {
    echo 'post=' . $post->ID . ':' . get_the_date('Y-m-d H:i:s', $post) . ':' . $post->post_title . PHP_EOL;
}
PHP
  php /usr/local/bin/wp cron event list --path=/var/www/europulse/public --allow-root --fields=hook,next_run_gmt --format=table | grep -E 'epv2_(process|publish|collect)' || true
  echo
} >> "$OUT_FILE"
