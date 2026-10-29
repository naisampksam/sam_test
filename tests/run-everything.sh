#!/bin/sh
# All tests: unit (calculations), integration (code + database + files) and the browser tests (every page and feature).
# Needs a TEST copy of the app and database (config.php), the app running at $BASE_URL, Playwright ($PWPATH). See tests/e2e/README.md.
cd "$(dirname "$0")/.." || exit 1
status=0
echo "== Unit tests";        php tests/unit/run.php | grep -v '^PASS' || status=1
echo "== Integration tests"; php tests/integration/run.php | grep -v '^PASS' || status=1
echo "== Browser tests";     sh tests/e2e/run-all.sh || status=1
exit $status
