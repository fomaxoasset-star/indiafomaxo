<?php
declare(strict_types=1);
/* FOMAXO India — online payment (UPI, cards, netbanking, wallets) through Razorpay Checkout.
   POST {action:"create", lines, customer, coupon} → prices the bag here (less the together saving and the coupon), creates a Razorpay order, returns what Checkout.js needs
   POST {action:"verify", razorpay_*}       → checks Razorpay's signature with the Key Secret, then records the paid order
   POST with X-Razorpay-Signature header    → webhook (optional): records a payment even if the customer closed the page
   GET  ?status                             → set-up check: are the keys found, test or live (never shows a key)
   Keys live in ../fomaxo-private/razorpay-config.php, next to public_html (never on GitHub, never public).
   See api/razorpay-config.example.php. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/store-lib.php';

$cfg = fomaxo_config();
$KEY_ID = trim((string)($cfg['razorpay_key_id'] ?? ''));
$SECRET = trim((string)($cfg['razorpay_key_secret'] ?? ''));
if (stripos($KEY_ID, 'rzp_') !== 0 || stripos($KEY_ID . $SECRET, 'PASTE') !== false) { $KEY_ID = ''; $SECRET = ''; }
$API = rtrim(is_string($cfg['razorpay_api'] ?? null) && $cfg['razorpay_api'] !== '' ? $cfg['razorpay_api'] : 'https://api.razorpay.com/v1', '/');   // override only for local testing
$TEST = str_starts_with($KEY_ID, 'rzp_test_');

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['status'])) {
  out(['keys' => $KEY_ID && $SECRET ? 'found' : 'NOT FOUND — add razorpay-config.php in public_html/api/data (or in fomaxo-private next to public_html)',
       'mode' => !$KEY_ID ? '-' : ($TEST ? 'test (no real money)' : 'live'),
       'webhook' => ($cfg['razorpay_webhook_secret'] ?? '') !== '' ? 'secret set' : 'not set (optional)',
       'products' => count(fomaxo_catalog()['products'])]);
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
if (!$KEY_ID || !$SECRET) { error_log('FOMAXO Razorpay: keys not found in fomaxo-private/razorpay-config.php'); fail('Online payment is not set up yet. Please choose cash on delivery or order on WhatsApp.', 503); }

function rzp(string $method, string $path, ?array $body = null): array {
  global $API, $KEY_ID, $SECRET;
  $ch = curl_init($API . $path);
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
    CURLOPT_USERPWD => "$KEY_ID:$SECRET", CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']]
    + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body)] : []));
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  return [$code, is_string($res) ? json_decode($res, true) : null, $err ?: (string)$res];
}

/* First time an order is seen as paid: it becomes an order now, so it gets the next order number and today's date, takes its items off the stock, is logged,
   and the store and the customer are emailed. Until then it is only a card try: no number, not in Orders, not a sale. One transaction, so the checkout page and the webhook can't both do it. */
