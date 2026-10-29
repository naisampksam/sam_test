#!/bin/sh
# Runs the browser tests (all, or the ones named) and prints one line per test plus any failed checks.
# Needs: the app running at $BASE_URL with a TEST database, Playwright ($PWPATH), the mysql client.
cd "$(dirname "$0")" || exit 1
mkdir -p shots
export PWPATH="${PWPATH:-$(npm root -g 2>/dev/null)/playwright}"
TESTS="$*"
[ -z "$TESTS" ] && TESTS="newo_e2e pm_e2e gst_e2e inst_e2e pay_e2e quote_e2e neck_e2e shipgst_e2e od_e2e estimate_e2e five_e2e lbl_e2e prod_e2e cost_e2e staff_e2e pct_e2e priv_e2e batch1_e2e batch2_e2e notion_e2e attendance_e2e portal_e2e"
failed=0
for t in $TESTS; do
  out=$(timeout 300 node "$t.js" 2>&1)
  res=$(echo "$out" | grep -E "ALL PASSED|FAILED" | tail -1)
  [ -z "$res" ] && res="ERROR: $(echo "$out" | grep -m1 -iE 'error')"
  echo "$t: $res"
  echo "$out" | grep "^FAIL" | sed 's/^/    /'
  case "$res" in "ALL PASSED") ;; *) failed=$((failed + 1)) ;; esac
done
echo "---"; [ "$failed" -eq 0 ] && echo "Everything passed." || echo "$failed test file(s) with failures."
exit "$failed"
