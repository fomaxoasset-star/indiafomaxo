<?php
declare(strict_types=1);
/* FOMAXO India — online payment (UPI, cards, netbanking, wallets) through Razorpay Checkout.
   POST {action:"create", lines, customer, coupon} → prices the bag here (less the coupon), creates a Razorpay order, returns what Checkout.js needs
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

/* ---------------- webhook (optional; set the same secret in Razorpay → Settings → Webhooks) ---------------- */
if (isset($_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) {
  $raw = (string)file_get_contents('php://input');
  $ws = (string)($cfg['razorpay_webhook_secret'] ?? '');
  if ($ws === '' || !hash_equals(hash_hmac('sha256', $raw, $ws), (string)$_SERVER['HTTP_X_RAZORPAY_SIGNATURE'])) fail('Bad signature', 401);
  $ev = json_decode($raw, true) ?: [];
  $pay = $ev['payload']['payment']['entity'] ?? [];
  if (in_array($ev['event'] ?? '', ['payment.captured', 'order.paid'], true) && !empty($pay['order_id'])) mark_paid((string)$pay['order_id'], (string)($pay['id'] ?? ''), 'webhook');
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
  $cp = fomaxo_coupon($in, $order['subtotal']);
  if (isset($cp['error'])) fail($cp['error']);
  $amount = $order['subtotal'] - ($cp['discount'] ?? 0);   // what Razorpay charges: worked out here, never taken from the browser
  /* the order number (FMX-IN-…) is given once the payment is confirmed, so unfinished payments leave no gaps */
  $ref = 'web-' . date('ymd') . '-' . bin2hex(random_bytes(4));
  [$code, $ro, $raw] = rzp('POST', '/orders', ['amount' => $amount, 'currency' => 'INR', 'receipt' => $ref,
    'notes' => ['customer' => $cust['name'], 'mobile' => $cust['phone']]]);
  if ($code !== 200 || empty($ro['id'])) { error_log('FOMAXO Razorpay create error: ' . $raw); fail('Online payment is unavailable right now. Please try again, or choose cash on delivery.', 502); }
  try {
    shop_insert_order(shop_db(), ['ref' => $ro['id'], 'no' => null, 'created' => shop_now(), 'method' => 'online', 'status' => 'awaiting', 'total' => $amount,
      'items' => $order['items'], 'rows' => $order['rows'], 'cust' => $cust, 'test' => $TEST, 'coupon' => $cp['code'] ?? '', 'discount' => $cp['discount'] ?? 0]);
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

fail('Unknown action.');