function mark_paid(string $orderId, string $paymentId, string $via): ?array {
  try {
    [$rec, $first] = shop_tx(function (PDO $db) use ($orderId, $paymentId) {
      $rec = shop_find_order($orderId, true, $db);
      if (!$rec || !empty($rec['paid'])) return [$rec, false];
      $rec['no'] = $rec['no'] ?: shop_next_no($db);
      $rec['paid'] = $rec['paid_at'] = $rec['created'] = shop_now(); $rec['payment'] = $paymentId;
      shop_take_stock($db, $rec['items'], false);   // the money is taken, so stock goes down even if it was short
      $db->prepare("UPDATE orders SET no = ?, created = ?, paid_at = ?, payment_id = ?, status = 'paid', stock_taken = 1, closed_at = NULL, updated = ? WHERE ref = ?")
        ->execute([$rec['no'], $rec['created'], $rec['paid'], $paymentId, shop_now(), $orderId]);
      shop_coupon_spent($db, (string)$rec['coupon']);   // a one-use coupon deletes itself now
      return [$rec, true];
    });
  } catch (Throwable $e) { error_log('FOMAXO Razorpay mark paid: ' . $e->getMessage()); return null; }
  if ($first) {
    $rec['review'] = fomaxo_review_token($rec);   // Verified Purchaser review link, emailed below
    if ($rec['review'] !== '') shop_db()->prepare('UPDATE orders SET review = ? WHERE ref = ?')->execute([$rec['review'], $orderId]);
    $c = $rec['cust'];
    fomaxo_log_order([date('Y-m-d H:i'), $rec['no'], 'Razorpay — PAID' . ($rec['test'] ? ' (TEST)' : '') . " via $via", rupees((int)$rec['total']),
      $c['name'], $c['phone'], $c['email'], $c['address'], $c['note'], implode(' | ', $rec['rows']), $paymentId, $rec['coupon']]);
    fomaxo_send_emails($rec, 'Paid online (Razorpay)' . ($rec['test'] ? ' — TEST' : ''));
  }
  return $rec;
}

/* ---------------- Razorpay Magic Checkout (Admin → Settings → Razorpay, Off until Razorpay switches it on for the account) ----------------
   The bag's Checkout opens Razorpay's one-tap window: Razorpay asks for the mobile number first (filled in for shoppers already saved with Razorpay),
   then the address and payment (or cash on delivery). Razorpay calls three addresses here while the window is open:
   POST ?magic=shipping → free delivery, and whether cash on delivery is open for this order (Admin → Settings → Cash on delivery)
   POST ?magic=promos   → coupons to suggest (none: shoppers type their own)
   POST ?magic=apply    → checks a typed coupon with the same rules as our checkout page
   If the shopper closes the window after typing their number, it is saved to Admin → Analytics → Left at checkout. */
