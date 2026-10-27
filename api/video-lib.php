<?php
declare(strict_types=1);
/* FOMAXO India — shop videos (fomaxo.in/admin → Products → Videos): short upright videos on the home page; tapping one opens it
   full screen with its product's Add To Cart / Buy Now. The list is kept in the settings ('videos'); the video files go in
   fomaxo-private/videos and are sent by api/live.php?vid=… (cover photos go with the product photos, api/live.php?img=…).
   From Instagram: the access token is typed on the admin page and kept in fomaxo-private/instagram.php (never on GitHub,
   never public). A reel is copied to our server, so it keeps playing even if it is removed from Instagram. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const IG_CODE = '/^[A-Za-z0-9_-]{5,40}$/';   // an Instagram reel shown with Instagram's own player (pasted link, no token)
const VIDEO_FILE = '/^[a-z0-9-]{1,48}-[a-f0-9]{8}(-p|-h)?\.(mp4|mov|webm)$/';   // -p = the small preview the home page row plays (made on the admin page, vshrink.js); -h = the sharp 1080p copy, kept on Cloudflare only
function shop_videos(): array { return array_values(array_filter(json_decode((string)shop_setting('videos'), true) ?: [], fn($v) => is_array($v) && !empty($v['id']) && (preg_match(VIDEO_FILE, (string)($v['file'] ?? '')) || preg_match(IG_CODE, (string)($v['embed'] ?? ''))))); }
function shop_videos_save(array $v): void { shop_set('videos', json_encode(array_values($v), JSON_UNESCAPED_SLASHES)); }
function video_dir(): string { global $PRIV; $d = "$PRIV/videos"; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
/* a public copy of each video in media/v (a hard link, so no extra space), sent by the web server itself like a photo.
   Sending them through PHP kept one PHP process busy per video for as long as it downloaded; with 12 videos on the admin
   Videos page and Review videos on product pages, that filled every PHP process and the whole admin and shop froze (21/10/2026). */
function video_pub_dir(): string { return dirname(__DIR__) . '/media/v'; }
function video_pub(string $f): bool {
  if (!preg_match(VIDEO_FILE, $f)) return false;
  $to = video_pub_dir() . "/$f"; if (is_file($to)) return true;
  $from = video_dir() . "/$f"; if (!is_file($from)) return false;
  if (!is_dir(video_pub_dir()) && !@mkdir(video_pub_dir(), 0755, true)) return false;
  $tmp = $to . '.' . bin2hex(random_bytes(4));
  try { if (!(function_exists('link') && @link($from, $tmp)) && !@copy($from, $tmp)) return false; } catch (Throwable $e) { @unlink($tmp); return false; }   // link() is switched off on Hostinger: copy instead
  @chmod($tmp, 0644); if (!@rename($tmp, $to)) { @unlink($tmp); return false; }
  return true;
}
function video_pub_drop(string $f): void { if (preg_match(VIDEO_FILE, $f)) @unlink(video_pub_dir() . "/$f"); }
/* the address of a video: its public copy (made here the first time), else api/live.php?vid=… */
function video_url(string $f): string { try { $ok = video_pub($f); } catch (Throwable $e) { $ok = false; } return $ok ? "media/v/$f" : "api/live.php?vid=$f"; }
/* the other way round: a video uploaded here with "Also add to fomaxo.com" ticked goes to fomaxo.com's fomaxo-media/video-inbox
   (same Hostinger account), where fomaxo.com adds it (media-lib.php fx_video_inbox). '' when that folder can't be reached (then no tick) */
function video_com_inbox(): string { $p = getenv('FOMAXO_COM_MEDIA') ?: dirname(__DIR__, 3) . '/fomaxo.com/fomaxo-media'; return is_dir($p) && is_writable($p) ? "$p/video-inbox" : ''; }
/* a video on our server that can still go to fomaxo.com: not from fomaxo.com, not sent yet */
function video_to_com_ok(array $v): bool { return empty($v['embed']) && empty($v['from']) && empty($v['sent_com']) && preg_match(VIDEO_FILE, (string)($v['file'] ?? '')) && is_file(video_dir() . '/' . $v['file']); }
function video_to_com(string $path, array $v): bool {
  $in = video_com_inbox(); $name = basename($path); if ($in === '' || (!is_dir($in) && !@mkdir($in, 0755, true))) return false;
  $tmp = "$in/$name." . bin2hex(random_bytes(4)) . '.part';
  if (!@copy($path, $tmp) || !@rename($tmp, "$in/$name")) { @unlink($tmp); return false; }
  if (!@file_put_contents("$in/$name.json", json_encode(['file' => $name] + array_intersect_key($v, array_flip(['product', 'words', 'wide', 'fp', 'person', 'where'])), JSON_UNESCAPED_SLASHES), LOCK_EX)) return false;
  @unlink(dirname($in, 2) . '/fomaxo-cache/products.json');   // fomaxo.com's one-minute copy of its shop answer: its next page load adds the video straight away
  return true;
}
/* a video uploaded on fomaxo.com with "Also add to fomaxo.in" ticked: fomaxo.com puts the file and a small note (product, words,
   shape) in fomaxo-private/video-inbox. It is added to the top of our list here, the first time the shop or the admin opens after.
   A product fomaxo.in does not sell is left out (the video then gets a Shop Now button). One at a time (a lock), nothing to do = one folder read */
