<?php
declare(strict_types=1);
/* FOMAXO India — review requests: a few days after each order, a WhatsApp message asking the customer for an honest review through that order's
   private review link (shows Verified Purchaser). Optional single-use coupon (REVIEW-XXXXX), one per customer and only with their mobile.
   Used by admin → Orders → Review requests (one tap per customer, or the green Review button on an order) and by api/whatsapp.php (automatic). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/refill-lib.php';

/* settings, from the Settings box on Review requests: days after the order, sending hours (India time), coupon on/off + %, automatic on/off,
   remove not reviewed on/off + after how many days. The list shows orders from that day until 30 days after it. */
function rq_set(): array {
  $t = json_decode((string)shop_setting('rvreq'), true) ?: [];
  $d = max(1, min(120, (int)($t['days'] ?? 7))); $f = max(0, min(23, (int)($t['from'] ?? 11))); $to = max($f + 1, min(24, (int)($t['to'] ?? 20)));
  return ['days' => $d, 'from' => $f, 'to' => $to, 'list_to' => $d + 30, 'coupon' => !empty($t['coupon']), 'pct' => max(1, min(99, (int)($t['pct'] ?? 10))),
          'auto' => !empty($t['auto']), 'drop' => !empty($t['drop']), 'drop_days' => max(1, min(365, (int)($t['drop_days'] ?? 30)))];
}

/* the customer's coupon: REVIEW- + 5 letters, always the same for that mobile, so each customer can only ever get (and use) one */
function rq_code(string $phone): string { return fixed_code('REVIEW-', 'review|' . coupon_phone($phone), refill_key()); }

/* marks the order's request as sent ('tap' or 'auto'); with the coupon on, saves the customer's REVIEW- code in Coupons
   (single use, only with this customer's mobile, no end date). A code already saved for them is left as it is. */
function rq_mark(string $no, string $how = 'tap'): void {
  $sent = json_decode((string)shop_setting('rvreq_sent'), true) ?: [];
  if (isset($sent[$no])) return;   // one request per order
  $sent[$no] = date('Y-m-d') . ($how === 'auto' ? ' auto' : '');
  $set = rq_set();
  $s = shop_db()->prepare('SELECT phone FROM orders WHERE no = ?'); $s->execute([$no]); $ph = coupon_phone((string)$s->fetchColumn());
  if ($set['coupon'] && $ph !== '') phone_coupon(rq_code($ph), $set['pct'], $ph);
  shop_set('rvreq_sent', json_encode($sent));
}

/* how far each order's review link has got (reviews.sqlite, as api/reviews.php keeps it): token → total perfumes, reviewed, average stars */
function rq_reviewed(): array {
  static $out = null;
  if ($out !== null) return $out;
  $out = [];
  try {
    if (!($db = reviews_db())) return $out;
    $rv = [];
    foreach ($db->query('SELECT order_id, product, rating FROM reviews WHERE order_id IS NOT NULL') as $r) $rv[(int)$r['order_id']][(string)$r['product']] = (int)$r['rating'];
    foreach ($db->query('SELECT id, token, products FROM orders') as $o) {
      $ps = array_values(array_unique((array)json_decode((string)$o['products'], true))); $done = $rv[(int)$o['id']] ?? [];
      $out[$o['token']] = ['total' => max(count($ps), count($done)), 'done' => count($done), 'stars' => $done ? round(array_sum($done) / count($done), 1) : null];
    }
  } catch (Throwable $e) { error_log('FOMAXO review requests: ' . $e->getMessage()); }
  return $out;
}
function rq_info(array $o): array { return rq_reviewed()[(string)$o['review']] ?? ['total' => 0, 'done' => 0, 'stars' => null]; }

/* rv = every perfume reviewed, part = some, not = none yet */
function rq_state(array $a): string { return $a['total'] > 0 && $a['done'] >= $a['total'] ? 'rv' : ($a['done'] > 0 ? 'part' : 'not'); }

