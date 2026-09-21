# Small Restaurant POS — Tea & Food

A point-of-sale system for a small tea shop / restaurant. Plain PHP 8 + MariaDB on
XAMPP, Bootstrap 5 vendored locally so the till keeps working with no internet.

## Getting started

1. Start **Apache** and **MySQL** in the XAMPP control panel.
2. Import the database (already done on this machine):
   ```
   C:\xampp\mysql\bin\mysql.exe -u root < database\schema.sql
   ```
   This drops and recreates the `smallrest` database, so only run it again for a
   clean reset.
3. Open <http://localhost/smallrest/>.

### Upgrading an existing install

**Never re-import `database/schema.sql` on a till that has sales** — it deletes
everything. Back up, then apply only the numbered migrations you have not run yet:

```
C:\xampp\mysql\bin\mysqldump.exe -u root smallrest > backups\smallrest_before_upgrade.sql
C:\xampp\mysql\bin\mysql.exe -u root smallrest < db_Migration\002_edit_orders.sql
```

| Migration | Adds |
|---|---|
| `002_edit_orders.sql` | `order_items.round`, `order_items.created_at`, `orders.updated_at` — needed to add items to an open order |
| `003_vat_rate.sql` | `orders.vat_rate` — the VAT rate each order was charged at, so reprinted receipts stay correct |
| `004_multi_tenant.sql` | companies, plans, payments and the platform owner; `company_id` on every business table. The existing restaurant becomes company 1 (`main`, Pro plan, no expiry) and every current login keeps working |

`backups/` is git-ignored: dumps contain password hashes and real sales.

Database credentials live at the top of [core/config.php](core/config.php); set
`APP_DEBUG` to `false` there before using this on a real till.

## Many restaurants on one install

Any restaurant can sign up at **/public/register.php** (linked from the login page).
It gets its own menu, staff, shifts, orders, expenses, settings and reports. No
restaurant can see another's data.

- **One shared database.** Every business table has a `company_id`, and every query
  filters on `company_id()`, the signed-in user's company.
  `tests/check_tenant_sql.php` fails the test run if a query on one of those tables
  forgets the filter.
- **Login is still username + password.** Usernames are unique across *all*
  restaurants, so the user row tells the system which restaurant they belong to.
  Sign-up and **Users** refuse a name another restaurant already has and suggest
  one ending in the restaurant's short name, for example `ali.hodan-cafe`.
- **Receipt numbers are per restaurant**: each one counts its own bills from 0001
  (`companies.order_seq`).
- **Plans and access.** A new restaurant starts a 14-day trial on the Trial plan
  (`TRIAL_PLAN_ID` and `TRIAL_DAYS` in `core/config.php`). Each plan caps active users
  and menu items. When a trial or subscription lapses, or the restaurant is
  suspended, staff are signed out at once. The restaurant's admin can still open
  **Billing** to see how to renew.
