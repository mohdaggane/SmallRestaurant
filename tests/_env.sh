#!/usr/bin/env bash
# Shared by run_all.sh and run_screens.sh — sourced, not run.
# Builds a THROWAWAY database from database/schema.sql and serves the app from
# PHP's built-in server against it. core/config.php honours SMALLREST_DB only
# under that server, so Apache and the live `smallrest` database are untouched.

TESTS_DIR=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
APP=$(cd "$TESTS_DIR/.." && pwd)
DOCROOT=$(cd "$APP/.." && pwd)

export TEST_DB=${TEST_DB:-smallrest_test}
export PORT=${PORT:-8099}
export BASE="http://localhost:$PORT/$(basename "$APP")"
export MYSQL=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
PHP=${PHP:-/c/xampp/php/php.exe}

if [ "$TEST_DB" = "smallrest" ]; then
  echo "Refusing to run against the live database." >&2
  exit 2
fi

echo "== building $TEST_DB from database/schema.sql =="
sed "s/smallrest/$TEST_DB/g" "$APP/database/schema.sql" | "$MYSQL" -u root || { echo "schema import failed"; exit 2; }

SERVER_LOG=$(mktemp)
SMALLREST_DB=$TEST_DB "$PHP" -S "localhost:$PORT" -t "$DOCROOT" > "$SERVER_LOG" 2>&1 &
SERVER_PID=$!

test_env_cleanup() {
  kill $SERVER_PID 2>/dev/null
  if [ "${KEEP_TEST_DB:-0}" != "1" ]; then
    "$MYSQL" -u root -e "DROP DATABASE IF EXISTS \`$TEST_DB\`"
  fi
  rm -f "$SERVER_LOG"
}
trap test_env_cleanup EXIT

for i in 1 2 3 4 5 6 7 8 9 10; do
  curl -s -o /dev/null "$BASE/public/login.php" && break
  sleep 0.5
done
echo "   serving $BASE against $TEST_DB (live smallrest untouched)"