function video_inbox(): void {
  global $PRIV; $in = "$PRIV/video-inbox"; $notes = glob("$in/*.json") ?: []; if (!$notes) return;
  $lk = @fopen("$in/.lock", 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return;
  try {
    $vids = json_decode((string)shop_setting('videos'), true) ?: []; $cat = fomaxo_catalog()['products']; $added = 0;
    foreach ($notes as $j) {
      $m = json_decode((string)@file_get_contents($j), true); $f = basename((string)($m['file'] ?? ''));
      if (!is_array($m) || !preg_match(VIDEO_FILE, $f) || !is_file("$in/$f")) { if (@filemtime($j) < time() - 3600) @unlink($j); continue; }   // the file is still being copied: next time
      if (!@rename("$in/$f", video_dir() . "/$f")) continue;
      @unlink($j); $prod = (string)($m['product'] ?? ''); $words = substr(trim((string)($m['words'] ?? '')), 0, 60);
      array_unshift($vids, ['id' => bin2hex(random_bytes(5)), 'file' => $f, 'cover' => '', 'product' => isset($cat[$prod]) ? $prod : '', 'on' => true, 'from' => 'fomaxo.com']
        + (preg_match('/^[a-f0-9]{32}$/', (string)($m['fp'] ?? '')) ? ['fp' => (string)$m['fp']] : []) + ($words !== '' ? ['words' => $words] : []) + (!empty($m['wide']) ? ['wide' => true] : []) + (in_array($m['where'] ?? '', ['home', 'product'], true) ? ['where' => $m['where']] : []) + (($nm = mb_substr(trim((string)($m['person'] ?? '')), 0, 30)) !== '' ? ['person' => $nm] : []));
      $added++;
    }
    if ($added) shop_videos_save($vids);
  } catch (Throwable $e) {} finally { flock($lk, LOCK_UN); fclose($lk); }
}
/* for STORE_LIVE.videos: only the ones switched on, of products on the website; shown on the home page and as Review videos on their product pages (wh: h = home page only, p = product pages only, none = both) */
function shop_videos_live(): array {
  try { video_inbox(); } catch (Throwable $e) {}
  if (shop_setting('videos_off') === '1') return [];   // the main switch on Admin → Products → Videos: the whole row is off
  $cat = fomaxo_catalog()['products']; $out = [];
  foreach (shop_videos() as $v) { if (empty($v['on']) || !empty($v['embed'])) continue;   // only videos on our server: an Instagram-player entry sends shoppers off to Instagram
    $all = video_products($v); $ps = array_values(array_filter($all, fn($id) => isset($cat[$id]) && empty($cat[$id]['hidden'])));   // one product, several, or none (a Shop Now button)
    if (($all || (string)($v['product'] ?? '') !== '') && !$ps) continue;   // every product in it is hidden or deleted
    $out[] = video_full($v) + ['p' => $ps[0] ?? ''] + (count($ps) > 1 ? ['a' => array_slice($ps, 1)] : []) + (($v['cover'] ?? '') !== '' ? ['c' => (string)$v['cover']] : []) + (preg_match('/^[a-z0-9-]+-[a-f0-9]{8}-p\.mp4$/', (string)($v['prev'] ?? '')) ? ['pv' => video_cdn((string)$v['prev'])] : (preg_match('/\.mp4$/', (string)$v['file']) && is_file(video_dir() . '/' . $v['file']) && filesize(video_dir() . '/' . $v['file']) <= 1.5 * 1048576 ? ['pv' => video_cdn((string)$v['file'])] : [])) + (($v['words'] ?? '') !== '' ? ['w' => (string)$v['words']] : []) + (!empty($v['wide']) ? ['wd' => 1] : []) + (in_array($v['where'] ?? '', ['home', 'product'], true) ? ['wh' => $v['where'][0]] : []) + (($v['person'] ?? '') !== '' ? ['n' => (string)$v['person']] : []) + ($ps && !empty($v['shop']) ? ['sn' => 1] : []); }
  return $out;
}
/* the products a video sells, first one first ('product' plus 'also'); only those in $cat when it is given */
function video_products(array $v, ?array $cat = null): array {
  return array_values(array_unique(array_filter(array_merge([(string)($v['product'] ?? '')], (array)($v['also'] ?? [])), fn($id) => is_string($id) && $id !== '' && ($cat === null || isset($cat[$id])))));
}
/* Cloudflare R2 (Admin → Products → Videos → Cloudflare): the same video files, also kept on Cloudflare (free up to 10 GB,
   nothing to pay for watching) and played from there once its switch is on. The keys are typed there and kept in
   fomaxo-private/r2.php (never on GitHub). A video not copied yet, or the switch off, plays from media/v as before, and a
   video Cloudflare fails to send falls back to media/v on the website by itself (index.html vidFallback). */
function r2_file(): string { global $PRIV; return "$PRIV/r2.php"; }
/* fomaxo.com's keys (same Hostinger account, one level above its public_html): typed once there, used here too, so both sites share the bucket */
function r2_com_file(): string { return getenv('FOMAXO_COM_R2') ?: dirname(__DIR__, 3) . '/fomaxo.com/fomaxo-r2.php'; }
function r2_conf(): ?array {
  $ok = fn($x) => is_array($x) && !empty($x['acct']) && !empty($x['key']) && !empty($x['secret']) && !empty($x['bucket']) && !empty($x['url']);
  $f = r2_file(); $x = is_file($f) ? require $f : null; if ($ok($x)) return $x;   // keys typed here win
  $f = r2_com_file(); $x = @is_readable($f) ? (function ($f) { try { return require $f; } catch (Throwable $e) { return null; } })($f) : null;
  return $ok($x) ? ['shared' => true] + $x : null;
}
function r2_save(?array $c): bool {
  $f = r2_file(); unset($c['shared']);
  if (!$c) return @unlink($f) || !is_file($f);
  $ok = @file_put_contents($f, "<?php\n// Cloudflare R2 keys for the shop videos (written by fomaxo.in/admin → Products → Videos)\nreturn " . var_export($c, true) . ";\n", LOCK_EX) !== false;
  if ($ok) @chmod($f, 0600);
  return $ok;
}
function r2_on(): bool { return shop_setting('r2_on') === '1' && r2_conf() !== null && r2_live(); }
/* does the public address really send our videos (DNS, custom domain, CORS)? One small ask of a copied file, at most every
   10 minutes; while it fails the website plays everything from Hostinger as before (v.fomaxo.com not on the internet yet, 27/10/2026) */
function r2_live(bool $fresh = false): bool {
  global $PRIV; $cf = "$PRIV/r2-live.json"; $j = json_decode((string)@file_get_contents($cf), true);
  if (!$fresh && is_array($j) && ($j['t'] ?? 0) > time() - 600) return !empty($j['ok']);
  $c = r2_conf(); $have = r2_have(); $ok = false;
  if ($c && $have && function_exists('curl_init')) {
    asort($have); $f = (string)array_key_first($have);   // the smallest file
    $ch = curl_init(rtrim($c['url'], '/') . '/' . $f);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 4, CURLOPT_TIMEOUT => 6, CURLOPT_HTTPHEADER => ['Range: bytes=0-0', 'Origin: https://fomaxo.in'], CURLOPT_HEADER => true]);
    $out = (string)curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
    $ok = in_array($code, [200, 206], true) && preg_match('~^access-control-allow-origin:\s*(\*|https://(www\.)?fomaxo\.in)\s*$~mi', $out);   // without CORS the page could not use it either
  }
  @file_put_contents($cf, json_encode(['t' => time(), 'ok' => $ok]), LOCK_EX);
  return $ok;
}
/* the videos already on Cloudflare: name => size */
function r2_have(): array { global $PRIV; $h = json_decode((string)@file_get_contents("$PRIV/r2-have.json"), true); return is_array($h) ? $h : []; }
function r2_have_save(array $h): void { global $PRIV; @file_put_contents("$PRIV/r2-have.json", json_encode($h), LOCK_EX); }
/* the AWS signature (version 4) R2 asks for; $hd: lower-case headers in order, with host, x-amz-content-sha256 and x-amz-date */
function r2_sign(array $c, string $method, string $uri, string $query, array $hd, string $region = 'auto'): string {
  $amz = $hd['x-amz-date']; $day = substr($amz, 0, 8); $scope = "$day/$region/s3/aws4_request";
  $canon = $method . "\n" . $uri . "\n" . $query . "\n" . implode('', array_map(fn($k, $v) => "$k:" . trim((string)$v) . "\n", array_keys($hd), $hd)) . "\n" . implode(';', array_keys($hd)) . "\n" . $hd['x-amz-content-sha256'];
  $k = hash_hmac('sha256', 'aws4_request', hash_hmac('sha256', 's3', hash_hmac('sha256', $region, hash_hmac('sha256', $day, 'AWS4' . $c['secret'], true), true), true), true);
  return 'AWS4-HMAC-SHA256 Credential=' . $c['key'] . "/$scope, SignedHeaders=" . implode(';', array_keys($hd)) . ', Signature=' . hash_hmac('sha256', "AWS4-HMAC-SHA256\n$amz\n$scope\n" . hash('sha256', $canon), $k);
}
/* one signed request to R2 (its S3 address): [HTTP code, answer or error]. $body is a string, or ['file' => path] for a video */
function r2_req(array $c, string $method, string $path, string $query = '', $body = '', array $hd = []): array {
  if (!function_exists('curl_init')) return [0, 'curl is off on this server'];
  $host = $c['acct'] . '.r2.cloudflarestorage.com'; $uri = '/' . $c['bucket'] . ($path !== '' ? '/' . $path : '');
  $file = is_array($body) ? (string)$body['file'] : '';
  $hash = $file !== '' ? hash_file('sha256', $file) : hash('sha256', (string)$body);
  $hd = array_change_key_case($hd) + ['host' => $host, 'x-amz-content-sha256' => $hash, 'x-amz-date' => gmdate('Ymd\THis\Z')]; ksort($hd);
  $auth = r2_sign($c, $method, $uri, $query, $hd);
  $ch = curl_init("https://$host$uri" . ($query !== '' ? "?$query" : ''));
  $opt = [CURLOPT_CUSTOMREQUEST => $method, CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 120,
    CURLOPT_HTTPHEADER => array_merge(['Authorization: ' . $auth], array_map(fn($k, $v) => "$k: $v", array_keys(array_diff_key($hd, ['host' => 1])), array_diff_key($hd, ['host' => 1])))];
  $fh = null;
  if ($file !== '') { $fh = fopen($file, 'rb'); $opt += [CURLOPT_UPLOAD => true, CURLOPT_INFILE => $fh, CURLOPT_INFILESIZE => filesize($file)]; }
  elseif ($method === 'PUT' || $method === 'POST') $opt[CURLOPT_POSTFIELDS] = (string)$body;
  curl_setopt_array($ch, $opt); $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch); if ($fh) fclose($fh);
  if ($out === false) return [0, $err ?: 'no answer'];
  return [$code, $code >= 300 && preg_match('~<Message>([^<]+)~', (string)$out, $m) ? $m[1] : (string)$out];
}
/* copies one video to R2: '' when done, else what went wrong */
function r2_put(array $c, string $f): string {
  $p = video_dir() . "/$f"; if (!preg_match(VIDEO_FILE, $f) || !is_file($p)) return 'file missing';
  $type = ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'][pathinfo($f, PATHINFO_EXTENSION)] ?? 'video/mp4';
  [$code, $msg] = r2_req($c, 'PUT', $f, '', ['file' => $p], ['Content-Type' => $type, 'Cache-Control' => 'public, max-age=31536000, immutable']);   // a new name for every upload, so it never changes
  return $code === 200 ? '' : ($msg !== '' ? substr($msg, 0, 160) : "error $code");
}
/* every video file the list uses (the video and its small preview) */
function r2_wanted(): array {
  $w = []; $have = null;
  foreach (shop_videos() as $v) foreach ([(string)($v['file'] ?? ''), (string)($v['prev'] ?? ''), (string)($v['hq'] ?? '')] as $f) {
    if (!preg_match(VIDEO_FILE, $f)) continue;
    if (is_file(video_dir() . "/$f")) $w[$f] = (int)filesize(video_dir() . "/$f");
    elseif ($f === (string)($v['hq'] ?? '') && isset(($have ??= r2_have())[$f])) $w[$f] = $have[$f];   // the sharp copy leaves Hostinger once it is on Cloudflare
  }
  return $w;
}
/* copies the videos not on Cloudflare yet, for up to $secs seconds: [copied now, error or ''] */
function r2_sync(float $secs = 8): array {
  $c = r2_conf(); if (!$c) return [0, ''];
  if (function_exists('set_time_limit')) @set_time_limit((int)$secs + 180);   // one big video may still be on its way when the time is up: the page must not stop half-way (a white page)
  global $PRIV; $lk = @fopen("$PRIV/r2-sync.lock", 'c'); if (!$lk || !flock($lk, LOCK_EX | LOCK_NB)) return [0, ''];   // one copy at a time
  $t = microtime(true); $n = 0; $err = '';
  try {
    $have = r2_have(); $want = r2_wanted();
    foreach (array_diff_key($have, $want) as $f => $sz) {   // deleted videos leave Cloudflare too, so the 10 GB bar stays true
      if (microtime(true) - $t > $secs) break;
      [$code] = r2_req($c, 'DELETE', $f); if ($code !== 204 && $code !== 200 && $code !== 404) break;
      unset($have[$f]); r2_have_save($have); @unlink("$PRIV/r2-total.json");
    }
    foreach ($want as $f => $sz) {
      if (($have[$f] ?? -1) === $sz) continue;
      if (microtime(true) - $t > $secs) break;
      $e = r2_put($c, $f); if ($e !== '') { $err = $e; break; }
      $have[$f] = $sz; r2_have_save($have); $n++;
      if (str_ends_with($f, '-h.mp4')) @unlink(video_dir() . "/$f");
      @unlink("$PRIV/r2-total.json");   // the 10 GB bar counts again   // Hostinger keeps only the small copy (the fallback), never the big one
    }
  } finally { flock($lk, LOCK_UN); fclose($lk); }
  return [$n, $err];
}
/* Cloudflare's free space: 10 GB; the bytes our videos take there */
const R2_FREE = 10 * 1000 ** 3;
function r2_used(): int { return (int)array_sum(r2_have()); }
/* the whole bucket (fomaxo.in and fomaxo.com share the free 10 GB): every file's size, asked from Cloudflare at most every
   10 minutes; this site's own files when Cloudflare can't answer */
