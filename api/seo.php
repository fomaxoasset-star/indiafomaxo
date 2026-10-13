<?php
declare(strict_types=1);
/* FOMAXO India — real addresses for Google.
   GET /fomaxo-<name> → index.html with this product's title, description, photo, canonical address and Product data
                        (price, ₹, stock, star rating) in the page head. <base href="/"> keeps photos and scripts loading from
                        the site root, and a tiny script at the top turns the address into /#/product/<id> before the shop
                        starts, so it opens the product page exactly as before.
   GET /product/<id>  → moves (301) to that product's /fomaxo-<name> address.
   GET /sitemap.xml   → the home page, the Returns page and every product on sale.
   GET /google-shopping.xml → the product list for free Google Shopping listings (Merchant Center → Products → Add from a file → link):
                        one line per size, with the same prices, "was" prices, stock and photos as the shop.
   GET /returns       → index.html with the Returns page's title and text (Merchant Center's return policy link), opening #/returns.
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
      'short' => (string)($p['short'] ?? ''), 'desc' => $desc, 'was' => (array)($c['was'] ?? []), 'images' => $imgs, 'sizes' => $sizes, 'vol' => $c['vol']];
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
  try {
    if (!($db = reviews_db())) return null;
    $s = $db->prepare("SELECT AVG(rating), COUNT(*) FROM reviews WHERE product = ? AND status = 'live'"); $s->execute([$id]);
    [$avg, $n] = $s->fetch(PDO::FETCH_NUM);
    return (int)$n > 0 ? ['avg' => round((float)$avg, 1), 'n' => (int)$n] : null;
  } catch (Throwable $e) { return null; }
}
/* the words people type into Google for each perfume (scent and occasion), taken from its notes, as on fomaxo.com; used only in
   Google titles, descriptions and the Shopping list, never on the page itself. Products not listed here keep their usual title. */
function seo_scent(string $id): string {
  return ['king' => 'Luxury Vanilla & Amber Perfume with Real Gold', 'gold' => 'Oud & Leather Arabic Perfume',
    'oldmoney' => 'Fresh Citrus & Sandalwood Perfume', 'royalcandy' => 'Sweet Candy & Praline Gourmand Perfume',
    'dollar' => 'Fresh Mint & Incense Winter Perfume', 'matchacoco' => 'Coconut & Vanilla Summer Perfume',
    'passionsin' => 'Fruity Passion Fruit Evening Perfume'][$id] ?? '';
}
function seo_title(array $p): string {
  if (($kw = seo_scent($p['id'])) !== '') return "$p[name] $kw — FOMAXO";
  return ($p['care'] || $p['family'] === '' ? $p['name'] : $p['name'] . ' ' . $p['family']) . ' — FOMAXO';
}
function seo_meta(array $p): string {
  $t = trim(preg_replace('/\s+/u', ' ', $p['short'] ?: $p['desc'][0]) ?? '');
  if (($kw = seo_scent($p['id'])) !== '') $t = "FOMAXO $p[name], $kw, $p[family]. $t";
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
  echo '  <url><loc>' . SITE . "/returns</loc><lastmod>$mod</lastmod></url>\n";
  $all = seo_products(); $slugs = seo_urls($all);
  foreach ($all as $id => $p) {
    echo '  <url><loc>' . SITE . '/fomaxo-' . h($slugs[$id]) . "</loc><lastmod>$mod</lastmod>";
    foreach (array_slice($p['images'], 0, 5) as $im) echo '<image:image><image:loc>' . h($im) . '</image:loc></image:image>';
    echo "</url>\n";
  }
  echo "</urlset>\n";
  exit;
}