function magic_on(): bool { return shop_setting('magic') === '1'; }
/* our order for a Razorpay order id, or for the receipt we gave it (kept in payment_id until the order is paid) */
function magic_find(string $id): ?array {
  if ($id === '') return null;
  $rec = shop_find_order($id);
  if ($rec) return $rec;
  $s = shop_db()->prepare("SELECT ref FROM orders WHERE payment_id = ? AND method = 'online' AND no IS NULL"); $s->execute([$id]);
  $ref = $s->fetchColumn(); return $ref ? shop_find_order((string)$ref) : null;
}
/* the bag before any saving, in paise */
function magic_subtotal(array $rec): int { return array_sum(array_map(fn($it) => (int)$it['unit'] * (int)$it['qty'], $rec['items'])); }
/* cash on delivery for this Magic order: the same minimum, hidden maximum and fee as our checkout page */
function magic_cod(array $rec, int $promo = 0): ?int {
  $cod = fomaxo_catalog()['cod'];
  if (!$cod || $cod['onlyName'] !== '') return null;   // COD switched off, or only open for the test name
  if (magic_subtotal($rec) < $cod['min']) return null;
  if ($cod['max'] > 0 && $rec['total'] - $promo >= $cod['max']) return null;
  return (int)$cod['fee'];
}
/* a 10-digit Indian mobile from +91…, or '' */
function magic_phone10(string $p): string {
  $d = preg_replace('/\D/', '', $p) ?? '';
  if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
  if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
  return preg_match('/^[6-9]\d{9}$/', $d) ? $d : '';
}
/* the shopper's details from Razorpay's order (Fetch Order → customer_details), in the shape of our checkout's delivery details */
function magic_customer(array $ro): array {
  $cd = is_array($ro['customer_details'] ?? null) ? $ro['customer_details'] : [];
  $a = is_array($cd['shipping_address'] ?? null) ? $cd['shipping_address'] : (is_array($cd['billing_address'] ?? null) ? $cd['billing_address'] : []);
  $t = fn($v, $max) => trim(mb_substr(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', is_scalar($v) ? (string)$v : '') ?? '', 0, $max));
  $d = magic_phone10((string)($cd['contact'] ?? ($a['contact'] ?? '')));
  $st = $t($a['state'] ?? '', 60);
  foreach (FOMAXO_STATES as $s) if (strcasecmp($s, $st) === 0) $st = $s;
  $c = ['name' => $t($a['name'] ?? ($cd['name'] ?? ''), 80), 'phone' => $d !== '' ? '+91 ' . substr($d, 0, 5) . ' ' . substr($d, 5) : '', 'phone10' => $d,
    'email' => filter_var($t($cd['email'] ?? '', 120), FILTER_VALIDATE_EMAIL) ?: '', 'house' => $t($a['line1'] ?? '', 120), 'street' => $t($a['line2'] ?? '', 120),
    'landmark' => $t($a['landmark'] ?? '', 80), 'city' => $t($a['city'] ?? '', 60), 'state' => $st, 'pin' => $t($a['zipcode'] ?? '', 10), 'note' => '', 'wa' => 0];
  $c['address'] = implode(', ', array_filter([$c['house'], $c['street'], $c['landmark'] !== '' ? 'Near ' . $c['landmark'] : '', $c['city'], trim($c['state'] . ' ' . $c['pin'])]));
  return $c;
}
/* the coupon on a Magic order, checked again with our rules: [] / ['error'] / ['code', 'discount', 'offer', 'value' (what Razorpay takes off, paise)] */
function magic_coupon(array $rec, string $code, string $phone, ?PDO $db = null): array {
  if ($code === '') return [];
  $order = ['subtotal' => magic_subtotal($rec), 'together' => (int)$rec['together']];
  $cp = fomaxo_coupon(['coupon' => $code, 'phone' => $phone], $order, $db);
  if (!$cp || isset($cp['error'])) return $cp;
  if (!empty($cp['free'])) return ['error' => 'This coupon gives a free product. Please use it on our checkout page at fomaxo.in.'];
  $value = $cp['discount'] + $cp['offer'] - (int)$rec['together'];   // the order already has the together saving taken off
  if ($value <= 0) return ['error' => 'Your together extra off already saves more than this coupon.'];
  return $cp + ['value' => min($value, (int)$rec['total'] - 100)];
}
/* fills our order with what the shopper gave Razorpay (name, mobile, address, coupon), before it becomes an order */
function magic_fill(string $oid): ?array {
  [$code, $ro] = rzp('GET', '/orders/' . rawurlencode($oid));
  if ($code !== 200 || !is_array($ro)) return null;
  $rec = shop_find_order($oid);
  if (!$rec || !empty($rec['no'])) return $ro;
  $c = magic_customer($ro);
  $promo = ''; foreach ((array)($ro['promotions'] ?? []) as $p) if (is_array($p) && !empty($p['code'])) $promo = (string)$p['code'];
  $cp = $promo !== '' ? magic_coupon($rec, $promo, $c['phone10']) : [];
  if (isset($cp['error'])) { error_log("FOMAXO Magic: coupon $promo on $oid no longer valid: {$cp['error']}"); $cp = []; }
  $rows = $rec['rows'];
  if ($cp) $rows[] = "• Coupon {$cp['code']} — −" . rupees((int)$cp['value']);
  if ((int)($ro['shipping_fee'] ?? 0) > 0) $rows[] = '• Delivery — ' . rupees((int)$ro['shipping_fee']);
  shop_db()->prepare('UPDATE orders SET name = ?, phone = ?, email = ?, address = ?, city = ?, state = ?, pin = ?, coupon = ?, discount = ?, total = ?, rows_text = ?, updated = ? WHERE ref = ? AND no IS NULL')
    ->execute([$c['name'], $c['phone'], $c['email'], $c['address'], $c['city'], $c['state'], $c['pin'], $cp['code'] ?? '', (int)($cp['value'] ?? 0),
      magic_subtotal($rec) - (int)$rec['together'] - (int)($cp['value'] ?? 0), implode("\n", $rows), shop_now(), $oid]);
  return $ro;
}
/* a Magic order the shopper chose to pay by cash on delivery: it becomes an order now (number, stock, emails), like api/cod.php */
function magic_cod_placed(string $oid, array $ro): ?array {
  $fee = (int)($ro['cod_fee'] ?? 0);
  try {
    [$rec, $first] = shop_tx(function (PDO $db) use ($oid, $ro, $fee) {
      $rec = shop_find_order($oid, true, $db);
      if (!$rec || !empty($rec['no'])) return [$rec, false];
      $short = shop_take_stock($db, $rec['items'], false);
      if ($short !== '') error_log("FOMAXO Magic COD $oid: $short");
      $rec['no'] = shop_next_no($db); $rec['created'] = shop_now(); $rec['codFee'] = $fee; $rec['cod'] = true;
      $rec['total'] = (int)($ro['amount'] ?? $rec['total']);
      if ($fee > 0) $rec['rows'][] = '• Cash on delivery fee — ' . rupees($fee);
      $db->prepare("UPDATE orders SET no = ?, created = ?, method = 'cod', status = 'new', total = ?, cod_fee = ?, stock_taken = 1, payment_id = '', rows_text = ?, updated = ? WHERE ref = ?")
        ->execute([$rec['no'], $rec['created'], $rec['total'], $fee, implode("\n", $rec['rows']), shop_now(), $oid]);
      shop_coupon_spent($db, (string)$rec['coupon']);
      return [$rec, true];
    });
  } catch (Throwable $e) { error_log('FOMAXO Magic COD: ' . $e->getMessage()); return null; }
  if ($first) {
    $rec['review'] = fomaxo_review_token($rec);
    if ($rec['review'] !== '') shop_db()->prepare('UPDATE orders SET review = ? WHERE ref = ?')->execute([$rec['review'], $oid]);
    $c = $rec['cust'];
    fomaxo_log_order([date('Y-m-d H:i'), $rec['no'], 'Cash on delivery (Razorpay Magic)', rupees((int)$rec['total']), $c['name'], $c['phone'], $c['email'], $c['address'], '', implode(' | ', $rec['rows']), '', $rec['coupon']]);
    fomaxo_send_emails($rec, 'Cash on delivery');
  }
  return $rec;
}

/* Razorpay's calls while the Magic window is open: no sign-in, so they only ever read our order and answer */
if (isset($_GET['magic'])) {
  $m = (string)$_GET['magic']; $in = input();
  $rec = magic_find((string)($in['razorpay_order_id'] ?? '')) ?: magic_find((string)($in['order_id'] ?? ''));
  if (!$rec || !empty($rec['no'])) out(['error' => ['code' => 'ORDER_NOT_FOUND', 'description' => 'Order not found']], 404);
  if ($m === 'shipping') {
    $cod = magic_cod($rec);
    $addr = array_map(fn($a) => ['id' => (string)($a['id'] ?? '0'), 'zipcode' => (string)($a['zipcode'] ?? ''), 'country' => (string)($a['country'] ?? 'IN'),
      'shipping_methods' => [['id' => 'free', 'name' => 'Free delivery', 'description' => 'Delivered in 1–3 days across India',
        'serviceable' => ($a['country'] ?? 'IN') === 'IN', 'shipping_fee' => 0, 'cod' => $cod !== null, 'cod_fee' => $cod ?? 0]]],
      array_values(array_filter((array)($in['addresses'] ?? []), 'is_array')));
    out(['addresses' => $addr]);
  }
  if ($m === 'promos') out(['promotions' => []]);
  if ($m === 'apply') {
    $cp = magic_coupon($rec, strtoupper(trim((string)($in['code'] ?? ''))), magic_phone10((string)($in['contact'] ?? '')));
    if (!$cp || isset($cp['error'])) out(['failure_code' => 'INVALID_PROMOTION', 'failure_reason' => $cp['error'] ?? 'Please enter a coupon code.'], 400);
    out(['promotion' => ['reference_id' => $cp['code'], 'code' => $cp['code'], 'type' => 'coupon', 'value' => (int)$cp['value'], 'value_type' => 'fixed_amount',
      'description' => $cp['label'] ?? ('Coupon ' . $cp['code'])]]);
  }
  fail('Unknown action.', 404);
}

/* ---------------- webhook (optional; set the same secret in Razorpay → Settings → Webhooks) ---------------- */
if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) {
  $raw = (string)file_get_contents('php://input');
  $ws = (string)($cfg['razorpay_webhook_secret'] ?? '');
  if ($ws === '' || !hash_equals(hash_hmac('sha256', $raw, $ws), (string)$_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) fail('Bad signature', 401);
  $ev = json_decode($raw, true) ?: [];
  $pay = $ev['payload']['payment']['entity'] ?? [];
  if (in_array($ev['event'] ?? '', ['payment.captured', 'order.paid'], true) && !empty($pay['order_id'])) {
    $oid = (string)$pay['order_id']; $o = shop_find_order($oid);
    if ($o && empty($o['no']) && $o['cust']['phone'] === '') magic_fill($oid);   // a Magic order closed before our page heard back: its details come from Razorpay
    mark_paid($oid, (string)($pay['id'] ?? ''), 'webhook');
  }
  out(['ok' => true]);
}

$in = input();
$action = $in['action'] ?? '';

/* ---------------- 1) create the Razorpay order ---------------- */
if ($action === 'create') {
  $order = fomaxo_price_order($in);
  if (isset($order['error'])) fail($order['error']);
  $cust = fomaxo_customer($in);
  if (isset($cust['error'])) fail($cust['error']);
  $cp = fomaxo_coupon($in, $order);
  if (isset($cp['error'])) fail($cp['error']);
  if (($e = fomaxo_add_free($order, $cp)) !== '') fail($e);   // a free product coupon: its item joins the order at ₹0
  $together = fomaxo_together_kept($order, $cp);   // the Customers bought together saving kept: its line joins the order's rows
  $amount = $order['subtotal'] - ($cp['discount'] ?? 0) - $together;   // what Razorpay charges: worked out here, never taken from the browser
  /* the order number (FMX-IN-…) is given once the payment is confirmed, so unfinished payments leave no gaps */
  $ref = 'web-' . date('ymd') . '-' . bin2hex(random_bytes(4));
  [$code, $ro, $raw] = rzp('POST', '/orders', ['amount' => $amount, 'currency' => 'INR', 'receipt' => $ref,
    'notes' => ['customer' => $cust['name'], 'mobile' => $cust['phone']]]);
  if ($code !== 200 || empty($ro['id'])) { error_log('FOMAXO Razorpay create error: ' . $raw); fail('Online payment is unavailable right now. Please try again, or choose cash on delivery.', 502); }
  try {
    shop_insert_order(shop_db(), ['ref' => $ro['id'], 'no' => null, 'created' => shop_now(), 'method' => 'online', 'status' => 'awaiting', 'total' => $amount,
      'items' => $order['items'], 'rows' => $order['rows'], 'cust' => $cust, 'test' => $TEST, 'coupon' => $cp['code'] ?? '', 'discount' => $cp['discount'] ?? 0, 'together' => $together]);
  } catch (Throwable $e) { error_log('FOMAXO Razorpay save order: ' . $e->getMessage()); fail('Online payment is unavailable right now. Please try again, or choose cash on delivery.', 500); }
  out(['key' => $KEY_ID, 'order_id' => $ro['id'], 'amount' => $amount, 'currency' => 'INR',
       'prefill' => ['name' => $cust['name'], 'email' => $cust['email'], 'contact' => preg_replace('/\s+/', '', $cust['phone'])]]);
}

/* ---------------- 2) customer paid in Checkout.js: verify the signature ---------------- */
if ($action === 'verify') {
  $oid = (string)($in['razorpay_order_id'] ?? ''); $pid = (string)($in['razorpay_payment_id'] ?? ''); $sig = (string)($in['razorpay_signature'] ?? '');
  if (!preg_match('/^order_\w+$/', $oid) || !preg_match('/^pay_\w+$/', $pid) || $sig === '') fail('Missing payment details.');
  if (!hash_equals(hash_hmac('sha256', "$oid|$pid", $SECRET), $sig)) { error_log("FOMAXO Razorpay: bad signature for $oid"); fail('We could not confirm your payment. If money was taken, please WhatsApp us.', 400); }
  $rec = mark_paid($oid, $pid, 'checkout');
  if (!$rec) fail('We could not find this order. If money was taken, please WhatsApp us.', 404);
  out(['status' => 'paid', 'order' => $rec['no'], 'total' => $rec['total'] / 100, 'review' => $rec['review'] ?? '']);
}

/* ---------------- 3) Magic Checkout: the bag's Checkout opens Razorpay's one-tap window ---------------- */
if ($action === 'magic_create') {
  if (!magic_on()) fail('Magic Checkout is off.', 409);
  $order = fomaxo_price_order($in);
  if (isset($order['error'])) fail($order['error']);
  $together = fomaxo_together_kept($order, []);
  /* Razorpay wants each item's price; the together saving is spread over the items so they add up to what is charged (a few paise in the shopper's favour) */
  $share = $order['subtotal'] > 0 ? $together / $order['subtotal'] : 0; $li = []; $sum = 0;
  foreach ($order['items'] as $k => $it) {
    $offer = max(0, $it['unit'] - (int)ceil($it['unit'] * $share)); $sum += $offer * $it['qty'];
    $li[] = ['sku' => "{$it['id']}-{$it['opt']}", 'variant_id' => "{$it['id']}-{$it['opt']}", 'price' => (int)($it['was'] ?? $it['unit']), 'offer_price' => $offer, 'tax_amount' => 0,
      'quantity' => (int)$it['qty'], 'name' => mb_substr($it['name'], 0, 120), 'description' => mb_substr($it['desc'] !== '' ? $it['desc'] : $it['name'], 0, 250),
      'product_url' => 'https://fomaxo.in/#/product/' . rawurlencode($it['id'])];
  }
  if ($sum < 100) fail('This order cannot be paid online. Please order on WhatsApp.');
  $ref = 'mg-' . date('ymd') . '-' . bin2hex(random_bytes(4));
  [$code, $ro, $raw] = rzp('POST', '/orders', ['amount' => $sum, 'currency' => 'INR', 'receipt' => $ref, 'line_items_total' => $sum, 'line_items' => $li, 'notes' => ['site' => 'fomaxo.in']]);
  if ($code !== 200 || empty($ro['id'])) { error_log('FOMAXO Magic create error: ' . $raw); fail('Online payment is unavailable right now. Please try again.', 502); }
  $none = ['name' => '', 'phone' => '', 'email' => '', 'address' => '', 'city' => '', 'state' => '', 'pin' => '', 'note' => '', 'wa' => 0];
  try {
    shop_insert_order(shop_db(), ['ref' => $ro['id'], 'no' => null, 'created' => shop_now(), 'method' => 'online', 'status' => 'awaiting', 'total' => $sum, 'payment' => $ref,
      'items' => $order['items'], 'rows' => $order['rows'], 'cust' => $none, 'test' => $TEST, 'together' => $order['subtotal'] - $sum]);
  } catch (Throwable $e) { error_log('FOMAXO Magic save order: ' . $e->getMessage()); fail('Online payment is unavailable right now. Please try again.', 500); }
  out(['key' => $KEY_ID, 'order_id' => $ro['id'], 'amount' => $sum, 'currency' => 'INR']);
}

/* the Magic window finished: Razorpay's own order (read here with the Key Secret) says paid, or cash on delivery placed */
if ($action === 'magic_done') {
  $oid = (string)($in['razorpay_order_id'] ?? '');
  if (!preg_match('/^order_\w+$/', $oid)) fail('Missing payment details.');
  $ro = magic_fill($oid);
  if (!$ro) fail('We could not confirm your order. If money was taken, please WhatsApp us.', 502);
  $st = (string)($ro['status'] ?? '');
  if ($st === 'paid') {
    [$c2, $ps] = rzp('GET', '/orders/' . rawurlencode($oid) . '/payments');
    $pid = ''; foreach ((array)($ps['items'] ?? []) as $p) if (in_array($p['status'] ?? '', ['captured', 'authorized'], true)) $pid = (string)$p['id'];
    $rec = mark_paid($oid, $pid ?: (string)($in['razorpay_payment_id'] ?? ''), 'magic');
    if (!$rec) fail('We could not find this order. If money was taken, please WhatsApp us.', 404);
    out(['status' => 'paid', 'order' => $rec['no'], 'total' => $rec['total'] / 100, 'review' => $rec['review'] ?? '', 'pay' => 'online']);
  }
  if ($st === 'placed') {
    $rec = magic_cod_placed($oid, $ro);
    if (!$rec) fail('We could not place your order right now. Please WhatsApp us.', 500);
    out(['status' => 'placed', 'order' => $rec['no'], 'total' => $rec['total'] / 100, 'review' => $rec['review'] ?? '', 'pay' => 'cod']);
  }
  fail('Your payment did not go through and no money was taken. Please try again.', 409);
}

/* the shopper closed the Magic window: if they gave Razorpay their mobile number, it goes to Admin → Analytics → Left at checkout */
if ($action === 'magic_left') {
  $oid = (string)($in['order_id'] ?? ''); $vid = (string)($in['v'] ?? ''); $sid = (string)($in['s'] ?? '');
  if (!preg_match('/^order_\w+$/', $oid) || !preg_match('/^[a-z0-9]{8,16}$/', $vid) || !preg_match('/^[a-z0-9]{8,16}$/', $sid)) out(['ok' => false]);
  $rec = shop_find_order($oid);
  if (!$rec || !empty($rec['no'])) out(['ok' => false]);
  $ro = magic_fill($oid);
  $c = $ro ? magic_customer($ro) : null;
  if (!$c || $c['phone10'] === '') out(['ok' => false]);
  $bag = array_map(fn($it) => ['id' => $it['id'], 'opt' => (string)$it['opt'], 'qty' => (int)$it['qty'], 'name' => preg_replace('/^FOMAXO /', '', str_replace(' — ', ' · ', $it['name']))], array_slice($rec['items'], 0, 20));
  try {
    $s = shop_db()->prepare('SELECT created FROM leads WHERE sid = ?'); $s->execute([$sid]); $was = $s->fetchColumn();
    shop_upsert('leads', ['sid'], ['sid' => $sid, 'vid' => $vid, 'created' => $was ?: shop_now(), 'updated' => shop_now(), 'name' => $c['name'], 'phone' => $c['phone10'],
      'step' => $c['address'] !== '' ? 'payment' : 'details', 'bag' => json_encode($bag, JSON_UNESCAPED_UNICODE), 'total' => magic_subtotal($rec), 'ordered' => 0,
      'email' => $c['email'], 'state' => in_array($c['state'], FOMAXO_STATES, true) ? $c['state'] : '', 'address' => mb_substr($c['address'], 0, 300)]);
  } catch (Throwable $e) { error_log('FOMAXO Magic left: ' . $e->getMessage()); }
  out(['ok' => true]);
}

fail('Unknown action.');