function r2_total(): int {
  global $PRIV; $cf = "$PRIV/r2-total.json"; $j = json_decode((string)@file_get_contents($cf), true);
  if (is_array($j) && ($j['t'] ?? 0) > time() - 600) return (int)$j['b'];
  $c = r2_conf(); if (!$c) return r2_used();
  $b = 0; $tok = '';
  for ($i = 0; $i < 50; $i++) {
    [$code, $out] = r2_req($c, 'GET', '', ($tok !== '' ? 'continuation-token=' . rawurlencode($tok) . '&' : '') . 'list-type=2');   // the signed query names go in a-z order
    if ($code !== 200) return r2_used();
    preg_match_all('~<Size>(\d+)</Size>~', $out, $m); $b += array_sum(array_map('intval', $m[1]));
    if (!preg_match('~<IsTruncated>true</IsTruncated>~', $out) || !preg_match('~<NextContinuationToken>([^<]+)~', $out, $t)) break;
    $tok = html_entity_decode($t[1], ENT_XML1);
  }
  @file_put_contents($cf, json_encode(['t' => time(), 'b' => $b]), LOCK_EX);
  return $b;
}
/* the same, after this page has gone to the browser: the admin never waits on Cloudflare while it loads */
function r2_sync_later(float $secs = 60): void {
  static $once = false; if ($once || !r2_conf()) return; $once = true;
  register_shutdown_function(function () use ($secs) {
    if (session_status() === PHP_SESSION_ACTIVE) session_write_close();   // the next admin page doesn't wait for this one
    ignore_user_abort(true);
    if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    try { r2_sync($secs); } catch (Throwable $e) {}
  });
}
/* checks the keys (a small test file), lets fomaxo.in play the videos (CORS, so Safari's black-frame check still works),
   and checks the public address: '' when all is well, else what to fix */