if ($page === 'feed') {   // Google Shopping list
  header('Content-Type: application/xml; charset=utf-8');
  header('Cache-Control: public, max-age=3600');
  $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  $money = fn($v) => number_format((float)$v, 2, '.', '') . ' INR';
  $all = seo_products(); $slugs = seo_urls($all);
  echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<rss version="2.0" xmlns:g="http://base.google.com/ns/1.0">' . "\n<channel>\n<title>FOMAXO India</title>\n<link>" . SITE . "/</link>\n<description>FOMAXO luxury perfumes and personal care, India</description>\n";
  foreach ($all as $id => $p) {
    if (!$p['sizes'] || !$p['images']) continue;
    $kw = seo_scent($p['id']); $perfume = !$p['care'] && $p['kind'] !== 'car';
    $desc = trim(preg_replace('/\s+/u', ' ', implode(' ', $p['desc'])) ?? '');
    if ($kw !== '') $desc = "FOMAXO $p[name]: $kw. $desc";
    $desc .= ' Free delivery across India.';
    foreach ($p['sizes'] as $k => $sz) {
      $k = (string)$k; $size = seo_size_name($p, $k);
      $title = 'FOMAXO ' . $p['name'] . ($kw !== '' ? " $kw" : '') . ($p['family'] !== '' && !$p['care'] ? ", $p[family]" : '') . ($k !== 'one' ? " $size" : ($p['vol'] ? " $p[vol]" : ''));
      $was = $p['was'][$k] ?? null;
      echo "<item>\n  <g:id>" . $x("$p[id]-$k") . "</g:id>\n  <title>" . $x($title) . "</title>\n  <description>" . $x($desc) . "</description>\n"
         . '  <link>' . $x(SITE . '/fomaxo-' . $slugs[$id] . (count($p['sizes']) > 1 ? '?size=' . rawurlencode($k) : '')) . "</link>\n"
         . '  <g:image_link>' . $x($p['images'][0]) . "</g:image_link>\n";
      foreach (array_slice($p['images'], 1, 10) as $im) echo '  <g:additional_image_link>' . $x($im) . "</g:additional_image_link>\n";
      echo '  <g:availability>' . ($sz['out'] ? 'out_of_stock' : 'in_stock') . "</g:availability>\n  <g:condition>new</g:condition>\n  <g:brand>FOMAXO</g:brand>\n"
         . (is_numeric($was) && $was > $sz['price'] ? '  <g:price>' . $money($was) . "</g:price>\n  <g:sale_price>" . $money($sz['price']) . "</g:sale_price>\n" : '  <g:price>' . $money($sz['price']) . "</g:price>\n")
         . ($perfume ? "  <g:google_product_category>479</g:google_product_category>\n  <g:gender>unisex</g:gender>\n" : '')
         . '  <g:product_type>' . $x($perfume ? 'Perfume > ' . ($p['family'] ?: 'Perfume') : ($p['kind'] === 'car' ? 'Car Fragrance' : 'Personal Care')) . "</g:product_type>\n"
         . "  <g:identifier_exists>no</g:identifier_exists>\n  <g:age_group>adult</g:age_group>\n"
         . (count($p['sizes']) > 1 ? '  <g:item_group_id>' . $x($p['id']) . "</g:item_group_id>\n  <g:size>" . $x($size) . "</g:size>\n" : '')
         . "  <g:shipping><g:country>IN</g:country><g:service>Free delivery</g:service><g:price>0.00 INR</g:price></g:shipping>\n</item>\n";
    }
  }
  echo "</channel>\n</rss>\n";
  exit;
}

