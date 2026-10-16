<?php
declare(strict_types=1);
/* FOMAXO India — Razorpay Magic Checkout, shared by api/magic.php and the webhook in api/razorpay.php.
   Magic Checkout is Razorpay's own one-tap checkout window: the shopper's mobile number, saved addresses and payment come from
   Razorpay's network, so our checkout page and its form are skipped. It is switched on at Admin → Settings → Magic Checkout.
   The bag is still priced here (never in the browser), our coupons are checked here when Razorpay asks, COD follows the limits on
   Admin → Settings → Cash on delivery, and the order is saved, numbered, emailed and taken off the stock here as before. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/store-lib.php';

/* [key id, key secret, API address, test keys?] from fomaxo-private/razorpay-config.php, the same keys as api/razorpay.php */
function magic_keys(): array {
  $cfg = fomaxo_config();
  $id = trim((string)($cfg['razorpay_key_id'] ?? '')); $secret = trim((string)($cfg['razorpay_key_secret'] ?? ''));
  if (stripos($id, 'rzp_') !== 0 || stripos($id . $secret, 'PASTE') !== false) { $id = ''; $secret = ''; }
  $api = rtrim(is_string($cfg['razorpay_api'] ?? null) && $cfg['razorpay_api'] !== '' ? $cfg['razorpay_api'] : 'https://api.razorpay.com/v1', '/');   // override only for local testing
  return [$id, $secret, $api, str_starts_with($id, 'rzp_test_')];
}
function magic_rzp(string $method, string $path, ?array $body = null): array {
  [$id, $secret, $api] = magic_keys();
  $ch = curl_init($api . $path);
  curl_setopt_array($ch, [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 25,
    CURLOPT_USERPWD => "$id:$secret", CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json']]
    + ($body !== null ? [CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)] : []));
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  return [$code, is_string($res) ? json_decode($res, true) : null, $err ?: (string)$res];
}

/* the secret part of the Abandoned webhook address (Razorpay sends no signature with it), made from the Key Secret */
function magic_hook_key(): string { [, $secret] = magic_keys(); return $secret === '' ? '' : substr(hash_hmac('sha256', 'magic-abandoned', $secret), 0, 24); }
/* https://fomaxo.in */
function magic_site(): string { return 'https://' . (preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? '') ?: 'fomaxo.in'); }

/* a bag sent to Magic Checkout: by our receipt (what Razorpay calls order_id when it asks for shipping or coupons) or by the Razorpay order id */
function magic_row(string $receipt): ?array {
  $s = shop_db()->prepare('SELECT * FROM magic WHERE receipt = ?'); $s->execute([$receipt]); $r = $s->fetch();
  return $r ? $r + ['bag' => json_decode((string)$r['cart'], true) ?: []] : null;
}
function magic_row_by_rzp(string $rzp): ?array {
  if ($rzp !== '' && !str_starts_with($rzp, 'order_')) $rzp = "order_$rzp";   // the shipping address call sends it without order_
  $s = shop_db()->prepare('SELECT * FROM magic WHERE rzp = ?'); $s->execute([$rzp]); $r = $s->fetch();
  return $r ? $r + ['bag' => json_decode((string)$r['cart'], true) ?: []] : null;
}

/* Razorpay wants plain letters and digits for sku and variant_id: "oldmoney" and "oldmoneyv50"; magic_pid() turns an sku back into our product id */
function magic_sku(string $id): string { return preg_replace('/[^A-Za-z0-9]/', '', $id) ?? ''; }
function magic_variant(string $id, string $opt): string { return magic_sku($id) . 'v' . (preg_replace('/[^A-Za-z0-9]/', '', $opt) ?? ''); }
function magic_pid(string $sku): string {
  foreach (array_keys(fomaxo_catalog()['products']) as $id) if (magic_sku((string)$id) === $sku) return (string)$id;
  return '';
}

/* What the bag costs before any coupon: the together saving taken off, the bag always keeping at least ₹1 */
function magic_amount(array $bag): int { return $bag['subtotal'] - max(0, min($bag['together'], $bag['subtotal'] - 100)); }

/* A coupon typed in Magic Checkout, checked against the bag here: ['error' => …] or the coupon (fomaxo_coupon) plus
   'value' = what Razorpay takes off the amount it showed (the coupon, and any together saving the coupon gives up with "Use the bigger offer") */
function magic_coupon(array $bag, string $code, string $phone): array {
  $cp = fomaxo_coupon(['coupon' => $code, 'phone' => $phone], $bag);
  if (!$cp || isset($cp['error'])) return $cp ?: ['error' => 'Please type your coupon code.'];
  $o = $bag; if (($e = fomaxo_add_free($o, $cp)) !== '') return ['error' => $e];
  return $cp + ['value' => max(0, magic_amount($bag) - ($bag['subtotal'] - $cp['discount'] - (int)$cp['offer']))];
}

