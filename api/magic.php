<?php
declare(strict_types=1);
/* FOMAXO India — Razorpay Magic Checkout (Admin → Settings → Magic Checkout). See api/magic-lib.php.
   From the website:
   POST {action:"create", lines, sid, vid}   → prices the bag here, creates a Magic Checkout order at Razorpay, returns what magic-checkout.js needs
   POST {action:"verify", razorpay_*}        → checks Razorpay's signature, reads the order back from Razorpay and saves it (paid or COD)
   Razorpay calls these itself (addresses pasted in the Razorpay Dashboard → Magic Checkout → Setup & Settings):
   ?shipping                                  → can we deliver to this PIN, is COD allowed and its fee (Shipping Setup → API)
   ?promotions                                → coupons to list in Magic Checkout (none: shoppers type their code)
   ?apply                                     → checks a coupon code against the bag, the same rules as our checkout
   ?abandoned&k=…                             → Abandoned webhook: who left Magic Checkout, kept in Admin → Left at checkout
   GET ?status                                → set-up check (never shows a key) */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/magic-lib.php';

[$KEY_ID, $SECRET, , $TEST] = magic_keys();
$in = input();

if (isset($_GET['status'])) {
  $mode = shop_magic();
  out(['magic' => ['off' => 'Off (our own checkout page)', 'admin' => 'On only in a browser signed in to the admin', 'on' => 'On for every shopper'][$mode],
       'keys' => $KEY_ID && $SECRET ? 'found' : 'NOT FOUND — see api/razorpay-config.example.php', 'mode' => !$KEY_ID ? '-' : ($TEST ? 'test (no real money)' : 'live')]);
}
if (!$KEY_ID || !$SECRET) { error_log('FOMAXO Magic: keys not found'); fail('Online payment is not set up yet. Please try again later or order on WhatsApp.', 503); }

/* ---------------- Razorpay asks: can we deliver here, and is cash on delivery allowed ---------------- */
if (isset($_GET['shipping'])) {
  $m = magic_row((string)($in['order_id'] ?? '')) ?? magic_row_by_rzp((string)($in['razorpay_order_id'] ?? ''));
  if (!$m) fail('Unknown order', 404);
  [$cod, $fee] = magic_cod($m['bag'], $m['coupon'] !== '' ? (int)(magic_coupon($m['bag'], $m['coupon'], (string)($in['contact'] ?? ''))['value'] ?? 0) : 0);
  $out = [];
  foreach (array_slice((array)($in['addresses'] ?? []), 0, 20) as $a) {
    if (!is_array($a)) continue;
    $zip = (string)($a['zipcode'] ?? ''); $ok = strtoupper((string)($a['country'] ?? 'IN')) === 'IN' && preg_match('/^[1-9]\d{5}$/', $zip);
    $out[] = ['id' => (string)($a['id'] ?? '0'), 'zipcode' => $zip, 'state_code' => (string)($a['state_code'] ?? ''), 'country' => (string)($a['country'] ?? 'IN'),
      'shipping_methods' => [['id' => 'free', 'name' => 'Free delivery', 'description' => 'Free delivery across India', 'serviceable' => (bool)$ok, 'shipping_fee' => 0,
        'cod' => $ok && $cod, 'cod_fee' => $ok && $cod ? $fee : 0]]];
  }
  /* the mobile number and PIN typed in Magic Checkout: Admin → Left at checkout */
  try { $a0 = (array)($in['addresses'][0] ?? []);
    magic_lead($m, ['phone' => (string)($in['contact'] ?? ''), 'email' => (string)($in['email'] ?? ''), 'state' => magic_state((string)($a0['state_code'] ?? '')),
      'address' => isset($a0['zipcode']) ? 'PIN ' . preg_replace('/\D/', '', (string)$a0['zipcode']) : '']);
    if (($p = magic_phone((string)($in['contact'] ?? ''))) !== '') shop_db()->prepare('UPDATE magic SET phone = ? WHERE receipt = ?')->execute([$p, $m['receipt']]);
  } catch (Throwable $e) { error_log('FOMAXO Magic lead: ' . $e->getMessage()); }
  out(['addresses' => $out]);
}

/* ---------------- Razorpay asks: coupons to show in the list. None are listed, so codes sent to one person stay private; shoppers type theirs ---------------- */
if (isset($_GET['promotions'])) out(['promotions' => []]);