function r2_check(array $c): string {
  [$code, $msg] = r2_req($c, 'PUT', 'fomaxo-check.txt', '', 'ok', ['Content-Type' => 'text/plain']);
  if ($code !== 200) return 'Cloudflare did not accept the keys: ' . ($code ? substr($msg, 0, 160) : $msg);
  $cors = '<?xml version="1.0" encoding="UTF-8"?><CORSConfiguration><CORSRule><AllowedOrigin>https://fomaxo.in</AllowedOrigin><AllowedOrigin>https://www.fomaxo.in</AllowedOrigin><AllowedOrigin>https://fomaxo.com</AllowedOrigin><AllowedOrigin>https://www.fomaxo.com</AllowedOrigin>'
    . '<AllowedMethod>GET</AllowedMethod><AllowedMethod>HEAD</AllowedMethod><AllowedHeader>*</AllowedHeader><ExposeHeader>Content-Length</ExposeHeader><ExposeHeader>Content-Range</ExposeHeader><ExposeHeader>Accept-Ranges</ExposeHeader><MaxAgeSeconds>86400</MaxAgeSeconds></CORSRule></CORSConfiguration>';
  [$code, $msg] = r2_req($c, 'PUT', '', 'cors=', $cors, ['Content-Type' => 'application/xml', 'Content-MD5' => base64_encode(md5($cors, true))]);
  $note = $code === 200 ? '' : 'The keys work, but CORS could not be set (' . substr($msg, 0, 80) . '): please add the CORS rule in Cloudflare → R2 → your bucket → Settings';   // a token for one bucket only may not set it
  $ch = curl_init(rtrim($c['url'], '/') . '/fomaxo-check.txt'); curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_TIMEOUT => 20]);
  $out = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
  if ($code !== 200 || trim((string)$out) !== 'ok') $note .= ($note !== '' ? '. Also, ' : 'The keys work, but ') . $c['url'] . ' does not open the files yet (custom domain not connected to the bucket?)';
  return $note;
}
/* the full video for the player: the sharp 1080p copy when Cloudflare plays it (vf = the small Hostinger copy the page falls back to), else the usual one */
function video_full(array $v): array {
  $u = r2_url((string)($v['hq'] ?? ''));
  return $u !== '' ? ['v' => $u, 'vf' => video_url((string)$v['file'])] : ['v' => video_cdn((string)$v['file'])];
}
/* the address the website plays a video from: Cloudflare when on and copied, else media/v */
function video_cdn(string $f): string {
  if (($u = r2_url($f)) !== '') { try { video_pub($f); } catch (Throwable $e) {} return $u; }   // the media/v copy stays, for the fallback
  return video_url($f);
}
/* a video's Cloudflare address, '' when Cloudflare is off or the file is not there yet */
function r2_url(string $f): string {
  static $on = null, $have = null, $base = '';
  if ($on === null) { $on = r2_on(); $have = $on ? r2_have() : []; $base = $on ? rtrim((string)r2_conf()['url'], '/') : ''; }
  return $on && $f !== '' && isset($have[$f]) ? "$base/$f" : '';
}
/* sends a video with Range support (iPhones only play videos that can be asked for in parts) */
function send_video(string $path): void {
  $size = (int)filesize($path); $from = 0; $to = $size - 1;
  header('Content-Type: ' . ['mp4' => 'video/mp4', 'mov' => 'video/quicktime', 'webm' => 'video/webm'][pathinfo($path, PATHINFO_EXTENSION)]);
  header('Cache-Control: public, max-age=2592000, immutable');   // a new upload always gets a new file name
  header('Accept-Ranges: bytes');
  if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $m) && ($m[1] !== '' || $m[2] !== '')) {
    if ($m[1] === '') { $from = max(0, $size - (int)$m[2]); } else { $from = (int)$m[1]; if ($m[2] !== '') $to = min($to, (int)$m[2]); }
    if ($from > $to || $from >= $size) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206); header("Content-Range: bytes $from-$to/$size");
  }
  header('Content-Length: ' . ($to - $from + 1));
  if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'HEAD') exit;
  $fh = fopen($path, 'rb'); fseek($fh, $from); $left = $to - $from + 1;
  while ($left > 0 && !feof($fh) && !connection_aborted()) { $chunk = fread($fh, (int)min(262144, $left)); if ($chunk === false) break; echo $chunk; flush(); $left -= strlen($chunk); }
  fclose($fh); exit;
}

