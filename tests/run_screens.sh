#!/usr/bin/env bash
# Screenshot matrix against the throwaway test database (never the live till).
#
#   bash tests/run_screens.sh <output-dir>
#   ONLY=pos,kitchen bash tests/run_screens.sh <output-dir>
#
# Output images are for review, not for the repository — pass a folder outside it.

OUTDIR=${1:?usage: run_screens.sh <output-dir>}
source "$(cd "$(dirname "$0")" && pwd)/_env.sh"

# Show VAT on bills and reports the way the shop runs it.
"$MYSQL" -u root -D "$TEST_DB" -e "UPDATE settings SET setting_value='5' WHERE setting_key='tax_percent';
  UPDATE settings SET setting_value='125232' WHERE setting_key='merchant_id';"

node "$TESTS_DIR/screens.mjs" "$OUTDIR"
