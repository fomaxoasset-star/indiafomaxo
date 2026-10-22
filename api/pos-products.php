<?php
declare(strict_types=1);
/* FOMAXO India — the product list for the store system at pos.fomaxo.in (its Stock → Sync, and on its own every 10 minutes).
   Every product on sale with its sizes, prices, crossed-out prices, family and photos, as the shop sells them right now:
   edits, added products and Discount Off from fomaxo.in/admin are applied, hidden products are left out.
   Only what the shop already shows to every visitor; nothing private. The store system keeps its own stock counts. */
require __DIR__ . '/store-lib.php';
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-cache');
header('X-LiteSpeed-Cache-Control: no-cache');
header('X-Robots-Tag: noindex');

$cat = fomaxo_catalog();
try { $live = shop_products(); } catch (Throwable $e) { $live = []; }
$out = [];
foreach ($cat['products'] as $id => $p) {
  if (!empty($p['hidden']) || !$p['prices']) continue;
  $id = (string)$id;
  /* the product as written in index.html (or as added on the admin page), with admin edits on top: for its family and photos */
  $src = !empty($p['added']) ? (array)($live[$id]['site'] ?? []) : (fomaxo_store_product($id) ?? []);
  if (is_array($live[$id]['edit'] ?? null)) $src = $live[$id]['edit'] + $src;
  $imgs = [];
  foreach (array_merge((array)($src['images'] ?? []), [$src['image'] ?? '']) as $k) if (is_string($k) && $k !== '' && !in_array($k, $imgs, true)) $imgs[] = $k;
  $out[] = [
    'id' => $id, 'name' => $p['name'], 'kind' => $p['kind'] !== '' ? $p['kind'] : (string)($src['kind'] ?? ''), 'cat' => $p['cat'], 'vol' => $p['vol'],
    'family' => (string)($src['family'] ?? ''), 'sizes' => array_map('strval', array_keys($p['prices'])),
    'prices' => (object)$p['prices'], 'compareAt' => (object)$p['was'], 'soldOut' => !empty($p['soldOut']), 'images' => $imgs,
  ];
}
echo json_encode(['products' => $out], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
