<?php
declare(strict_types=1);
/* FOMAXO India — cash on delivery orders.
   POST {lines, customer, coupon} → prices the bag here, takes off the coupon (if any), checks the COD minimum, maximum and fee set on Admin → Settings
   (index.html's STORE.checkout.cod until saved there),
   records the order and emails the store and the customer. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/store-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
$cod = fomaxo_catalog()['cod'];
if (!$cod) fail('Cash on delivery is not available. Please pay online.');

$in = input();
$order = fomaxo_price_order($in);
if (isset($order['error'])) fail($order['error']);
$cust = fomaxo_customer($in);
if (isset($cust['error'])) fail($cust['error']);
/* while onlyName is set, COD is unactivated for everyone except that exact full name (testing) */
if ($cod['onlyName'] !== '') { if (strtolower($cust['name']) !== strtolower(trim(preg_replace('/\s+/', ' ', $cod['onlyName'])))) fail('Cash on delivery is not available right now. Please pay online.'); }
elseif ($order['subtotal'] < $cod['min']) fail('Cash on delivery is for orders of ' . rupees($cod['min']) . ' and above. Please pay online.');
$cp = fomaxo_coupon($in, $order['subtotal']);   // a coupon typed at checkout: checked again inside the order below, so its usage limit holds
if (isset($cp['error'])) fail($cp['error']);
if (($e = fomaxo_add_free($order, $cp)) !== '') fail($e);   // a free product coupon: its item joins the order at ₹0 and its stock is taken below
/* the hidden maximum: COD only for orders under it, counted after the coupon and before the COD fee */
$codMax = fn(array $cp) => $cod['max'] > 0 && $order['subtotal'] - ($cp['off'] ?? 0) >= $cod['max'];
$maxMsg = 'COD for orders under ' . rupees($cod['max']) . '. Please pay by card.';
if ($codMax($cp)) fail($maxMsg);

/* simple guard against repeated fake COD orders: at most 5 an hour from one connection */
$rl = fomaxo_orders_dir() . '/cod-' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16) . '.txt';
$recent = array_filter(array_map('intval', is_file($rl) ? file($rl, FILE_IGNORE_NEW_LINES) : []), fn($t) => $t > time() - 3600);
if (count($recent) >= 5) fail('Too many orders from this connection. Please WhatsApp us to complete your order.', 429);
$recent[] = time(); @file_put_contents($rl, implode("\n", $recent), LOCK_EX);

/* one transaction: check and take the stock, then give the order the next number (FMX-IN-1001, FMX-IN-1002 …) */
try {
  $rec = shop_tx(function (PDO $db) use ($order, $cust, $cod, $in, $codMax, $maxMsg) {
    $cp = fomaxo_coupon($in, $order['subtotal'], $db);
    if (isset($cp['error'])) return $cp;
    if ($codMax($cp)) return ['error' => $maxMsg];
    $short = shop_take_stock($db, $order['items'], true);
    if ($short !== '') return ['error' => $short];
    $total = $order['subtotal'] - ($cp['off'] ?? 0) + $cod['fee'];
    $rec = ['ref' => 'cod-' . bin2hex(random_bytes(8)), 'no' => shop_next_no($db), 'created' => shop_now(), 'method' => 'cod', 'status' => 'new',
      'total' => $total, 'codFee' => $cod['fee'], 'items' => $order['items'], 'rows' => $order['rows'], 'cust' => $cust, 'stock_taken' => true,
      'coupon' => $cp['code'] ?? '', 'discount' => $cp['off'] ?? 0];
    shop_insert_order($db, $rec);
    shop_coupon_spent($db, $rec['coupon']);   // a one-use coupon deletes itself now
    return $rec;
  });
} catch (Throwable $e) { error_log('FOMAXO COD order: ' . $e->getMessage()); fail('We could not place your order right now. Please try again or WhatsApp us.', 500); }
if (isset($rec['error'])) fail($rec['error'], 409);
$no = $rec['no']; $total = $rec['total'];
$rec['ids'] = array_column($order['items'], 'id'); $rec['cod'] = true;
$rec['review'] = fomaxo_review_token($rec);   // Verified Purchaser review link, sent in the confirmation email
if ($rec['review'] !== '') shop_db()->prepare('UPDATE orders SET review = ? WHERE ref = ?')->execute([$rec['review'], $rec['ref']]);
fomaxo_log_order([date('Y-m-d H:i'), $no, 'Cash on delivery', rupees($total), $cust['name'], $cust['phone'], $cust['email'], $cust['address'], $cust['note'], implode(' | ', $order['rows']), '', $rec['coupon']]);
fomaxo_send_emails($rec, 'Cash on delivery');
out(['status' => 'placed', 'order' => $no, 'total' => $total / 100, 'review' => $rec['review']]);
