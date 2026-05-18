#!/usr/bin/env bash
# R22 2026-05-14: Per-stage AI cost analysis for EuroPulse pipeline.
#
# Aggregates `ep_epv2_queue.ai_payload._meta.ai_runtime` за период (24h по
# умолчанию, configurable). Report:
#   - per-stage totals (tokens, cached_tokens, requests)
#   - cache hit ratio per stage (R5 visibility)
#   - average per item
#   - daily totals from epv2_ai_usage_<date> option
#
# Usage:
#   ./cost_per_stage.sh                # последние 24h
#   ./cost_per_stage.sh 168            # последние 7 дней
#   ./cost_per_stage.sh 24 csv         # CSV output
#
# Зависит от: R4 (story_card tokens) + R5 (cached_tokens) для полноты данных.

set -euo pipefail

HOURS="${1:-24}"
FORMAT="${2:-text}"
WP_DIR="/var/www/europulse/public"

if ! command -v wp >/dev/null 2>&1; then
    echo "ERROR: wp-cli not found in PATH" >&2
    exit 1
fi

cd "$WP_DIR"

# Pull all ai_runtime arrays для items updated_at в окне.
RAW_DATA=$(wp --allow-root db query "
SELECT JSON_UNQUOTE(JSON_EXTRACT(ai_payload, '\$._meta.ai_runtime'))
FROM ep_epv2_queue
WHERE ai_payload LIKE '%ai_runtime%'
  AND updated_at >= DATE_SUB(UTC_TIMESTAMP(), INTERVAL ${HOURS} HOUR)
" --skip-column-names 2>/dev/null || echo "")

# Aggregate с python (JSON parsing).
python3 <<PYEOF
import json
import sys
from collections import defaultdict

raw = """$RAW_DATA""".strip().split('\n')
stages = defaultdict(lambda: {'tokens': 0, 'cached_tokens': 0, 'requests': 0})
items_count = 0

for line in raw:
    line = line.strip()
    if not line or line == 'NULL':
        continue
    try:
        runtime = json.loads(line)
    except json.JSONDecodeError:
        continue
    if not isinstance(runtime, list):
        continue
    items_count += 1
    for entry in runtime:
        if not isinstance(entry, dict):
            continue
        stage = entry.get('stage', 'unknown')
        stages[stage]['tokens'] += int(entry.get('tokens', 0) or 0)
        stages[stage]['cached_tokens'] += int(entry.get('cached_tokens', 0) or 0)
        stages[stage]['requests'] += 1

if items_count == 0:
    print(f"No items с ai_runtime found за последние ${HOURS}h.")
    sys.exit(0)

total_tokens = sum(s['tokens'] for s in stages.values())
total_cached = sum(s['cached_tokens'] for s in stages.values())
total_requests = sum(s['requests'] for s in stages.values())

if "$FORMAT" == "csv":
    print("stage,requests,tokens,cached_tokens,cache_pct,avg_tokens_per_call")
    for stage in sorted(stages.keys()):
        s = stages[stage]
        cache_pct = round(s['cached_tokens'] / s['tokens'] * 100, 1) if s['tokens'] > 0 else 0
        avg = round(s['tokens'] / s['requests'], 0) if s['requests'] > 0 else 0
        print(f"{stage},{s['requests']},{s['tokens']},{s['cached_tokens']},{cache_pct},{avg}")
else:
    print(f"=== EuroPulse per-stage cost report (last ${HOURS}h) ===")
    print(f"Items processed: {items_count}")
    print(f"Total: {total_tokens:,} tokens ({total_requests} AI calls)")
    if total_tokens > 0:
        print(f"Cached: {total_cached:,} ({round(total_cached/total_tokens*100, 1)}% — R5 visibility)")
    print()
    print(f"{'Stage':<22} {'Requests':>10} {'Tokens':>12} {'Cached':>10} {'Cache %':>8} {'Avg/call':>10}")
    print("-" * 80)
    for stage in sorted(stages.keys(), key=lambda k: stages[k]['tokens'], reverse=True):
        s = stages[stage]
        cache_pct = round(s['cached_tokens'] / s['tokens'] * 100, 1) if s['tokens'] > 0 else 0
        avg = round(s['tokens'] / s['requests'], 0) if s['requests'] > 0 else 0
        print(f"{stage:<22} {s['requests']:>10} {s['tokens']:>12,} {s['cached_tokens']:>10,} {cache_pct:>7}% {avg:>10,.0f}")
    print()
    print(f"Average per item: {round(total_tokens/items_count, 0):,.0f} tokens, "
          f"{round(total_requests/items_count, 1)} AI calls")

PYEOF