/* Cash on delivery for this bag, the same rules as api/cod.php: [allowed, fee in paise].
   While the COD test name (onlyName) is set, Magic Checkout never offers COD: Razorpay does not tell us the name before it shows the choice. */
function magic_cod(array $bag, int $couponValue): array {
  $cod = fomaxo_catalog()['cod'];
  if (!$cod || $cod['onlyName'] !== '' || $bag['subtotal'] < $cod['min']) return [false, 0];
  if ($cod['max'] > 0 && magic_amount($bag) - $couponValue >= $cod['max']) return [false, 0];
  return [true, (int)$cod['fee']];
}

/* "KA" or "Karnataka" → "Karnataka" (our list of states), else what Razorpay sent */
const MAGIC_STATES = ['AN' => 'Andaman and Nicobar Islands', 'AP' => 'Andhra Pradesh', 'AR' => 'Arunachal Pradesh', 'AS' => 'Assam', 'BR' => 'Bihar', 'CH' => 'Chandigarh',
  'CT' => 'Chhattisgarh', 'CG' => 'Chhattisgarh', 'DN' => 'Dadra and Nagar Haveli and Daman and Diu', 'DD' => 'Dadra and Nagar Haveli and Daman and Diu', 'DH' => 'Dadra and Nagar Haveli and Daman and Diu',
  'DL' => 'Delhi', 'GA' => 'Goa', 'GJ' => 'Gujarat', 'HR' => 'Haryana', 'HP' => 'Himachal Pradesh', 'JK' => 'Jammu and Kashmir', 'JH' => 'Jharkhand', 'KA' => 'Karnataka', 'KL' => 'Kerala',
  'LA' => 'Ladakh', 'LD' => 'Lakshadweep', 'MP' => 'Madhya Pradesh', 'MH' => 'Maharashtra', 'MN' => 'Manipur', 'ML' => 'Meghalaya', 'MZ' => 'Mizoram', 'NL' => 'Nagaland',
  'OR' => 'Odisha', 'OD' => 'Odisha', 'PY' => 'Puducherry', 'PB' => 'Punjab', 'RJ' => 'Rajasthan', 'SK' => 'Sikkim', 'TN' => 'Tamil Nadu', 'TG' => 'Telangana', 'TS' => 'Telangana',
  'TR' => 'Tripura', 'UP' => 'Uttar Pradesh', 'UT' => 'Uttarakhand', 'UK' => 'Uttarakhand', 'WB' => 'West Bengal'];
function magic_state(string $s): string {
  $s = trim($s); if (isset(MAGIC_STATES[strtoupper($s)])) return MAGIC_STATES[strtoupper($s)];
  foreach (FOMAXO_STATES as $n) if (strcasecmp($n, $s) === 0) return $n;
  return mb_substr($s, 0, 60);
}
/* "+91 98765 43210" from any way of writing an Indian mobile ('' when it isn't one) */
function magic_phone(string $p): string {
  $d = preg_replace('/\D/', '', $p) ?? '';
  if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
  if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
  return preg_match('/^[6-9]\d{9}$/', $d) ? '+91 ' . substr($d, 0, 5) . ' ' . substr($d, 5) : '';
}

