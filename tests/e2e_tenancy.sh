#!/usr/bin/env bash
# Multi-company isolation: two restaurants register, and neither can see or
# touch the other's menu, orders, expenses, settings or users. Also covers the
# username-is-global rule, per-company receipt numbers, plan limits,
# trial expiry / suspension and a scoped System Reset.
# Run through tests/run_all.sh (throwaway smallrest_test DB, never the live till).
BASE=${BASE:-http://localhost:8099/smallrest}
MYSQL=${MYSQL:-/c/xampp/mysql/bin/mysql.exe}
fail=0
TMP=$(mktemp -d)
RUN=$RANDOM   # keeps names unique if the suite is re-run against a kept DB

tok()   { grep -oE '[0-9a-f]{64}' | head -1; }
sql()   { "$MYSQL" -u root -D "${TEST_DB:-smallrest_test}" -N -e "$1"; }
pass()  { echo "  PASS $1"; }
flunk() { echo "  FAIL $1"; fail=1; }
eq()    { if [ "$2" = "$3" ]; then pass "$1"; else flunk "$1 (got '$2', want '$3')"; fi; }
has()   { if grep -q -- "$3" "$2"; then pass "$1"; else flunk "$1 (missing: $3)"; fi; }
lacks() { if grep -q -- "$3" "$2"; then flunk "$1 (should not contain: $3)"; else pass "$1"; fi; }

login() { # login <jar> <user> <password>  -> prints the redirect target
  curl -s -c "$1" $BASE/public/login.php > $TMP/l.html
  curl -s -b "$1" -c "$1" -o $TMP/login_out.html -w "%{redirect_url}" \
    -d "csrf=$(tok < $TMP/l.html)&username=$2&password=$3" $BASE/public/login.php
}
register() { # register <jar> <company name> <slug> <username>  -> prints the redirect target
  curl -s -c "$1" $BASE/public/register.php > $TMP/r.html
  curl -s -b "$1" -c "$1" -o $TMP/reg_out.html -w "%{redirect_url}" \
    --data-urlencode "csrf=$(tok < $TMP/r.html)" --data-urlencode "company_name=$2" \
    --data-urlencode "slug=$3" --data-urlencode "phone=0612345678" \
    --data-urlencode "full_name=Owner of $2" --data-urlencode "username=$4" \
    -d "password=secret123&password2=secret123&starter=1&website=" $BASE/public/register.php
}
form() { # form <jar> <page> <urlencoded fields>  -> posts with a fresh CSRF token, prints HTTP code
  curl -s -b "$1" -c "$1" "$BASE/$2" > $TMP/f.html
  curl -s -b "$1" -c "$1" -o $TMP/form_out.html -w "%{http_code}" -d "csrf=$(tok < $TMP/f.html)&$3" "$BASE/$2"
}
api() { # api <jar> <endpoint> <json>  -> prints "HTTPCODE body"
  curl -s -b "$1" "$BASE/public/pos.php" > $TMP/pos.html
  curl -s -b "$1" -o $TMP/api.json -w "%{http_code}" -X POST -H "Content-Type: application/json" \
    -H "X-CSRF-Token: $(tok < $TMP/pos.html)" -d "$3" "$BASE/public/api/$2"
  echo " $(cat $TMP/api.json)"
}
code_of() { curl -s -b "$1" -o $TMP/page.html -w "%{http_code}" "$2"; }
jnum() { grep -oE "\"$1\":[0-9.]+" | head -1 | cut -d: -f2; }

JA=$TMP/a_admin; JB=$TMP/b_admin; JP=$TMP/platform; JX=$TMP/x

echo "== T1. two restaurants register =="
eq "A registers and lands on its dashboard" "$(register $JA "Alpha Cafe $RUN" "alpha-$RUN" "ahmed.alpha$RUN")" "$BASE/admin/index.php"
eq "B registers and lands on its dashboard" "$(register $JB "Bravo Grill $RUN" "bravo-$RUN" "bashir.bravo$RUN")" "$BASE/admin/index.php"
CA=$(sql "SELECT id FROM companies WHERE slug='alpha-$RUN'")
CB=$(sql "SELECT id FROM companies WHERE slug='bravo-$RUN'")
eq "A is on a 14-day trial" "$(sql "SELECT CONCAT(status,'|',plan_id,'|',DATEDIFF(trial_ends_at, CURDATE())) FROM companies WHERE id=$CA")" "trial|1|14"
eq "A got its own settings" "$(sql "SELECT setting_value FROM settings WHERE company_id=$CA AND setting_key='shop_name'")" "Alpha Cafe $RUN"
eq "A got 5 starter categories" "$(sql "SELECT COUNT(*) FROM categories WHERE company_id=$CA")" "5"
curl -s -b $JA $BASE/admin/index.php > $TMP/dashA.html
has "A's top bar shows A's name" $TMP/dashA.html "Alpha Cafe $RUN"
lacks "A's dashboard never shows B" $TMP/dashA.html "Bravo Grill"
has "A sees the getting-started list" $TMP/dashA.html "Getting started"

echo "== T2. usernames are unique across every restaurant =="
register $JX "Charlie $RUN" "charlie-$RUN" "ahmed.alpha$RUN" > /dev/null
has "registering with A's username is refused" $TMP/reg_out.html "is taken"
eq "no company was created for the refused sign-up" "$(sql "SELECT COUNT(*) FROM companies WHERE slug='charlie-$RUN'")" "0"
register $JX "Alpha again $RUN" "alpha-$RUN" "someone$RUN" > /dev/null
has "a taken short name is refused" $TMP/reg_out.html "already uses the short name"
form $JB admin/users.php "action=save&id=0&full_name=Copycat&username=ahmed.alpha$RUN&password=secret123&role=cashier&is_active=1" > /dev/null
eq "B cannot create a user with A's username" "$(sql "SELECT COUNT(*) FROM users WHERE username='ahmed.alpha$RUN'")" "1"
form $JA admin/users.php "action=save&id=0&full_name=Alpha Cashier&username=cash.alpha$RUN&password=secret123&role=cashier&is_active=1" > /dev/null
eq "A adds its own cashier" "$(sql "SELECT company_id FROM users WHERE username='cash.alpha$RUN'")" "$CA"

echo "== T3. A builds a menu and trades =="
CAT_A=$(sql "SELECT id FROM categories WHERE company_id=$CA ORDER BY id LIMIT 1")
CAT_B=$(sql "SELECT id FROM categories WHERE company_id=$CB ORDER BY id LIMIT 1")
form $JA admin/menu_items.php "action=save&id=0&name=Alpha Tea&category_id=$CAT_A&price=1.00&cost_price=0.30&needs_prep=1&is_available=1&sort_order=1" > /dev/null
ITEM_A=$(sql "SELECT id FROM menu_items WHERE company_id=$CA AND name='Alpha Tea'")
[ -n "$ITEM_A" ] && pass "A's menu item saved" || flunk "A's menu item saved"
form $JA admin/menu_items.php "action=save&id=0&name=Sneaky&category_id=$CAT_B&price=1.00&cost_price=0&is_available=1" > /dev/null
eq "A cannot file an item under B's category" "$(sql "SELECT COUNT(*) FROM menu_items WHERE name='Sneaky'")" "0"
R=$(api $JA order_save.php "{\"action\":\"hold\",\"order_type\":\"dine_in\",\"table_label\":\"A1\",\"lines\":[{\"id\":$ITEM_A,\"qty\":2}]}")
ORDER_A=$(echo "$R" | jnum order_id)
eq "A's order saved" "${R%% *}" "200"
eq "A's first receipt number ends -0001" "$(sql "SELECT RIGHT(order_no,5) FROM orders WHERE id=$ORDER_A")" "-0001"
form $JA admin/expenses.php "action=save&description=Alpha charcoal&amount=3.50&category=General&paid_from=other" > /dev/null
EXP_A=$(sql "SELECT id FROM expenses WHERE company_id=$CA AND description='Alpha charcoal'")
[ -n "$EXP_A" ] && pass "A's expense saved" || flunk "A's expense saved"
USER_A=$(sql "SELECT id FROM users WHERE username='cash.alpha$RUN'")

echo "== T4. B cannot see or touch anything of A's =="
form $JB admin/menu_items.php "action=save&id=0&name=Bravo Burger&category_id=$CAT_B&price=5.00&cost_price=2&needs_prep=1&is_available=1" > /dev/null
ITEM_B=$(sql "SELECT id FROM menu_items WHERE company_id=$CB AND name='Bravo Burger'")
R=$(api $JB order_save.php "{\"action\":\"hold\",\"lines\":[{\"id\":$ITEM_B,\"qty\":1}]}")
ORDER_B=$(echo "$R" | jnum order_id)
eq "B's first receipt number is also -0001" "$(sql "SELECT RIGHT(order_no,5) FROM orders WHERE id=$ORDER_B")" "-0001"

code_of $JB "$BASE/public/receipt.php?id=$ORDER_A" > /dev/null
has  "B gets 'not found' for A's receipt" $TMP/page.html "Order not found"
lacks "A's receipt shows nothing of A" $TMP/page.html "Alpha Tea"
code_of $JB "$BASE/admin/menu_items.php?edit=$ITEM_A" > /dev/null
lacks "B's menu editor does not load A's item" $TMP/page.html "Alpha Tea"
code_of $JB "$BASE/public/pos.php" > /dev/null
lacks "B's POS lists none of A's menu" $TMP/page.html "Alpha Tea"
code_of $JB "$BASE/public/orders.php" > /dev/null
lacks "B's open orders omit A's table" $TMP/page.html "A1"
code_of $JB "$BASE/public/kitchen.php" > /dev/null
lacks "B's kitchen omits A's order" $TMP/page.html "Alpha Tea"
code_of $JB "$BASE/admin/expenses.php" > /dev/null
lacks "B's expenses omit A's" $TMP/page.html "Alpha charcoal"
code_of $JB "$BASE/admin/users.php?edit=$USER_A" > /dev/null
lacks "B's user list and editor omit A's staff" $TMP/page.html "cash.alpha$RUN"
code_of $JB "$BASE/admin/report_unpaid.php" > /dev/null
lacks "B's unpaid report omits A's bill" $TMP/page.html "A1"
code_of $JB "$BASE/admin/sales.php?status=all" > /dev/null
lacks "B's sales log omits A's order" $TMP/page.html "receipt.php?id=$ORDER_A\""

R=$(api $JB order_save.php "{\"action\":\"hold\",\"order_id\":$ORDER_A,\"lines\":[{\"id\":$ITEM_B,\"qty\":1}]}")
eq "B cannot add items to A's order (404)" "${R%% *}" "404"
R=$(api $JB order_save.php "{\"action\":\"hold\",\"lines\":[{\"id\":$ITEM_A,\"qty\":1}]}")
eq "B cannot ring up A's menu item (422)" "${R%% *}" "422"
LINE_A=$(sql "SELECT id FROM order_items WHERE order_id=$ORDER_A LIMIT 1")
R=$(api $JB order_line_update.php "{\"order_id\":$ORDER_A,\"line_id\":$LINE_A,\"qty\":0}")
eq "B cannot change A's order lines (404)" "${R%% *}" "404"
form $JB public/order_void.php "order_id=$ORDER_A&reason=hack" > /dev/null
eq "B cannot void A's order" "$(sql "SELECT status FROM orders WHERE id=$ORDER_A")" "open"
form $JB public/kitchen_update.php "order_id=$ORDER_A&to=served" > /dev/null
eq "B cannot mark A's kitchen lines served" "$(sql "SELECT kitchen_status FROM order_items WHERE id=$LINE_A")" "pending"
form $JB admin/menu_items.php "action=delete&id=$ITEM_A" > /dev/null
eq "B cannot delete A's menu item" "$(sql "SELECT COUNT(*) FROM menu_items WHERE id=$ITEM_A")" "1"
form $JB admin/menu_items.php "action=save&id=$ITEM_A&name=Hacked&category_id=$CAT_B&price=9&cost_price=0&is_available=1" > /dev/null
eq "B cannot rename A's menu item" "$(sql "SELECT name FROM menu_items WHERE id=$ITEM_A")" "Alpha Tea"
form $JB admin/expenses.php "action=delete&id=$EXP_A" > /dev/null
eq "B cannot delete A's expense" "$(sql "SELECT COUNT(*) FROM expenses WHERE id=$EXP_A")" "1"
form $JB admin/users.php "action=toggle&id=$USER_A" > /dev/null
eq "B cannot disable A's user" "$(sql "SELECT is_active FROM users WHERE id=$USER_A")" "1"
form $JB admin/categories.php "action=delete&id=$(sql "SELECT id FROM categories WHERE company_id=$CA ORDER BY id DESC LIMIT 1")" > /dev/null
eq "B cannot delete A's category" "$(sql "SELECT COUNT(*) FROM categories WHERE company_id=$CA")" "5"
form $JB admin/settings.php "shop_name=Bravo Renamed $RUN&shop_tagline=Grill&currency=%24&tax_percent=0&receipt_footer=Bye&ussd_prefix=*789*" > /dev/null
eq "B's settings save changes B" "$(sql "SELECT setting_value FROM settings WHERE company_id=$CB AND setting_key='shop_name'")" "Bravo Renamed $RUN"
eq "... and leaves A's alone" "$(sql "SELECT setting_value FROM settings WHERE company_id=$CA AND setting_key='shop_name'")" "Alpha Cafe $RUN"
eq "the seeded demo restaurant is untouched" "$(sql "SELECT setting_value FROM settings WHERE company_id=1 AND setting_key='shop_name'")" "Small Restaurant"

echo "== T5. each user lands in their own company =="
JAC=$TMP/a_cashier
login $JAC "cash.alpha$RUN" secret123 > /dev/null
code_of $JAC "$BASE/public/pos.php" > /dev/null
has "A's cashier sees A's menu" $TMP/page.html "Alpha Tea"
lacks "A's cashier does not see B's menu" $TMP/page.html "Bravo Burger"

echo "== T6. plan limits (trial plan: 3 active users) =="
form $JA admin/users.php "action=save&id=0&full_name=Alpha Waiter&username=wait.alpha$RUN&password=secret123&role=waiter&is_active=1" > /dev/null
eq "third user fits the plan" "$(sql "SELECT COUNT(*) FROM users WHERE company_id=$CA AND is_active=1")" "3"
form $JA admin/users.php "action=save&id=0&full_name=One Too Many&username=extra.alpha$RUN&password=secret123&role=waiter&is_active=1" > /dev/null
eq "a fourth active user is refused" "$(sql "SELECT COUNT(*) FROM users WHERE username='extra.alpha$RUN'")" "0"
has "admin is told about the plan limit" $TMP/form_out.html "Your plan allows 3 active accounts"

echo "== T7. System Reset clears only the restaurant that runs it =="
B_ORDERS_BEFORE=$(sql "SELECT COUNT(*) FROM orders WHERE company_id=$CB")
DEMO_BEFORE=$(sql "SELECT COUNT(*) FROM orders WHERE company_id=1")
form $JA admin/system_reset.php "confirm_word=RESET" > /dev/null
eq "A's orders are gone" "$(sql "SELECT COUNT(*) FROM orders WHERE company_id=$CA")" "0"
eq "A's expenses are gone" "$(sql "SELECT COUNT(*) FROM expenses WHERE company_id=$CA")" "0"
eq "A's menu is kept" "$(sql "SELECT COUNT(*) FROM menu_items WHERE company_id=$CA")" "1"
eq "B's orders survive" "$(sql "SELECT COUNT(*) FROM orders WHERE company_id=$CB")" "$B_ORDERS_BEFORE"
eq "the demo restaurant's orders survive" "$(sql "SELECT COUNT(*) FROM orders WHERE company_id=1")" "$DEMO_BEFORE"
R=$(api $JA order_save.php "{\"action\":\"hold\",\"lines\":[{\"id\":$ITEM_A,\"qty\":1}]}")
eq "A's receipt numbers restart at -0001" "$(sql "SELECT RIGHT(order_no,5) FROM orders WHERE id=$(echo "$R" | jnum order_id)")" "-0001"

echo "== T8. trial expiry and suspension =="
sql "UPDATE companies SET trial_ends_at = CURDATE() - INTERVAL 1 DAY WHERE id=$CA"
eq "an open staff session is signed out at once" "$(code_of $JAC "$BASE/public/pos.php")" "302"
login $JAC "cash.alpha$RUN" secret123 > /dev/null
has "A's cashier is refused at login" $TMP/login_out.html "free trial for this restaurant has ended"
eq "A's admin is sent to Billing" "$(login $JA "ahmed.alpha$RUN" secret123)" "$BASE/admin/billing.php"
curl -s -b $JA -o /dev/null -w "%{redirect_url}" $BASE/admin/menu_items.php > $TMP/redir.txt
has "A's admin cannot use other pages" $TMP/redir.txt "admin/billing.php"
eq "B is unaffected" "$(code_of $JB "$BASE/public/pos.php")" "200"

echo "== T9. platform owner records a payment and suspends =="
curl -s -c $JP $BASE/platform/login.php > $TMP/pl.html
eq "owner signs in to the platform" \
   "$(curl -s -b $JP -c $JP -o /dev/null -w "%{redirect_url}" -d "csrf=$(tok < $TMP/pl.html)&username=owner&password=platform123" $BASE/platform/login.php)" \
   "$BASE/platform/index.php"
code_of $JP "$BASE/platform/index.php" > /dev/null
has "owner's list shows A" $TMP/page.html "Alpha Cafe $RUN"
has "owner's list shows B" $TMP/page.html "Bravo"
eq "a restaurant admin cannot open the platform" "$(code_of $JB "$BASE/platform/index.php")" "302"
form $JP "platform/company.php?id=$CA" "action=payment&id=$CA&plan_id=2&months=3&amount=30&method=mobile&reference=TX$RUN" > /dev/null
eq "payment makes A active on Basic" "$(sql "SELECT CONCAT(status,'|',plan_id) FROM companies WHERE id=$CA")" "active|2"
eq "paid for 3 months from today" "$(sql "SELECT paid_until = CURDATE() + INTERVAL 3 MONTH - INTERVAL 1 DAY FROM companies WHERE id=$CA")" "1"
eq "payment is on record" "$(sql "SELECT CONCAT(amount,'|',reference) FROM company_payments WHERE company_id=$CA")" "30.00|TX$RUN"
eq "A's cashier can sign in again" "$(login $JAC "cash.alpha$RUN" secret123)" "$BASE/public/pos.php"
code_of $JA "$BASE/admin/billing.php" > /dev/null
has "A's billing page lists the payment" $TMP/page.html "TX$RUN"
form $JP "platform/company.php?id=$CB" "action=update&id=$CB&name=Bravo Grill $RUN&plan_id=1&status=suspended&trial_ends_at=$(date +%Y-%m-%d)&paid_until=" > /dev/null
eq "B's open session is signed out when suspended" "$(code_of $JB "$BASE/public/pos.php")" "302"
login $JB "bashir.bravo$RUN" secret123 > /dev/null
has "B's admin is refused while suspended" $TMP/login_out.html "has been suspended"

echo
if [ $fail -eq 0 ]; then echo "ALL TENANCY CHECKS PASSED"; else echo "SOME TENANCY CHECKS FAILED"; exit 1; fi