- **Platform owner panel** at **/platform/**, with its own login (`owner` /
  `platform123`; change it). From there you can list every restaurant, record a
  payment (this sets the restaurant Active and extends its paid-until date; an early
  renewal starts when the current period ends), change plan, status and dates,
  suspend a restaurant, reset a user's password, and edit plans. Payments are
  recorded by hand. There is no online gateway.

## Default logins

These belong to the demo restaurant (company 1). Change these passwords under
**Users** as soon as you sign in.

| Username | Password     | Role    | Can do                                        |
|----------|--------------|---------|-----------------------------------------------|
| admin    | `admin123`   | Admin   | Everything                                    |
| cashier  | `cashier123` | Cashier | POS, payments, cash drawer, expenses, sales   |
| waiter   | `waiter123`  | Waiter  | Takes orders to the kitchen; cannot take money |
| kitchen  | `kitchen123` | Kitchen | The preparation screen only                    |

## How the day runs

1. **Cashier opens the drawer** — *Cash Drawer* → enter the opening float. No
   payment can be taken until a shift is open, so every cash sale belongs to a
   drawer that gets counted.
2. **Orders are rung up** — *POS Terminal*. Tap items, set dine-in or takeaway and
   a table label, then either:
   - **Charge & Print** — takes payment now and opens the receipt, or
   - **Send to Kitchen (unpaid)** — parks the order for a waiter-served table.
3. **The kitchen cooks** — *Kitchen* lists every unserved line, oldest first, and
   turns red past 10 minutes. Items marked "send to the kitchen screen = off"
   (bottled drinks) never appear there.
4. **The table orders more** — *Open Orders* → **Add items** reopens the order on
   the POS. What is already on it is shown greyed with its kitchen status; tap new
   items and press **Add to order** (or **Add & Charge**). The additions go to the
   kitchen as *Round 2*, *Round 3*… on the same bill, and the kitchen times them
   from when they were added, not from when the table sat down.
   - An item can be **reduced or removed only while the kitchen has not started it**
     (and bottled drinks until the order is paid). Anything cooking or served is
     *locked*; only an admin void removes it.
   - The last item cannot be removed — void the order instead.
   - A paid or voided order cannot be changed.
5. **Unpaid orders are settled** — *Open Orders* → **Take payment**. The change due
   is calculated as the cashier types. If two cashiers press pay on the same bill,
   only the first is recorded.
6. **Expenses go in as they happen** — *Expenses*. Anything paid from the till is
   attached to the open shift.
7. **Cashier closes the drawer** — *Cash Drawer* → count the cash. The system
   compares it against `float + cash sales − drawer expenses` and records the
   variance. Then *Reports → Daily transactions → Thermal slip* prints the day.

## Reports

*Reports* has four tabs. Every tab prints on A4 and has **Download CSV** (opens in
Excel, Somali names and symbols intact).

| Tab | Who | What it answers |
|---|---|---|
| **Daily transactions** | admin, cashier | Everything on one day in time order — sales, bills still unpaid, voids, expenses — with cash / mobile / card totals, net cash for the drawer, and each shift's float, expected, counted and variance. **Thermal slip** prints an 80mm end-of-day summary from the same figures. |
| **Unpaid bills** | admin, cashier | Every bill not yet paid, from any day: how long it has waited, grouped *under 1 hour · earlier today · 1–3 days · over 3 days*, and the total money still owed. Each row links to the bill, *Add items* and *Take payment*. |
| **Financial (P&L)** | admin | A profit-and-loss statement for any range: gross sales − discounts = net sales − cost of goods = gross profit − expenses (by category) = net profit, with margins. Money collected by channel, drawer differences, voids, unpaid bills, and P&L day by day. |
| **Sales overview** | admin | Sales-per-day chart, best sellers, category and payment mix, orders per staff member. |

Definitions used by the Financial tab (paid orders, by the day they were paid):
*net sales* = subtotal − discount, **before VAT**; *VAT collected* is shown
separately and is not income; *cost of goods* uses each item's cost price **at the
moment it was sold**, so keep cost prices in *Menu Items* up to date or profit is
overstated.

## VAT

Set the rate under *Settings → VAT* as a **percentage: type `5` for 5%**. (A value
under 1, like `0.05`, is read as 0.05% and the screen warns you.) VAT is **added on
top** of menu prices:

```
  Canjeero with Tea          $1.50
  Sambusa                    $0.50
  Total before VAT           $2.00
  VAT 5%                     $0.10
  TOTAL incl. VAT            $2.10   <- what the customer pays, and the *789* amount
```

- VAT is charged on the amount **after** any discount, worked in whole cents; a half
  cent rounds up ($1.50 at 5% = 7.5¢ → $0.08). The POS screen uses the same rule, so
  the screen and the saved bill never differ.
- Every order stores the rate it was charged at. Changing the rate **re-prices unpaid
  bills** (they are charged at the rate in force when paid); paid receipts keep
  their original rate forever.
- Every report splits the money three ways: **sales before VAT** (the shop's income,
  used for all profit figures), **VAT collected** (the government's), and **total
  collected** (what came into the drawer / mobile / card). The Financial tab has a
  **VAT account** — sales taxed and VAT per rate — for the VAT return.

## Layout

```
core/          config, session/auth gates, CSRF, helpers (incl. the order-total
               and report logic), header/sidebar/footer
admin/         dashboard, menu items, categories, users, expenses, cash drawer,
               sales log, settings, billing, and the reports:
               report_daily.php · report_daily_slip.php · report_unpaid.php ·
               report_financial.php · reports.php (overview) · _report_tabs.php
public/        login, restaurant sign-up (register.php), POS terminal (+ ?order=ID
               edit mode), open orders, kitchen, receipts, payment/void handlers
platform/      the platform owner's panel: restaurants, payments, plans
public/api/    order_save.php (create / add a round / charge),
               order_line_update.php (reduce or remove an unstarted line)
assets/        app.css, pos.js, vendored Bootstrap 5.3.3
database/      schema.sql — fresh install only (drops the database)
db_Migration/  numbered upgrade scripts for a live install
tests/         end-to-end suites + run_all.sh
```

## Running the tests

```
bash tests/run_all.sh
```

This never touches the live till. It builds a throwaway `smallrest_test` database
from `database/schema.sql` (which also proves a fresh install works), serves the
app from PHP's built-in server on port 8099 pointed at that database, runs the
tenant SQL guard and all five suites, then drops it. `core/config.php` only accepts that database override
under the built-in server, so Apache always uses the real `smallrest`.

| Suite | Covers |
|---|---|
| `e2e_core.sh` | login, roles, POS order/pay, server-side pricing, CSRF, receipts, kitchen, reports |
| `e2e_drawer.sh` | settling a held order, double-pay refusal, drawer expense, exact drawer close |
| `e2e_merchant.sh` | the `*789*` dial code on bills and receipts, settings sanitising |
| `e2e_orders_reports.sh` | adding rounds, remove/reduce rules, Add & Charge, kitchen timing, and the Daily / Unpaid / Financial figures checked against direct SQL sums |
| `e2e_tenancy.sh` | two restaurants sign up; neither can read or change the other's orders, menu, expenses, users or settings (pages and APIs); global usernames; per-restaurant receipt numbers; plan limits; System Reset scope; trial expiry, payment and suspension |
| `check_tenant_sql.php` | static check: every SQL string on a restaurant's table mentions `company_id`, unless it is marked `// tenant-global: <why>` |

Run them after every change.

## Design notes

- **Prices are never trusted from the browser.** `public/api/order_save.php`
  re-reads every price and cost from the database; the POS only sends item ids and
  quantities.
- **Sold items are not deleted.** Each order line stores the item name, price and
  cost as they were at the moment of sale, so editing the menu or hiding an item
  never rewrites history. Deleting an item that has sold hides it instead.
- **Voiding, not deleting.** A voided order keeps its lines for the audit trail and
  drops out of every sales figure.
- Every state-changing request is CSRF-checked and every query is a prepared
  statement.
- Money is `DECIMAL(10,2)` in the database and rounded server-side.
- **One place computes order totals**: `recalc_order_totals()` in
  `core/helper_functions.php` re-adds the saved lines after every create, added
  round or removed line, inside the same transaction. Adding to an order locks its
  row (`SELECT … FOR UPDATE`), so two waiters adding at once cannot lose a round.

## Settings

*Settings* (admin) controls the shop name, tagline, address, phone, currency
symbol, tax percentage and receipt footer. Tax applies to new orders only —
changing the rate never alters orders already saved. Set tax to `0` to sell
tax-free. The whole form saves in one transaction: if any value is rejected,
nothing is written and the old settings stand.

## Mobile money — the dial code on bills and receipts

Enter your merchant number under *Settings → Mobile money merchant account* and
every printout carries a dial code the customer can enter to pay you:

```
*789*616123456*6.00#
```

- **Prefix** defaults to `*789*` (EVC Plus). Change it if your provider differs.
- **Merchant number** is stripped to digits, so `616-123 456` is stored as
  `616123456`. Leave it blank and no dial block prints at all.
- **Amount** is the order total with two decimals and no thousands separator —
  a dial string has to be dialable.
- The **unpaid bill** always shows it, headed `PAY BY MOBILE MONEY`.
- The **paid receipt** shows it headed `MOBILE MONEY ACCOUNT` only while
  *"Also print the dial code on paid receipts"* is ticked. Untick it if printing
  a dial code on a cash-paid receipt confuses customers.
- On screen, a **Dial on this device** link appears under the code (a `tel:` URI
  with `#` percent-encoded). It never prints.

The block is boxed with a dashed border rather than a filled background —
thermal print heads smear solid blocks — and long numbers wrap inside a 58mm
roll instead of running off the edge.

## Not included in this build

Table map / dine-in floor plan, inventory and stock deduction, and customer
accounts. Order lines carry a free-text table label instead of a table map.
