#!/usr/bin/env bash
# Drawer suite: settle a held order, double-pay refusal, drawer expense,
# kitchen state, and closing the shift to an exact balance.
# Runs after e2e_core.sh (which opens a 50.00 shift, pays 6.00 cash, holds 11.00).
BASE=${BASE:-http://localhost:8099/smallrest}   # the test server started by run_all.sh
MYSQL=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
JAR=$(mktemp); JARK=$(mktemp)
fail=0
tok()   { grep -oE '[0-9a-f]{64}' | head -1; }
sql()   { "$MYSQL" -u root -D "${TEST_DB:-smallrest_test}" -N -e "$1"; }
pass()  { echo "  PASS $1"; }
flunk() { echo "  FAIL $1"; fail=1; }

HELD=$(sql "SELECT id FROM orders WHERE status='open' ORDER BY id DESC LIMIT 1")
PAID=$(sql "SELECT id FROM orders WHERE status='paid' ORDER BY id DESC LIMIT 1")

curl -s -c $JAR $BASE/public/login.php > /tmp/x.html
curl -s -b $JAR -c $JAR -o /dev/null -d "csrf=$(tok < /tmp/x.html)&username=cashier&password=cashier123" $BASE/public/login.php

echo "== settle the held order #$HELD (total 11.00, paying 20.00) =="
curl -s -b $JAR $BASE/public/orders.php > /tmp/oo.html
CSRF=$(tok < /tmp/oo.html)
curl -s -b $JAR -c $JAR -o /dev/null \
  -d "csrf=$CSRF&order_id=$HELD&payment_method=cash&paid_amount=20" $BASE/public/order_pay.php
[ "$(sql "SELECT CONCAT(status,'|',change_amount) FROM orders WHERE id=$HELD")" = "paid|9.00" ] \
  && pass "held order paid with 9.00 change" || flunk "held order not settled"

echo "== paying it twice is refused =="
curl -s -b $JAR -c $JAR -o /dev/null \
  -d "csrf=$CSRF&order_id=$HELD&payment_method=cash&paid_amount=20" $BASE/public/order_pay.php
curl -s -b $JAR $BASE/public/orders.php > /tmp/oo2.html
grep -q "already paid" /tmp/oo2.html && pass "second payment refused" || flunk "second payment not refused"

echo "== record a 4.25 drawer expense =="
curl -s -b $JAR $BASE/admin/expenses.php > /tmp/ex.html
CSRF=$(tok < /tmp/ex.html)
curl -s -b $JAR -c $JAR -o /dev/null \
  -d "csrf=$CSRF&action=save&description=Sugar+5kg&category=Ingredients&amount=4.25&paid_from=drawer&spent_on=$(date +%F)" \
  $BASE/admin/expenses.php

echo "== kitchen marks order #$PAID served =="
curl -s -c $JARK $BASE/public/login.php > /tmp/k.html
curl -s -b $JARK -c $JARK -o /dev/null -d "csrf=$(tok < /tmp/k.html)&username=kitchen&password=kitchen123" $BASE/public/login.php
curl -s -b $JARK -c $JARK $BASE/public/kitchen.php > /tmp/k2.html
CSRF=$(tok < /tmp/k2.html)
curl -s -b $JARK -c $JARK -o /dev/null -d "csrf=$CSRF&order_id=$PAID&to=served" $BASE/public/kitchen_update.php
[ "$(sql "SELECT COUNT(*) FROM order_items WHERE order_id=$PAID AND needs_prep=1 AND kitchen_status<>'served'")" = "0" ] \
  && pass "kitchen marked the order served" || flunk "kitchen update did not apply"

echo "== drawer: 50 float + 6.00 + 11.00 cash - 4.25 expense = 62.75 =="
curl -s -b $JAR -c $JAR $BASE/admin/shifts.php > /tmp/sh.html
grep -q 'Drawer should hold</div><div class="value">\$62.75' /tmp/sh.html \
  && pass "drawer expects 62.75" || flunk "drawer expectation wrong"
CSRF=$(tok < /tmp/sh.html)

echo "== close the drawer with 62.75 counted =="
curl -s -b $JAR -c $JAR -o /dev/null \
  -d "csrf=$CSRF&action=close&counted_cash=62.75&note=e2e+test" $BASE/admin/shifts.php
curl -s -b $JAR $BASE/admin/shifts.php > /tmp/sh2.html
grep -q 'balances exactly' /tmp/sh2.html && pass "drawer balanced" || flunk "drawer did not balance"

echo
if [ $fail -eq 0 ]; then echo "ALL DRAWER CHECKS PASSED"; else echo "SOME CHECKS FAILED"; fi
exit $fail
