<?php
declare(strict_types=1);
/* FOMAXO India — what the admin page changes, for the website.
   GET api/live.php          → a small script setting window.STORE_LIVE: stock left per product and size, products
                               added, hidden or edited on the admin page, and changed prices. index.html loads it before the shop.
   GET api/live.php?img=…    → a product photo uploaded on the admin page (kept in fomaxo-private/product-images). */
require __DIR__ . '/store-lib.php';
header('X-Content-Type-Options: nosniff');

if (isset($_GET['img'])) {
  $f = (string)$_GET['img'];
  $path = "$PRIV/product-images/$f";
  if (!preg_match('/^[a-z0-9-]{1,48}-[a-f0-9]{8}\.(webp|jpg)$/', $f) || !is_file($path)) { http_response_code(404); exit; }
  header('Content-Type: ' . (str_ends_with($f, '.webp') ? 'image/webp' : 'image/jpeg'));
  header('Cache-Control: public, max-age=31536000, immutable');   // a new upload always gets a new file name
  readfile($path); exit;
}

header('Content-Type: application/javascript; charset=utf-8');
header('Cache-Control: no-store');
try {
  fomaxo_catalog();   // the first time, this copies the products in index.html into the database
  $live = ['stock' => shop_stock(), 'lowStock' => shop_low_stock(), 'hidden' => [], 'prices' => [], 'compareAt' => [], 'added' => [], 'edits' => []];
  foreach (shop_products() as $id => $d) {
    if ($d['hidden']) $live['hidden'][] = $id;
    if ($d['added']) { $p = $d['site'] ?? null; if (is_array($p) && !$d['hidden']) $live['added'][] = $p; continue; }
    if (!empty($d['edit']) && !$d['hidden']) $live['edits'][$id] = $d['edit'];
    if (!empty($d['prices'])) $live['prices'][$id] = $d['prices'];
    if (!empty($d['compareAt'])) $live['compareAt'][$id] = $d['compareAt'];
  }
  /* the checkout page shows "Have a coupon code?" only while a code is switched on and not past its end date */
  $q = shop_db()->prepare("SELECT COUNT(*) FROM coupons WHERE active = 1 AND (ends = '' OR ends >= ?)"); $q->execute([date('Y-m-d')]);
  $live['coupons'] = (int)$q->fetchColumn() > 0;
  if (($loc = shop_store_location()) !== null)
    $live['stores'] = $loc['show'] ? array_map(fn($st) => $st + ['mapQuery' => shop_map_query_from_link((string)($st['link'] ?? ''))], $loc['stores']) : false;
  echo 'window.STORE_LIVE = ' . json_encode($live, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . ";\n";
} catch (Throwable $e) {
  error_log('FOMAXO live: ' . $e->getMessage());
  echo "/* shop data unavailable */\n";
}