/* ---------------- Instagram (Instagram API with Instagram login; a Business or Creator account) ---------------- */
function ig_file(): string { global $PRIV; return "$PRIV/instagram.php"; }
function ig_conf(): ?array { $f = ig_file(); $c = is_file($f) ? require $f : null; return is_array($c) && !empty($c['token']) ? $c : null; }
function ig_save(?array $c): bool {
  $f = ig_file();
  if (!$c) return @unlink($f) || !is_file($f);
  $ok = @file_put_contents($f, "<?php\n// Instagram access token for the shop videos (written by fomaxo.in/admin → Products → Videos)\nreturn " . var_export($c, true) . ";\n", LOCK_EX) !== false;
  if ($ok) @chmod($f, 0600);
  return $ok;
}
/* GET from the Instagram API: [data, error message] */
function ig_api(string $path, array $q, string $token): array {
  $base = defined('FX_IG_API') ? FX_IG_API : 'https://graph.instagram.com';
  $ch = curl_init($base . '/' . ltrim($path, '/') . '?' . http_build_query($q + ['access_token' => $token]));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
  $r = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  $d = json_decode((string)$r, true);
  if ($code === 200 && is_array($d)) return [$d, null];
  return [null, (is_array($d) && isset($d['error']['message'])) ? (string)$d['error']['message'] : ($err ?: "Instagram did not answer ($code)")];
}
/* checks a newly typed token: the saved settings, or an error message */
function ig_connect(string $token) {
  [$me, $err] = ig_api('me', ['fields' => 'user_id,username'], $token);
  if (!$me) return $err;
  $c = ['token' => $token, 'user' => (string)($me['username'] ?? ''), 'at' => time(), 'since' => (ig_conf()['since'] ?? time())];   // since: Automatic only adds reels posted after the first connect
  return ig_save($c) ? $c : 'The token could not be saved. Please try again.';
}
/* long-lived tokens last 60 days: renew it once a week, so it never runs out */
function ig_fresh(): ?array {
  $c = ig_conf(); if (!$c || time() - (int)($c['at'] ?? 0) < 7 * 86400) return $c;
  [$r] = ig_api('refresh_access_token', ['grant_type' => 'ig_refresh_token'], $c['token']);
  $c['at'] = time(); if (!empty($r['access_token'])) $c['token'] = (string)$r['access_token'];
  ig_save($c);
  return $c;
}
/* the latest videos and reels, or an error message */
function ig_reels(array $c, int $n = 24) {
  [$d, $err] = ig_api('me/media', ['fields' => 'id,caption,media_type,media_url,thumbnail_url,permalink,timestamp', 'limit' => 50], $c['token']);
  if (!$d) return $err;
  $out = [];
  foreach ((array)($d['data'] ?? []) as $m) if (($m['media_type'] ?? '') === 'VIDEO' && !empty($m['media_url']))
    $out[] = ['id' => (string)$m['id'], 'thumb' => (string)($m['thumbnail_url'] ?? ''), 'caption' => (string)($m['caption'] ?? ''), 'at' => strtotime((string)($m['timestamp'] ?? '')) ?: 0, 'link' => (string)($m['permalink'] ?? '')];
  return array_slice($out, 0, $n);
}
/* copies a file from Instagram to $dest (at most $max bytes): true or an error message */
function ig_download(string $url, string $dest, int $max = 300 * 1048576) {
  if (!preg_match('~^https?://~', $url) || !($fh = @fopen($dest, 'wb'))) return 'The video could not be saved.';
  $ch = curl_init($url); $got = 0;
  curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 4, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 10,
    CURLOPT_WRITEFUNCTION => function ($ch, $s) use ($fh, &$got, $max) { $got += strlen($s); return $got > $max ? 0 : fwrite($fh, $s); }]);
  $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fh);
  if ($ok && $code === 200 && $got > 0) return true;
  @unlink($dest);
  return $got > $max ? 'The video is too big.' : 'The video could not be copied from Instagram. Please try again.';
}
/* copies one reel (video + cover photo) to our server: the new entry for the video list, or an error message */
function ig_copy(array $c, string $ig, string $prod) {
  global $PRIV;
  [$m, $err] = ig_api(rawurlencode($ig), ['fields' => 'id,media_type,media_url,thumbnail_url'], $c['token']);   // fresh addresses: Instagram's expire
  if (!$m) return 'Instagram: ' . $err;
  if (($m['media_type'] ?? '') !== 'VIDEO' || empty($m['media_url'])) return 'That post is not a video.';
  $name = $prod . '-' . bin2hex(random_bytes(4)) . '.mp4';
  @set_time_limit(300);
  if (($r = ig_download((string)$m['media_url'], video_dir() . "/$name")) !== true) return $r;
  $cover = '';   // Instagram's cover picture, as a compressed webp with the product photos
  if (!empty($m['thumbnail_url']) && function_exists('imagewebp') && ($tmp = tempnam(sys_get_temp_dir(), 'fxig'))) {
    if (ig_download((string)$m['thumbnail_url'], $tmp, 15 * 1048576) === true && ($im = @imagecreatefromstring((string)file_get_contents($tmp)))) {
      $w = imagesx($im); $h = imagesy($im); if (max($w, $h) > 1600) $im = imagescale($im, $w >= $h ? 1600 : (int)round($w * 1600 / $h), $w >= $h ? (int)round($h * 1600 / $w) : 1600);
      $dir = "$PRIV/product-images"; $k = $prod . '-cover-' . bin2hex(random_bytes(4)) . '.webp';
      imagepalettetotruecolor($im);
      if ((is_dir($dir) || @mkdir($dir, 0750, true)) && @imagewebp($im, "$dir/$k", 80)) $cover = "up/$k";
    }
    @unlink($tmp);
  }
  return ['id' => bin2hex(random_bytes(5)), 'file' => $name, 'cover' => $cover, 'product' => $prod, 'on' => true, 'ig' => $ig];
}
/* the product a caption names (the longest matching name wins, so "Old Money" beats "Money"); null = none */
function ig_product(string $caption, array $names): ?string {
  $best = null; $len = 0; $cap = ' ' . strtolower(preg_replace('/[^\p{L}\p{N}]+/u', ' ', $caption)) . ' ';
  foreach ($names as $id => $n) { $w = strtolower(trim(preg_replace('/[^\p{L}\p{N}]+/u', ' ', (string)$n))); if ($w !== '' && strlen($w) > $len && str_contains($cap, " $w ")) { $best = (string)$id; $len = strlen($w); } }
  return $best;
}
/* Automatic: reels posted after Instagram was connected are added by themselves when their caption names a product.
   Runs at most once an hour (from api/live.php after the page has its answer, and from the admin Videos page). */
