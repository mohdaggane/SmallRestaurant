#!/usr/bin/env bash
# Runs every end-to-end suite against a THROWAWAY database, never the live till.
#
#   bash tests/run_all.sh
#
# See tests/_env.sh for how the isolated test database and server are set up.
# Set KEEP_TEST_DB=1 to keep the test database for inspection.

source "$(cd "$(dirname "$0")" && pwd)/_env.sh"

failed=()

# Order matters: drawer continues the shift core opens; merchant opens its own.
# tenancy registers its own restaurants, so it runs last and leaves the demo one alone.
echo
echo "################ tenant SQL guard ################"
"${PHP:-/c/xampp/php/php.exe}" "$TESTS_DIR/check_tenant_sql.php" || failed+=("check_tenant_sql")

for suite in e2e_core e2e_drawer e2e_merchant e2e_orders_reports e2e_tenancy; do
  echo
  echo "################ $suite ################"
  if bash "$TESTS_DIR/$suite.sh"; then :; else failed+=("$suite"); fi
done

echo
if [ ${#failed[@]} -eq 0 ]; then
  echo "ALL SUITES PASSED"
else
  echo "FAILED SUITES: ${failed[*]}"
  echo "--- PHP server log (last 20 lines) ---"
  tail -20 "$SERVER_LOG"
  exit 1
fi