/* ---------------- Razorpay asks: is this coupon code good for this bag ---------------- */
if (isset($_GET['apply'])) {
  $no = fn(string $code, string $why) => out(['failure_code' => $code, 'failure_reason' => $why], 400);
  /* guessing codes: at most 20 tries in 10 minutes for one bag */
  $m = magic_row((string)($in['order_id'] ?? ''));
  if (!$m) $no('INVALID_PROMOTION', 'This order has expired. Please close checkout and try again.');
  $rl = fomaxo_orders_dir() . '/cpm-' . substr(hash('sha256', $m['receipt']), 0, 16) . '.txt';
  $recent = array_filter(array_map('intval', is_file($rl) ? file($rl, FILE_IGNORE_NEW_LINES) : []), fn($t) => $t > time() - 600);
  if (count($recent) >= 20) $no('INVALID_PROMOTION', 'Too many tries. Please wait a few minutes and try again.');
  $recent[] = time(); @file_put_contents($rl, implode("\n", $recent), LOCK_EX);
  $code = coupon_clean((string)($in['code'] ?? ''));
  $cp = magic_coupon($m['bag'], $code, (string)($in['contact'] ?? '') ?: $m['phone']);
  if (isset($cp['error'])) $no(preg_match('/ and above|^Spend /', $cp['error']) ? 'REQUIREMENT_NOT_MET' : 'INVALID_PROMOTION', $cp['error']);
  shop_db()->prepare('UPDATE magic SET coupon = ? WHERE receipt = ?')->execute([$cp['code'], $m['receipt']]);
  $desc = !empty($cp['free']) ? $cp['label'] . ' (added to your order)' : ($cp['discount'] ? $cp['label'] : 'Your together extra off saves more, so we kept it.');
  out(['promotion' => ['reference_id' => $cp['code'], 'code' => $cp['code'], 'type' => 'coupon', 'value' => $cp['value'], 'value_type' => 'fixed_amount', 'description' => $desc]]);
}

