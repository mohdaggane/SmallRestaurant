/* ============================================================
   POS terminal behaviour: item grid, cart maths, order submit.
   Configuration arrives from pos.php as window.POS.
   ============================================================ */
(function () {
    'use strict';

    var cfg  = window.POS;
    var cart = [];           /* [{id, name, price, cost, prep, qty}] */
    var activeCat = 'all';

    var $ = function (id) { return document.getElementById(id); };

    /* Translated UI text from pos.php; {name} placeholders are filled in. */
    function T(key, vars) {
        var s = (cfg.i18n && cfg.i18n[key]) || key;
        Object.keys(vars || {}).forEach(function (k) { s = s.split('{' + k + '}').join(vars[k]); });
        return s;
    }

    function money(n) {
        return cfg.currency + Number(n || 0).toFixed(2);
    }

    /* -------------------------------------------------- menu grid */
    function visibleItems() {
        var term = ($('itemSearch').value || '').trim().toLowerCase();
        return cfg.items.filter(function (it) {
            var catOk = activeCat === 'all' || String(it.category_id) === String(activeCat);
            var hitOk = term === '' || it.name.toLowerCase().indexOf(term) !== -1;
            return catOk && hitOk;
        });
    }

    function renderItems() {
        var list = visibleItems();
        var grid = $('itemGrid');
        grid.innerHTML = '';

        list.forEach(function (it) {
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'item-btn';
            b.dataset.id = it.id;

            var nm = document.createElement('span');
            nm.className = 'nm';
            nm.textContent = it.name;

            var pr = document.createElement('span');
            pr.className = 'pr';
            pr.textContent = money(it.price);

            b.appendChild(nm);
            b.appendChild(pr);
            b.addEventListener('click', function () { addToCart(it.id); });
            grid.appendChild(b);
        });

        $('noItems').classList.toggle('d-none', list.length > 0);
    }

    /* -------------------------------------------------- cart */
    function addToCart(itemId) {
        var found = cart.find(function (l) { return String(l.id) === String(itemId); });
        if (found) {
            found.qty += 1;
        } else {
            var it = cfg.items.find(function (x) { return String(x.id) === String(itemId); });
            if (!it) { return; }
            cart.push({
                id:    it.id,
                name:  it.name,
                price: Number(it.price),
                cost:  Number(it.cost_price),
                prep:  Number(it.needs_prep) === 1,
                qty:   1
            });
        }
        renderCart();
    }

    function changeQty(itemId, delta) {
        var i = cart.findIndex(function (l) { return String(l.id) === String(itemId); });
        if (i === -1) { return; }
        cart[i].qty += delta;
        if (cart[i].qty < 1) { cart.splice(i, 1); }
        renderCart();
    }

    /* -------------------------------------------------- existing order (edit mode) */
    var edit     = cfg.editOrder || null;
    var existing = edit ? edit.lines : [];

    var STATUS_LABEL = { pending: T('st_pending'), preparing: T('st_preparing'), served: T('st_served') };

    function existingSubtotal() {
        return existing.reduce(function (s, l) { return s + Number(l.line_total); }, 0);
    }

    function renderExisting() {
        var box = $('existingLines');
        if (!box) { return; }
        box.innerHTML = '';

        existing.forEach(function (l) {
            var row = document.createElement('div');
            row.className = 'cart-line existing';

            var nm = document.createElement('div');
            nm.className = 'nm';
            nm.appendChild(document.createTextNode(l.qty + ' × ' + l.name));
            nm.appendChild(document.createElement('br'));
            var sm = document.createElement('small');
            sm.textContent = (l.round > 1 ? T('round', { n: l.round }) + ' · ' : '') + (STATUS_LABEL[l.kitchen_status] || l.kitchen_status);
            nm.appendChild(sm);
            row.appendChild(nm);

            if (l.removable) {
                var less = document.createElement('button');
                less.type = 'button';
                less.className = 'qty-btn';
                less.title = T('reduce_one');
                less.textContent = '-';
                less.addEventListener('click', function () { updateLine(l, l.qty - 1); });
                row.appendChild(less);

                var drop = document.createElement('button');
                drop.type = 'button';
                drop.className = 'qty-btn text-danger';
                drop.title = T('remove');
                drop.textContent = '×';
                drop.addEventListener('click', function () { updateLine(l, 0); });
                row.appendChild(drop);
            } else {
                var lock = document.createElement('small');
                lock.className = 'text-muted';
                lock.title = T('locked_help');
                lock.textContent = T('locked');
                row.appendChild(lock);
            }

            var lt = document.createElement('span');
            lt.className = 'lt';
            lt.textContent = money(l.line_total);
            row.appendChild(lt);

            box.appendChild(row);
        });
    }

    /* Reduce (qty > 0) or remove (qty = 0) a line already on the order. */
    function updateLine(line, qty) {
        var msg = qty === 0
            ? T('confirm_remove', { name: line.name })
            : T('confirm_reduce', { name: line.name, n: qty });
        if (!confirm(msg)) { return; }

        fetch(cfg.lineUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
            body:    JSON.stringify({ order_id: edit.id, line_id: line.id, qty: qty })
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            if (!res.ok) {
                alert(res.error || T('change_failed'));
                return;
            }
            existing = res.lines;
            renderExisting();
            renderCart();
            toast(qty === 0 ? T('removed', { name: line.name }) : T('reduced', { name: line.name, n: qty }));
        })
        .catch(function () {
            alert(T('no_server'));
        });
    }

    function totals() {
        var sub = existingSubtotal() + cart.reduce(function (s, l) { return s + l.price * l.qty; }, 0);
        var disc = Math.min(Math.max(Number($('discount').value) || 0, 0), sub);
        var net  = round2(sub - disc);
        // VAT on top, in whole cents — the same rule as vat_amount() in PHP,
        // so the screen and the saved bill never differ by a cent.
        var tax  = Math.round(Math.round(net * 100) * (Number(cfg.taxPercent) || 0) / 100) / 100;
        return {
            sub:   round2(sub),
            disc:  round2(disc),
            net:   net,
            tax:   tax,
            total: round2(net + tax)
        };
    }

    function round2(n) { return Math.round((Number(n) + Number.EPSILON) * 100) / 100; }

    function renderCart() {
        var box = $('cartLines');
        box.innerHTML = '';

        if (cart.length === 0) {
            var p = document.createElement('div');
            p.className = 'empty-cart';
            p.textContent = edit
                ? T('tap_add')
                : T('tap_start');
            box.appendChild(p);
        }

        cart.forEach(function (l) {
            var row = document.createElement('div');
            row.className = 'cart-line';

            var nm = document.createElement('div');
            nm.className = 'nm';
            nm.innerHTML = '';
            nm.appendChild(document.createTextNode(l.name));
            var sm = document.createElement('small');
            sm.textContent = ' ' + T('each', { price: money(l.price) });
            nm.appendChild(document.createElement('br'));
            nm.appendChild(sm);

            var minus = document.createElement('button');
            minus.type = 'button';
            minus.className = 'qty-btn';
            minus.textContent = '-';
            minus.addEventListener('click', function () { changeQty(l.id, -1); });

            var qty = document.createElement('span');
            qty.style.minWidth = '22px';
            qty.style.textAlign = 'center';
            qty.textContent = l.qty;

            var plus = document.createElement('button');
            plus.type = 'button';
            plus.className = 'qty-btn';
            plus.textContent = '+';
            plus.addEventListener('click', function () { changeQty(l.id, 1); });

            var lt = document.createElement('span');
            lt.className = 'lt';
            lt.textContent = money(l.price * l.qty);

            row.appendChild(nm);
            row.appendChild(minus);
            row.appendChild(qty);
            row.appendChild(plus);
            row.appendChild(lt);
            box.appendChild(row);
        });

        var t = totals();
        $('sumSub').textContent = money(t.sub);
        $('sumTotal').textContent = money(t.total);
        if ($('sumNet')) { $('sumNet').textContent = money(t.net); }
        if ($('sumTax')) { $('sumTax').textContent = money(t.tax); }
        renderChange();
    }

    function renderChange() {
        if (!cfg.canCharge || !$('changeDue')) { return; }
        var paid = Number($('paidAmount').value) || 0;
        var due  = paid - totals().total;
        $('changeDue').textContent = paid > 0 ? money(due > 0 ? due : 0) : '—';
        $('changeDue').style.color = (paid > 0 && due < 0) ? 'var(--bad)' : 'var(--ok)';
    }

    /* -------------------------------------------------- submit */
    function submitOrder(action) {
        if (cart.length === 0) {
            alert(edit ? T('tap_first') : T('empty'));
            return;
        }

        var t = totals();
        var paid = Number($('paidAmount') ? $('paidAmount').value : 0) || 0;

        if (action === 'pay') {
            if (!cfg.hasShift) {
                alert(T('open_shift'));
                return;
            }
            if (paid + 0.001 < t.total) {
                alert(T('underpaid', { total: money(t.total) }));
                return;
            }
        }

        var payload = {
            action:      action,
            order_type:  $('orderType').value,
            table_label: $('tableLabel').value,
            discount:    t.disc,
            payment_method: $('payMethod') ? $('payMethod').value : 'cash',
            paid_amount: paid,
            lines: cart.map(function (l) {
                return { id: l.id, qty: l.qty, note: '' };
            })
        };
        if (edit) { payload.order_id = edit.id; }

        setBusy(true);
        fetch(cfg.saveUrl, {
            method:  'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': cfg.csrf },
            body:    JSON.stringify(payload)
        })
        .then(function (r) { return r.json(); })
        .then(function (res) {
            setBusy(false);
            if (!res.ok) {
                alert(res.error || T('save_failed'));
                return;
            }
            if (action === 'pay') {
                window.open(cfg.receiptUrl + '?id=' + res.order_id, '_blank');
            }
            if (edit) {
                // Round saved; back to the open orders list where it now shows.
                window.location.href = cfg.ordersUrl;
                return;
            }
            resetCart();
            toast(action === 'pay'
                ? T('paid_saved', { no: res.order_no })
                : T('sent_kitchen', { no: res.order_no }));
        })
        .catch(function () {
            setBusy(false);
            alert(T('no_server'));
        });
    }

    function setBusy(on) {
        ['btnCharge', 'btnHold'].forEach(function (id) {
            var b = $(id);
            if (b) { b.disabled = on || (id === 'btnCharge' && !cfg.hasShift); }
        });
    }

    function resetCart() {
        cart = [];
        // In edit mode "Clear" only drops the new items; the order's own
        // table and discount stay as they are.
        if (!edit) {
            $('discount').value = 0;
            $('tableLabel').value = '';
        }
        if ($('paidAmount')) { $('paidAmount').value = ''; }
        renderCart();
    }

    function toast(msg) {
        var box = document.createElement('div');
        box.className = 'alert alert-success position-fixed shadow';
        box.style.cssText = 'right:16px;bottom:16px;z-index:2000;max-width:320px;';
        box.textContent = msg;
        document.body.appendChild(box);
        setTimeout(function () { box.remove(); }, 3500);
    }

    /* -------------------------------------------------- wiring */
    document.querySelectorAll('.cat-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.cat-tab').forEach(function (t) { t.classList.remove('active'); });
            tab.classList.add('active');
            activeCat = tab.dataset.cat;
            renderItems();
        });
    });

    $('itemSearch').addEventListener('input', renderItems);
    $('itemSearch').addEventListener('keydown', function (ev) {
        /* Enter adds the item when the search narrows to a single match. */
        if (ev.key === 'Enter') {
            var list = visibleItems();
            if (list.length === 1) {
                addToCart(list[0].id);
                ev.target.value = '';
                renderItems();
            }
        }
    });

    $('discount').addEventListener('input', renderCart);
    if ($('paidAmount')) { $('paidAmount').addEventListener('input', renderChange); }
    $('clearCart').addEventListener('click', function () {
        if (cart.length === 0 || confirm(T('confirm_clear'))) { resetCart(); }
    });
    $('btnHold').addEventListener('click', function () { submitOrder('hold'); });
    if ($('btnCharge')) {
        $('btnCharge').addEventListener('click', function () { submitOrder('pay'); });
    }

    renderExisting();
    renderItems();
    renderCart();
}());
