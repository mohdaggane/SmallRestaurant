#!/usr/bin/env bash
# End-to-end smoke test for the Small Restaurant POS.
BASE=${BASE:-http://localhost:8099/smallrest}   # the test server started by run_all.sh
JAR=$(mktemp)
JAR2=$(mktemp)
JAR3=$(mktemp)
fail=0

tok() { grep -oE 'value="[0-9a-f]{64}"' | head -1 | cut -d'"' -f2; }
check() { # check <label> <haystack-file> <needle>
  if grep -q "$3" "$2"; then echo "  PASS $1"; else echo "  FAIL $1 (missing: $3)"; fail=1; fi
}

echo "== 1. cashier login =="
curl -s -c $JAR $BASE/public/login.php > /tmp/l1.html
CSRF=$(tok < /tmp/l1.html)
curl -s -b $JAR -c $JAR -o /tmp/l2.html -w "  login status %{http_code} -> %{redirect_url}\n" \
  -d "csrf=$CSRF&username=cashier&password=cashier123" $BASE/public/login.php

echo "== 2. open a shift with a 50.00 float =="
curl -s -b $JAR -c $JAR $BASE/admin/shifts.php > /tmp/s1.html
CSRF=$(tok < /tmp/s1.html)
curl -s -b $JAR -c $JAR -o /dev/null -w "  open-shift status %{http_code}\n" \
  -d "csrf=$CSRF&action=open&opening_float=50" $BASE/admin/shifts.php

echo "== 3. paid order through the POS API =="
curl -s -b $JAR -c $JAR $BASE/public/pos.php > /tmp/p1.html
CSRF=$(grep -oE '[0-9a-f]{64}' /tmp/p1.html | head -1)
echo "  pos csrf: ${CSRF:0:12}..."
curl -s -b $JAR -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -d '{"action":"pay","order_type":"dine_in","table_label":"T3","discount":0.5,"payment_method":"cash","paid_amount":10,"lines":[{"id":1,"qty":3},{"id":11,"qty":1},{"id":5,"qty":2}]}' \
  $BASE/public/api/order_save.php > /tmp/pay.json
echo "  response: $(cat /tmp/pay.json)"
check "order saved" /tmp/pay.json '"ok":true'
ORDER_ID=$(grep -oE '"order_id":[0-9]+' /tmp/pay.json | cut -d: -f2)

echo "== 4. unpaid order sent to the kitchen =="
curl -s -b $JAR -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -d '{"action":"hold","order_type":"takeaway","table_label":"Ayan","lines":[{"id":12,"qty":2},{"id":15,"qty":4}]}' \
  $BASE/public/api/order_save.php > /tmp/hold.json
echo "  response: $(cat /tmp/hold.json)"
check "hold saved" /tmp/hold.json '"ok":true'

echo "== 5. server rejects a tampered price / underpayment =="
curl -s -b $JAR -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CSRF" \
  -d '{"action":"pay","lines":[{"id":11,"qty":1}],"paid_amount":0.01}' \
  $BASE/public/api/order_save.php > /tmp/bad.json
echo "  response: $(cat /tmp/bad.json)"
check "underpayment refused" /tmp/bad.json 'less than the total'

echo "== 6. CSRF is enforced =="
curl -s -b $JAR -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: deadbeef" \
  -d '{"action":"hold","lines":[{"id":1,"qty":1}]}' $BASE/public/api/order_save.php > /tmp/csrf.json
echo "  response: $(cat /tmp/csrf.json)"
check "bad token refused" /tmp/csrf.json 'session expired'

echo "== 7. receipt renders =="
curl -s -b $JAR "$BASE/public/receipt.php?id=$ORDER_ID" > /tmp/r.html
check "receipt total line" /tmp/r.html 'TOTAL'
check "receipt marked paid" /tmp/r.html 'RECEIPT'

echo "== 8. open orders page lists the held order =="
curl -s -b $JAR $BASE/public/orders.php > /tmp/oo.html
check "held order visible" /tmp/oo.html 'Take payment'

echo "== 9. waiter cannot take payment =="
curl -s -c $JAR2 $BASE/public/login.php > /tmp/w1.html
CSRF=$(tok < /tmp/w1.html)
curl -s -b $JAR2 -c $JAR2 -o /dev/null -d "csrf=$CSRF&username=waiter&password=waiter123" $BASE/public/login.php
curl -s -b $JAR2 -c $JAR2 $BASE/public/pos.php > /tmp/w2.html
if grep -q 'btnCharge' /tmp/w2.html; then echo "  FAIL waiter sees the Charge button"; fail=1; else echo "  PASS waiter has no Charge button"; fi
WCSRF=$(grep -oE '[0-9a-f]{64}' /tmp/w2.html | head -1)
curl -s -b $JAR2 -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $WCSRF" \
  -d '{"action":"pay","lines":[{"id":1,"qty":1}],"paid_amount":99}' \
  $BASE/public/api/order_save.php > /tmp/wpay.json
echo "  response: $(cat /tmp/wpay.json)"
check "waiter payment blocked" /tmp/wpay.json 'Only a cashier'

echo "== 10. waiter is blocked from the admin area =="
curl -s -b $JAR2 -o /dev/null -w "  users.php -> %{http_code} %{redirect_url}\n" $BASE/admin/users.php

echo "== 11. kitchen screen shows the queue =="
curl -s -c $JAR3 $BASE/public/login.php > /tmp/k1.html
CSRF=$(tok < /tmp/k1.html)
curl -s -b $JAR3 -c $JAR3 -o /dev/null -d "csrf=$CSRF&username=kitchen&password=kitchen123" $BASE/public/login.php
curl -s -b $JAR3 -c $JAR3 $BASE/public/kitchen.php > /tmp/k2.html
check "kitchen queue rendered" /tmp/k2.html 'Kitchen queue'
check "kitchen shows an order" /tmp/k2.html 'Mark whole order served'

echo "== 12. admin dashboard + reports =="
curl -s -c $JAR -b $JAR $BASE/public/logout.php -o /dev/null
curl -s -c $JAR $BASE/public/login.php > /tmp/a1.html
CSRF=$(tok < /tmp/a1.html)
curl -s -b $JAR -c $JAR -o /dev/null -d "csrf=$CSRF&username=admin&password=admin123" $BASE/public/login.php
curl -s -b $JAR $BASE/admin/index.php > /tmp/a2.html
check "dashboard sales tile" /tmp/a2.html 'Sales today'
curl -s -b $JAR "$BASE/admin/reports.php?range=today" > /tmp/a3.html
check "reports sales before VAT" /tmp/a3.html 'Sales before VAT'
check "reports daily chart" /tmp/a3.html 'chart-plot'
check "reports best sellers" /tmp/a3.html 'Best selling items'

echo "== 13. bad login is rejected =="
curl -s -c /tmp/j4 $BASE/public/login.php > /tmp/b1.html
CSRF=$(tok < /tmp/b1.html)
curl -s -b /tmp/j4 -c /tmp/j4 -d "csrf=$CSRF&username=admin&password=wrongpass" $BASE/public/login.php > /tmp/b2.html
check "wrong password refused" /tmp/b2.html 'Wrong username or password'

echo
if [ $fail -eq 0 ]; then echo "ALL CHECKS PASSED"; else echo "SOME CHECKS FAILED"; fi
exit $fail
