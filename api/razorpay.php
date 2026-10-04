<?php
declare(strict_types=1);
/* FOMAXO India — online payment (UPI, cards, netbanking, wallets) through Razorpay Checkout.
   POST {action:"create", lines, customer}  → prices the bag here, creates a Razorpay order, returns what Checkout.js needs
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

/* First time an order is seen as paid: mark it, log it, email the store and the customer. */
function mark_paid(string $orderId, string $paymentId, string $via): ?array {
  $rec = fomaxo_load_order($orderId);
  if (!$rec) return null;
  if (empty($rec['paid'])) {
    $rec['paid'] = date('Y-m-d H:i'); $rec['payment'] = $paymentId;
    fomaxo_save_order($orderId, $rec);
    $c = $rec['cust'];
    fomaxo_log_order([date('Y-m-d H:i'), $rec['no'], 'Razorpay — PAID' . ($rec['test'] ? ' (TEST)' : '') . " via $via", rupees((int)$rec['total']),
      $c['name'], $c['phone'], $c['email'], $c['address'], $c['note'], implode(' | ', $rec['rows']), $paymentId]);
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
  $no = fomaxo_order_no();
  [$code, $ro, $raw] = rzp('POST', '/orders', ['amount' => $order['subtotal'], 'currency' => 'INR', 'receipt' => $no,
    'notes' => ['order' => $no, 'customer' => $cust['name'], 'mobile' => $cust['phone']]]);
  if ($code !== 200 || empty($ro['id'])) { error_log('FOMAXO Razorpay create error: ' . $raw); fail('Online payment is unavailable right now. Please try again, or choose cash on delivery.', 502); }
  fomaxo_save_order($ro['id'], ['no' => $no, 'created' => date('Y-m-d H:i'), 'total' => $order['subtotal'], 'rows' => $order['rows'],
    'cust' => $cust, 'test' => $TEST, 'paid' => null]);
  fomaxo_log_order([date('Y-m-d H:i'), $no, 'Razorpay — awaiting payment' . ($TEST ? ' (TEST)' : ''), rupees($order['subtotal']),
    $cust['name'], $cust['phone'], $cust['email'], $cust['address'], $cust['note'], implode(' | ', $order['rows']), $ro['id']]);
  out(['key' => $KEY_ID, 'order_id' => $ro['id'], 'amount' => $order['subtotal'], 'currency' => 'INR', 'no' => $no,
       'prefill' => ['name' => $cust['name'], 'email' => $cust['email'], 'contact' => preg_replace('/\s+/', '', $cust['phone'])]]);
}

/* ---------------- 2) customer paid in Checkout.js: verify the signature ---------------- */
if ($action === 'verify') {
  $oid = (string)($in['razorpay_order_id'] ?? ''); $pid = (string)($in['razorpay_payment_id'] ?? ''); $sig = (string)($in['razorpay_signature'] ?? '');
  if (!preg_match('/^order_\w+$/', $oid) || !preg_match('/^pay_\w+$/', $pid) || $sig === '') fail('Missing payment details.');
  if (!hash_equals(hash_hmac('sha256', "$oid|$pid", $SECRET), $sig)) { error_log("FOMAXO Razorpay: bad signature for $oid"); fail('We could not confirm your payment. If money was taken, please WhatsApp us.', 400); }
  $rec = mark_paid($oid, $pid, 'checkout');
  if (!$rec) fail('We could not find this order. If money was taken, please WhatsApp us.', 404);
  out(['status' => 'paid', 'order' => $rec['no'], 'total' => $rec['total'] / 100]);
}

fail('Unknown action.');
