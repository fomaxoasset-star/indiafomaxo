<?php
declare(strict_types=1);
/* FOMAXO India — real addresses for Google.
   GET /fomaxo-<name> → index.html with this product's title, description, photo, canonical address and Product data
                        (price, ₹, stock, star rating) in the page head. <base href="/"> keeps photos and scripts loading from
                        the site root, and a tiny script at the top turns the address into /#/product/<id> before the shop
                        starts, so it opens the product page exactly as before.
   GET /product/<id>  → moves (301) to that product's /fomaxo-<name> address.
   GET /sitemap.xml   → the home page and every product on sale.
   Both come here through the rewrite rules in the root .htaccess. Names, prices, photos, hidden products and stock follow
   the admin page, as on the site. */
require __DIR__ . '/store-lib.php';
header('X-Content-Type-Options: nosniff');

const SITE = 'https://fomaxo.in';

/* every product the shop shows: index.html, with the admin page's edits, added products and hidden ones applied */
function seo_products(): array {
  $cat = fomaxo_catalog()['products'];
  try { $live = shop_products(); $stock = shop_stock(); } catch (Throwable $e) { $live = []; $stock = []; }
  $out = [];
  foreach ($cat as $id => $c) {
    if (!empty($c['hidden'])) continue;
    $d = $live[$id] ?? [];
    if (!empty($c['added'])) $p = (array)($d['site'] ?? []);
    else {
      $p = fomaxo_store_product((string)$id) ?? [];
      $main = !empty($p['lockMain']) ? ($p['images'][0] ?? null) : null;
      if (!empty($d['edit']) && is_array($d['edit'])) $p = $d['edit'] + $p;
      if ($main) $p['images'] = array_values(array_unique([$main, ...(array)($p['images'] ?? [])]));
    }
    $care = $c['kind'] === 'care';
    $imgs = $care ? array_merge([(string)($p['image'] ?? '')], (array)($p['extraImages'] ?? [])) : (array)($p['images'] ?? []);
    $imgs = array_values(array_filter(array_map('seo_img', array_filter($imgs, 'is_string'))));
    if (!$imgs && $c['img']) $imgs = [SITE . '/' . $c['img']];
    $desc = array_values(array_filter(array_map('strval', (array)($p['description'] ?? [])))) ?: [(string)($p['short'] ?? '')];
    $family = $care ? explode(' — ', (string)($p['type'] ?? ''))[0] : explode(' · ', (string)($p['family'] ?? ''))[0];
    $sizes = [];
    foreach ($c['prices'] as $k => $v) {
      if ($v <= 0) continue;
      $left = $stock[$id][(string)$k] ?? null;
      $sizes[(string)$k] = ['price' => $v, 'out' => $left !== null ? $left <= 0 : !empty($c['soldOut'])];
    }
    $out[$id] = ['id' => (string)$id, 'name' => (string)$c['name'], 'family' => $family, 'kind' => $c['kind'], 'care' => $care,
      'short' => (string)($p['short'] ?? ''), 'desc' => $desc, 'images' => $imgs, 'sizes' => $sizes, 'vol' => $c['vol']];
  }
  return $out;
}
function seo_img(string $k): string {
  if ($k === '') return '';
  if (str_starts_with($k, 'up/')) return SITE . '/api/live.php?img=' . rawurlencode(substr($k, 3));
  return preg_match('/^[\w.-]+$/', $k) ? SITE . "/assets/img/$k.webp" : '';
}
/* the live star rating from api/reviews.php's store, or null when there are no reviews yet */
function seo_rating(string $id): ?array {
  global $PRIV;
  if (!is_file("$PRIV/reviews.sqlite")) return null;
  try {
    $db = new PDO("sqlite:$PRIV/reviews.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $s = $db->prepare("SELECT AVG(rating), COUNT(*) FROM reviews WHERE product = ? AND status = 'live'"); $s->execute([$id]);
    [$avg, $n] = $s->fetch(PDO::FETCH_NUM);
    return (int)$n > 0 ? ['avg' => round((float)$avg, 1), 'n' => (int)$n] : null;
  } catch (Throwable $e) { return null; }
}
function seo_title(array $p): string { return ($p['care'] || $p['family'] === '' ? $p['name'] : $p['name'] . ' ' . $p['family']) . ' — FOMAXO'; }
function seo_meta(array $p): string {
  $t = trim(preg_replace('/\s+/u', ' ', $p['short'] ?: $p['desc'][0]) ?? '');
  if (mb_strlen($t) > 155) $t = rtrim(mb_substr($t, 0, mb_strrpos(mb_substr($t, 0, 152), ' ') ?: 152), ' ,.;—') . '…';
  return $t;
}
function seo_size_name(array $p, string $k): string {
  if ($k === 'one') return $p['vol'] ?: 'One size';
  if ($p['kind'] === 'set') return "$k ml set";
  return "$k ml";
}
/* the product's own address, made from its name as on fomaxo.com: fomaxo.in/fomaxo-gold, fomaxo.in/fomaxo-avo-kiss-lip-balm.
   Two products with the same name: the later one uses its id instead. */
function seo_slug(string $s): string { return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)) ?? '', '-'); }
function seo_urls(array $all): array {
  $out = []; $taken = [];
  foreach ($all as $id => $p) { $s = seo_slug($p['name']) ?: seo_slug((string)$id); if (isset($taken[$s])) $s = seo_slug((string)$id); $taken[$s] = 1; $out[$id] = $s; }
  return $out;
}
function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_HTML5, 'UTF-8'); }

