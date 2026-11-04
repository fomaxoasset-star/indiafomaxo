<?php
declare(strict_types=1);
/* FOMAXO India — what the admin page changes, for the website.
   GET api/live.php          → a small script setting window.STORE_LIVE: stock left per product and size, products
                               added, hidden or edited on the admin page, and changed prices. index.html loads it before the shop.
   GET api/live.php?img=…    → a product photo uploaded on the admin page (kept in fomaxo-private/product-images); pages ask for media/p/<file>,
                               which the web server sends itself once this has made the copy (.htaccess sends a missing one here).
   GET api/live.php?vid=…    → a shop video added on the admin page (kept in fomaxo-private/videos). */
require __DIR__ . '/store-lib.php';
require __DIR__ . '/video-lib.php';
header('X-Content-Type-Options: nosniff');

if (isset($_GET['img'])) {
  $f = (string)$_GET['img'];
  $path = "$PRIV/product-images/$f";
  if (!preg_match('/^[a-z0-9-]{1,48}-[a-f0-9]{8}\.(webp|jpg)$/', $f) || !is_file($path)) { http_response_code(404); exit; }
  header('Content-Type: ' . (str_ends_with($f, '.webp') ? 'image/webp' : 'image/jpeg'));
  header('Cache-Control: public, max-age=31536000, immutable');   // a new upload always gets a new file name
  fomaxo_pub($path, 'media/p', $f);   // the web server sends media/p/<file> from now on (api/pub-lib.php)
  readfile($path); exit;
}
if (isset($_GET['vid'])) {
  $f = (string)$_GET['vid'];
  if (!preg_match(VIDEO_FILE, $f) || !is_file($path = video_dir() . "/$f")) { http_response_code(404); exit; }
  try { $pub = video_pub($f); } catch (Throwable $e) { $pub = false; }
  if ($pub) {   // the web server sends its public copy from now on, so no PHP process is held while it downloads
    header('Cache-Control: public, max-age=2592000'); header('Location: /media/v/' . $f, true, 302); exit;
  }
  send_video($path);
}

header('Content-Type: application/javascript; charset=utf-8');
/* Every visitor gets the same answer, so it is worked out at most once a minute and kept in fomaxo-private/live-cache.js: one quick file read
   instead of the database and the whole of index.html on every page load. An admin save, an order or a returned order (stock) and a deploy
   (a newer index.html) start a fresh one straight away (fomaxo_live_drop in shop-db.php). */
