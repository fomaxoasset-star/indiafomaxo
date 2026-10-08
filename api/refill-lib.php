<?php
declare(strict_types=1);
/* FOMAXO India — refill reminders: customers whose latest order was about 45 days ago (admin can change it) get a personal WhatsApp message with a single-use
   coupon (REFILL-XXXXX, only with their mobile) and their Verified Purchaser review link.
   Used by admin → Members → Refill reminders (one tap per customer) and by api/whatsapp.php (automatic, through the WhatsApp Business API). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/store-lib.php';

/* timing, set on admin → Refill reminders → Automatic sending: days after the latest order, and the sending hours (India time).
   The list shows customers from 5 days before that day until 15 days after it. */
function refill_time(): array {
  $t = json_decode((string)shop_setting('refill_time'), true) ?: [];
  $d = max(1, min(365, (int)($t['days'] ?? 45))); $f = max(0, min(23, (int)($t['from'] ?? 11))); $to = max($f + 1, min(24, (int)($t['to'] ?? 20)));
  return ['days' => $d, 'from' => $f, 'to' => $to, 'list_from' => max(1, $d - 5), 'list_to' => $d + 15];
}
/* 11 → "11 AM", 20 → "8 PM", 0 and 24 → "12 AM" */
function refill_hour(int $h): string { return (($h % 12) ?: 12) . ' ' . ($h % 24 < 12 ? 'AM' : 'PM'); }
function refill_pct(): int { return max(1, min(99, (int)(shop_setting('refill_pct') ?? '10'))); }
/* a private key of this shop for the coupon codes and the webhook Verify token (made once, never shown) */
function refill_key(): string { $k = shop_setting('wa_key'); if (!$k) { $k = bin2hex(random_bytes(16)); shop_set('wa_key', $k); } return $k; }

/* the order's coupon: REFILL- + 5 letters, always the same for that order */
function refill_code(string $no): string {
  $abc = 'ABCDEFGHJKMNPQRSTUVWXYZ23456789'; $hx = hash_hmac('sha256', 'refill|' . $no, refill_key()); $c = 'REFILL-';
  for ($i = 0; $i < 5; $i++) $c .= $abc[hexdec(substr($hx, $i * 2, 2)) % strlen($abc)];
  return $c;
}

/* marks the order's reminder as sent ('tap' = FOMAXO tapped WhatsApp, 'auto' = sent by itself) and, with $coupon, saves the order's
   REFILL- code in Coupons (the automatic message: single use, only with this customer's mobile, no end date). A tap makes its own code (admin). */
function refill_mark(string $no, string $how = 'tap', bool $coupon = true): void {
  $sent = json_decode((string)shop_setting('refill_sent'), true) ?: [];
  if (isset($sent[$no])) return;   // one message per order
  $sent[$no] = date('Y-m-d') . ($how === 'auto' ? ' auto' : '');
  $s = shop_db()->prepare('SELECT phone FROM orders WHERE no = ?'); $s->execute([$no]); $ph = coupon_phone((string)$s->fetchColumn());
  if ($coupon && $ph !== '') {
    $code = refill_code($no); $s = shop_db()->prepare('SELECT 1 FROM coupons WHERE code = ?'); $s->execute([$code]);
    if (!$s->fetchColumn()) shop_upsert('coupons', ['code'], ['code' => $code, 'kind' => 'pct', 'value' => refill_pct(), 'min_order' => 0, 'starts' => '', 'ends' => '',
      'max_uses' => 1, 'stack' => 0, 'active' => 1, 'phone' => $ph, 'created' => shop_now()]);
  }
  $keep = date('Y-m-d', strtotime('-' . (refill_time()['list_to'] + 30) . ' days'));   // forgotten only once the order has long left the list
  shop_set('refill_sent', json_encode(array_filter($sent, fn($d) => substr($d, 0, 10) >= $keep)));
}

/* every customer (same mobile = one customer) whose latest real order is in the list window (refill_time), oldest first; 'sent' = date already reminded,
   'auto' = sent by itself, 'optin' = ticked WhatsApp offers at checkout (only they get automatic messages), 'stopped' = replied STOP and has not ticked offers since */