if ($page === 'returns') {   // the Returns page at its own address; keep the words in step with returnsPage() in index.html
  $html = (string)@file_get_contents(dirname(__DIR__) . '/index.html');
  if ($html === '') { http_response_code(503); exit; }
  header('Content-Type: text/html; charset=utf-8');
  header('Cache-Control: no-cache');
  $items = [['7-day returns', 'You can return any FOMAXO product within 7 days of delivery if it is unopened, with its seal and box intact.'],
    ['Damaged or wrong item', 'If your order arrives damaged, or you received the wrong item, tell us within 7 days of delivery and we will replace it or refund you in full.'],
    ['Free pickup', 'We pick up the item from your address anywhere in India, free of charge.'],
    ['Refunds', 'We refund you within 5 working days of receiving the item: to the card or UPI you paid with, or by bank transfer for cash on delivery orders.'],
    ['Opened products', 'For hygiene reasons, opened or used perfumes and personal care products cannot be returned unless they arrived damaged or faulty.'],
    ['How to return', 'Message us on WhatsApp (+91 73491 05165) or email fomaxoasset@gmail.com with your order number and we will arrange the pickup.']];
  $title = 'Returns & refunds — FOMAXO'; $desc = 'Return any unopened FOMAXO product within 7 days of delivery. Damaged or wrong items replaced or refunded. Free pickup anywhere in India, refund within 5 working days.';
  $go = '<base href="/"><script>history.replaceState(null,"","/#/returns")</script>';
  $head = '<link rel="canonical" href="' . SITE . '/returns"><meta property="og:url" content="' . SITE . '/returns">';
  $html = preg_replace('/<!--seo-->.*?<!--\/seo-->/s', '<!--seo-->' . $head . '<!--/seo-->', $html, 1);
  foreach (['/<title>.*?<\/title>/s' => '<title>' . h($title) . '</title>', '/<meta name="description" content="[^"]*">/' => '<meta name="description" content="' . h($desc) . '">'] as $re => $to)
    $html = preg_replace($re, str_replace(['\\', '$'], ['\\\\', '\\$'], $to), $html, 1);
  $text = '<noscript><div style="max-width:720px;margin:40px auto;padding:0 16px"><h1>FOMAXO Returns &amp; refunds</h1>' . implode('', array_map(fn($i) => '<h2>' . h($i[0]) . '</h2><p>' . h($i[1]) . '</p>', $items)) . '</div></noscript>';
  $html = preg_replace('/<body([^>]*)>/', '$0' . str_replace(['\\', '$'], ['\\\\', '\\$'], $text), $html, 1);
  echo preg_replace('/<meta charset="utf-8">/', '$0' . $go, $html, 1);
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

$title = h(seo_title($p)); $desc = h(seo_meta($p));
$ogTitle = h(($p['care'] || $p['family'] === '' ? $p['name'] : $p['name'] . ' ' . $p['family']) . ' — FOMAXO');   // WhatsApp / Instagram previews keep the plain name
$go .= '<script>window.FX_TITLE=' . json_encode([$p['id'] => seo_title($p)], JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . '</script>'; $img = h($p['images'][0] ?? SITE . '/assets/img/oldmoney-1.jpg');
$head = '<link rel="canonical" href="' . h($url) . '"><meta property="og:url" content="' . h($url) . '"><meta property="og:type" content="product"><meta property="og:site_name" content="FOMAXO India">'
  . '<script type="application/ld+json">' . json_encode($ld, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG) . '</script>';
$html = preg_replace('/<!--seo-->.*?<!--\/seo-->/s', '<!--seo-->' . str_replace(['\\', '$'], ['\\\\', '\\$'], $head) . '<!--/seo-->', $html, 1);
$swap = [
  '/<title>.*?<\/title>/s' => "<title>$title</title>",
  '/<meta name="description" content="[^"]*">/' => "<meta name=\"description\" content=\"$desc\">",
  '/<meta property="og:title" content="[^"]*">/' => "<meta property=\"og:title\" content=\"$ogTitle\">",
  '/<meta property="og:description" content="[^"]*">/' => "<meta property=\"og:description\" content=\"$desc\">",
  '/<meta property="og:image" content="[^"]*">/' => "<meta property=\"og:image\" content=\"$img\">",
  '/<meta name="twitter:image" content="[^"]*">/' => "<meta name=\"twitter:image\" content=\"$img\">",
  '/<meta property="og:image:width" content="[^"]*">\s*<meta property="og:image:height" content="[^"]*">\s*/' => '',   // sizes of the home link picture, not this product photo
];
foreach ($swap as $re => $to) $html = preg_replace($re, str_replace(['\\', '$'], ['\\\\', '\\$'], $to), $html, 1);
// the home banner's early download (top of index.html) skips itself here: $go below sets the #/product/… address first
echo preg_replace('/<meta charset="utf-8">/', '$0' . str_replace(['\\', '$'], ['\\\\', '\\$'], $go), $html, 1);
