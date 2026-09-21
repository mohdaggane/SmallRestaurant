// Screenshot matrix: every key screen at phone, iPad portrait/landscape,
// counter PC and wall-screen sizes, driven through the Edge already installed.
//
//   bash tests/run_screens.sh <output-dir>
//
// Runs against the throwaway test server from tests/_env.sh (BASE), seeds a few
// orders through the real API, then captures each screen and reports layout
// problems it can measure: sideways page scroll anywhere, and page scroll on
// the counter-PC POS (which must fit one screen).

import { chromium } from 'playwright-core';
import { mkdirSync } from 'node:fs';
import { join } from 'node:path';

const BASE = process.env.BASE || 'http://localhost:8099/smallrest';
const OUT  = process.argv[2] || 'screens';
const ONLY = (process.env.ONLY || '').split(',').filter(Boolean);   // e.g. ONLY=pos,kitchen
mkdirSync(OUT, { recursive: true });

const VIEWPORTS = {
  'phone':   { width: 390,  height: 844,  touch: true },
  'ipad-p':  { width: 820,  height: 1180, touch: true },
  'ipad-l':  { width: 1180, height: 820,  touch: true },
  'counter': { width: 1366, height: 768,  touch: false },
  'wall':    { width: 1920, height: 1080, touch: false },
};
const USERS = {
  admin:   ['admin', 'admin123'],
  cashier: ['cashier', 'cashier123'],
  waiter:  ['waiter', 'waiter123'],
  kitchen: ['kitchen', 'kitchen123'],
};

const problems = [];
const browser  = await chromium.launch({ channel: 'msedge', headless: true });

async function contextFor(vp) {
  const v = VIEWPORTS[vp];
  return browser.newContext({
    viewport: { width: v.width, height: v.height },
    hasTouch: v.touch,
    isMobile: v.touch,
    deviceScaleFactor: 1,
  });
}

async function login(ctx, role) {
  const page = await ctx.newPage();
  await page.goto(`${BASE}/public/login.php`);
  await page.fill('input[name="username"]', USERS[role][0]);
  await page.fill('input[name="password"]', USERS[role][1]);
  await Promise.all([page.waitForNavigation(), page.press('input[name="password"]', 'Enter')]);
  return page;
}

async function api(page, endpoint, body) {
  return page.evaluate(async ([url, data]) => {
    const r = await fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': window.POS.csrf },
      body: JSON.stringify(data),
    });
    return r.json();
  }, [`${BASE}/public/api/${endpoint}`, body]);
}

// ------------------------------------------------------------------ seed data
async function seed() {
  const ctx  = await contextFor('counter');
  const page = await login(ctx, 'cashier');
  await page.goto(`${BASE}/admin/shifts.php`);
  if (await page.locator('input[name="opening_float"]').count()) {
    await page.fill('input[name="opening_float"]', '50');
    await Promise.all([page.waitForNavigation(), page.locator('input[name="opening_float"]').press('Enter')]);
  }
  await page.goto(`${BASE}/public/pos.php`);
  const t3 = await api(page, 'order_save.php', { action: 'hold', table_label: 'T3',
    lines: [{ id: 1, qty: 2 }, { id: 11, qty: 1 }, { id: 15, qty: 3 }] });
  await api(page, 'order_save.php', { action: 'hold', order_id: t3.order_id, lines: [{ id: 8, qty: 1 }] });
  await api(page, 'order_save.php', { action: 'hold', table_label: 'T5', lines: [{ id: 12, qty: 2 }, { id: 2, qty: 2 }] });
  await api(page, 'order_save.php', { action: 'hold', order_type: 'takeaway', table_label: 'Ayaan',
    lines: [{ id: 13, qty: 1 }, { id: 7, qty: 2 }] });
  await api(page, 'order_save.php', { action: 'pay', payment_method: 'cash', paid_amount: 20,
    lines: [{ id: 9, qty: 1 }, { id: 5, qty: 2 }, { id: 3, qty: 1 }] });
  await api(page, 'order_save.php', { action: 'pay', payment_method: 'mobile', paid_amount: 10,
    lines: [{ id: 14, qty: 1 }, { id: 6, qty: 2 }] });
  await ctx.close();
  return { t3: t3.order_id };
}

// ------------------------------------------------------------------ capture
async function measure(page, vp, name, { mustFitScreen = false } = {}) {
  const m = await page.evaluate(() => ({
    sw: document.documentElement.scrollWidth,
    sh: document.documentElement.scrollHeight,
    iw: window.innerWidth,
    ih: window.innerHeight,
  }));
  if (m.sw > m.iw + 1) {
    problems.push(`${vp}/${name}: page scrolls sideways (${m.sw}px content in ${m.iw}px screen)`);
  }
  if (mustFitScreen && m.sh > m.ih + 1) {
    problems.push(`${vp}/${name}: page scrolls vertically (${m.sh}px in ${m.ih}px) — counter POS must fit one screen`);
  }
}

