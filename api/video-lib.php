<?php
declare(strict_types=1);
/* FOMAXO India — shop videos (fomaxo.in/admin → Products → Videos): short upright videos on the home page; tapping one opens it
   full screen with its product's Add To Cart / Buy Now. The list is kept in the settings ('videos'); the video files go in
   fomaxo-private/videos and are sent by api/live.php?vid=… (cover photos go with the product photos, api/live.php?img=…).
   From Instagram: the access token is typed on the admin page and kept in fomaxo-private/instagram.php (never on GitHub,
   never public). A reel is copied to our server, so it keeps playing even if it is removed from Instagram. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const IG_CODE = '/^[A-Za-z0-9_-]{5,40}$/';   // an Instagram reel shown with Instagram's own player (pasted link, no token)
const VIDEO_FILE = '/^[a-z0-9-]{1,48}-[a-f0-9]{8}\.(mp4|mov|webm)$/';
function shop_videos(): array { return array_values(array_filter(json_decode((string)shop_setting('videos'), true) ?: [], fn($v) => is_array($v) && !empty($v['id']) && (preg_match(VIDEO_FILE, (string)($v['file'] ?? '')) || preg_match(IG_CODE, (string)($v['embed'] ?? ''))))); }
function shop_videos_save(array $v): void { shop_set('videos', json_encode(array_values($v), JSON_UNESCAPED_SLASHES)); }
function video_dir(): string { global $PRIV; $d = "$PRIV/videos"; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
/* for STORE_LIVE.videos: only the ones switched on, of products on the website */
function shop_videos_live(): array {
  if (shop_setting('videos_off') === '1') return [];   // the main switch on Admin → Products → Videos: the whole row is off
  $cat = fomaxo_catalog()['products']; $out = [];
  foreach (shop_videos() as $v) { if (empty($v['on']) || !empty($v['embed'])) continue;   // only videos on our server: an Instagram-player entry sends shoppers off to Instagram
    $all = video_products($v); $ps = array_values(array_filter($all, fn($id) => isset($cat[$id]) && empty($cat[$id]['hidden'])));   // one product, several, or none (a Shop Now button)
    if (($all || (string)($v['product'] ?? '') !== '') && !$ps) continue;   // every product in it is hidden or deleted
    $out[] = ['v' => 'api/live.php?vid=' . $v['file']] + ['p' => $ps[0] ?? ''] + (count($ps) > 1 ? ['a' => array_slice($ps, 1)] : []) + (($v['cover'] ?? '') !== '' ? ['c' => (string)$v['cover']] : []) + (!$ps && ($v['words'] ?? '') !== '' ? ['w' => (string)$v['words']] : []); }
  return $out;
}
/* the products a video sells, first one first ('product' plus 'also'); only those in $cat when it is given */
function video_products(array $v, ?array $cat = null): array {
  return array_values(array_unique(array_filter(array_merge([(string)($v['product'] ?? '')], (array)($v['also'] ?? [])), fn($id) => is_string($id) && $id !== '' && ($cat === null || isset($cat[$id])))));
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