function refill_due(): array {
  $last = [];
  foreach (shop_db()->query("SELECT id, no, created, name, phone, items, wa_optin, review FROM orders WHERE status IN ('new', 'paid', 'delivered') AND test = 0 AND COALESCE(no, '') <> '' ORDER BY created, id") as $o) {
    if (($k = coupon_phone((string)$o['phone'])) === '') continue;
    $o['optin'] = (int)$o['wa_optin'] === 1 || !empty($last[$k]['optin']);   // ticked "Send me offers on WhatsApp" on any order (STOP clears them all)
    $last[$k] = $o;   // the latest order wins
  }
  $sent = json_decode((string)shop_setting('refill_sent'), true) ?: [];
  $stops = json_decode((string)shop_setting('wa_stop'), true) ?: [];
  $tm = refill_time(); $due = [];
  foreach ($last as $k => $o) {
    $days = (int)floor((strtotime('today') - strtotime(substr((string)$o['created'], 0, 10))) / 86400);
    if ($days < $tm['list_from'] || $days > $tm['list_to']) continue;
    $sd = (string)($sent[$o['no']] ?? '');
    $due["m:$k"] = $o + ['days' => $days, 'sent' => $sd !== '' ? substr($sd, 0, 10) : null, 'auto' => str_ends_with($sd, 'auto'), 'stopped' => isset($stops[$k]) && !$o['optin']];
  }
  uasort($due, fn($a, $b) => (int)($a['sent'] || $a['stopped']) <=> (int)($b['sent'] || $b['stopped']) ?: $b['days'] <=> $a['days']);
  return $due;
}

/* the review link of an order, or null when every perfume in it is already reviewed (reviews.sqlite, as api/reviews.php keeps it) */
function refill_review(string $token): ?string {
  global $PRIV;
  if (!preg_match('/^[a-f0-9]{32}$/', $token) || !is_file("$PRIV/reviews.sqlite")) return null;
  try {
    $db = new PDO("sqlite:$PRIV/reviews.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $s = $db->prepare('SELECT id, products FROM orders WHERE token = ?'); $s->execute([$token]); $r = $s->fetch();
    if (!$r) return null;
    $s = $db->prepare('SELECT product FROM reviews WHERE order_id = ?'); $s->execute([$r['id']]);
    if (!array_diff((array)json_decode((string)$r['products'], true), $s->fetchAll(PDO::FETCH_COLUMN))) return null;
  } catch (Throwable $e) { return null; }
  return "https://fomaxo.in/#/review?t=$token";
}

/* what the message says for one order: first name, perfumes, days, %, coupon, review link (null once everything is reviewed) */
function refill_parts(array $o): array {
  $names = array_values(array_unique(array_filter(array_map(fn($i) => trim((string)($i['name'] ?? '')), json_decode((string)$o['items'], true) ?: []))));
  return ['first' => preg_split('/\s+/u', trim((string)$o['name']))[0] ?? '', 'perfumes' => $names ? implode(', ', $names) : 'your FOMAXO perfume', 'days' => (int)$o['days'],
          'pct' => refill_pct(), 'code' => refill_code((string)$o['no']), 'review' => refill_review((string)$o['review'])];
}

/* the message, with a blank line between each part so it reads like a personal note (the same words as the WhatsApp template; *…* is WhatsApp bold) */
function refill_text(array $p): string {
  $n2 = "\n\n";
  return 'Hi ' . $p['first'] . ',' . $n2 . 'I hope you are enjoying ' . $p['perfumes'] . '. It has been ' . $p['days'] . ' days since your order, so your bottle may be running low.'
    . $n2 . 'As a thank you, here is your personal code for ' . $p['pct'] . '% off your next order (single use):' . $n2 . '*' . $p['code'] . '*'
    . $n2 . "You can reorder anytime here:\nhttps://fomaxo.in"
    . ($p['review'] ? $n2 . "If you have a moment, we would love your honest review. It will show as Verified Purchaser:\n" . $p['review'] : '')
    . $n2 . 'Just reply here if you would like help choosing your next scent. If you would rather not get these messages, reply STOP.' . $n2 . "Thank you,\nFOMAXO";
}
