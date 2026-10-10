<?php
declare(strict_types=1);
/* FOMAXO India — "Have a coupon code?" on the checkout page.
   POST {code, lines, phone} → prices the bag here and checks the code (made on fomaxo.in/admin → Coupons); returns the discount
   and the new total, or a short message. Only a preview: api/cod.php and api/razorpay.php check the code again when the order is placed. */
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/store-lib.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') fail('Method not allowed', 405);
$in = input();

/* guessing codes: at most 20 tries in 10 minutes from one connection */
$rl = fomaxo_orders_dir() . '/cpn-' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16) . '.txt';
$recent = array_filter(array_map('intval', is_file($rl) ? file($rl, FILE_IGNORE_NEW_LINES) : []), fn($t) => $t > time() - 600);
if (count($recent) >= 20) fail('Too many tries. Please wait a few minutes and try again.', 429);
$recent[] = time(); @file_put_contents($rl, implode("\n", $recent), LOCK_EX);

$order = fomaxo_price_order($in);
if (isset($order['error'])) fail($order['error']);
$cp = fomaxo_coupon(['coupon' => is_string($in['code'] ?? null) ? $in['code'] : '', 'phone' => is_string($in['phone'] ?? null) ? $in['phone'] : ''], $order);   // phone: for goodwill coupons
if (!$cp) fail('Please type your coupon code.');
if (isset($cp['error'])) fail($cp['error']);
if (($e = fomaxo_add_free($order, $cp)) !== '') fail($e);
/* discount = the coupon, offer = the Customers bought together saving kept with it ("Use both", or when it saves more); stack = the coupon's choice */
out(['code' => $cp['code'], 'label' => $cp['label'], 'discount' => $cp['discount'] / 100, 'offer' => $cp['offer'] / 100, 'stack' => $cp['stack'],
  'subtotal' => $order['subtotal'] / 100, 'total' => ($order['subtotal'] - $cp['off']) / 100,
  'ends' => $cp['ends'] === '' ? null : date('c', strtotime($cp['ends'] . ':59'))]   // for "Ends in 2h 15m" at checkout
  /* a free product coupon: the item shown at ₹0 in the order summary */
  + (isset($order['free']) ? ['free' => ['id' => $order['free']['id'], 'opt' => $order['free']['opt'], 'name' => $order['free']['name'], 'size' => $order['free']['size'], 'worth' => $order['free']['worth'] / 100]] : []));