$page = (string)($_GET['p'] ?? '');

if ($page === 'sitemap') {
  header('Content-Type: application/xml; charset=utf-8');
  header('Cache-Control: public, max-age=3600');
  $mod = gmdate('Y-m-d', (int)@filemtime(dirname(__DIR__) . '/index.html') ?: time());
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9" xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
  echo '  <url><loc>' . SITE . "/</loc><lastmod>$mod</lastmod></url>\n";
  $all = seo_products(); $slugs = seo_urls($all);
  foreach ($all as $id => $p) {
    echo '  <url><loc>' . SITE . '/fomaxo-' . h($slugs[$id]) . "</loc><lastmod>$mod</lastmod>";
    foreach (array_slice($p['images'], 0, 5) as $im) echo '<image:image><image:loc>' . h($im) . '</image:loc></image:image>';
    echo "</url>\n";
  }
  echo "</urlset>\n";
  exit;
}

/* ---- one product: /fomaxo-<name> ---- */
$want = seo_slug((string)($_GET['id'] ?? ''));   // /fomaxo-<name> (or /product/<id>, which moves to the name address)
$html = (string)@file_get_contents(dirname(__DIR__) . '/index.html');
if ($html === '') { http_response_code(503); exit; }
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-cache');
$all = $want !== '' && strlen($want) <= 80 ? seo_products() : []; $slugs = seo_urls($all);
$id = (string)(array_search($want, $slugs, true) ?: (isset($all[$want]) ? $want : ''));
$p = $all[$id] ?? null;
if ($p && ($_GET['p'] ?? '') !== 'name' || $p && $slugs[$id] !== $want) {
  $q = $_GET; unset($q['p'], $q['id']);
  header('Location: ' . SITE . '/fomaxo-' . $slugs[$id] . ($q ? '?' . http_build_query($q) : ''), true, 301); exit;
}
if (!$p) $id = $want;
$hash = '#/product/' . $id;   // the address the shop itself uses; ?size=… and ?utm_… ride along after it
$qs = (string)($_SERVER['QUERY_STRING'] ?? '');
parse_str($qs, $q); unset($q['p'], $q['id']);
if ($q) $hash .= '?' . http_build_query($q);
$go = '<base href="/"><script>history.replaceState(null,"","/"+' . json_encode($hash, JSON_HEX_TAG | JSON_UNESCAPED_SLASHES) . ')</script>';

if (!$p) {   // unknown or hidden product: the shop's own "not found" page, and Google is told it is gone
  http_response_code(404);
  $html = preg_replace('/<!--seo-->.*?<!--\/seo-->/s', '<meta name="robots" content="noindex">', $html, 1);
  echo preg_replace('/<meta charset="utf-8">/', '$0' . $go, $html, 1);
  exit;
}

