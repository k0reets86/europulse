#!/usr/bin/env bash
set -euo pipefail

ROOT="/root/projects/europulse"
WP_ROOT="/var/www/europulse/public"
BASE_URL="${BASE_URL:-http://204.168.148.47}"

echo "== EuroPulse system stress suite =="
echo "BASE_URL=${BASE_URL}"

echo
echo "== 1. Scheduler / Queue / Automation =="
wp --allow-root eval '
echo "mode=" . EPV2_Settings::get("mode", "semi") . "\n";
echo "default_post_status=" . EPV2_Settings::get("default_post_status", "draft") . "\n";
echo "automation_paused=" . (EPV2_Jobs::automation_paused() ? 1 : 0) . "\n";
echo "next_collect=" . (EPV2_Jobs::next_collect_timestamp() ?: 0) . "\n";
echo "next_process=" . (EPV2_Jobs::next_process_timestamp() ?: 0) . "\n";
echo "next_publish=" . (EPV2_Jobs::next_publish_timestamp() ?: 0) . "\n";
global $wpdb;
$rows = $wpdb->get_results("SELECT hook,status,COUNT(*) qty FROM {$wpdb->prefix}actionscheduler_actions WHERE hook LIKE \"epv2_%\" GROUP BY hook,status ORDER BY hook,status", ARRAY_A);
foreach ($rows as $row) {
	echo $row["hook"] . "\t" . $row["status"] . "\t" . $row["qty"] . "\n";
}
$queue = $wpdb->get_results("SELECT state,COUNT(*) qty FROM {$wpdb->prefix}epv2_queue GROUP BY state ORDER BY state", ARRAY_A);
foreach ($queue as $row) {
	echo "queue\t" . $row["state"] . "\t" . $row["qty"] . "\n";
}
' --path="$WP_ROOT"

echo
echo "== 2. Frontend smoke =="
node "$ROOT/scripts/smoke_check.js"

echo
echo "== 3. Accessibility =="
node "$ROOT/scripts/qa_accessibility.js"

echo
echo "== 4. Crawl / hreflang / links =="
python3 "$ROOT/scripts/qa_crawl_check.py"

echo
echo "== 5. Google compliance =="
php "$ROOT/scripts/epv2_google_compliance_check.php" "$BASE_URL/" "$BASE_URL/?p=607" "$BASE_URL/?p=609&lang=uk" "$BASE_URL/?p=611&lang=en"

echo
echo "== 6. HTTP stress =="
node "$ROOT/scripts/stress_test.js"

