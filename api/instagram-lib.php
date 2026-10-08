<?php
declare(strict_types=1);
/* FOMAXO India — the latest Instagram reels for the home page (Admin → Settings → Instagram).
   The owner pastes an Instagram access token once (Instagram API with Instagram Login: a Business or Creator account,
   no Facebook Page needed). The server asks Instagram for the newest reels at most every 30 minutes, copies each
   cover picture and video into fomaxo-private/instagram (Instagram's own links expire after a few days), and the
   home page shows them. The token lasts 60 days, so it is renewed here every week while the site is visited.
   Needs api/store-lib.php first (for $PRIV and the settings). */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const IG_API = 'https://graph.instagram.com';
const IG_EVERY = 1800;              // seconds between checks for new reels
const IG_MAX_VIDEO = 60 * 1048576;  // a bigger reel shows its cover and opens on Instagram

function ig_dir(): string { global $PRIV; $d = "$PRIV/instagram"; if (!is_dir($d)) @mkdir($d, 0750, true); return $d; }
/* token, show (on the home page), count (reels shown), user (the @name), renewed (when the token was last renewed) */
function ig_settings(): array {
  $s = json_decode((string)shop_setting('instagram'), true);
  return (is_array($s) ? $s : []) + ['token' => '', 'show' => true, 'count' => 8, 'user' => '', 'renewed' => 0];
}
function ig_save_settings(array $s): void { shop_set('instagram', json_encode($s, JSON_UNESCAPED_SLASHES)); }
function ig_cache(): array {
  $c = json_decode((string)@file_get_contents(ig_dir() . '/cache.json'), true);
  return (is_array($c) ? $c : []) + ['at' => 0, 'reels' => [], 'error' => ''];
}

function ig_get(string $path, array $q): array {
  $ch = curl_init(IG_API . $path . '?' . http_build_query($q));
  curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20, CURLOPT_CONNECTTIMEOUT => 8]);
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  $d = is_string($res) ? json_decode($res, true) : null;
  if ($code !== 200 || !is_array($d)) {
    $msg = is_array($d) ? (string)($d['error']['message'] ?? '') : '';
    throw new RuntimeException($msg !== '' ? $msg : ($err ?: "Instagram answered $code"));
  }
  return $d;
}

/* Copies one file from Instagram's CDN, no bigger than $max bytes. */
function ig_download(string $url, string $to, int $max): bool {
  $tmp = "$to.part"; $fh = @fopen($tmp, 'wb'); if (!$fh) return false;
  $ch = curl_init($url);
  curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_TIMEOUT => 120, CURLOPT_CONNECTTIMEOUT => 8,
    CURLOPT_NOPROGRESS => false, CURLOPT_PROGRESSFUNCTION => fn($c, $dlTotal, $dl) => ($dlTotal > $max || $dl > $max) ? 1 : 0]);
  $ok = curl_exec($ch) && (int)curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200; curl_close($ch); fclose($fh);
  if ($ok && filesize($tmp) > 0) return rename($tmp, $to);
  @unlink($tmp); return false;
}

/* Who the token belongs to; throws with Instagram's message when the token is wrong or expired. */
function ig_whoami(string $token): string { return (string)(ig_get('/me', ['fields' => 'username', 'access_token' => $token])['username'] ?? ''); }

/* Asks Instagram for the newest reels and copies the new ones here. Old copies are removed. */
function ig_refresh(): array {
  $s = ig_settings(); $c = ig_cache();
  if ($s['token'] === '') return $c;
  $dir = ig_dir(); $lock = @fopen("$dir/.lock", 'c');
  if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $c;   // another visit is already checking
  @set_time_limit(300); @ignore_user_abort(true);
  try {
    if (time() - (int)$s['renewed'] > 7 * 86400) {   // keep the 60-day token alive
      try {
        $r = ig_get('/refresh_access_token', ['grant_type' => 'ig_refresh_token', 'access_token' => $s['token']]);
        if (!empty($r['access_token'])) { $s['token'] = (string)$r['access_token']; $s['renewed'] = time(); ig_save_settings($s); }
      } catch (Throwable $e) { error_log('FOMAXO Instagram token renew: ' . $e->getMessage()); }
    }
    $d = ig_get('/me/media', ['fields' => 'id,media_type,media_product_type,media_url,thumbnail_url,permalink,caption,timestamp', 'limit' => 50, 'access_token' => $s['token']]);
    $reels = [];
    foreach ($d['data'] ?? [] as $m) {
      if (($m['media_type'] ?? '') !== 'VIDEO' || !preg_match('/^\d{5,30}$/', (string)($m['id'] ?? ''))) continue;
      $id = (string)$m['id'];
      $poster = is_file("$dir/$id.jpg") || (!empty($m['thumbnail_url']) && ig_download((string)$m['thumbnail_url'], "$dir/$id.jpg", 8 * 1048576));
      if (!$poster) continue;
      $video = is_file("$dir/$id.mp4") || (!empty($m['media_url']) && ig_download((string)$m['media_url'], "$dir/$id.mp4", IG_MAX_VIDEO));
      $reels[] = ['id' => $id, 'video' => $video, 'link' => (string)($m['permalink'] ?? ''),
        'caption' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($m['caption'] ?? ''))), 0, 140), 'at' => (string)($m['timestamp'] ?? '')];
      if (count($reels) >= 12) break;
    }
    $keep = array_flip(array_column($reels, 'id'));
    foreach (glob("$dir/*.{jpg,mp4}", GLOB_BRACE) ?: [] as $f) if (!isset($keep[pathinfo($f, PATHINFO_FILENAME)])) @unlink($f);
    $c = ['at' => time(), 'reels' => $reels, 'error' => ''];
  } catch (Throwable $e) {
    error_log('FOMAXO Instagram: ' . $e->getMessage());
    $c['at'] = time(); $c['error'] = $e->getMessage();   // keep showing the reels already here, try again later
  }
  file_put_contents("$dir/cache.json", json_encode($c, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), LOCK_EX);
  flock($lock, LOCK_UN); fclose($lock);
  return $c;
}

/* What the home page gets: the newest reels, or [] when Instagram is not connected or hidden. */
function ig_public(): array {
  $s = ig_settings(); if ($s['token'] === '' || !$s['show']) return [];
  $out = [];
  foreach (ig_cache()['reels'] as $r) {
    $out[] = ['id' => $r['id'], 'poster' => "/api/instagram.php?f=$r[id].jpg", 'video' => $r['video'] ? "/api/instagram.php?f=$r[id].mp4" : '', 'link' => $r['link'], 'caption' => $r['caption']];
    if (count($out) >= max(4, min(12, (int)$s['count']))) break;
  }
  return $out;
}
