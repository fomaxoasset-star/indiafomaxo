<?php
declare(strict_types=1);
/* FOMAXO India — the Instagram reels row on the home page (set up on Admin → Settings → Instagram).
   GET api/instagram.php           → {"user": "fomaxo_india", "reels": [{id, poster, video, link, caption}, …]}
                                      and, when the last check is over 30 minutes old, a check for new reels after answering.
   GET api/instagram.php?f=<id>.jpg → a reel's cover picture; ?f=<id>.mp4 → its video (kept in fomaxo-private/instagram). */
require __DIR__ . '/store-lib.php';
require __DIR__ . '/instagram-lib.php';
header('X-Content-Type-Options: nosniff');

if (isset($_GET['f'])) {
  $f = (string)$_GET['f'];
  if (!preg_match('/^\d{5,30}\.(jpg|mp4)$/', $f, $m) || !is_file($path = ig_dir() . "/$f")) { http_response_code(404); exit; }
  $size = filesize($path); $from = 0; $to = $size - 1;
  header('Content-Type: ' . ($m[1] === 'jpg' ? 'image/jpeg' : 'video/mp4'));
  header('Cache-Control: public, max-age=604800');   // a reel's files never change
  header('Accept-Ranges: bytes');
  if (preg_match('/^bytes=(\d*)-(\d*)$/', (string)($_SERVER['HTTP_RANGE'] ?? ''), $r) && ($r[1] !== '' || $r[2] !== '')) {   // phones play videos in pieces
    if ($r[1] === '') { $from = max(0, $size - (int)$r[2]); } else { $from = (int)$r[1]; if ($r[2] !== '') $to = min($to, (int)$r[2]); }
    if ($from > $to) { http_response_code(416); header("Content-Range: bytes */$size"); exit; }
    http_response_code(206); header("Content-Range: bytes $from-$to/$size");
  }
  header('Content-Length: ' . ($to - $from + 1));
  if ($_SERVER['REQUEST_METHOD'] === 'HEAD') exit;
  $fh = fopen($path, 'rb'); fseek($fh, $from); $left = $to - $from + 1;
  while ($left > 0 && !feof($fh)) { $chunk = fread($fh, min(262144, $left)); echo $chunk; $left -= strlen($chunk); flush(); }
  fclose($fh); exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: public, max-age=300');
try {
  $s = ig_settings();
  echo json_encode(['user' => $s['user'] ?: (fomaxo_store_data()['contact']['instagram'] ?? ''), 'reels' => ig_public()], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
  if ($s['token'] !== '' && time() - (int)ig_cache()['at'] > IG_EVERY) {   // answer first, then look for new reels
    if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request();
    ig_refresh();
  }
} catch (Throwable $e) {
  error_log('FOMAXO Instagram: ' . $e->getMessage());
  echo '{"user":"","reels":[]}';
}
