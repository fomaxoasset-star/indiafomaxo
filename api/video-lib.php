<?php
declare(strict_types=1);
/* FOMAXO India — shop videos (fomaxo.in/admin → Products → Videos): short upright videos on the home page; tapping one opens it
   full screen with its product's Add To Cart / Buy Now. The list is kept in the settings ('videos'); the video files go in
   fomaxo-private/videos and are sent by api/live.php?vid=… (cover photos go with the product photos, api/live.php?img=…).
   From Instagram: the access token is typed on the admin page and kept in fomaxo-private/instagram.php (never on GitHub,
   never public). A reel is copied to our server, so it keeps playing even if it is removed from Instagram. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const VIDEO_FILE = '/^[a-z0-9-]{1,48}-[a-f0-9]{8}\.(mp4|mov|webm)$/';
function shop_videos(): array { return array_values(array_filter(json_decode((string)shop_setting('videos'), true) ?: [], fn($v) => is_array($v) && !empty($v['id']) && preg_match(VIDEO_FILE, (string)($v['file'] ?? '')))); }
function shop_videos_save(array $v): void { shop_set('videos', json_encode(array_values($v), JSON_UNESCAPED_SLASHES)); }
function video_dir(): string { global $PRIV; $d = "$PRIV/videos"; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
/* for STORE_LIVE.videos: only the ones switched on, of products on the website */
function shop_videos_live(): array {
  $cat = fomaxo_catalog()['products']; $out = [];
  foreach (shop_videos() as $v) { $p = $cat[$v['product'] ?? ''] ?? null; if (empty($v['on']) || !$p || !empty($p['hidden'])) continue;
    $out[] = ['v' => 'api/live.php?vid=' . $v['file'], 'p' => (string)$v['product']] + (($v['cover'] ?? '') !== '' ? ['c' => (string)$v['cover']] : []); }
  return $out;
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