function ig_sync(bool $force = false): int {
  $c = ig_conf(); if (!$c || shop_setting('ig_auto') === '0') return 0;
  if (!$force && time() - (int)shop_setting('ig_sync_at') < 3600) return 0;
  shop_set('ig_sync_at', (string)time());
  $c = ig_fresh(); $reels = ig_reels($c, 25); if (!is_array($reels)) return 0;
  $names = []; foreach (fomaxo_catalog()['products'] as $id => $p) if (empty($p['hidden'])) $names[$id] = $p['name'];
  $since = (int)($c['since'] ?? $c['at'] ?? 0);
  $seen = json_decode((string)shop_setting('ig_seen'), true) ?: [];   // reels already looked at (added, deleted or with no product name)
  $vids = shop_videos(); $have = array_filter(array_column($vids, 'ig')); $added = 0;
  foreach (array_reverse($reels) as $r) {   // oldest first, so the newest ends up first on the website
    if ($r['at'] < $since || in_array($r['id'], $have, true) || in_array($r['id'], $seen, true) || $added >= 3) continue;
    $seen[] = $r['id'];
    if (!($prod = ig_product($r['caption'], $names))) continue;
    $v = ig_copy($c, $r['id'], $prod); if (!is_array($v)) { array_pop($seen); continue; }   // try again next hour
    $v['auto'] = true; array_unshift($vids, $v); $added++;
  }
  shop_set('ig_seen', json_encode(array_slice($seen, -300)));
  if ($added) shop_videos_save($vids);
  return $added;
}

