<?php
declare(strict_types=1);
/* FOMAXO India — refill reminders: customers whose latest order was about 45 days ago (admin can change it) get a personal WhatsApp message with a single-use
   coupon (REFILL-XXXXX, only with their mobile) and their Verified Purchaser review link.
   Used by admin → Members → Refill reminders (one tap per customer) and by api/whatsapp.php (automatic, through the WhatsApp Business API). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/store-lib.php';

/* timing, set on admin → Refill reminders → Automatic sending: days after the latest order, and the sending hours (India time).
   The list shows customers list_from to list_to days after their latest order: typed in on the Refill reminders bar, else 5 days before that day until 15 days after it. */
function refill_time(): array {
  $t = json_decode((string)shop_setting('refill_time'), true) ?: [];
  $d = max(1, min(365, (int)($t['days'] ?? 45))); $f = max(0, min(23, (int)($t['from'] ?? 11))); $to = max($f + 1, min(24, (int)($t['to'] ?? 20)));
  $lf = max(1, min(730, (int)($t['list_from'] ?? $d - 5))); $lt = max($lf, min(730, (int)($t['list_to'] ?? $d + 15)));
  return ['days' => $d, 'from' => $f, 'to' => $to, 'list_from' => $lf, 'list_to' => $lt];
}
/* the list days typed in on the Refill reminders bar (none saved = the default around Days after order), kept when Automatic sending is saved */
function refill_time_saved(): array { return array_intersect_key(json_decode((string)shop_setting('refill_time'), true) ?: [], ['list_from' => 1, 'list_to' => 1]); }
/* 11 → "11 AM", 20 → "8 PM", 0 and 24 → "12 AM" */
function refill_hour(int $h): string { return (($h % 12) ?: 12) . ' ' . ($h % 24 < 12 ? 'AM' : 'PM'); }
function refill_pct(): int { return max(1, min(99, (int)(shop_setting('refill_pct') ?? '10'))); }
/* the automatic coupon's minimum order in ₹ (0 = none), set on Automatic sending */
function refill_min(): int { return max(0, (int)(shop_setting('refill_min') ?? '0')); }
/* what the coupon gives, as the message says it: "10% off your next order" or "10% off your next order of ₹1,500 or more" */
function refill_offer(int $pct, int $min): string { return $pct . '% off your next order' . ($min ? ' of ' . rupees($min * 100) . ' or more' : ''); }
/* a private key of this shop for the coupon codes and the webhook Verify token (made once, never shown) */
function refill_key(): string { $k = shop_setting('wa_key'); if (!$k) { $k = bin2hex(random_bytes(16)); shop_set('wa_key', $k); } return $k; }

/* the order's coupon: REFILL- + 5 letters, always the same for that order */
function refill_code(string $no): string { return fixed_code('REFILL-', 'refill|' . $no, refill_key()); }

/* marks the order's reminder as sent ('tap' = FOMAXO tapped WhatsApp, 'auto' = sent by itself). Sent by itself also saves the order's REFILL- code
   in Coupons with the % and minimum set on Automatic sending (single use, only with this customer's mobile, no end date); a tap makes its own code (admin). */
function refill_mark(string $no, string $how = 'tap'): void {
  $sent = json_decode((string)shop_setting('refill_sent'), true) ?: [];
  if (isset($sent[$no]) && $how === 'tap') { $sent[$no] = date('Y-m-d'); shop_set('refill_sent', json_encode($sent)); return; }   // sent again from the admin: the new date
  if (isset($sent[$no])) return;   // one message per order
  $sent[$no] = date('Y-m-d') . ($how === 'auto' ? ' auto' : '');
  $s = shop_db()->prepare('SELECT phone FROM orders WHERE no = ?'); $s->execute([$no]); $ph = coupon_phone((string)$s->fetchColumn());
  if ($how === 'auto' && $ph !== '') phone_coupon(refill_code($no), refill_pct(), $ph, refill_min() * 100);
  $keep = date('Y-m-d', strtotime('-' . (refill_time()['list_to'] + 30) . ' days'));   // forgotten only once the order has long left the list
  shop_set('refill_sent', json_encode(array_filter($sent, fn($d) => substr($d, 0, 10) >= $keep)));
}

