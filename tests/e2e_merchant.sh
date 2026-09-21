#!/usr/bin/env bash
# Merchant dial-code tests.
BASE=${BASE:-http://localhost:8099/smallrest}   # the test server started by run_all.sh
JA=$(mktemp); JC=$(mktemp)
fail=0
tok() { grep -oE '[0-9a-f]{64}' | head -1; }
check() { if grep -q "$3" "$2"; then echo "  PASS $1"; else echo "  FAIL $1 (missing: $3)"; fail=1; fi; }
nocheck() { if grep -q "$3" "$2"; then echo "  FAIL $1 (should be absent: $3)"; fail=1; else echo "  PASS $1"; fi; }

# --- admin session
curl -s -c $JA $BASE/public/login.php > /tmp/m0.html
curl -s -b $JA -c $JA -o /dev/null -d "csrf=$(tok < /tmp/m0.html)&username=admin&password=admin123" $BASE/public/login.php

save_settings() { # save_settings <merchant_id> <on_receipt: 1|0>
  curl -s -b $JA -c $JA $BASE/admin/settings.php > /tmp/set.html
  local C=$(tok < /tmp/set.html)
  local ONFLAG=""
  [ "$2" = "1" ] && ONFLAG="--data-urlencode merchant_on_receipt=1"
  echo "  token used: ${C:0:12} (len ${#C})"
  curl -s -b $JA -c $JA -o /tmp/save_resp.html -w "  save status %{http_code}\n" \
    --data-urlencode "csrf=$C" \
    --data-urlencode "shop_name=Small Restaurant" \
    --data-urlencode "shop_tagline=Tea & Food" \
    --data-urlencode "shop_address=Bakaara Road, Mogadishu" \
    --data-urlencode "shop_phone=615 000 000" \
    --data-urlencode "currency=\$" \
    --data-urlencode "tax_percent=0.05" \
    --data-urlencode "receipt_footer=Thank you — come again!" \
    --data-urlencode "merchant_name=Xamar Tea Shop" \
    --data-urlencode "merchant_id=$1" \
    --data-urlencode "ussd_prefix=*789*" \
    $ONFLAG \
    $BASE/admin/settings.php
}

echo "== 1. save a merchant number typed with spaces and a dash =="
save_settings "616-123 456" 1
/c/xampp/mysql/bin/mysql.exe -u root -D "${TEST_DB:-smallrest_test}" -N -e \
  "SELECT CONCAT('  stored merchant_id = [', setting_value, ']') FROM settings WHERE setting_key='merchant_id';"
/c/xampp/mysql/bin/mysql.exe -u root -D "${TEST_DB:-smallrest_test}" -N -e \
  "SELECT setting_value FROM settings WHERE setting_key='merchant_id';" > /tmp/mid.txt
check "digits only" /tmp/mid.txt '^616123456$'

echo "== 2. settings page previews the code =="
curl -s -b $JA $BASE/admin/settings.php > /tmp/set2.html
check "preview shows dial code" /tmp/set2.html '789[*]616123456[*]6.00#'

echo "== 3. cashier rings up an unpaid bill =="
curl -s -c $JC $BASE/public/login.php > /tmp/c0.html
curl -s -b $JC -c $JC -o /dev/null -d "csrf=$(tok < /tmp/c0.html)&username=cashier&password=cashier123" $BASE/public/login.php
curl -s -b $JC -c $JC $BASE/admin/shifts.php > /tmp/c1.html
curl -s -b $JC -c $JC -o /dev/null -d "csrf=$(tok < /tmp/c1.html)&action=open&opening_float=0" $BASE/admin/shifts.php
curl -s -b $JC -c $JC $BASE/public/pos.php > /tmp/c2.html
CS=$(tok < /tmp/c2.html)
curl -s -b $JC -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CS" \
  -d '{"action":"hold","order_type":"dine_in","table_label":"T1","lines":[{"id":11,"qty":1},{"id":1,"qty":2}]}' \
  $BASE/public/api/order_save.php > /tmp/h.json
echo "  $(cat /tmp/h.json)"
HOLD=$(grep -oE '"order_id":[0-9]+' /tmp/h.json | cut -d: -f2)
TOTAL=$(grep -oE '"total":[0-9.]+' /tmp/h.json | cut -d: -f2)
TOTAL=$(printf '%.2f' "$TOTAL")   # the dial code always carries 2 decimals
echo "  bill total = $TOTAL"

curl -s -b $JC "$BASE/public/receipt.php?id=$HOLD" > /tmp/bill.html
check "bill headed PAY BY MOBILE MONEY" /tmp/bill.html 'PAY BY MOBILE MONEY'
check "bill shows merchant name" /tmp/bill.html 'Xamar Tea Shop'
check "bill dial code matches total" /tmp/bill.html "789[*]616123456[*]$TOTAL#"
check "bill has tel: link" /tmp/bill.html 'tel:[*]789'
echo "  printed block:"
grep -A2 'pay-code' /tmp/bill.html | head -3 | sed 's/^/    /'

echo "== 4. paid receipt shows it while the option is on =="
curl -s -b $JC -c $JC $BASE/public/orders.php > /tmp/oo.html
curl -s -b $JC -c $JC -o /dev/null -d "csrf=$(tok < /tmp/oo.html)&order_id=$HOLD&payment_method=cash&paid_amount=10" $BASE/public/order_pay.php
curl -s -b $JC "$BASE/public/receipt.php?id=$HOLD" > /tmp/rec.html
check "receipt headed MOBILE MONEY ACCOUNT" /tmp/rec.html 'MOBILE MONEY ACCOUNT'
check "receipt dial code" /tmp/rec.html "789[*]616123456[*]$TOTAL#"

echo "== 5. turning the option off hides it on paid receipts only =="
save_settings "616123456" 0
curl -s -b $JC "$BASE/public/receipt.php?id=$HOLD" > /tmp/rec2.html
nocheck "paid receipt no longer prints it" /tmp/rec2.html 'MOBILE MONEY'
curl -s -b $JC -X POST -H "Content-Type: application/json" -H "X-CSRF-Token: $CS" \
  -d '{"action":"hold","lines":[{"id":15,"qty":3}]}' $BASE/public/api/order_save.php > /tmp/h2.json
H2=$(grep -oE '"order_id":[0-9]+' /tmp/h2.json | cut -d: -f2)
curl -s -b $JC "$BASE/public/receipt.php?id=$H2" > /tmp/bill2.html
check "unpaid bill still prints it" /tmp/bill2.html 'PAY BY MOBILE MONEY'

echo "== 6. blank merchant number prints nothing =="
save_settings "" 1
curl -s -b $JC "$BASE/public/receipt.php?id=$H2" > /tmp/bill3.html
nocheck "no dial block without a merchant number" /tmp/bill3.html 'MOBILE MONEY'
check "receipt still renders normally" /tmp/bill3.html 'TOTAL'

echo
if [ $fail -eq 0 ]; then echo "ALL MERCHANT CHECKS PASSED"; else echo "SOME CHECKS FAILED"; fi
exit $fail