/* ---------------- Paste a link (Admin → Products → Videos) ---------------- */
/* An Instagram reel link from the connected account, a direct video link (…mp4), or a web page that carries a video. */
const VIDEO_MIMES = ['video/mp4' => 'mp4', 'video/quicktime' => 'mov', 'video/webm' => 'webm', 'video/x-m4v' => 'mp4'];
/* a link to a public web address (never this server or the local network) */
function link_public(string $url): bool {
  $p = parse_url($url); $host = trim((string)($p['host'] ?? ''), '[]');
  if (!in_array(strtolower((string)($p['scheme'] ?? '')), ['http', 'https'], true) || $host === '') return false;
  if (defined('FX_IG_API')) return true;   // local testing only
  $ips = filter_var($host, FILTER_VALIDATE_IP) ? [$host] : (gethostbynamel($host) ?: []);
  foreach ($ips as $ip) if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) return false;
  return (bool)$ips;
}
/* downloads $url to $dest (at most $max bytes), following up to 4 redirects that stay public: [true, final url] or [error, ''] */
function link_get(string $url, string $dest, int $max): array {
  for ($hop = 0; $hop < 5; $hop++) {
    if (!link_public($url)) return ['Please paste a full web link starting with https://', ''];
    if (!($fh = @fopen($dest, 'wb'))) return ['The video could not be saved.', ''];
    $ch = curl_init($url); $got = 0; $loc = '';
    curl_setopt_array($ch, [CURLOPT_FOLLOWLOCATION => false, CURLOPT_TIMEOUT => 240, CURLOPT_CONNECTTIMEOUT => 10, CURLOPT_PROTOCOLS => CURLPROTO_HTTP | CURLPROTO_HTTPS,
      CURLOPT_USERAGENT => 'Mozilla/5.0 (FOMAXO shop videos)',
      CURLOPT_HEADERFUNCTION => function ($ch, $h) use (&$loc) { if (stripos($h, 'location:') === 0) $loc = trim(substr($h, 9)); return strlen($h); },
      CURLOPT_WRITEFUNCTION => function ($ch, $s) use ($fh, &$got, $max) { $got += strlen($s); return $got > $max ? 0 : fwrite($fh, $s); }]);
    $ok = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); fclose($fh);
    if ($code >= 300 && $code < 400 && $loc !== '') { $url = link_abs($loc, $url); continue; }
    if ($ok && $code === 200 && $got > 0) return [true, $url];
    return [$got > $max ? 'The video is too big.' : "That link did not open ($code). Please check it and try again.", ''];
  }
  return ['That link goes round in circles. Please paste the video’s own link.', ''];
}
/* a link found on a page ($rel) as a full address */
function link_abs(string $rel, string $base): string {
  $rel = html_entity_decode(trim($rel), ENT_QUOTES);
  if (preg_match('~^https?://~i', $rel)) return $rel;
  $b = parse_url($base); $root = $b['scheme'] . '://' . $b['host'] . (isset($b['port']) ? ':' . $b['port'] : '');
  if (str_starts_with($rel, '//')) return $b['scheme'] . ':' . $rel;
  if (str_starts_with($rel, '/')) return $root . $rel;
  return $root . preg_replace('~/[^/]*$~', '/', (string)($b['path'] ?? '/')) . $rel;
}
/* one of the connected account's reels by its link code (looks through the latest 200 posts) */
function ig_find(array $c, string $code): ?string {
  $after = '';
  for ($page = 0; $page < 4; $page++) {
    [$d] = ig_api('me/media', ['fields' => 'id,permalink,media_type', 'limit' => 50] + ($after !== '' ? ['after' => $after] : []), $c['token']);
    if (!$d) return null;
    foreach ((array)($d['data'] ?? []) as $m) if (preg_match('~/' . preg_quote($code, '~') . '/?$~', rtrim((string)($m['permalink'] ?? ''), '/') . '/')) return (string)$m['id'];
    $after = (string)($d['paging']['cursors']['after'] ?? ''); if ($after === '' || empty($d['paging']['next'])) return null;
  }
  return null;
}
/* The video file's address and picture in one of Instagram's public answers (a page, its page data or link-preview tags, escaped once or twice). */
function ig_pick(string $s): array {
  $get = function (string $k) use ($s): string {
    foreach (['~' . $k . '\\\\*"\s*:\s*\\\\*"([^"]+?)\\\\*"~', '~<meta[^>]+property=["\']' . $k . '["\'][^>]+content=["\']([^"\']+)~i'] as $re) {
      if (!preg_match($re, $s, $x)) continue;
      $v = html_entity_decode($x[1], ENT_QUOTES); for ($i = 0; $i < 3 && preg_match('~\\\\[/u\\\\]~', $v); $i++) { $d = json_decode('"' . $v . '"'); if (!is_string($d)) break; $v = $d; }
      return $v;
    }
    return '';
  };
  $vid = $get('video_url'); if ($vid === '') $vid = $get('video_versions\\\\*"\s*:\s*\[\s*\{[^\]]*?\\\\*"url'); if ($vid === '') $vid = $get('og:video(?::secure_url)?');
  $img = $get('display_url'); if ($img === '') $img = $get('og:image');
  return [$vid, $img];
}
/* A public reel without a token: tries each way Instagram shows a public reel (its embed page as a phone and as a computer, the link preview
   it gives Facebook and WhatsApp, its own page data) until one gives the video file. ['', ''] when none does. Each attempt is noted in a
   private log so a refusal can be traced. */