/* ---------------- Razorpay sends: a shopper left Magic Checkout (the Abandoned webhook) ---------------- */
if (isset($_GET['abandoned'])) {
  if (!hash_equals(magic_hook_key(), (string)($_GET['k'] ?? ''))) fail('Bad key', 401);
  $c = is_array($in['customer'] ?? null) ? $in['customer'] : [];
  $a = (array)($c['Shipping_address'] ?? $c['shipping_address'] ?? []);
  $phone = (string)($in['phone'] ?? $a['phone'] ?? '');
  $m = null; foreach (['receipt', 'order_id', 'cart_token', 'token'] as $k) if (!$m && is_string($in[$k] ?? null)) $m = magic_row($in[$k]) ?? magic_row_by_rzp($in[$k]);
  if (!$m) {   // not one of our bags we can find: the bag from the webhook itself, on its own line
    $CAT = fomaxo_catalog()['products']; $items = [];
    foreach (array_slice((array)($in['line_items'] ?? []), 0, 20) as $li) if (is_array($li)) {
      $id = magic_pid((string)($li['sku'] ?? $li['product_id'] ?? '')); $opt = preg_replace('/^' . preg_quote(magic_sku($id), '/') . 'v/', '', (string)($li['variant_id'] ?? '')) ?? '';
      $items[] = ['id' => $id, 'opt' => $opt, 'qty' => max(1, (int)($li['quantity'] ?? 1)), 'name' => (string)($li['name'] ?? $li['title'] ?? ($CAT[$id]['name'] ?? ''))];
    }
    $m = ['receipt' => 'abandoned-' . preg_replace('/\D/', '', $phone), 'sid' => '', 'vid' => 'magic', 'bag' => ['items' => $items, 'subtotal' => (int)($in['line_items_total'] ?? 0)]];
  }
  $name = trim((string)($c['first_name'] ?? '') . ' ' . (string)($c['last_name'] ?? '')) ?: (string)($a['name'] ?? '');
  try { magic_lead($m, ['name' => $name, 'phone' => $phone, 'email' => (string)($in['email'] ?? $c['email'] ?? ''), 'state' => magic_state((string)($a['province'] ?? $a['state'] ?? '')),
    'address' => implode(', ', array_filter(array_map('strval', [$a['address1'] ?? $a['line1'] ?? '', $a['address2'] ?? $a['line2'] ?? '', $a['city'] ?? '', $a['zip'] ?? $a['zipcode'] ?? '']))), 'step' => 'payment']); }
  catch (Throwable $e) { error_log('FOMAXO Magic abandoned: ' . $e->getMessage()); }
  out(['ok' => true]);
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
$action = $in['action'] ?? '';

/* ---------------- 1) the shopper pressed Checkout: price the bag and open a Magic Checkout order ---------------- */
if ($action === 'create') {
  $magic = shop_magic();
  if ($magic === 'off' || ($magic === 'admin' && !fomaxo_is_admin_visitor())) fail('Please use the checkout page.', 409);
  /* at most 30 a minute from one connection */
  $rl = fomaxo_orders_dir() . '/mgc-' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16) . '.txt';
  $recent = array_filter(array_map('intval', is_file($rl) ? file($rl, FILE_IGNORE_NEW_LINES) : []), fn($t) => $t > time() - 60);
  if (count($recent) >= 30) fail('Too many tries. Please wait a minute and try again.', 429);
  $recent[] = time(); @file_put_contents($rl, implode("\n", $recent), LOCK_EX);
  $bag = fomaxo_price_order($in);
  if (isset($bag['error'])) fail($bag['error']);
  $amount = magic_amount($bag);
  $CAT = fomaxo_catalog()['products']; $site = magic_site(); $li = [];
  foreach ($bag['items'] as $it) {
    $p = $CAT[$it['id']];
    $li[] = ['sku' => magic_sku($it['id']), 'variant_id' => magic_variant($it['id'], (string)$it['opt']), 'price' => max($it['unit'], (int)($it['was'] ?? 0)), 'offer_price' => $it['unit'],
      'quantity' => $it['qty'], 'name' => mb_substr($it['name'], 0, 120), 'description' => mb_substr($it['desc'] !== '' ? $it['desc'] : $it['name'], 0, 250),
      'product_url' => "$site/#/product/{$it['id']}"] + ($p['img'] !== '' ? ['image_url' => "$site/{$p['img']}"] : []);
  }
  $receipt = 'mx-' . date('ymd') . '-' . bin2hex(random_bytes(5));
  $kept = $bag['subtotal'] - $amount;
  [$code, $ro, $raw] = magic_rzp('POST', '/orders', ['amount' => $amount, 'currency' => 'INR', 'receipt' => $receipt, 'line_items_total' => $amount, 'line_items' => $li,
    'notes' => ['site' => 'fomaxo.in'] + ($kept > 0 ? ['prediscount_applied' => "Together {$bag['togetherTxt']} off " . rupees($kept)] : [])]);
  if ($code !== 200 || empty($ro['id'])) { error_log('FOMAXO Magic create error: ' . $raw); fail('Fast checkout is unavailable right now.', 502); }
  $clean = fn($v) => preg_match('/^[a-z0-9]{6,16}$/', (string)$v) ? (string)$v : '';
  try {
    shop_db()->prepare('INSERT INTO magic(receipt, rzp, created, cart, sid, vid) VALUES(?,?,?,?,?,?)')->execute([$receipt, $ro['id'], shop_now(),
      json_encode(['lines' => $in['lines']] + $bag, JSON_UNESCAPED_UNICODE), $clean($in['sid'] ?? ''), $clean($in['vid'] ?? '')]);
  } catch (Throwable $e) { error_log('FOMAXO Magic save bag: ' . $e->getMessage()); fail('Fast checkout is unavailable right now.', 500); }
  out(['key' => $KEY_ID, 'order_id' => $ro['id'], 'amount' => $amount, 'together' => $kept / 100]);
}

/* ---------------- 2) Magic Checkout finished: paid online or COD placed ---------------- */
if ($action === 'verify') {
  $oid = (string)($in['razorpay_order_id'] ?? ''); $pid = (string)($in['razorpay_payment_id'] ?? ''); $sig = (string)($in['razorpay_signature'] ?? '');
  if (!preg_match('/^order_\w+$/', $oid) || !preg_match('/^pay_\w+$/', $pid) || $sig === '') fail('Missing payment details.');
  if (!magic_row_by_rzp($oid)) fail('We could not find this order. If money was taken, please WhatsApp us.', 404);
  if (!hash_equals(hash_hmac('sha256', "$oid|$pid", $SECRET), $sig)) { error_log("FOMAXO Magic: bad signature for $oid"); fail('We could not confirm your order. If money was taken, please WhatsApp us.', 400); }
  $rec = magic_finalize($oid, $pid, 'checkout');
  if (!$rec) fail('We could not confirm your order yet. If money was taken, please WhatsApp us.', 409);
  out(['status' => $rec['method'] === 'cod' ? 'placed' : 'paid', 'pay' => $rec['method'] === 'cod' ? 'cod' : 'online', 'order' => $rec['no'], 'total' => $rec['total'] / 100, 'review' => $rec['review'] ?? '']);
}

fail('Unknown action.');