$url = SITE . '/fomaxo-' . $slugs[$id];
$offers = [];
foreach ($p['sizes'] as $k => $s) { $k = (string)$k; $offers[] = ['@type' => 'Offer', 'name' => seo_size_name($p, $k), 'sku' => "$p[id]-$k",
  'url' => $url . (count($p['sizes']) > 1 ? '?size=' . rawurlencode($k) : ''),
  'price' => number_format($s['price'], 2, '.', ''), 'priceCurrency' => 'INR', 'itemCondition' => 'https://schema.org/NewCondition',
  'availability' => 'https://schema.org/' . ($s['out'] ? 'OutOfStock' : 'InStock'), 'seller' => ['@id' => SITE . '/#org'],
  'shippingDetails' => ['@type' => 'OfferShippingDetails', 'shippingRate' => ['@type' => 'MonetaryAmount', 'value' => 0, 'currency' => 'INR'],
    'shippingDestination' => ['@type' => 'DefinedRegion', 'addressCountry' => 'IN'],
    'deliveryTime' => ['@type' => 'ShippingDeliveryTime', 'handlingTime' => ['@type' => 'QuantitativeValue', 'minValue' => 0, 'maxValue' => 1, 'unitCode' => 'DAY'],
      'transitTime' => ['@type' => 'QuantitativeValue', 'minValue' => 1, 'maxValue' => 3, 'unitCode' => 'DAY']]]]; }
$ld = ['@context' => 'https://schema.org', '@graph' => [
  ['@type' => 'Organization', '@id' => SITE . '/#org', 'name' => 'FOMAXO', 'url' => SITE . '/'],
  ['@type' => 'Product', '@id' => "$url#product", 'name' => $p['name'], 'url' => $url, 'sku' => $p['id'],
    'description' => implode(' ', $p['desc']), 'image' => $p['images'], 'brand' => ['@type' => 'Brand', 'name' => 'FOMAXO'],
    'category' => $p['family'] ?: null] + ($offers ? ['offers' => count($offers) === 1 ? $offers[0] : $offers] : []),
  ['@type' => 'BreadcrumbList', 'itemListElement' => [
    ['@type' => 'ListItem', 'position' => 1, 'name' => 'FOMAXO', 'item' => SITE . '/'],
    ['@type' => 'ListItem', 'position' => 2, 'name' => $p['name'], 'item' => $url]]],
]];
if ($r = seo_rating($p['id'])) $ld['@graph'][1]['aggregateRating'] = ['@type' => 'AggregateRating', 'ratingValue' => $r['avg'], 'reviewCount' => $r['n'], 'bestRating' => 5, 'worstRating' => 1];
if ($ld['@graph'][1]['category'] === null) unset($ld['@graph'][1]['category']);

$title = h(seo_title($p)); $desc = h(seo_meta($p)); $img = h($p['images'][0] ?? SITE . '/assets/img/oldmoney-1.jpg');
$head = '<link rel="canonical" href="' . h($url) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:type" content="product"><meta property="og:site_name" content="FOMAXO India">'
  . '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
$html = preg_replace('/<!--seo-->.*?<!--\/seo-->/s', '<!--seo-->' . str_replace(['\\', '$'], ['\\\\', '\\$'], $head) . '<!--/seo-->', $html, 1);
$swap = [
  '/<title>.*?<\/title>/s' => "<title>$title</title>",
  '/<meta name="description" content="[^"]*">/' => "<meta name=\"description\" content=\"$desc\">",
  '/<meta property="og:title" content="[^"]*">/' => "<meta property=\"og:title\" content=\"$title\">",
  '/<meta property="og:description" content="[^"]*">/' => "<meta property=\"og:description\" content=\"$desc\">",
  '/<meta property="og:image" content="[^"]*">/' => "<meta property=\"og:image\" content=\"$img\">",
];
foreach ($swap as $re => $to) $html = preg_replace($re, str_replace(['\\', '$'], ['\\\\', '\\$'], $to), $html, 1);
$html = preg_replace('/<link rel="preload" as="image"[^>]*>/', '', $html, 2);   // the home banner (phone and laptop): not shown on a product page
echo preg_replace('/<meta charset="utf-8">/', '$0' . str_replace(['\\', '$'], ['\\\\', '\\$'], $go), $html, 1);