$CACHE = "$PRIV/live-cache.js"; $js = ''; $built = false;
if (($t = @filemtime($CACHE)) && $t > time() - 60 && $t >= (int)@filemtime(dirname(__DIR__) . '/index.html')) $js = (string)@file_get_contents($CACHE);
if ($js === '') try {
  fomaxo_catalog();   // the first time, this copies the products in index.html into the database
  $live = ['stock' => shop_stock(), 'lowStock' => shop_low_stock(), 'perItem' => shop_max_per_item(), 'hidden' => [], 'prices' => [], 'compareAt' => [], 'added' => [], 'edits' => []];
  foreach (shop_products() as $id => $d) {
    if ($d['hidden']) $live['hidden'][] = $id;
    if ($d['added']) { $p = $d['site'] ?? null; if (is_array($p) && !$d['hidden']) $live['added'][] = $p; continue; }
    if (!empty($d['edit']) && !$d['hidden']) $live['edits'][$id] = $d['edit'];
    if (!empty($d['prices'])) $live['prices'][$id] = $d['prices'];
    if (!empty($d['compareAt'])) $live['compareAt'][$id] = $d['compareAt'];
  }
  if ($so = shop_sale_off()) $live['saleOff'] = $so;   // Discount Off on Admin → Products: those products show and sell at their Original price
  if (($loc = shop_store_location()) !== null)
    $live['stores'] = $loc['show'] ? array_map(fn($st) => $st + ['mapQuery' => shop_map_query_from_link((string)($st['link'] ?? ''))], $loc['stores']) : false;
  if (($offer = shop_offer_live()) !== null) $live['offer'] = $offer;   // the limited-time offer popup and countdown lines
  if (($np = shop_newprod_live()) !== null) $live['newProduct'] = $np;   // the Coming soon / Just arrived popup
  if ($ads = shop_ads()) $live['ads'] = $ads;
  if ($vids = shop_videos_live()) $live['videos'] = $vids;   // shop videos (Admin → Products → Videos)
  if ($off = shop_pages_off()) $live['pagesOff'] = $off;   // pages turned off on Admin → Settings → Site pages
  if ($cod = fomaxo_catalog()['cod']) $live['cod'] = ['min' => $cod['min'] / 100, 'max' => $cod['max'] / 100, 'fee' => $cod['fee'] / 100];   // Admin → Settings → Cash on delivery   // Meta, TikTok and Google ad tags (Admin → Settings → Ads)
  if ($tg = fomaxo_together_pct()) $live['together'] = $tg;   // Customers bought together: % off one of each of two products (Admin → Products)
  if ($tr = fomaxo_together_rs()) $live['togetherRs'] = $tr;   // … and / or ₹ off each pair
  try { $sm = shop_sold_month(); if ($sm['sold']) $live['sold'] = $sm['sold']; } catch (Throwable $e) {}   // "X purchased this week" on the product page, real orders only
  try { $live['also'] = shop_also(); } catch (Throwable $e) {}   // "You may also like" order on the product page, from real orders
  if (!shop_together()['on']) $live['fbtOff'] = 1;   // its On / Off switch: Off hides the box on every product page
  if ($wp = array_keys(array_filter(shop_wa_popups()))) $live['waPop'] = $wp;   // the WhatsApp coupon boxes switched On on Admin → Offers (never saved = Off)
  try {   // star rating and number of reviews per product (same as api/reviews.php?action=summary), so the shop shows the stars at once without asking reviews.php on every page load
    $rt = []; if ($rdb = reviews_db()) foreach ($rdb->query("SELECT product, AVG(rating) a, COUNT(*) n FROM reviews WHERE status = 'live' GROUP BY product") as $r) $rt[$r['product']] = ['rating' => round((float)$r['a'], 1), 'reviews' => (int)$r['n']];
    $live['ratings'] = (object)$rt;
  } catch (Throwable $e) {}   // left out: the website asks reviews.php itself, as before
  $js = 'window.STORE_LIVE = ' . json_encode($live, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n"; $built = true;
  $tmp = $CACHE . '.' . bin2hex(random_bytes(4));
  if (@file_put_contents($tmp, $js) === false || !@rename($tmp, $CACHE)) @unlink($tmp);
} catch (Throwable $e) {
  error_log('FOMAXO live: ' . $e->getMessage());
  $js = "/* shop data unavailable */\n";
}
/* the browser checks again on every page load (no-cache) but gets "not changed" with no data when nothing changed, e.g. on a reload */
$tag = substr(md5($js), 0, 16);
header('Cache-Control: private, no-cache'); header('X-LiteSpeed-Cache-Control: no-cache'); header('ETag: "' . $tag . '"');   // private: only the visitor's own browser may keep it, never Hostinger's CDN
if (str_contains((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? ''), $tag)) { http_response_code(304); exit; }
echo $js;

/* Automatic Instagram reels (Admin → Products → Videos): at most once an hour, after the visitor already has the answer above
   (checked only when the answer was worked out afresh, so a cached answer never opens the database) */
try {
  if ($built && is_file(ig_file()) && time() - (int)shop_setting('ig_sync_at') >= 3600) {
    if (function_exists('litespeed_finish_request')) litespeed_finish_request(); elseif (function_exists('fastcgi_finish_request')) fastcgi_finish_request(); else return;
    ignore_user_abort(true);
    ig_sync();
  }
} catch (Throwable $e) { error_log('FOMAXO Instagram: ' . $e->getMessage()); }