/* orders taken off Review requests ('rvreq_sent') or Refill reminders ('refill_sent') with the ✕ in admin: order no → day removed */
function list_hidden(string $sentKey): array { return json_decode((string)shop_setting($sentKey . '_hide'), true) ?: []; }
function list_hide(string $sentKey, string $no): void {
  $h = array_filter(list_hidden($sentKey), fn($d) => $d >= date('Y-m-d', strtotime('-400 days')));   // old ones are forgotten
  $h[$no] = date('Y-m-d'); shop_set($sentKey . '_hide', json_encode($h));
}

/* each customer's (same mobile = one customer) latest real order (not cancelled, not an unpaid online try, not test) that is $from to $to days old,
   not yet sent first, then oldest first. 'sent' = date already sent (from the $sentKey setting), 'auto' = sent by itself, 'optin' = ticked WhatsApp offers
   at checkout on any order (only they get automatic messages), 'stopped' = replied STOP and has not ticked offers since. $skip($o) leaves an order out.
   Shared by Refill reminders and Review requests. */
function latest_orders_due(int $from, int $to, string $sentKey, ?callable $skip = null): array {
  $last = [];
  foreach (shop_db()->query("SELECT id, no, created, name, phone, items, wa_optin, review FROM orders WHERE status IN ('new', 'paid', 'delivered') AND test = 0 AND COALESCE(no, '') <> '' ORDER BY created, id") as $o) {
    if (($k = coupon_phone((string)$o['phone'])) === '') continue;
    $o['optin'] = (int)$o['wa_optin'] === 1 || !empty($last[$k]['optin']);   // ticked "Send me offers on WhatsApp" on any order (STOP clears them all)
    $last[$k] = $o;   // the latest order wins
  }
  $sent = json_decode((string)shop_setting($sentKey), true) ?: [];
  $stops = json_decode((string)shop_setting('wa_stop'), true) ?: [];
  $hid = list_hidden($sentKey);   // ✕ in admin: that order is off the list (a new order brings the customer back)
  $due = [];
  foreach ($last as $k => $o) {
    $days = (int)floor((strtotime('today') - strtotime(substr((string)$o['created'], 0, 10))) / 86400);
    if ($days < $from || $days > $to || isset($hid[$o['no']]) || ($skip && $skip($o))) continue;
    $sd = (string)($sent[$o['no']] ?? '');
    $due["m:$k"] = $o + ['days' => $days, 'sent' => $sd !== '' ? substr($sd, 0, 10) : null, 'auto' => str_ends_with($sd, 'auto'), 'stopped' => isset($stops[$k]) && !$o['optin']];
  }
  uasort($due, fn($a, $b) => (int)($a['sent'] || $a['stopped']) <=> (int)($b['sent'] || $b['stopped']) ?: $b['days'] <=> $a['days']);
  return $due;
}
/* Refill reminders: the customers in the list window (refill_time) */
function refill_due(): array { $tm = refill_time(); return latest_orders_due($tm['list_from'], $tm['list_to'], 'refill_sent'); }

/* the review link of an order, or null once anything in it is reviewed (reviews.sqlite, as api/reviews.php keeps it): a customer who reviewed
   all or part of the order gets the refill reminder without the review request */
function refill_review(string $token): ?string {
  if (!preg_match('/^[a-f0-9]{32}$/', $token)) return null;
  try {
    if (!($db = reviews_db())) return null;
    $s = $db->prepare('SELECT id, products FROM orders WHERE token = ?'); $s->execute([$token]); $r = $s->fetch();
    if (!$r) return null;
    $s = $db->prepare('SELECT 1 FROM reviews WHERE order_id = ? LIMIT 1'); $s->execute([$r['id']]);
    if ($s->fetchColumn()) return null;
  } catch (Throwable $e) { return null; }
  return "https://fomaxo.in/#/review?t=$token";
}

/* what the message says for one order: first name, perfumes, days, offer words, coupon, review link (null once anything is reviewed) */
function refill_parts(array $o): array {
  $names = array_values(array_unique(array_filter(array_map(fn($i) => trim((string)($i['name'] ?? '')), json_decode((string)$o['items'], true) ?: []))));
  return ['first' => first_name((string)$o['name']), 'perfumes' => $names ? implode(', ', $names) : 'your FOMAXO perfume', 'days' => (int)$o['days'],
          'offer' => refill_offer(refill_pct(), refill_min()), 'code' => refill_code((string)$o['no']), 'review' => refill_review((string)$o['review'])];
}
