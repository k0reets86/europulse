#!/bin/bash
# EuroPulse Phase A test runner — skeleton.
#
# Usage:
#   ./tests/run.sh                 # run all suites
#   ./tests/run.sh state_machine   # run specific suite
#
# Tests are wp eval-file invocations — need WP context.

set -e
TESTS_DIR="$(cd "$(dirname "$0")" && pwd)"
WP_PATH="/var/www/europulse/public"

if [ -z "$1" ]; then
    SUITES=("state_machine_test" "selection_test" "category_authority_test" "publish_gate_test")
else
    SUITES=("$1_test")
fi

echo "EuroPulse Phase A tests — $(date -u +%Y-%m-%dT%H:%M:%SZ)"
echo "Tests dir: $TESTS_DIR"
echo "WP path: $WP_PATH"
echo ""

# Skeleton: phase A test files don't exist yet — placeholder check
for suite in "${SUITES[@]}"; do
    suite_file="$TESTS_DIR/suites/$suite.php"
    if [ ! -f "$suite_file" ]; then
        echo "[SKIP] $suite — file not implemented yet ($suite_file)"
        continue
    fi
    echo "=== Running $suite ==="
    cd "$WP_PATH" && sudo -u www-data wp eval-file "$suite_file"
    echo ""
done

echo "Done."