/* the status shown in admin: green Reviewed (with stars), Reviewed 1 of 2, or Not reviewed yet */
function rq_badge(array $a): string {
  $st = rq_state($a);
  if ($st === 'rv') return '<span class="rqst ok" title="Every perfume in this order is reviewed">Reviewed' . ($a['stars'] ? ' ★' . rtrim(rtrim(number_format($a['stars'], 1), '0'), '.') : '') . '</span>';
  if ($st === 'part') return '<span class="rqst part">Reviewed ' . $a['done'] . ' of ' . $a['total'] . '</span>';
  return '<span class="rqst">Not reviewed yet</span>';
}

/* Review requests: each customer's latest order from 'days' to 'list_to' days old with nothing in it reviewed yet (see latest_orders_due) */
function rq_due(): array {
  $set = rq_set();
  return latest_orders_due($set['days'], $set['list_to'], 'rvreq_sent',   // reviewed (all or part): never asked again for this order
    fn($o) => !preg_match('/^[a-f0-9]{32}$/', (string)$o['review']) || rq_info($o)['done'] > 0);
}

/* every order already asked, newest first, with its review status ('total', 'done', 'stars'); orders asked but still not reviewed
   'drop_days' after sending leave when Remove not reviewed is on */
function rq_asked(): array {
  $sent = json_decode((string)shop_setting('rvreq_sent'), true) ?: [];
  if (!$sent) return [];
  $set = rq_set(); $out = [];
  $s = shop_db()->prepare('SELECT id, no, created, name, phone, items, review FROM orders WHERE no IN (' . implode(',', array_fill(0, count($sent), '?')) . ')');
  $s->execute(array_map('strval', array_keys($sent)));
  foreach ($s->fetchAll() as $o) {
    $sd = (string)$sent[$o['no']]; $a = $o + rq_info($o) + ['sent' => substr($sd, 0, 10), 'auto' => str_ends_with($sd, 'auto')];
    if ($set['drop'] && rq_state($a) === 'not' && strtotime($a['sent']) < strtotime('today -' . $set['drop_days'] . ' days')) continue;
    $out[$o['no']] = $a;
  }
  uasort($out, fn($a, $b) => strcmp($b['sent'], $a['sent']) ?: strcmp((string)$b['created'], (string)$a['created']));
  return $out;
}

/* what the message says: first name, perfumes, the order's review link, and (coupon on) the % and the customer's code */
function rq_parts(array $o): array {
  $set = rq_set(); $p = refill_parts($o + ['days' => 0]); $cat = fomaxo_catalog()['products'];
  $names = array_values(array_unique(array_filter(array_map(fn($i) => empty($i['free']) ? (string)($cat[$i['id'] ?? '']['name'] ?? '') : '', json_decode((string)$o['items'], true) ?: []))));
  return ['first' => $p['first'] !== '' ? $p['first'] : 'there', 'perfumes' => $names ? implode(', ', $names) : $p['perfumes'], 'review' => 'https://fomaxo.in/#/review?t=' . $o['review'],
          'pct' => $set['pct'], 'code' => $set['coupon'] ? rq_code((string)$o['phone']) : null];
}

/* the message: a blank line between parts, the code in WhatsApp bold on its own line. The same words as the Meta templates review_ask / review_ask_coupon. */
function rq_text(array $p): string {
  $n2 = "\n\n";
  return 'Hi ' . $p['first'] . ',' . $n2 . "*Thank You Again For Your Order*\nI hope you received it safely and are enjoying " . $p['perfumes'] . '.'
    . $n2 . "Could you spare a minute to share your honest review?\nIt will show as Verified Purchaser.\n" . $p['review'] . "\nYour honest review helps others choose their FOMAXO."
    . ($p['code'] ? $n2 . 'As a thank you for your time, here is your personal code for ' . $p['pct'] . '% off your next order (single use):' . $n2 . '*' . $p['code'] . '*' : '')
    . $n2 . 'Just reply here if you need anything. If you would rather not get these messages, reply STOP.' . $n2 . "Thank you,\nFOMAXO";
}

/* the wa.me link with the ready message, for the WhatsApp buttons */
function rq_wa(array $o): string { return wa_link((string)$o['phone'], rq_text(rq_parts($o))); }
