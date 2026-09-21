#!/usr/bin/env bash
# Editable open orders + Daily / Unpaid / Financial reports.
# Run through tests/run_all.sh (throwaway smallrest_test DB, never the live till).
BASE=${BASE:-http://localhost:8099/smallrest}   # the test server started by run_all.sh
MYSQL=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
fail=0
TMP=$(mktemp -d)

tok()   { grep -oE '[0-9a-f]{64}' | head -1; }
sql()   { "$MYSQL" -u root -D "${TEST_DB:-smallrest_test}" -N -e "$1"; }
pass()  { echo "  PASS $1"; }
flunk() { echo "  FAIL $1"; fail=1; }
eq()    { if [ "$2" = "$3" ]; then pass "$1"; else flunk "$1 (got '$2', want '$3')"; fi; }
has()   { if grep -q -- "$3" "$2"; then pass "$1"; else flunk "$1 (missing: $3)"; fi; }
lacks() { if grep -q -- "$3" "$2"; then flunk "$1 (should not contain: $3)"; else pass "$1"; fi; }

login() { # login <jar> <user> <password>
  curl -s -c "$1" $BASE/public/login.php > $TMP/l.html
  curl -s -b "$1" -c "$1" -o /dev/null -d "csrf=$(tok < $TMP/l.html)&username=$2&password=$3" $BASE/public/login.php
}
api() { # api <jar> <csrf> <endpoint> <json>  -> prints "HTTPCODE body"
  curl -s -b "$1" -o $TMP/api.json -w "%{http_code}" -X POST \
    -H "Content-Type: application/json" -H "X-CSRF-Token: $2" -d "$4" "$BASE/public/api/$3"
  echo " $(cat $TMP/api.json)"
}
jnum() { grep -oE "\"$1\":[0-9.]+" | head -1 | cut -d: -f2; }
money2() { printf '%.2f' "$1"; }

JC=$TMP/cashier; JW=$TMP/waiter; JK=$TMP/kitchen; JA=$TMP/admin
login $JC cashier cashier123
login $JW waiter  waiter123
login $JK kitchen kitchen123
login $JA admin   admin123

# Cashier needs an open drawer to charge (the merchant suite may have left one open).
curl -s -b $JC -c $JC $BASE/admin/shifts.php > $TMP/sh.html
curl -s -b $JC -c $JC -o /dev/null -d "csrf=$(tok < $TMP/sh.html)&action=open&opening_float=0" $BASE/admin/shifts.php
SHIFT=$(sql "SELECT id FROM shifts WHERE status='open' AND user_id=(SELECT id FROM users WHERE username='cashier') ORDER BY id DESC LIMIT 1")

curl -s -b $JC $BASE/public/pos.php > $TMP/pos.html;  CC=$(tok < $TMP/pos.html)
curl -s -b $JW $BASE/public/pos.php > $TMP/posw.html; CW=$(tok < $TMP/posw.html)
curl -s -b $JK $BASE/public/kitchen.php > $TMP/k.html; CK=$(tok < $TMP/k.html)

# ====================================================================== Part A
echo "== A1. new held order: 2 x Milk Tea (0.50) + 1 x Rice with Beef (4.00) =="
R=$(api $JC $CC order_save.php '{"action":"hold","order_type":"dine_in","table_label":"T7","lines":[{"id":1,"qty":2},{"id":11,"qty":1}]}')
A=$(echo "$R" | jnum order_id)
eq "created" "${R%% *}" "200"
eq "subtotal 5.00" "$(sql "SELECT subtotal FROM orders WHERE id=$A")" "5.00"
eq "total = subtotal - discount + tax" \
   "$(sql "SELECT total = subtotal - discount + tax FROM orders WHERE id=$A")" "1"
eq "first round is 1" "$(sql "SELECT MAX(round) FROM order_items WHERE order_id=$A")" "1"
A_NO=$(sql "SELECT order_no FROM orders WHERE id=$A")

echo "== A2. add a round of 3 x Sambusa with a tampered client price =="
R=$(api $JW $CW order_save.php "{\"action\":\"hold\",\"order_id\":$A,\"lines\":[{\"id\":15,\"qty\":3,\"price\":0.01}]}")
eq "waiter can add to an open order" "${R%% *}" "200"
eq "response reports round 2" "$(echo "$R" | jnum round)" "2"
eq "subtotal 6.50 (tampered price ignored)" "$(sql "SELECT subtotal FROM orders WHERE id=$A")" "6.50"
eq "same order number kept" "$(sql "SELECT order_no FROM orders WHERE id=$A")" "$A_NO"
eq "updated_at stamped" "$(sql "SELECT updated_at IS NOT NULL FROM orders WHERE id=$A")" "1"

echo "== A3. POS edit screen and open-orders page =="
curl -s -b $JC "$BASE/public/pos.php?order=$A" > $TMP/pose.html
has "POS shows edit banner" $TMP/pose.html "Adding to order"
has "POS carries existing lines" $TMP/pose.html 'editOrder:  {"id":'
has "POS shows Add & Charge" $TMP/pose.html "Add &amp; Charge"
curl -s -b $JC $BASE/public/orders.php > $TMP/oo.html
has "open orders has Add items link" $TMP/oo.html "pos.php?order=$A"
has "open orders shows Round 2 badge" $TMP/oo.html "Round 2"

echo "== A4. remove / reduce rules =="
BEEF=$(sql "SELECT id FROM order_items WHERE order_id=$A AND menu_item_id=11")
TEA=$(sql  "SELECT id FROM order_items WHERE order_id=$A AND menu_item_id=1")
SAMB=$(sql "SELECT id FROM order_items WHERE order_id=$A AND menu_item_id=15")
curl -s -b $JK -o /dev/null -d "csrf=$CK&item_id=$BEEF&to=preparing" $BASE/public/kitchen_update.php
R=$(api $JC $CC order_line_update.php "{\"order_id\":$A,\"line_id\":$BEEF,\"qty\":0}")
eq "cooking line cannot be removed (409)" "${R%% *}" "409"
R=$(api $JC $CC order_line_update.php "{\"order_id\":$A,\"line_id\":$TEA,\"qty\":5}")
eq "line update cannot increase qty (422)" "${R%% *}" "422"
R=$(api $JW $CW order_line_update.php "{\"order_id\":$A,\"line_id\":$TEA,\"qty\":1}")
eq "waiter reduces pending tea 2 -> 1" "${R%% *}" "200"
eq "subtotal 6.00 after reduce" "$(sql "SELECT subtotal FROM orders WHERE id=$A")" "6.00"
R=$(api $JC $CC order_line_update.php "{\"order_id\":$A,\"line_id\":$SAMB,\"qty\":0}")
eq "pending sambusa removed" "${R%% *}" "200"
eq "subtotal 4.50 after remove" "$(sql "SELECT subtotal FROM orders WHERE id=$A")" "4.50"
R=$(api $JC $CC order_line_update.php "{\"order_id\":$A,\"line_id\":999999,\"qty\":0}")
eq "foreign line id refused (404)" "${R%% *}" "404"

echo "== A5. Add & Charge: add a bottled water and pay 10.00 in one step =="
R=$(api $JW $CW order_save.php "{\"action\":\"pay\",\"order_id\":$A,\"paid_amount\":10,\"lines\":[{\"id\":5,\"qty\":1}]}")
eq "waiter cannot charge (403)" "${R%% *}" "403"
R=$(api $JC $CC order_save.php "{\"action\":\"pay\",\"order_id\":$A,\"payment_method\":\"cash\",\"paid_amount\":0.10,\"lines\":[{\"id\":5,\"qty\":1}]}")
eq "underpayment refused (422)" "${R%% *}" "422"
eq "refused charge rolled back (no water line)" "$(sql "SELECT COUNT(*) FROM order_items WHERE order_id=$A AND menu_item_id=5")" "0"
R=$(api $JC $CC order_save.php "{\"action\":\"pay\",\"order_id\":$A,\"payment_method\":\"cash\",\"paid_amount\":10,\"lines\":[{\"id\":5,\"qty\":1}]}")
eq "add & charge ok" "${R%% *}" "200"
eq "order paid, linked to the open shift" "$(sql "SELECT CONCAT(status,'|',shift_id) FROM orders WHERE id=$A")" "paid|$SHIFT"
eq "subtotal 5.00 incl. water" "$(sql "SELECT subtotal FROM orders WHERE id=$A")" "5.00"
eq "change = 10 - total" "$(sql "SELECT change_amount = 10 - total FROM orders WHERE id=$A")" "1"

echo "== A6. a paid order is closed to edits =="
R=$(api $JC $CC order_save.php "{\"action\":\"hold\",\"order_id\":$A,\"lines\":[{\"id\":1,\"qty\":1}]}")
eq "adding to paid order refused (409)" "${R%% *}" "409"
R=$(api $JC $CC order_line_update.php "{\"order_id\":$A,\"line_id\":$TEA,\"qty\":0}")
eq "changing a paid order refused (409)" "${R%% *}" "409"
curl -s -b $JC -o /dev/null -w "%{redirect_url}" "$BASE/public/pos.php?order=$A" > $TMP/redir.txt
has "POS edit on paid order bounces to open orders" $TMP/redir.txt "orders.php"

echo "== A7. the last line cannot be removed =="
R=$(api $JC $CC order_save.php '{"action":"hold","table_label":"T9","lines":[{"id":2,"qty":1}]}')
B=$(echo "$R" | jnum order_id)
ONLY=$(sql "SELECT id FROM order_items WHERE order_id=$B")
R=$(api $JC $CC order_line_update.php "{\"order_id\":$B,\"line_id\":$ONLY,\"qty\":0}")
eq "removing the only line refused (409)" "${R%% *}" "409"
has "tells staff to void instead" $TMP/api.json "void the order instead"

echo "== A8. kitchen times an added round from when it was added =="
R=$(api $JC $CC order_save.php '{"action":"hold","table_label":"T12","lines":[{"id":9,"qty":1}]}')
C=$(echo "$R" | jnum order_id)
CNO=$(sql "SELECT order_no FROM orders WHERE id=$C")
sql "UPDATE orders SET created_at = created_at - INTERVAL 2 HOUR WHERE id=$C;
     UPDATE order_items SET created_at = created_at - INTERVAL 2 HOUR, kitchen_status='served' WHERE order_id=$C;"
api $JW $CW order_save.php "{\"action\":\"hold\",\"order_id\":$C,\"lines\":[{\"id\":10,\"qty\":1}]}" > /dev/null
curl -s -b $JK $BASE/public/kitchen.php > $TMP/k2.html
has "kitchen shows the round badge" $TMP/k2.html "Round 2 · added"
AGE=$(grep -A12 "#$CNO" $TMP/k2.html | grep -oE '[0-9]+ min' | head -1)
case "$AGE" in "0 min"|"1 min") pass "added round starts fresh ($AGE, not 120)";; *) flunk "added round age is '$AGE'";; esac