async function shot(page, vp, name, opts = {}) {
  if (ONLY.length && !ONLY.includes(name)) return;
  await page.waitForTimeout(250);   // let transitions settle
  await measure(page, vp, name, opts);
  await page.screenshot({ path: join(OUT, `${vp}__${name}.png`), fullPage: !opts.viewportOnly });
}

const { t3 } = await seed();

for (const vp of Object.keys(VIEWPORTS)) {
  const isCounter = vp === 'counter' || vp === 'wall';

  // Login (signed out)
  {
    const ctx = await contextFor(vp);
    const page = await ctx.newPage();
    await page.goto(`${BASE}/public/login.php`);
    await shot(page, vp, 'login', { viewportOnly: true });
    await ctx.close();
  }

  // Cashier: POS, POS with a cart, payment, edit mode, open orders, bill
  {
    const ctx = await contextFor(vp);
    const page = await login(ctx, 'cashier');

    await page.goto(`${BASE}/public/pos.php`);
    await shot(page, vp, 'pos', { viewportOnly: true, mustFitScreen: isCounter });

    for (const item of ['Rice with Chicken', 'Shaah Cadeys (Milk Tea)', 'Sambusa', 'Sambusa', 'Fresh Mango Juice']) {
      await page.locator('[data-item-name]', { hasText: item }).first().click().catch(async () => {
        await page.getByText(item, { exact: true }).first().click();
      });
    }
    await shot(page, vp, 'pos-cart', { viewportOnly: true, mustFitScreen: isCounter });

    // Handheld layouts: open the ticket drawer / sheet if the page has one.
    const ticketToggle = page.locator('[data-test="open-ticket"]');
    if (await ticketToggle.isVisible().catch(() => false)) {
      await ticketToggle.click();
      await shot(page, vp, 'pos-ticket', { viewportOnly: true });
      await page.keyboard.press('Escape');
    }

    // Payment modal (new UI only — the old UI's button would submit the order).
    const payBtn = page.locator('[data-test="open-pay"]').first();
    if (await payBtn.count()) {
      if (!(await payBtn.isVisible())) {
        const t = page.locator('[data-test="open-ticket"]');
        if (await t.isVisible().catch(() => false)) await t.click();
      }
      await payBtn.click();
      await shot(page, vp, 'pos-pay', { viewportOnly: true });
      await page.keyboard.press('Escape');
    }

    await page.goto(`${BASE}/public/pos.php?order=${t3}`);
    await shot(page, vp, 'pos-edit', { viewportOnly: true, mustFitScreen: isCounter });

    await page.goto(`${BASE}/public/orders.php`);
    await shot(page, vp, 'orders');

    await page.goto(`${BASE}/public/receipt.php?id=${t3}`);
    await shot(page, vp, 'bill');
    await ctx.close();
  }

  // Waiter POS (no payment)
  {
    const ctx = await contextFor(vp);
    const page = await login(ctx, 'waiter');
    await page.goto(`${BASE}/public/pos.php`);
    await shot(page, vp, 'pos-waiter', { viewportOnly: true, mustFitScreen: isCounter });
    await ctx.close();
  }

  // Kitchen
  {
    const ctx = await contextFor(vp);
    const page = await login(ctx, 'kitchen');
    await page.goto(`${BASE}/public/kitchen.php`);
    await shot(page, vp, 'kitchen', { viewportOnly: vp === 'wall' });
    await ctx.close();
  }

  // Admin back office
  {
    const ctx = await contextFor(vp);
    const page = await login(ctx, 'admin');
    const pages = {
      'dashboard':       'admin/index.php',
      'report-daily':    'admin/report_daily.php',
      'report-unpaid':   'admin/report_unpaid.php',
      'report-financial':'admin/report_financial.php',
      'report-overview': 'admin/reports.php',
      'sales':           'admin/sales.php',
      'menu-items':      'admin/menu_items.php',
      'users':           'admin/users.php',
      'settings':        'admin/settings.php',
      'drawer':          'admin/shifts.php',
      'expenses':        'admin/expenses.php',
    };
    for (const [name, path] of Object.entries(pages)) {
      await page.goto(`${BASE}/${path}`);
      await shot(page, vp, name);
    }
    // Phone/tablet navigation drawer, if the shell has one.
    const menuBtn = page.locator('[data-test="open-nav"]');
    if (await menuBtn.isVisible().catch(() => false)) {
      await page.goto(`${BASE}/admin/index.php`);
      await menuBtn.click();
      await shot(page, vp, 'nav-drawer', { viewportOnly: true });
    }
    await ctx.close();
  }
  console.log(`captured ${vp}`);
}

await browser.close();

console.log('');
if (problems.length) {
  console.log(`LAYOUT PROBLEMS (${problems.length}):`);
  for (const p of problems) console.log('  - ' + p);
  process.exitCode = 1;
} else {
  console.log('LAYOUT CHECKS PASSED: no sideways scroll; counter POS fits one screen');
}
