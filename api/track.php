<?php
declare(strict_types=1);
/* FOMAXO India — visitor analytics, kept on this server (no Google or other trackers).
   POST (navigator.sendBeacon from index.html) {t: type, v: visitor id, s: visit id, src, p: path, id, opt, q, lead}
   Types: view (page), product (product page), add (to bag), checkout, pay (payment step), card (the Razorpay payment page opened),
   buy (order placed), lead (checkout details typed), scroll (how far down the homepage: q = 25, 50, 75 or 100, once each per visit),
   ping (still on the site). c is the utm_campaign name in the link the visit came from, kept with the visit. Visitor and visit ids are random strings made in the browser; no IP address,
   name or cookie is stored for ordinary visits. Each visit's country and (in India) state are looked up from the IP address on this
   server (api/geo, DB-IP Lite); only the country and state are kept, never the address. Bots and the shop owner's own visits (signed in to /admin) are not counted. */
require __DIR__ . '/store-lib.php';
require __DIR__ . '/geo-lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
http_response_code(204);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
/* what the last calls here were: "time why" for each reason (bot, owner, bad, error), at most once a minute each, shown on Admin → Analytics
   so a day of zeros can be told apart from visits that reach the server but are not saved */
function track_seen(string $why, string $msg = ''): void {
  try { $k = "track_$why"; $old = (int)shop_setting($k); if ($msg !== '' || time() - $old > 60) shop_set($k, time() . ($msg !== '' ? ' ' . mb_substr($msg, 0, 300) : '')); } catch (Throwable $e) {}
}
register_shutdown_function(function () { $e = error_get_last(); if ($e && in_array($e['type'], [E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR], true)) track_seen('error', $e['message']); });
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|embedly|python|curl|wget|httpclient|phantom|selenium/i', $ua)) { track_seen('bot'); exit; }
if (fomaxo_is_admin_visitor()) { track_seen('owner'); exit; }

$raw = (string)file_get_contents('php://input', false, null, 0, 8192);
$in = json_decode($raw, true); if (!is_array($in)) { track_seen('bad'); exit; }
$t = (string)($in['t'] ?? '');
$id = fn($k) => preg_match('/^[a-z0-9]{8,16}$/', (string)($in[$k] ?? '')) ? (string)$in[$k] : '';
$vid = $id('v'); $sid = $id('s');
if (!$vid || !$sid || !in_array($t, ['view', 'product', 'add', 'checkout', 'pay', 'card', 'buy', 'lead', 'scroll', 'ping', 'invite'], true)) { track_seen('bad'); exit; }

