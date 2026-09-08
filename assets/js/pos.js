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

    function totals() {
        var sub = cart.reduce(function (s, l) { return s + l.price * l.qty; }, 0);
        var disc = Math.min(Math.max(Number($('discount').value) || 0, 0), sub);
        var tax = (sub - disc) * (Number(cfg.taxPercent) || 0) / 100;
        return {
            sub:   round2(sub),
            disc:  round2(disc),
            tax:   round2(tax),
            total: round2(sub - disc + tax)
        };
    }

    function round2(n) { return Math.round((Number(n) + Number.EPSILON) * 100) / 100; }

    function renderCart() {
        var box = $('cartLines');
        box.innerHTML = '';

        if (cart.length === 0) {
            var p = document.createElement('div');
            p.className = 'empty-cart';
            p.textContent = 'Tap a menu item to start an order.';
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
            sm.textContent = ' ' + money(l.price) + ' each';
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
            alert('The order is empty.');
            return;
        }

        var t = totals();
        var paid = Number($('paidAmount') ? $('paidAmount').value : 0) || 0;

        if (action === 'pay') {
            if (!cfg.hasShift) {
                alert('Open your cash drawer shift before taking payment.');
                return;
            }
            if (paid + 0.001 < t.total) {
                alert('Amount paid is less than the total (' + money(t.total) + ').');
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
                alert(res.error || 'The order could not be saved.');
                return;
            }
            if (action === 'pay') {
                window.open(cfg.receiptUrl + '?id=' + res.order_id, '_blank');
            }
            resetCart();
            toast(action === 'pay'
                ? 'Paid. Order ' + res.order_no + ' saved.'
                : 'Order ' + res.order_no + ' sent to the kitchen.');
        })
        .catch(function () {
            setBusy(false);
            alert('Could not reach the server. Check that Apache and MySQL are running.');
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
        $('discount').value = 0;
        $('tableLabel').value = '';
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
        if (cart.length === 0 || confirm('Clear the current order?')) { resetCart(); }
    });
    $('btnHold').addEventListener('click', function () { submitOrder('hold'); });
    if ($('btnCharge')) {
        $('btnCharge').addEventListener('click', function () { submitOrder('pay'); });
    }

    renderItems();
    renderCart();
}());
