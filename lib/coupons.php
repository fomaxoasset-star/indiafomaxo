<?php
// Coupon codes: the store's own codes, and (when switched on in Settings) the codes made on fomaxo.in.

function coupon_norm($code)
{
    return substr(preg_replace('/[^A-Z0-9_\-]/', '', strtoupper(trim((string) $code))), 0, 30);
}

function store_coupon_label($r)
{
    if ($r['kind'] === 'free') {
        $p = $r['free_product_id'] ? q1('SELECT name, variant FROM products WHERE id = ?', [$r['free_product_id']]) : null;
        return 'Free ' . ($p ? trim(preg_replace('/^fomaxo\s+/i', '', $p['name']) . ' ' . $p['variant']) : 'gift');
    }
    return $r['kind'] === 'pct' ? rtrim(rtrim(number_format($r['amount'] / 100, 2), '0'), '.') . '% off' : inr((int) $r['amount']) . ' off';
}

function coupon_uses($code)
{
    return (int) q1("SELECT COUNT(*) AS n FROM sales WHERE coupon = ? AND status IN ('paid', 'pending')", [$code])['n'];
}

/**
 * Finds a coupon: the store's own codes first (made under Coupons), then - only if switched on in
 * Settings - the codes from fomaxo.in. Returns code, kind pct|aed|free (aed = a ₹ amount off), amount, min, label, free.
 */
function coupon_lookup($code, $phone = '', $storeId = null)
{
    $code = coupon_norm($code);
    if ($code === '') {
        fail('Type the coupon code.');
    }
    $r = q1('SELECT * FROM coupons WHERE code = ?', [$code]);
    if ($r) {
        if (!(int) $r['active']) {
            fail('This coupon code is switched off.');
        }
        if ($r['ends'] && $r['ends'] < now()) {
            fail('This coupon code has expired.');
        }
        if ($r['store_id'] && $storeId && (int) $r['store_id'] !== (int) $storeId) {
            fail('This coupon code is for another store.');
        }
        if ($r['max_uses'] !== null && coupon_uses($code) >= (int) $r['max_uses']) {
            fail('This coupon code has been fully used.');
        }
        $c = ['code' => $code, 'kind' => $r['kind'], 'amount' => $r['kind'] === 'pct' ? $r['amount'] / 100 : $r['amount'] / 100,
            'min' => $r['min_order'] / 100, 'label' => store_coupon_label($r), 'free' => null, 'source' => 'store'];
        if ($r['kind'] === 'free') {
            $p = q1('SELECT id, name, price, active FROM products WHERE id = ?', [(int) $r['free_product_id']]);
            if (!$p) {
                fail('The free gift of this coupon no longer exists.');
            }
            $c['free'] = ['sku' => '', 'product_id' => (int) $p['id'], 'name' => $p['name'], 'worth' => (int) $p['price']];
        }
        return $c;
    }
    if (!meta_get('set_web_coupons')) {
        fail('This coupon code is not valid.');
    }
    return web_coupon_lookup($code, $phone);
}

/** A code made on fomaxo.in/admin → Coupons (only when "Also accept fomaxo.in codes" is on). Read straight from the shop database. */
function web_coupon_lookup($code, $phone = '')
{
    require_once __DIR__ . '/website.php';
    $r = web_coupon_row($code, $phone);
    $c = [
        'code' => $code,
        'kind' => $r['kind'] === 'pct' ? 'pct' : ($r['kind'] === 'free' ? 'free' : 'aed'),
        'amount' => $r['kind'] === 'pct' ? (float) $r['value'] : ((int) $r['value']) / 100,
        'min' => ((int) $r['min_order']) / 100,
        'label' => '',
        'free' => null,
        'source' => 'web',
    ];
    if ($c['kind'] === 'free') {
        $sku = preg_replace('/[^a-z0-9-]/', '', strtolower((string) $r['free_id'])) . '-' . preg_replace('/[^a-z0-9]/', '', strtolower((string) $r['free_opt']));
        $p = q1('SELECT id, name, variant, price FROM products WHERE sku = ?', [$sku]);
        $c['free'] = ['sku' => $sku, 'product_id' => $p ? (int) $p['id'] : 0, 'name' => $p ? trim($p['name'] . ' ' . $p['variant']) : '', 'worth' => $p ? (int) $p['price'] : 0];
        $c['label'] = ($c['min'] > 0 ? 'Spend ' . inr((int) $r['min_order']) . ', get ' : 'Free ') . preg_replace('/^fomaxo\s+/i', '', $c['free']['name'] ?: 'gift') . ($c['min'] > 0 ? ' free' : '');
    } else {
        $c['label'] = $c['kind'] === 'pct' ? (int) $r['value'] . '% off' : inr((int) $r['value']) . ' off';
    }
    return $c;
}

/** Money off for a coupon on a bag worth $subtotal (fils). Free-gift coupons give 0 here (the gift line is free). */
function coupon_discount($c, $subtotal)
{
    if (!$c) {
        return 0;
    }
    if ($c['min'] > 0 && $subtotal < to_fils($c['min'])) {
        fail('Coupon ' . $c['code'] . ' needs a bag of at least ' . inr(to_fils($c['min'])) . '.');
    }
    if ($c['kind'] === 'free') {
        return 0;
    }
    if ($c['kind'] === 'pct') {
        return (int) round($subtotal * min(100, max(0, $c['amount'])) / 100);
    }
    return min($subtotal, to_fils($c['amount']));
}