try {
  $db = shop_db(); $now = time();
  shop_upsert('online', ['vid'], ['vid' => $vid, 'last' => $now]);
  $db->prepare('UPDATE visits SET last = ?, pages = pages + ? WHERE sid = ?')->execute([$now, $t === 'view' ? 1 : 0, $sid]);
  if (random_int(1, 200) === 1) {   // keep the tables small
    $db->prepare('DELETE FROM online WHERE last < ?')->execute([$now - 3600]);
    $db->prepare('DELETE FROM events WHERE ts < ?')->execute([date('Y-m-d H:i:s', $now - 800 * 86400)]);
    $db->prepare('DELETE FROM visits WHERE last < ?')->execute([$now - 800 * 86400]);
  }
  if ($t === 'ping') exit;
  /* Invite friends (Share with friends on a product page): this device's visitor id is kept with the sharer's WhatsApp number. The link they share carries
     ?ref=<their visitor id>; when a friend orders from it, the sharer shows in Left at checkout as "Invited a friend" so the shop can send their coupon. */
  $db->exec('CREATE TABLE IF NOT EXISTS inviters(vid VARCHAR(16) PRIMARY KEY, phone VARCHAR(24) NOT NULL, name VARCHAR(80) NOT NULL DEFAULT \'\', at INT NOT NULL DEFAULT 0)');
  if ($t === 'invite') {
    $phone = wa_who_phone((string)($in['w'] ?? '')) ?: substr(preg_replace('/\D/', '', (string)($in['ph'] ?? '')), -10);
    if (!preg_match('/^[6-9]\d{9}$/', $phone)) exit;
    $name = trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string)($in['n'] ?? '')) ?? '', 0, 80));
    if ($name === '') { $s = $db->prepare("SELECT name FROM orders WHERE phone LIKE ? ORDER BY created DESC LIMIT 1"); $s->execute(['%' . $phone]); $name = mb_substr((string)$s->fetchColumn(), 0, 80); }
    shop_upsert('inviters', ['vid'], ['vid' => $vid, 'phone' => $phone, 'name' => $name, 'at' => $now]);
    exit;
  }
  if ($t === 'lead') {   // name, mobile and bag typed at checkout, for "Left at checkout"
    $l = is_array($in['lead'] ?? null) ? $in['lead'] : [];
    if (($l['step'] ?? '') === 'visit') {   // opened the site from a link in our WhatsApp (?w= personal code): in Left at checkout right away, with the number we wrote to
      $phone = wa_who_phone((string)($l['who'] ?? '')); if ($phone === '') exit;
      $s = $db->prepare('SELECT 1 FROM leads WHERE sid = ?'); $s->execute([$sid]); if ($s->fetchColumn()) exit;   // checkout already noted this visit
      $s = $db->prepare("SELECT name FROM orders WHERE phone LIKE ? ORDER BY created DESC LIMIT 1"); $s->execute(['%' . $phone]);   // their name from their last order
      shop_upsert('leads', ['sid'], ['sid' => $sid, 'vid' => $vid, 'created' => shop_now(), 'updated' => shop_now(), 'name' => mb_substr((string)$s->fetchColumn(), 0, 80),
        'phone' => $phone, 'step' => 'visit', 'bag' => '[]', 'total' => 0, 'ordered' => 0, 'email' => '', 'state' => '', 'address' => '']);
      exit;
    }
    if (($l['step'] ?? '') === 'buyer') {   // came back on the device they ordered from: in Left at checkout right away, only if that mobile really has an order
      $phone = substr(preg_replace('/\D/', '', (string)($l['phone'] ?? '')), -10); if (!preg_match('/^[6-9]\d{9}$/', $phone)) exit;
      $s = $db->prepare('SELECT 1 FROM leads WHERE sid = ?'); $s->execute([$sid]); if ($s->fetchColumn()) exit;   // checkout or a WhatsApp link already noted this visit
      $s = $db->prepare("SELECT name FROM orders WHERE status NOT IN ('awaiting', 'cancelled', 'refunded') AND COALESCE(no, '') <> '' AND test = 0 AND phone LIKE ? ORDER BY created DESC LIMIT 1"); $s->execute(['%' . $phone]);
      $name = $s->fetchColumn(); if ($name === false) exit;
      shop_upsert('leads', ['sid'], ['sid' => $sid, 'vid' => $vid, 'created' => shop_now(), 'updated' => shop_now(), 'name' => mb_substr((string)$name, 0, 80),
        'phone' => $phone, 'step' => 'buyer', 'bag' => '[]', 'total' => 0, 'ordered' => 0, 'email' => '', 'state' => '', 'address' => '']);
      exit;
    }
    $txt = fn($v, $n) => trim(mb_substr(preg_replace('/[\x00-\x1F\x7F]+/u', ' ', is_string($v) ? $v : '') ?? '', 0, $n));
    $phone = substr(preg_replace('/\D/', '', (string)($l['phone'] ?? '')), -10);
    $name = $txt($l['name'] ?? '', 80);
    if ($name === '' && strlen($phone) < 10) exit;
    $bag = [];
    foreach (array_slice((array)($l['bag'] ?? []), 0, 20) as $b) if (is_array($b))
      $bag[] = ['id' => $txt($b['id'] ?? '', 48), 'opt' => $txt((string)($b['opt'] ?? ''), 16), 'qty' => max(1, min(99, (int)($b['qty'] ?? 1))), 'name' => $txt($b['name'] ?? '', 90)];
    $step = in_array($l['step'] ?? '', ['details', 'payment'], true) ? $l['step'] : 'details';
    $email = filter_var($txt($l['email'] ?? '', 120), FILTER_VALIDATE_EMAIL) ?: '';
    $state = in_array($l['state'] ?? '', FOMAXO_STATES, true) ? $l['state'] : '';
    $s = $db->prepare('SELECT step, created FROM leads WHERE sid = ?'); $s->execute([$sid]); $old = $s->fetch();
    if ($old && $old['step'] === 'payment') $step = 'payment';   // never step back
    shop_upsert('leads', ['sid'], ['sid' => $sid, 'vid' => $vid, 'created' => $old['created'] ?? shop_now(), 'updated' => shop_now(), 'name' => $name,
      'phone' => strlen($phone) === 10 ? $phone : '', 'step' => $step, 'bag' => json_encode($bag, JSON_UNESCAPED_UNICODE), 'total' => max(0, min(100000000, (int)round((float)($l['total'] ?? 0) * 100))), 'ordered' => 0,
      'email' => $email, 'state' => $state, 'address' => $txt($l['address'] ?? '', 300)]);
    exit;
  }
  $src = preg_match('/^[a-z]{1,16}$/', (string)($in['src'] ?? '')) ? (string)$in['src'] : 'direct';
  $path = mb_substr(preg_replace('/[^\w\/\-.]/', '', (string)($in['p'] ?? '/')) ?? '/', 0, 120);
  $product = preg_match('/^[a-z0-9-]{1,48}$/', (string)($in['id'] ?? '')) ? (string)$in['id'] : '';
  $device = preg_match('/Mobi|Android|iPhone|iPad/i', $ua) ? 'phone' : 'computer';
  $qty = max(0, min(99, (int)($in['q'] ?? 0)));
  if ($t === 'scroll') { $qty = (int)($in['q'] ?? 0); if (!in_array($qty, [25, 50, 75, 100], true)) exit; }
  $campaign = trim(preg_replace('/[^a-z0-9_.-]+/', '-', strtolower(substr((string)($in['c'] ?? ''), 0, 60))) ?? '', '-');   // e.g. ?utm_campaign=diwali-post
  $s = $db->prepare('SELECT 1 FROM visits WHERE sid = ?'); $s->execute([$sid]);
  if (!$s->fetchColumn()) try { $db->prepare('INSERT INTO visits(sid, vid, started, last, source, device, pages, country, region, campaign) VALUES(?,?,?,?,?,?,?,?,?,?)')->execute([$sid, $vid, $now, $now, $src, $device, $t === 'view' ? 1 : 0, ...fomaxo_geo(fomaxo_geo_ip()), ...[$campaign]]); } catch (Throwable $e) { /* the same visit, sent twice at once */ }
  $db->prepare('INSERT INTO events(ts, vid, sid, type, source, path, product, qty, device) VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute([shop_now(), $vid, $sid, $t, $src, $path, $product, $qty, $device]);
  if ($t === 'buy') $db->prepare('UPDATE leads SET ordered = 1 WHERE sid = ?')->execute([$sid]);
  if ($t === 'buy' && ($iv = $id('iv')) !== '' && $iv !== $vid) try {   // a friend's order from a shared link: the sharer shows in Left at checkout with "Invited a friend"
    $s = $db->prepare('SELECT phone, name FROM inviters WHERE vid = ?'); $s->execute([$iv]); $inv = $s->fetch();
    $s = $db->prepare('SELECT no, name, phone FROM orders WHERE UPPER(no) = UPPER(?) AND created >= ? LIMIT 1'); $s->execute([$product, date('Y-m-d H:i:s', $now - 3 * 3600)]); $o = $s->fetch();
    if ($inv && $o && substr(preg_replace('/\D/', '', (string)$o['phone']), -10) !== $inv['phone']) {   // not their own order
      $friend = trim(explode(' ', trim((string)$o['name']))[0] ?? '');
      shop_upsert('leads', ['sid'], ['sid' => substr(md5('invite:' . $o['no']), 0, 16), 'vid' => $iv, 'created' => shop_now(), 'updated' => shop_now(), 'name' => (string)$inv['name'],
        'phone' => (string)$inv['phone'], 'step' => 'invite', 'bag' => json_encode(['friend' => mb_substr($friend, 0, 40), 'order' => (string)$o['no']], JSON_UNESCAPED_UNICODE), 'total' => 0, 'ordered' => 0, 'email' => '', 'state' => '', 'address' => '']);
    }
  } catch (Throwable $e) { error_log('FOMAXO invite: ' . $e->getMessage()); }
} catch (Throwable $e) { error_log('FOMAXO track: ' . $e->getMessage()); track_seen('error', $e->getMessage()); }