/* The delivery details Razorpay collected (the order's customer_details), in the shape our orders use */
function magic_customer(array $ro, array $pay): array {
  $cd = is_array($ro['customer_details'] ?? null) ? $ro['customer_details'] : [];
  $a = is_array($cd['shipping_address'] ?? null) ? $cd['shipping_address'] : (is_array($cd['billing_address'] ?? null) ? $cd['billing_address'] : []);
  $t = fn($v, int $max) => trim(mb_substr(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', is_scalar($v) ? (string)$v : '') ?? '', 0, $max));
  $phone = magic_phone((string)($a['contact'] ?? '')) ?: magic_phone((string)($cd['contact'] ?? '')) ?: magic_phone((string)($pay['contact'] ?? ''));
  $email = $t($cd['email'] ?? $pay['email'] ?? '', 120); if (!filter_var($email, FILTER_VALIDATE_EMAIL) || str_ends_with($email, '@razorpay.com')) $email = '';
  $o = ['name' => $t($a['name'] ?? '', 80), 'phone' => $phone ?: $t($cd['contact'] ?? $pay['contact'] ?? '', 20), 'email' => $email,
    'house' => $t($a['line1'] ?? '', 80), 'street' => $t($a['line2'] ?? '', 120), 'landmark' => $t($a['landmark'] ?? '', 80), 'city' => $t($a['city'] ?? '', 60),
    'state' => magic_state((string)($a['state'] ?? '')), 'pin' => $t($a['zipcode'] ?? '', 10), 'note' => '', 'wa' => 0];
  if ($o['name'] === '') $o['name'] = 'Magic Checkout customer';
  $o['address'] = implode(', ', array_filter([$o['house'], $o['street'], $o['landmark'] !== '' ? 'Near ' . $o['landmark'] : '', $o['city'], trim($o['state'] . ' ' . $o['pin'])]));
  return $o;
}

/* Left at checkout: the mobile number (and anything else) a shopper gave Magic Checkout, kept on the visit the bag came from.
   Fields already there are never emptied, and a line already marked ordered stays ordered. */
function magic_lead(array $m, array $f): void {
  $sid = (string)$m['sid'] !== '' ? (string)$m['sid'] : substr(md5('magic:' . $m['receipt']), 0, 16);
  $bag = array_map(fn($it) => ['id' => $it['id'], 'opt' => (string)$it['opt'], 'qty' => (int)$it['qty'], 'name' => preg_replace('/^FOMAXO /', '', (string)$it['name'])], $m['bag']['items'] ?? []);
  $s = shop_db()->prepare('SELECT * FROM leads WHERE sid = ?'); $s->execute([$sid]); $old = $s->fetch() ?: [];
  $keep = fn(string $k, string $v) => $v !== '' ? $v : (string)($old[$k] ?? '');
  shop_upsert('leads', ['sid'], ['sid' => $sid, 'vid' => (string)($m['vid'] ?: ($old['vid'] ?? 'magic')), 'created' => $old['created'] ?? shop_now(), 'updated' => shop_now(),
    'name' => $keep('name', mb_substr((string)($f['name'] ?? ''), 0, 80)), 'phone' => $keep('phone', substr(preg_replace('/\D/', '', magic_phone((string)($f['phone'] ?? ''))) ?? '', -10)),
    'step' => ($old['step'] ?? '') === 'payment' || ($f['step'] ?? '') === 'payment' ? 'payment' : 'details', 'bag' => json_encode($bag, JSON_UNESCAPED_UNICODE),
    'total' => (int)($m['bag']['subtotal'] ?? 0), 'ordered' => (int)($old['ordered'] ?? 0), 'email' => $keep('email', filter_var((string)($f['email'] ?? ''), FILTER_VALIDATE_EMAIL) ?: ''),
    'state' => $keep('state', in_array($f['state'] ?? '', FOMAXO_STATES, true) ? $f['state'] : ''), 'address' => $keep('address', mb_substr((string)($f['address'] ?? ''), 0, 300))]);
}

/* The shopper finished Magic Checkout: paid online, or chose cash on delivery. Razorpay's order and payment are read back from Razorpay
   (never from the browser), the bag priced when Magic Checkout opened is used with the coupon Razorpay applied, and the order is saved once:
   it gets the next number, its items come off the stock, the coupon counts as used, and the store and the customer are emailed.
   The page, the webhook and a retry can all call this; only the first one does anything. Returns the order, or null. */
function magic_finalize(string $oid, string $pid, string $via): ?array {
  $m = magic_row_by_rzp($oid); if (!$m) return null;
  $have = shop_find_order($oid); if ($have && $have['status'] !== 'awaiting') return $have;
  [$c1, $ro, $raw1] = magic_rzp('GET', '/orders/' . rawurlencode($oid));
  if ($c1 !== 200 || !is_array($ro)) { error_log("FOMAXO Magic: order $oid not read: $raw1"); return null; }
  $pay = [];
  if ($pid !== '') { [$c2, $p, $raw2] = magic_rzp('GET', '/payments/' . rawurlencode($pid)); if ($c2 === 200 && is_array($p)) $pay = $p; else error_log("FOMAXO Magic: payment $pid not read: $raw2"); }
  if (!$pay) {   // the webhook may name only the order: its payments, the paid or COD one
    [$c2, $ps] = magic_rzp('GET', '/orders/' . rawurlencode($oid) . '/payments');
    foreach (($c2 === 200 ? (array)($ps['items'] ?? []) : []) as $p) if (in_array($p['status'] ?? '', ['captured', 'authorized'], true) || ($p['method'] ?? '') === 'cod') { $pay = $p; break; }
  }
  if (($pay['order_id'] ?? $oid) !== $oid) { error_log("FOMAXO Magic: payment {$pid} is not for $oid"); return null; }
  $cod = ($pay['method'] ?? '') === 'cod' || ($ro['status'] ?? '') === 'placed';
  $ok = $cod ? in_array($ro['status'] ?? '', ['placed', 'paid'], true) || in_array($pay['status'] ?? '', ['pending', 'captured'], true)
             : ($ro['status'] ?? '') === 'paid' || in_array($pay['status'] ?? '', ['captured', 'authorized'], true);
  if (!$ok) { error_log("FOMAXO Magic: $oid not paid or placed yet (" . ($ro['status'] ?? '?') . '/' . ($pay['status'] ?? '?') . ')'); return null; }
  [, , , $test] = magic_keys();

  $cust = magic_customer($ro, $pay);
  $bag = $m['bag']; $notes = [];
  $code = (string)($ro['promotions'][0]['code'] ?? $m['coupon']);
  $cp = $code !== '' ? magic_coupon($bag, $code, $cust['phone']) : [];
  if (isset($cp['error'])) { $notes[] = "Coupon $code was not counted here: {$cp['error']}"; $cp = []; }
  if ($cp && ($e = fomaxo_add_free($bag, $cp)) !== '') $notes[] = $e;
  $together = fomaxo_together_kept($bag, $cp);
  $codFee = $cod ? max(0, (int)($ro['cod_fee'] ?? 0)) : 0;
  $expected = $bag['subtotal'] - ($cp['discount'] ?? 0) - $together + $codFee + max(0, (int)($ro['shipping_fee'] ?? 0));
  $charged = (int)(($pay['amount'] ?? 0) ?: ($ro['amount'] ?? 0));
  if ($charged !== $expected) $notes[] = 'Check the amount: Razorpay ' . ($cod ? 'will collect ' : 'took ') . rupees($charged) . ', our prices make it ' . rupees($expected) . '.';
  $payId = (string)($pay['id'] ?? $pid);

  try {
    [$rec, $first] = shop_tx(function (PDO $db) use ($oid, $payId, $cod, $cust, $bag, $cp, $together, $codFee, $charged, $test, $notes) {
      $have = shop_find_order($oid, true, $db);
      if ($have && $have['status'] !== 'awaiting') return [$have, false];
      $now = shop_now();
      $rec = ['ref' => $oid, 'no' => shop_next_no($db), 'created' => $now, 'paid_at' => $cod ? null : $now, 'method' => $cod ? 'cod' : 'online', 'status' => $cod ? 'new' : 'paid',
        'total' => $charged, 'codFee' => $codFee, 'items' => $bag['items'], 'rows' => $bag['rows'], 'cust' => $cust, 'payment' => $payId, 'test' => $test, 'stock_taken' => true,
        'coupon' => $cp['code'] ?? '', 'discount' => $cp['discount'] ?? 0, 'together' => $together];
      if ($have) $db->prepare('DELETE FROM orders WHERE ref = ?')->execute([$oid]);
      shop_take_stock($db, $rec['items'], false);   // Razorpay has the order already, so stock goes down even if it was short
      shop_insert_order($db, $rec);
      $db->prepare('UPDATE orders SET admin_note = ? WHERE ref = ?')->execute([mb_substr('Magic Checkout' . ($notes ? '. ' . implode(' ', $notes) : ''), 0, 500), $oid]);
      shop_coupon_spent($db, $rec['coupon']);   // a one-use coupon deletes itself now
      $rec['paid'] = $rec['paid_at'];
      return [$rec, true];
    });
  } catch (Throwable $e) { error_log('FOMAXO Magic save order: ' . $e->getMessage()); return null; }
  if ($first) {
    $rec['cod'] = $cod;
    $rec['review'] = fomaxo_review_token($rec);   // Verified Purchaser review link, emailed below
    if ($rec['review'] !== '') shop_db()->prepare('UPDATE orders SET review = ? WHERE ref = ?')->execute([$rec['review'], $oid]);
    try { magic_lead($m, ['name' => $cust['name'], 'phone' => $cust['phone'], 'email' => $cust['email'], 'state' => $cust['state'], 'address' => $cust['address'], 'step' => 'payment']);
      shop_db()->prepare('UPDATE leads SET ordered = 1 WHERE sid = ?')->execute([(string)$m['sid'] !== '' ? $m['sid'] : substr(md5('magic:' . $m['receipt']), 0, 16)]); } catch (Throwable $e) {}
    $c = $cust;
    fomaxo_log_order([date('Y-m-d H:i'), $rec['no'], ($cod ? 'Cash on delivery' : 'Razorpay — PAID') . ' (Magic Checkout)' . ($test ? ' (TEST)' : '') . " via $via", rupees($charged),
      $c['name'], $c['phone'], $c['email'], $c['address'], '', implode(' | ', $rec['rows']), $payId, $rec['coupon']]);
    fomaxo_send_emails($rec, $cod ? 'Cash on delivery' : 'Paid online (Razorpay)' . ($test ? ' — TEST' : ''));
  }
  return $rec;
}