# ====================================================================== Part B
hasf() { if grep -qF -- "$3" "$2"; then pass "$1"; else flunk "$1 (missing: $3)"; fi; }
# money() as PHP prints it: $1.50, $-3.00
fmt() { printf '$%.2f' "$1"; }
code_of() { curl -s -b "$1" -o /dev/null -w "%{http_code}" "$2"; }
csv_check() { # csv_check <label> <jar> <url> <expected header text>
  curl -s -b "$2" -D $TMP/h.txt -o $TMP/c.csv "$3"
  if grep -qi "^Content-Type: text/csv" $TMP/h.txt && grep -qF -- "$4" $TMP/c.csv; then pass "$1"
  else flunk "$1 (not a CSV with '$4')"; fi
}
TODAY=$(date +%F)
DAY_START="$TODAY 00:00:00"

echo "== B1. daily transactions report (cashier) =="
curl -s -b $JC "$BASE/admin/report_daily.php?date=$TODAY" > $TMP/d.html
has "cashier can open the daily report" $TMP/d.html "Transactions in time order"
SALES=$(sql "SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND paid_at >= '$DAY_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
CASH=$(sql "SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND payment_method='cash' AND paid_at >= '$DAY_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
EXP=$(sql "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_on='$TODAY'")
hasf "total collected tile = SQL ($(fmt $SALES))" $TMP/d.html "Total collected</div><div class=\"value\">$(fmt $SALES)"
hasf "cash collected = SQL ($(fmt $CASH))" $TMP/d.html "Cash <strong class=\"float-end\">$(fmt $CASH)"
hasf "expenses tile = SQL ($(fmt $EXP))" $TMP/d.html "Expenses</div><div class=\"value\">$(fmt $EXP)"
BNO=$(sql "SELECT order_no FROM orders WHERE id=$B")
hasf "log lists the unpaid bill #$BNO" $TMP/d.html "#$BNO"
hasf "log lists the expense" $TMP/d.html "Sugar 5kg"
has "log marks unpaid rows" $TMP/d.html '>Unpaid</span>'
csv_check "daily CSV download" $JC "$BASE/admin/report_daily.php?date=$TODAY&export=csv" "Time,Type,Reference"

echo "== B2. thermal end-of-day slip =="
curl -s -b $JC "$BASE/admin/report_daily_slip.php?date=$TODAY" > $TMP/slip.html
has "slip renders" $TMP/slip.html "END OF DAY"
hasf "slip total collected = page" $TMP/slip.html "<strong>TOTAL COLLECTED</strong></td><td class=\"r\"><strong>$(fmt $SALES)"
has "slip uses the 80mm receipt layout" $TMP/slip.html 'class="receipt"'

echo "== B3. unpaid bills report =="
sql "UPDATE orders SET created_at = NOW() - INTERVAL 4 DAY WHERE id=$B"
curl -s -b $JC "$BASE/admin/report_unpaid.php" > $TMP/u.html
OWED=$(sql "SELECT COALESCE(SUM(total),0) FROM orders WHERE status='open'")
OPEN_N=$(sql "SELECT COUNT(*) FROM orders WHERE status='open'")
BTOTAL=$(sql "SELECT total FROM orders WHERE id=$B")
hasf "money owed = SQL ($(fmt $OWED))" $TMP/u.html "Money still owed</div><div class=\"value\">$(fmt $OWED)"
hasf "bill count = SQL ($OPEN_N)" $TMP/u.html "$OPEN_N unpaid bill(s)"
hasf "4-day-old bill listed" $TMP/u.html "#$BNO"
hasf "shows its age as 4 days" $TMP/u.html "4 days"
has "over-3-days bucket holds it" $TMP/u.html "Over 3 days</div>"
hasf "bucket total = that bill ($(fmt $BTOTAL))" $TMP/u.html "Over 3 days</div>
            <div class=\"value\" style=\"font-size:20px;\">$(fmt $BTOTAL)"
csv_check "unpaid CSV download" $JC "$BASE/admin/report_unpaid.php?export=csv" "TOTAL OWED"

echo "== B4. financial P&L (admin) matches a hand computation =="
M_START="$(date +%Y-%m-01) 00:00:00"
NET_SALES=$(sql "SELECT COALESCE(SUM(subtotal - discount),0) FROM orders WHERE status='paid' AND paid_at >= '$M_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
COST=$(sql "SELECT COALESCE(SUM(oi.unit_cost*oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status='paid' AND o.paid_at >= '$M_START' AND o.paid_at < '$DAY_START' + INTERVAL 1 DAY")
MEXP=$(sql "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_on BETWEEN '$(date +%Y-%m-01)' AND '$TODAY'")
COLLECTED=$(sql "SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND paid_at >= '$M_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
NP=$(awk "BEGIN { printf \"%.2f\", $NET_SALES - $COST - $MEXP }")
GP=$(awk "BEGIN { printf \"%.2f\", $NET_SALES - $COST }")
curl -s -b $JA "$BASE/admin/report_financial.php" > $TMP/f.html
hasf "net sales = SQL ($(fmt $NET_SALES))" $TMP/f.html "Net sales</div><div class=\"value\">$(fmt $NET_SALES)"
hasf "gross profit = net sales - cost ($(fmt $GP))" $TMP/f.html "Gross profit</div><div class=\"value\">$(fmt $GP)"
hasf "net profit = gross profit - expenses ($(fmt $NP))" $TMP/f.html "Net profit</div><div class=\"value\">$(fmt $NP)"
hasf "collections total = SQL ($(fmt $COLLECTED))" $TMP/f.html "<th>Total collected</th><th class=\"text-end\">$(fmt $COLLECTED)"
csv_check "financial CSV download" $JA "$BASE/admin/report_financial.php?export=csv" "Net profit"

echo "== B5. overview tab and access rules =="
curl -s -b $JA "$BASE/admin/reports.php?range=month" > $TMP/o.html
has "overview renders with the tab bar" $TMP/o.html "report-tabs"
csv_check "overview CSV download" $JA "$BASE/admin/reports.php?export=csv" "Date,\"Paid orders\",Sales"
eq "cashier kept out of Financial"  "$(code_of $JC "$BASE/admin/report_financial.php")" "302"
eq "cashier kept out of Overview"   "$(code_of $JC "$BASE/admin/reports.php")" "302"
eq "waiter kept out of Daily"       "$(code_of $JW "$BASE/admin/report_daily.php")" "302"
eq "waiter kept out of Unpaid"      "$(code_of $JW "$BASE/admin/report_unpaid.php")" "302"
curl -s -b $JC "$BASE/admin/report_daily.php" > $TMP/tabs.html
lacks "cashier's tab bar hides Financial" $TMP/tabs.html "report_financial.php"

# ====================================================================== Part C — VAT
set_vat() { # set_vat <rate>  — through the real Settings form, as the admin would
  curl -s -b $JA -c $JA $BASE/admin/settings.php > $TMP/set.html
  curl -s -b $JA -c $JA -o /dev/null \
    --data-urlencode "csrf=$(tok < $TMP/set.html)" \
    --data-urlencode "shop_name=Small Restaurant" --data-urlencode "shop_tagline=Tea & Food" \
    --data-urlencode "shop_address=" --data-urlencode "shop_phone=" --data-urlencode "currency=\$" \
    --data-urlencode "receipt_footer=Thank you" --data-urlencode "merchant_name=Test" \
    --data-urlencode "merchant_id=616123456" --data-urlencode "ussd_prefix=*789*" \
    --data-urlencode "merchant_on_receipt=1" --data-urlencode "tax_percent=$1" \
    $BASE/admin/settings.php
  curl -s -b $JA $BASE/admin/settings.php > $TMP/set_after.html
}
vat_of() { sql "SELECT CONCAT(subtotal,'|',discount,'|',vat_rate,'|',tax,'|',total) FROM orders WHERE id=$1"; }

echo "== C1. entering 0.05 warns that it means 0.05%, not 5% =="
set_vat 0.05
has "warning shown for a rate under 1%" $TMP/set_after.html "less than one percent"
hasf "suggests typing 5" $TMP/set_after.html "type 5 in the VAT box"

echo "== C2. set VAT to 5%: unpaid bills are re-priced, the example reads \$2.00 + \$0.10 = \$2.10 =="
OPEN_BEFORE=$(sql "SELECT COUNT(*) FROM orders WHERE status='open'")
A_BEFORE=$(sql "SELECT CONCAT(vat_rate,'|',tax,'|',total) FROM orders WHERE id=$A")
set_vat 5
eq "rate stored as 5" "$(sql "SELECT setting_value FROM settings WHERE setting_key='tax_percent'")" "5"
hasf "confirms the new rate" $TMP/set_after.html "VAT is now 5%"
hasf "reports $OPEN_BEFORE unpaid bill(s) re-priced" $TMP/set_after.html "$OPEN_BEFORE unpaid bill(s) re-priced"
has "settings example shows 2.10" $TMP/set_after.html 'customer pays <strong>\$2.10</strong>'
eq "every open bill now carries 5% VAT" \
   "$(sql "SELECT COUNT(*) FROM orders WHERE status='open' AND vat_rate=5 AND tax = ROUND(ROUND((subtotal-discount)*100) * 5 / 100) / 100 AND total = subtotal - discount + tax")" "$OPEN_BEFORE"
eq "paid order A untouched by the rate change ($A_BEFORE)" \
   "$(sql "SELECT CONCAT(vat_rate,'|',tax,'|',total) FROM orders WHERE id=$A")" "$A_BEFORE"

echo "== C3. the user's example: \$2.00 order at 5% =="
curl -s -b $JC $BASE/public/pos.php > $TMP/pos5.html; CC=$(tok < $TMP/pos5.html)
hasf "POS shows the VAT 5% row" $TMP/pos5.html "<span>VAT 5%</span>"
R=$(api $JC $CC order_save.php '{"action":"hold","table_label":"VAT","lines":[{"id":8,"qty":1},{"id":15,"qty":1}]}')
V=$(echo "$R" | jnum order_id)
eq "1.50 + 0.50 = 2.00, VAT 0.10, total 2.10" "$(vat_of $V)" "2.00|0.00|5.00|0.10|2.10"
curl -s -b $JC "$BASE/public/receipt.php?id=$V" > $TMP/rv.html
hasf "bill shows Total before VAT \$2.00" $TMP/rv.html "<tr><td>Total before VAT</td>
                <td class=\"r\">\$2.00</td>"
hasf "bill shows VAT 5% \$0.10" $TMP/rv.html "<tr><td>VAT 5%</td>
                <td class=\"r\">\$0.10</td>"
hasf "bill total says incl. VAT \$2.10" $TMP/rv.html "TOTAL incl. VAT</strong></td>
            <td class=\"r\"><strong>\$2.10"
hasf "mobile-money code asks for 2.10" $TMP/rv.html "*789*616123456*2.10#"

echo "== C4. half a cent rounds up: \$2.00 - \$0.50 discount = \$1.50 -> VAT 7.5c -> 8c =="
R=$(api $JC $CC order_save.php '{"action":"hold","discount":0.5,"lines":[{"id":8,"qty":1},{"id":15,"qty":1}]}')
eq "VAT on the discounted amount, rounded to 0.08" "$(vat_of $(echo "$R" | jnum order_id))" "2.00|0.50|5.00|0.08|1.58"

echo "== C5. payment must cover VAT =="
R=$(api $JC $CC order_save.php "{\"action\":\"pay\",\"order_id\":$V,\"payment_method\":\"cash\",\"paid_amount\":2.00,\"lines\":[{\"id\":5,\"qty\":1}]}")
eq "paying 2.00 on a 2.63 bill refused (422)" "${R%% *}" "422"
R=$(api $JC $CC order_save.php "{\"action\":\"pay\",\"order_id\":$V,\"payment_method\":\"mobile\",\"paid_amount\":2.63,\"lines\":[{\"id\":5,\"qty\":1}]}")
eq "add water (0.50) and pay 2.63 exactly" "${R%% *}" "200"
eq "2.50 + VAT 0.13 = 2.63, paid" "$(sql "SELECT CONCAT(subtotal,'|',tax,'|',total,'|',status) FROM orders WHERE id=$V")" "2.50|0.13|2.63|paid"

echo "== C6. reports show sales less VAT, and VAT separately =="
NET=$(sql "SELECT COALESCE(SUM(subtotal-discount),0) FROM orders WHERE status='paid' AND paid_at >= '$DAY_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
VAT=$(sql "SELECT COALESCE(SUM(tax),0) FROM orders WHERE status='paid' AND paid_at >= '$DAY_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
TOT=$(sql "SELECT COALESCE(SUM(total),0) FROM orders WHERE status='paid' AND paid_at >= '$DAY_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
eq "sanity: before VAT + VAT = collected" "$(awk "BEGIN{printf \"%.2f\", $NET + $VAT}")" "$(printf '%.2f' $TOT)"
curl -s -b $JC "$BASE/admin/report_daily.php?date=$TODAY" > $TMP/d2.html
hasf "daily: sales before VAT ($(fmt $NET))" $TMP/d2.html "Sales before VAT</div><div class=\"value\">$(fmt $NET)"
hasf "daily: VAT collected ($(fmt $VAT))" $TMP/d2.html "VAT collected</div><div class=\"value\">$(fmt $VAT)"
hasf "daily: total collected ($(fmt $TOT))" $TMP/d2.html "Total collected</div><div class=\"value\">$(fmt $TOT)"
csv_check "daily CSV has Before VAT / VAT columns" $JC "$BASE/admin/report_daily.php?date=$TODAY&export=csv" '"Before VAT",VAT,Total'
curl -s -b $JC "$BASE/admin/report_daily_slip.php?date=$TODAY" > $TMP/slip2.html
hasf "slip: VAT collected" $TMP/slip2.html "<strong>VAT collected</strong></td><td class=\"r\"><strong>$(fmt $VAT)"

MNET=$(sql "SELECT COALESCE(SUM(subtotal-discount),0) FROM orders WHERE status='paid' AND paid_at >= '$M_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
MVAT=$(sql "SELECT COALESCE(SUM(tax),0) FROM orders WHERE status='paid' AND paid_at >= '$M_START' AND paid_at < '$DAY_START' + INTERVAL 1 DAY")
MCOST=$(sql "SELECT COALESCE(SUM(oi.unit_cost*oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status='paid' AND o.paid_at >= '$M_START' AND o.paid_at < '$DAY_START' + INTERVAL 1 DAY")
MNP=$(awk "BEGIN { printf \"%.2f\", $MNET - $MCOST - $MEXP }")
curl -s -b $JA "$BASE/admin/report_financial.php" > $TMP/f2.html
hasf "financial: VAT collected tile ($(fmt $MVAT))" $TMP/f2.html "VAT collected</div><div class=\"value\">$(fmt $MVAT)"
hasf "financial: net profit leaves VAT out ($(fmt $MNP))" $TMP/f2.html "Net profit</div><div class=\"value\">$(fmt $MNP)"
has "financial: VAT account lists the 5% rate" $TMP/f2.html "<td>5%</td>"
csv_check "financial CSV has the VAT account" $JA "$BASE/admin/report_financial.php?export=csv" "Total VAT collected"

TCOST=$(sql "SELECT COALESCE(SUM(oi.unit_cost*oi.qty),0) FROM order_items oi JOIN orders o ON o.id=oi.order_id WHERE o.status='paid' AND o.paid_at >= '$DAY_START' AND o.paid_at < '$DAY_START' + INTERVAL 1 DAY")
TEXP=$(sql "SELECT COALESCE(SUM(amount),0) FROM expenses WHERE spent_on='$TODAY'")
NETTODAY=$(awk "BEGIN { printf \"%.2f\", $NET - $TCOST - $TEXP }")
curl -s -b $JA "$BASE/admin/index.php" > $TMP/dash.html
hasf "dashboard: net today excludes VAT ($(fmt $NETTODAY))" $TMP/dash.html "Net today</div><div class=\"value\">$(fmt $NETTODAY)"

echo "== C7. a later rate change never rewrites a paid receipt =="
set_vat 10
eq "paid 5% order still 0.13 VAT at 5%" "$(sql "SELECT CONCAT(vat_rate,'|',tax,'|',total) FROM orders WHERE id=$V")" "5.00|0.13|2.63"
curl -s -b $JC "$BASE/public/receipt.php?id=$V" > $TMP/rv2.html
hasf "reprinted receipt still says VAT 5%" $TMP/rv2.html "<tr><td>VAT 5%</td>"
eq "open bills moved to 10%" "$(sql "SELECT COUNT(*) FROM orders WHERE status='open' AND vat_rate <> 10")" "0"
set_vat 5

echo
if [ $fail -eq 0 ]; then echo "ALL ORDER/REPORT CHECKS PASSED"; else echo "SOME CHECKS FAILED"; fi
rm -rf $TMP
exit $fail
