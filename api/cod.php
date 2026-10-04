<?php
declare(strict_types=1);
/* FOMAXO India — cash on delivery orders.
   POST {lines, customer} → prices the bag here, checks the COD minimum and fee set in index.html (STORE.checkout.cod),
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
if ($order['subtotal'] < $cod['min']) fail('Cash on delivery is for orders of ' . rupees($cod['min']) . ' and above. Please pay online.');
$cust = fomaxo_customer($in);
if (isset($cust['error'])) fail($cust['error']);

/* simple guard against repeated fake COD orders: at most 5 an hour from one connection */
$rl = fomaxo_orders_dir() . '/cod-' . substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16) . '.txt';
$recent = array_filter(array_map('intval', is_file($rl) ? file($rl, FILE_IGNORE_NEW_LINES) : []), fn($t) => $t > time() - 3600);
if (count($recent) >= 5) fail('Too many orders from this connection. Please WhatsApp us to complete your order.', 429);
$recent[] = time(); @file_put_contents($rl, implode("\n", $recent), LOCK_EX);

$no = fomaxo_order_no();
$total = $order['subtotal'] + $cod['fee'];
$rec = ['no' => $no, 'created' => date('Y-m-d H:i'), 'total' => $total, 'codFee' => $cod['fee'], 'rows' => $order['rows'], 'cust' => $cust, 'cod' => true];
fomaxo_save_order($no, $rec);
fomaxo_log_order([date('Y-m-d H:i'), $no, 'Cash on delivery', rupees($total), $cust['name'], $cust['phone'], $cust['email'], $cust['address'], $cust['note'], implode(' | ', $order['rows']), '']);
fomaxo_send_emails($rec, 'Cash on delivery');
out(['status' => 'placed', 'order' => $no, 'total' => $total / 100]);
