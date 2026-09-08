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

Database credentials live at the top of [core/config.php](core/config.php); set
`APP_DEBUG` to `false` there before using this on a real till.

## Default logins

Change these passwords under **Users** as soon as you sign in.

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
4. **Unpaid orders are settled** — *Open Orders* → **Take payment**. The change due
   is calculated as the cashier types.
5. **Expenses go in as they happen** — *Expenses*. Anything paid from the till is
   attached to the open shift.
6. **Cashier closes the drawer** — *Cash Drawer* → count the cash. The system
   compares it against `float + cash sales − drawer expenses` and records the
   variance.

## What the reports tell you

*Reports* (admin) covers any date range:

- Gross sales, average order, gross profit, net profit after expenses
- Sales per day as a bar chart, with the best day highlighted
- Best-selling items with revenue and profit per item
- Sales by category, payment mix, and orders taken per staff member
- Expenses by category

Profit uses the **cost price** on each menu item, so keep those up to date or the
profit columns are just revenue.

## Layout

```
core/       config, session/auth gates, CSRF, helpers, header/sidebar/footer
admin/      dashboard, menu items, categories, users, expenses,
            cash drawer, sales log, reports, settings
public/     login, POS terminal, open orders, kitchen, receipts,
            payment/void handlers, api/order_save.php
assets/     app.css, pos.js, vendored Bootstrap 5.3.3
database/   schema.sql (structure + seed menu and logins)
```

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
