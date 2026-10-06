<?php
declare(strict_types=1);
/* FOMAXO India — visitor analytics, kept on this server (no Google or other trackers).
   POST (navigator.sendBeacon from index.html) {t: type, v: visitor id, s: visit id, src, p: path, id, opt, q, lead}
   Types: view (page), product (product page), add (to bag), checkout, pay (payment step), buy (order placed), lead (checkout
   details typed), ping (still on the site). Visitor and visit ids are random strings made in the browser; no IP address,
   name or cookie is stored for ordinary visits. Each visit's country and (in India) state are looked up from the IP address on this
   server (api/geo, DB-IP Lite); only the country and state are kept, never the address. Bots and the shop owner's own visits (signed in to /admin) are not counted. */
require __DIR__ . '/store-lib.php';
require __DIR__ . '/geo-lib.php';
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
http_response_code(204);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') exit;
$ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
if ($ua === '' || preg_match('/bot|crawl|spider|slurp|headless|lighthouse|preview|facebookexternalhit|embedly|python|curl|wget|httpclient|phantom|selenium/i', $ua)) exit;
if (fomaxo_is_admin_visitor()) exit;

$raw = (string)file_get_contents('php://input', false, null, 0, 8192);
$in = json_decode($raw, true); if (!is_array($in)) exit;
$t = (string)($in['t'] ?? '');
$id = fn($k) => preg_match('/^[a-z0-9]{8,16}$/', (string)($in[$k] ?? '')) ? (string)$in[$k] : '';
$vid = $id('v'); $sid = $id('s');
if (!$vid || !$sid || !in_array($t, ['view', 'product', 'add', 'checkout', 'pay', 'buy', 'lead', 'ping'], true)) exit;

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
  if ($t === 'lead') {   // name, mobile and bag typed at checkout, for "Left at checkout"
    $l = is_array($in['lead'] ?? null) ? $in['lead'] : [];
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
  $device = ($in['d'] ?? '') === 'tablet' || preg_match('/iPad|Tablet/i', $ua) || (preg_match('/Android/i', $ua) && !preg_match('/Mobi/i', $ua)) ? 'tablet'
    : (preg_match('/Mobi|Android|iPhone/i', $ua) ? 'phone' : 'computer');
  $s = $db->prepare('SELECT 1 FROM visits WHERE sid = ?'); $s->execute([$sid]);
  if (!$s->fetchColumn()) try { $db->prepare('INSERT INTO visits(sid, vid, started, last, source, device, pages, country, region) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$sid, $vid, $now, $now, $src, $device, $t === 'view' ? 1 : 0, ...fomaxo_geo(fomaxo_geo_ip())]); } catch (Throwable $e) { /* the same visit, sent twice at once */ }
  $db->prepare('INSERT INTO events(ts, vid, sid, type, source, path, product, qty, device) VALUES(?,?,?,?,?,?,?,?,?)')
    ->execute([shop_now(), $vid, $sid, $t, $src, $path, $product, max(0, min(99, (int)($in['q'] ?? 0))), $device]);
  if ($t === 'buy') $db->prepare('UPDATE leads SET ordered = 1 WHERE sid = ?')->execute([$sid]);
} catch (Throwable $e) { error_log('FOMAXO track: ' . $e->getMessage()); }