function ig_public_urls(string $code): array {
  @set_time_limit(300);
  $base = defined('FX_IG_EMBED') ? FX_IG_EMBED : 'https://www.instagram.com';
  $fetch = function (string $url, string $ua, array $hdr): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 15, CURLOPT_CONNECTTIMEOUT => 8, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_HTTPHEADER => array_merge($hdr, ['Accept-Language: en-US,en;q=0.9']), CURLOPT_USERAGENT => $ua, CURLOPT_ENCODING => '']);
    $r = (string)curl_exec($ch); $c = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch); return [$c, $r];
  };
  $ok = fn(string $u): bool => $u !== '' && (defined('FX_IG_EMBED') || (bool)preg_match('~^https://[\w.-]+\.(cdninstagram\.com|fbcdn\.net)/~', $u));
  $phone = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.0 Mobile/15E148 Safari/604.1';
  $pc = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36';
  $bot = 'facebookexternalhit/1.1 (+http://www.facebook.com/externalhit_uatext.php)';
  $api = ['X-IG-App-ID: 936619743392459', 'X-Requested-With: XMLHttpRequest', 'Accept: */*', "Referer: $base/reel/$code/"];
  $vars = rawurlencode(json_encode(['shortcode' => $code]));
  $tries = [
    ["$base/reel/$code/embed/captioned/", $phone, []], ["$base/p/$code/embed/captioned/", $pc, []],
    ["$base/reel/$code/", $bot, []], ["$base/p/$code/", 'WhatsApp/2.24.20.71 A', []],   // link previews
    ["$base/graphql/query/?doc_id=8845758582119845&variables=$vars", $pc, $api], ["$base/graphql/query/?query_hash=b3055c01b4b222b8a47dc12b090e4e64&variables=$vars", $pc, $api],
    ["$base/p/$code/?__a=1&__d=dis", $phone, $api],
  ];
  $log = []; $out = ['', ''];
  foreach ($tries as $i => [$url, $ua, $hdr]) {
    [$c, $body] = $fetch($url, $ua, $hdr); [$vid, $img] = ig_pick($body);
    if (preg_match('~copyright_blocked\\\\*"\s*:\s*true~', $body)) $GLOBALS['fx_ig_music'] = true;   // a reel with protected music: Instagram never gives its video
    $log[] = ($i + 1) . ":$c" . ($vid !== '' ? '+' : '');
    if ($ok($vid)) { $out = [$vid, $img]; break; }
  }
  $f = __DIR__ . '/ig-log.txt'; $old = is_file($f) ? array_slice(file($f, FILE_IGNORE_NEW_LINES), -29) : [];
  @file_put_contents($f, implode("\n", array_merge($old, [gmdate('Y-m-d H:i') . " $code " . implode(' ', $log) . ($out[0] !== '' ? ' copied' : ' refused')])) . "\n");
  return $out;
}
/* Copies a public reel to our server; null when Instagram doesn't give its video file. */
function ig_public_copy(string $code, string $prod): ?array {
  if (!function_exists('curl_init')) return null;
  [$vid, $img] = ig_public_urls($code); if ($vid === '') return null;
  $name = $prod . '-' . bin2hex(random_bytes(4)) . '.mp4';
  if (ig_download($vid, video_dir() . "/$name") !== true) return null;
  $cover = '';
  if ($img !== '' && function_exists('imagewebp') && ($tmp = tempnam(sys_get_temp_dir(), 'fxig'))) {
    global $PRIV;
    if (ig_download($img, $tmp, 15 * 1048576) === true && ($im = @imagecreatefromstring((string)file_get_contents($tmp)))) {
      $k = $prod . '-cover-' . bin2hex(random_bytes(4)) . '.webp'; imagepalettetotruecolor($im);
      if (@imagewebp($im, "$PRIV/product-images/$k", 80)) $cover = "up/$k";
    }
    @unlink($tmp);
  }
  return ['id' => bin2hex(random_bytes(5)), 'file' => $name, 'cover' => $cover, 'product' => $prod, 'on' => true, 'ig_code' => $code];
}
/* the new entry for the video list, or an error message */
function video_from_link(string $url, string $prod) {
  $url = trim($url);
  if (preg_match('~^(?:https?://)?(?:www\.)?instagram\.com/(?:[\w.]+/)?(?:reels?|p|tv)/([A-Za-z0-9_-]+)~i', $url, $m)) {
    $c = ig_fresh();   // connected: copy it to our server like the reel grid does
    if ($c && ($id = ig_find($c, $m[1])) && is_array($v = ig_copy($c, $id, $prod))) return $v + ['link' => $url];
    if ($v = ig_public_copy($m[1], $prod)) return $v + ['link' => $url];   // a public reel Instagram lets us copy
    if (!empty($GLOBALS['fx_ig_music'])) return 'This reel has music Instagram protects, so Instagram never lets the video be copied. In Instagram open the reel, tap ⋯ then Download, and add it under Upload from your phone.';
    return 'Instagram did not let us copy this reel, so it can’t play on your website. In Instagram open the reel, tap ⋯ then Download, and add it under Upload from your phone. Private accounts’ reels can’t be copied.';
  }
  if (preg_match('~^(?:https?://)?(?:[\w-]+\.)*(youtube\.com|youtu\.be|tiktok\.com|facebook\.com|fb\.watch)/~i', $url))
    return 'YouTube, TikTok and Facebook do not let their videos be copied. Save the video to your phone and upload it, or paste a reel link from your Instagram.';
  if (!preg_match('~^https?://~i', $url)) $url = 'https://' . $url;
  $tmp = tempnam(sys_get_temp_dir(), 'fxvl'); @set_time_limit(300);
  for ($try = 0; $try < 2; $try++) {
    [$ok, $final] = link_get($url, $tmp, 300 * 1048576);
    if ($ok !== true) { @unlink($tmp); return $ok; }
    $mime = function_exists('finfo_open') ? (string)finfo_file(finfo_open(FILEINFO_MIME_TYPE), $tmp) : '';
    if (isset(VIDEO_MIMES[$mime])) {
      $name = $prod . '-' . bin2hex(random_bytes(4)) . '.' . VIDEO_MIMES[$mime];
      if (!@rename($tmp, video_dir() . "/$name")) { @unlink($tmp); return 'The video could not be saved. Please try again.'; }
      @chmod(video_dir() . "/$name", 0640);
      return ['id' => bin2hex(random_bytes(5)), 'file' => $name, 'cover' => '', 'product' => $prod, 'on' => true, 'link' => trim($url)];
    }
    /* a web page: the video it shares (og:video), or the first <video> on it */
    $html = $try || filesize($tmp) > 4 * 1048576 ? '' : (string)file_get_contents($tmp);
    $src = '';
    foreach (['og:video:secure_url', 'og:video:url', 'og:video', 'twitter:player:stream'] as $k)
      if (preg_match('~<meta[^>]+(?:property|name)=["\']' . preg_quote($k, '~') . '["\'][^>]*content=["\']([^"\']+)~i', $html, $x) || preg_match('~<meta[^>]+content=["\']([^"\']+)["\'][^>]*(?:property|name)=["\']' . preg_quote($k, '~') . '["\']~i', $html, $x)) { $src = $x[1]; break; }
    if ($src === '' && preg_match('~<(?:video|source)[^>]+src=["\']([^"\']+)~i', $html, $x)) $src = $x[1];
    if ($src === '') break;
    $url = link_abs($src, $final);
  }
  @unlink($tmp);
  return 'No video was found at that link. Paste a reel link from your Instagram, or a link that opens the video itself (it often ends in .mp4).';
}
