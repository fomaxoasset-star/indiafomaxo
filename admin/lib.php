<?php
declare(strict_types=1);
/* FOMAXO India admin: products, reports, analytics and Excel helpers, used by admin/index.php. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function self_url(array $q = []): string { return '/admin/' . ($q ? '?' . http_build_query($q) : ''); }
function go(array $q = [], string $flash = ''): void { if ($flash !== '') $_SESSION['flash'] = $flash; header('Location: ' . self_url($q), true, 303); exit; }
function money(int $p): string { return $p < 0 ? '−' . rupees(-$p) : rupees($p); }
function opt_label(array $p, string $opt): string { return $p['kind'] === 'care' ? ($p['vol'] ?: 'Standard') : ($p['kind'] === 'car' ? ($p['vol'] ?: 'Car perfume') : ($p['kind'] === 'set' ? "Set of $opt" : "{$opt}ml")); }
function kind_label(string $k): string { return ['' => 'Fragrance', 'car' => 'Car fragrance', 'care' => 'Personal care', 'set' => 'Gift set'][$k] ?? $k; }
function pay_label(array $o): string { return $o['method'] === 'cod' ? 'Cash on delivery' : 'Card / UPI (Razorpay)' . ($o['test'] ? ' · TEST' : ''); }
function img_url(string $k): string { return str_starts_with($k, 'up/') ? '/api/live.php?img=' . rawurlencode(substr($k, 3)) : '/assets/img/' . rawurlencode($k) . '.webp'; }
/* a product's main photo, as an <img> */
function thumb(?array $p, string $cls = 'th'): string {
  $src = $p && !empty($p['img']) ? '/' . $p['img'] : '';
  return $src ? '<img class="' . $cls . '" src="' . h($src) . '" alt="" loading="lazy">' : '<span class="' . $cls . '"></span>';
}
const SALE_STATUSES = "('new', 'paid', 'delivered')";

/* ---------------- products ---------------- */
/* A product as the website shows it now: index.html with any edits made here, or the product added here. */
function product_now(string $id, array $LIVE): ?array {
  $d = $LIVE[$id] ?? null;
  if (!empty($d['added'])) return ($d['site'] ?? null) ? $d['site'] + ['kind' => $d['kind'] ?? ''] : null;
  $p = fomaxo_store_product($id); if (!$p) return null;
  if ($d && isset($d['edit'])) { $p = array_merge($p, $d['edit']); if (array_key_exists('notes', $d['edit']) && $d['edit']['notes'] === null) unset($p['notes']); }
  if ($d) {   // prices as saved on the Products list
    foreach ((array)($d['prices'] ?? []) as $k => $v) { if (($p['kind'] ?? '') === 'care') $p['price'] = $v; else $p['prices'][$k] = $v; }
    foreach ((array)($d['compareAt'] ?? []) as $k => $v) { if (($p['kind'] ?? '') === 'care') $p['was'] = $v ?: null; elseif ($v > 0) $p['compareAt'][$k] = $v; else unset($p['compareAt'][$k]); }
  }
  return $p;
}

/* The product form, read: [fields for the website, prices, was prices, sizes, costs, stock] or a message saying what is wrong. */
function product_post(string $kind) {
  $t = fn($k, $max) => trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? '')) ?? '', 0, $max));
  $name = $t('name', 60); $type = $t('type', 80); $short = $t('short', 200); $label = $t('label', 24);
  $desc = array_values(array_filter(array_map(fn($x) => trim(mb_substr($x, 0, 1200)), preg_split('/\n\s*\n/', str_replace("\r", '', (string)($_POST['description'] ?? '')))), 'strlen'));
  if (mb_strlen($name) < 2) return 'Please give the product a name.';
  if ($type === '') return $kind === 'care' ? 'Please write the product type, for example Moisturizing Shampoo.' : 'Please write the product type, for example Eau de Parfum.';
  $sizes = []; $prices = []; $was = []; $costs = []; $stock = [];
  foreach ([0, 1, 2, 3] as $i) {
    $price = trim((string)($_POST['price'][$i] ?? '')); if ($price === '') continue;
    if (!is_numeric($price) || $price < 1) return 'Please write each price as a number of rupees.';
    $opt = $kind === '' ? (string)(int)($_POST['size'][$i] ?? 0) : 'one';
    if ($kind === '' && (int)$opt < 1) return 'Please write the size in ml for each price.';
    if (isset($prices[$opt])) return $kind === '' ? 'Each size can only be listed once.' : 'This product has one price. Please fill in only the first row.';
    $sizes[] = $kind === '' ? (int)$opt : 'one'; $prices[$opt] = round((float)$price, 2);
    $w = trim((string)($_POST['was'][$i] ?? '')); $was[$opt] = is_numeric($w) && $w > $price ? round((float)$w, 2) : 0;
    $c = trim((string)($_POST['cost'][$i] ?? '')); $costs[$opt] = is_numeric($c) && $c >= 0 ? (int)round((float)$c * 100) : null;
    $s = trim((string)($_POST['stock'][$i] ?? '')); if ($s !== '') $stock[$opt] = max(0, (int)$s);
  }
  if (!$prices) return 'Please add a price.';
  if ($kind === 'care') {
    $f = ['name' => $name, 'type' => $type, 'cat' => in_array($_POST['cat'] ?? '', ['hair', 'body', 'face', 'lips'], true) ? $_POST['cat'] : 'body',
      'vol' => $t('vol', 20), 'price' => $prices['one'], 'was' => $was['one'] ?: null, 'short' => $short ?: $type, 'description' => $desc ?: [$short ?: $type], 'badge' => $label];
  } else {
    $f = ['name' => $name, 'family' => $type, 'tag' => $label, 'short' => $short ?: $type, 'description' => $desc ?: [$short ?: $type],
      'sizes' => $sizes, 'prices' => $prices, 'compareAt' => array_filter($was)];
    if ($kind === 'car') $f['vol'] = $t('vol', 30);
    if ($kind === '') {
      $f['tier'] = in_array($_POST['tier'] ?? '', ['elite', 'signature', 'prestige'], true) ? $_POST['tier'] : '';
      $notes = array_filter(['top' => $t('top', 120), 'heart' => $t('heart', 120), 'base' => $t('base', 120)]);
      $f['notes'] = count($notes) === 3 ? $notes : ($t('key', 160) !== '' ? ['key' => $t('key', 160)] : null);
    }
  }
  return [$f, $prices, $was, $costs, $stock];
}
/* photos ticked to keep (in order), then new uploads; the one picked as main goes first */
function product_photos(string $id, array $old) {
  $keep = array_values(array_filter($old, fn($k) => in_array($k, (array)($_POST['keep'] ?? []), true)));
  $new = save_images($id); if (is_string($new)) return $new;
  $new = array_map(fn($f) => "up/$f", $new);
  $main = (string)($_POST['main'] ?? '');
  $images = $main === 'new' && $new ? array_merge($new, $keep) : array_merge($keep, $new);
  if (in_array($main, $keep, true)) $images = array_values(array_unique([$main, ...$images]));
  $images = array_slice($images, 0, 8);
  return $images ?: 'Please keep or add at least one photo.';
}
function photos_of(array $p): array { return ($p['kind'] ?? '') === 'care' ? array_values(array_filter([$p['image'] ?? '', ...($p['extraImages'] ?? [])])) : array_values($p['images'] ?? []); }
function save_costs(string $id, array $costs): void { foreach ($costs as $opt => $c) shop_set_cost($id, (string)$opt, $c); }

/* Adds a product from the form. Returns '' or what is wrong. */
function add_product(array $CAT): string {
  $kind = (string)($_POST['kind'] ?? ''); if (!in_array($kind, ['', 'car', 'care'], true)) return 'Please choose a category.';
  $r = product_post($kind); if (is_string($r)) return $r;
  [$f, $prices, $was, $costs, $stock] = $r;
  $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($f['name'] . ($kind === 'care' ? ' ' . $f['type'] : ''))), '-') ?: 'product';
  $base = substr(($kind === 'care' ? 'pc-' : '') . $base, 0, 40); $id = $base; $n = 2;
  while (isset($CAT[$id])) $id = $base . '-' . $n++;
  $images = product_photos($id, []); if (is_string($images)) return $images === 'Please keep or add at least one photo.' ? 'Please add at least one photo.' : $images;
  if ($kind === 'care') $site = ['id' => $id, 'kind' => 'care'] + $f + ['image' => $images[0], 'extraImages' => array_slice($images, 1)];
  else { $site = ['id' => $id] + $f + ['images' => $images, 'url' => ''] + ($kind === 'car' ? ['kind' => 'car'] : []); if (empty($site['notes'])) unset($site['notes']); if (empty($site['tier'])) unset($site['tier']); }
  shop_save_product($id, true, empty($_POST['show']), ['kind' => $kind, 'name' => $f['name'], 'type' => $kind === 'care' ? $f['type'] : $f['family'], 'vol' => $f['vol'] ?? '',
    'prices' => $prices, 'compareAt' => $was, 'site' => $site], (int)time());
  foreach ($stock as $opt => $q) shop_set_stock($id, (string)$opt, $q);
  save_costs($id, $costs);
  return '';
}
/* Saves the edit form for any product (from index.html or added here). Returns '' or what is wrong. */
function edit_product(string $id, array $CAT, array $LIVE): string {
  $now = product_now($id, $LIVE); if (!$now || !isset($CAT[$id])) return 'That product was not found.';
  $kind = (string)($now['kind'] ?? ''); $added = !empty($LIVE[$id]['added']);
  $r = product_post($kind); if (is_string($r)) return $r;
  [$f, $prices, $was, $costs] = $r;
  $images = product_photos($id, photos_of($now)); if (is_string($images)) return $images;
  $orig = fomaxo_store_product($id); $orig = $orig ? photos_of($orig) : null;
  if ($added || $images !== $orig) $f += $kind === 'care' ? ['image' => $images[0], 'extraImages' => array_slice($images, 1)] : ['images' => $images];
  $d = $LIVE[$id] ?? ['added' => false, 'hidden' => false, 'sort' => 0];
  $data = array_diff_key($d, array_flip(['added', 'hidden', 'sort']));
  $data['prices'] = $prices; $data['compareAt'] = $was;
  if ($added) {
    $site = array_merge($data['site'], $f); if (array_key_exists('notes', $f) && $f['notes'] === null) unset($site['notes']);
    if (($site['tier'] ?? null) === '') unset($site['tier']);
    $data = array_merge($data, ['name' => $f['name'], 'type' => $kind === 'care' ? $f['type'] : $f['family'], 'vol' => $f['vol'] ?? '', 'site' => $site]);
  } else $data['edit'] = array_merge(array_diff_key((array)($data['edit'] ?? []), ['images' => 1, 'image' => 1, 'extraImages' => 1]), $f);
  shop_save_product($id, $added, empty($_POST['show']), $data, $d['sort']);
  save_costs($id, $costs);
  return '';
}
/* Saves up to 4 uploaded photos, re-encoded (WebP when the server can, else JPEG), at most 1600px. */
function save_images(string $id) {
  global $PRIV;
  $f = $_FILES['photos'] ?? null; if (!$f || !is_array($f['name'])) return [];
  $dir = "$PRIV/product-images"; if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return 'Photos could not be saved on the server.';
  if (!function_exists('imagecreatetruecolor')) return 'This server cannot process photos (GD is missing).';
  $out = [];
  foreach ($f['name'] as $i => $_) {
    if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
    if (count($out) >= 4) break;
    if ($f['error'][$i] !== UPLOAD_ERR_OK || $f['size'][$i] > 15 * 1024 * 1024) return 'Each photo must be a JPG, PNG or WebP under 15 MB.';
    $type = (@getimagesize($f['tmp_name'][$i]) ?: [2 => 0])[2];
    $img = match ($type) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name'][$i]), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name'][$i]),
      IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name'][$i]), default => false };
    if (!$img) return 'Each photo must be a JPG, PNG or WebP. On an iPhone, choose the photo from Photos and it is sent as JPG.';
    if ($type === IMAGETYPE_JPEG && function_exists('exif_read_data')) {   // phone photos: turn them the right way up
      $o = (int)((@exif_read_data($f['tmp_name'][$i]) ?: [])['Orientation'] ?? 1);
      $deg = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0; if ($deg) { $r = imagerotate($img, $deg, 0); if ($r) { imagedestroy($img); $img = $r; } }
    }
    $w = imagesx($img); $hh = imagesy($img); $k = min(1, 1600 / max($w, $hh));
    $nw = max(1, (int)round($w * $k)); $nh = max(1, (int)round($hh * $k));
    $dst = imagecreatetruecolor($nw, $nh); imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
    imagecopyresampled($dst, $img, 0, 0, 0, 0, $nw, $nh, $w, $hh);
    $webp = function_exists('imagewebp');
    $name = "$id-" . bin2hex(random_bytes(4)) . ($webp ? '.webp' : '.jpg');
    $ok = $webp ? imagewebp($dst, "$dir/$name", 84) : imagejpeg($dst, "$dir/$name", 86);
    imagedestroy($img); imagedestroy($dst);
    if (!$ok) return 'A photo could not be saved.';
    $out[] = $name;
  }
  return $out;
}

/* ---------------- orders ---------------- */
function order_where(array $F): array {
  $w = []; $a = [];
  if ($F['status'] === 'awaiting') $w[] = "status = 'awaiting'";
  elseif ($F['status'] === 'todo') $w[] = "status IN ('new', 'paid')";
  elseif (isset(FOMAXO_STATUSES[$F['status']])) { $w[] = 'status = ?'; $a[] = $F['status']; }
  else $w[] = "status <> 'awaiting'";
  if (in_array($F['method'], ['cod', 'online'], true)) { $w[] = 'method = ?'; $a[] = $F['method']; }
  if (($F['state'] ?? '') !== '') { $w[] = 'state = ?'; $a[] = $F['state']; }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $F['from'])) { $w[] = 'created >= ?'; $a[] = $F['from'] . ' 00:00:00'; }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $F['to'])) { $w[] = 'created <= ?'; $a[] = $F['to'] . ' 23:59:59'; }
  if ($F['q'] !== '') {
    $like = '%' . str_replace(['%', '_'], '', $F['q']) . '%'; $digits = preg_replace('/\D/', '', $F['q']);
    $w[] = '(no LIKE ? OR name LIKE ? OR email LIKE ? OR payment_id LIKE ? OR admin_note LIKE ?' . (strlen($digits) >= 4 ? ' OR REPLACE(phone, \' \', \'\') LIKE ?' : '') . ')';
    array_push($a, $like, $like, $like, $like, $like); if (strlen($digits) >= 4) $a[] = "%$digits%";
  }
  return [' WHERE ' . implode(' AND ', $w), $a];
}
/* sales: orders marked New, Paid or Delivered (not cancelled, not unfinished payments, not Razorpay test orders), by order date */
function sales_between(string $from, string $to): array {
  $s = shop_db()->prepare("SELECT COUNT(*) n, COALESCE(SUM(total), 0) t FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0 AND created >= ? AND created <= ?");
  $s->execute([$from, $to]); $r = $s->fetch(); return [(int)$r['n'], (int)$r['t']];
}

/* the Orders boxes and state row: the same filters, without cancelled orders, unfinished payments or test payments */
function order_summary(array $F): array {
  [$where, $args] = order_where($F); $x = " AND status IN " . SALE_STATUSES . " AND test = 0";
  $s = shop_db()->prepare("SELECT method, COUNT(*) n, COALESCE(SUM(total), 0) t FROM orders$where$x GROUP BY method"); $s->execute($args);
  $out = ['cod' => [0, 0], 'online' => [0, 0], 'states' => []];
  foreach ($s as $r) $out[$r['method']] = [(int)$r['n'], (int)$r['t']];
  [$where, $args] = order_where(['state' => ''] + $F);
  $s = shop_db()->prepare("SELECT state, COUNT(*) n FROM orders$where$x AND state <> '' GROUP BY state ORDER BY n DESC, state"); $s->execute($args);
  foreach ($s as $r) $out['states'][$r['state']] = (int)$r['n'];
  return $out;
}

/* ---------------- members (repeat customers) ---------------- */
function member_min(): int { return max(1, min(999, (int)(shop_setting('member_min') ?? '5'))); }
/* rupees; a customer who spent this much is a member too, whatever their order count (0 turns it off) */
function member_spend(): int { return max(0, min(10000000, (int)(shop_setting('member_spend') ?? '10000'))); }
/* customers with $min or more orders, or who spent ₹$spend or more, grouped by mobile (last 10 digits), or email when there is no mobile; cancelled, unfinished and test orders left out; most spent first */
function members(int $min, int $spend, string $q = ''): array {
  $M = [];
  foreach (shop_db()->query("SELECT id, no, created, status, method, total, name, phone, email, address, state FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0 ORDER BY created, id") as $o) {
    $digits = substr(preg_replace('/\D/', '', (string)$o['phone']), -10);
    $key = strlen($digits) === 10 ? "m:$digits" : (trim((string)$o['email']) !== '' ? 'e:' . strtolower(trim((string)$o['email'])) : '');
    if ($key === '') continue;
    $m = &$M[$key];
    $m ??= ['key' => $key, 'phone' => strlen($digits) === 10 ? $digits : '', 'orders' => []];
    $m['name'] = $o['name']; $m['email'] = $o['email'] ?: ($m['email'] ?? ''); $m['address'] = $o['address']; $m['state'] = $o['state'];
    $m['orders'][] = $o; unset($m);
  }
  $q = strtolower(trim($q)); $qd = preg_replace('/\D/', '', $q);
  $out = [];
  foreach ($M as $m) {
    $spent = array_sum(array_map(fn($o) => (int)$o['total'], $m['orders']));
    if (count($m['orders']) < $min && !($spend > 0 && $spent >= $spend * 100)) continue;
    if ($q !== '' && !str_contains(strtolower($m['name'] . ' ' . $m['email']), $q) && !(strlen($qd) >= 4 && str_contains($m['phone'], $qd))) continue;
    $m['count'] = count($m['orders']); $m['spent'] = $spent;
    $m['avg'] = intdiv($m['spent'], $m['count']); $m['first'] = $m['orders'][0]['created']; $m['last'] = end($m['orders'])['created'];
    $out[] = $m;
  }
  usort($out, fn($a, $b) => $b['spent'] <=> $a['spent'] ?: $b['count'] <=> $a['count']);
  return $out;
}
function phone_fmt(string $digits): string { return $digits === '' ? '' : '+91 ' . substr($digits, 0, 5) . ' ' . substr($digits, 5); }

/* ---------------- reports (profit & loss) ---------------- */
function pay_fee_pct(): float { return max(0, min(10, (float)(shop_setting('pay_fee') ?? '2'))); }
/* month by month: orders, sales, discounts given, payment fees, cost of goods, expenses, gross and net profit */
function report_year(int $year): array {
  $m = []; for ($i = 1; $i <= 12; $i++) $m[sprintf('%04d-%02d', $year, $i)] = ['orders' => 0, 'sales' => 0, 'discounts' => 0, 'online' => 0, 'fees' => 0, 'cost' => 0, 'nocost' => 0, 'expenses' => 0];
  $costs = shop_costs(); $pct = pay_fee_pct();
  $s = shop_db()->prepare("SELECT created, total, method, items FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0 AND created >= ? AND created < ?");
  $s->execute(["$year-01-01", ($year + 1) . '-01-01']);
  foreach ($s as $o) {
    $k = substr($o['created'], 0, 7); if (!isset($m[$k])) continue;
    $m[$k]['orders']++; $m[$k]['sales'] += (int)$o['total'];
    if ($o['method'] === 'online') $m[$k]['online'] += (int)$o['total'];
    foreach (json_decode((string)$o['items'], true) ?: [] as $it) {
      $q = (int)($it['qty'] ?? 0);
      if (!empty($it['was']) && $it['was'] > ($it['unit'] ?? 0)) $m[$k]['discounts'] += ((int)$it['was'] - (int)$it['unit']) * $q;
      $c = $it['cost'] ?? ($costs[$it['id'] ?? ''][(string)($it['opt'] ?? '')] ?? null);
      if ($c === null) $m[$k]['nocost'] += $q; else $m[$k]['cost'] += (int)$c * $q;
    }
  }
  $e = shop_db()->prepare('SELECT day, amount FROM expenses WHERE day >= ? AND day < ?'); $e->execute(["$year-01-01", ($year + 1) . '-01-01']);
  foreach ($e as $x) { $k = substr($x['day'], 0, 7); if (isset($m[$k])) $m[$k]['expenses'] += (int)$x['amount']; }
  foreach ($m as &$r) { $r['fees'] = (int)round($r['online'] * $pct / 100); $r['gross'] = $r['sales'] - $r['fees'] - $r['cost']; $r['net'] = $r['gross'] - $r['expenses']; } unset($r);
  return $m;
}
function report_sum(array $rows): array {
  $t = ['orders' => 0, 'sales' => 0, 'discounts' => 0, 'online' => 0, 'fees' => 0, 'cost' => 0, 'nocost' => 0, 'expenses' => 0, 'gross' => 0, 'net' => 0];
  foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += $r[$k];
  return $t;
}
function report_years(): array {
  $y = [(int)date('Y')];
  foreach (shop_db()->query("SELECT DISTINCT substr(created, 1, 4) y FROM orders UNION SELECT DISTINCT substr(day, 1, 4) FROM expenses") as $r) if ((int)$r['y'] > 2000) $y[] = (int)$r['y'];
  $y = array_unique($y); rsort($y); return $y;
}

/* ---------------- analytics ---------------- */
const SOURCES = ['instagram' => 'Instagram', 'whatsapp' => 'WhatsApp', 'google' => 'Google', 'facebook' => 'Facebook', 'youtube' => 'YouTube', 'search' => 'Other search engines', 'direct' => 'Direct (typed or saved link)', 'other' => 'Other websites'];
const FUNNEL = ['view' => 'Visited the shop', 'product' => 'Viewed a product', 'add' => 'Added to bag', 'checkout' => 'Opened checkout', 'pay' => 'Reached payment', 'buy' => 'Bought'];
function analytics(string $from, string $to, array $CAT): array {
  $db = shop_db(); $a = ["$from 00:00:00", "$to 23:59:59"];
  $q = function (string $sql, array $args = []) use ($db) { $s = $db->prepare($sql); $s->execute($args); return $s; };
  $out = ['visitors' => (int)$q('SELECT COUNT(DISTINCT vid) FROM events WHERE type = \'view\' AND ts >= ? AND ts <= ?', $a)->fetchColumn(),
    'visits' => (int)$q('SELECT COUNT(DISTINCT sid) FROM events WHERE type = \'view\' AND ts >= ? AND ts <= ?', $a)->fetchColumn(),
    'now' => (int)$q('SELECT COUNT(*) FROM online WHERE last > ?', [time() - 300])->fetchColumn()];
  $out['funnel'] = [];
  foreach (FUNNEL as $t => $_) $out['funnel'][$t] = (int)$q('SELECT COUNT(DISTINCT sid) FROM events WHERE type = ? AND ts >= ? AND ts <= ?', [$t, ...$a])->fetchColumn();
  $out['sources'] = [];
  foreach ($q('SELECT source, COUNT(DISTINCT sid) n FROM events WHERE type = \'view\' AND ts >= ? AND ts <= ? GROUP BY source ORDER BY n DESC', $a) as $r) $out['sources'][$r['source']] = (int)$r['n'];
  [$out['purchases'], $out['revenue']] = sales_between(...$a);
  $f = $out['funnel'];
  $out['conversion'] = $f['view'] ? $f['buy'] / $f['view'] * 100 : null;
  $out['cart_ab'] = $f['add'] ? max(0, 1 - $f['buy'] / $f['add']) * 100 : null;
  $out['checkout_ab'] = $f['checkout'] ? max(0, 1 - $f['buy'] / $f['checkout']) * 100 : null;
  /* per product: views and adds from visits, units and revenue from orders */
  $pr = [];
  foreach ($q('SELECT product, type, COUNT(*) n, SUM(qty) q FROM events WHERE type IN (\'product\', \'add\') AND product <> \'\' AND ts >= ? AND ts <= ? GROUP BY product, type', $a) as $r) {
    if (!isset($CAT[$r['product']])) continue;
    $pr[$r['product']][$r['type'] === 'product' ? 'views' : 'adds'] = $r['type'] === 'product' ? (int)$r['n'] : (int)$r['q'];
  }
  foreach ($q('SELECT items FROM orders WHERE status IN ' . SALE_STATUSES . ' AND test = 0 AND created >= ? AND created <= ?', $a) as $o)
    foreach (json_decode((string)$o['items'], true) ?: [] as $it) { $id = $it['id'] ?? ''; if (!isset($CAT[$id])) continue;
      $pr[$id]['units'] = ($pr[$id]['units'] ?? 0) + (int)($it['qty'] ?? 0); $pr[$id]['rev'] = ($pr[$id]['rev'] ?? 0) + (int)($it['unit'] ?? 0) * (int)($it['qty'] ?? 0); }
  foreach ($pr as &$p) $p += ['views' => 0, 'adds' => 0, 'units' => 0, 'rev' => 0]; unset($p);
  uasort($pr, fn($x, $y) => [$y['views'], $y['units']] <=> [$x['views'], $x['units']]);
  $out['products'] = $pr;
  /* left at checkout: typed a name or mobile but no order from that visit or that mobile since */
  $leads = $q('SELECT * FROM leads WHERE ordered = 0 AND updated >= ? AND updated <= ? ORDER BY updated DESC LIMIT 200', $a)->fetchAll();
  if ($leads) {
    $since = min(array_column($leads, 'created'));
    $phones = [];
    foreach ($q("SELECT phone, created FROM orders WHERE status <> 'awaiting' AND created >= ?", [$since]) as $o) { $ph = substr(preg_replace('/\D/', '', $o['phone']), -10); $phones[$ph] = max($phones[$ph] ?? '', $o['created']); }
    $leads = array_values(array_filter($leads, fn($l) => $l['phone'] === '' || !isset($phones[$l['phone']]) || $phones[$l['phone']] < $l['created']));
  }
  $out['leads'] = $leads;
  return $out;
}
/* chart series for the dashboard: [labels, full labels, values] for Today (hours), 7 and 30 days, and the last 12 months */
function series(): array {
  $db = shop_db(); $now = time(); $today = date('Y-m-d');
  $ranges = ['today' => ['H', 24, 13], 'd7' => ['D', 7, 10], 'd30' => ['D', 30, 10], 'year' => ['M', 12, 7]];
  $out = ['sales' => [], 'visitors' => []];
  foreach ($ranges as $r => [$unit, $n, $len]) {
    $keys = []; $labels = []; $full = [];
    for ($i = $n - 1; $i >= 0; $i--) {
      if ($unit === 'H') { $t = strtotime("$today 00:00:00") + (23 - $i) * 3600; $keys[] = date('Y-m-d H', $t); $labels[] = date('ga', $t); $full[] = date('ga', $t) . '–' . date('ga', $t + 3600) . ' today'; }
      elseif ($unit === 'D') { $t = strtotime("$today -$i day"); $keys[] = date('Y-m-d', $t); $labels[] = $n > 7 ? date('j M', $t) : date('D', $t); $full[] = date('D j M', $t); }
      else { $t = strtotime(date('Y-m-01') . " -$i month"); $keys[] = date('Y-m', $t); $labels[] = date('M', $t); $full[] = date('F Y', $t); }
    }
    $from = $unit === 'H' ? "$today 00:00:00" : ($unit === 'D' ? $keys[0] . ' 00:00:00' : $keys[0] . '-01 00:00:00');
    $sv = array_fill_keys($keys, 0); $vv = array_fill_keys($keys, 0);
    $s = $db->prepare("SELECT substr(created, 1, $len) k, SUM(total) t FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0 AND created >= ? GROUP BY substr(created, 1, $len)");
    $s->execute([$from]); foreach ($s as $x) if (isset($sv[$x['k']])) $sv[$x['k']] = (int)$x['t'];
    $s = $db->prepare("SELECT substr(ts, 1, $len) k, COUNT(DISTINCT vid) n FROM events WHERE type = 'view' AND ts >= ? GROUP BY substr(ts, 1, $len)");
    $s->execute([$from]); foreach ($s as $x) if (isset($vv[$x['k']])) $vv[$x['k']] = (int)$x['n'];
    $s = $db->prepare("SELECT COUNT(DISTINCT vid) FROM events WHERE type = 'view' AND ts >= ?"); $s->execute([$from]);
    $cap = ['today' => 'today', 'd7' => 'last 7 days', 'd30' => 'last 30 days', 'year' => 'last 12 months'][$r];
    $out['sales'][$r] = ['labels' => $labels, 'full' => $full, 'values' => array_values($sv), 'caption' => $cap];
    $out['visitors'][$r] = ['labels' => $labels, 'full' => $full, 'values' => array_values($vv), 'total' => (int)$s->fetchColumn(), 'caption' => 'visitors ' . $cap];
  }
  return $out;
}

/* ---------------- Excel ---------------- */
/* A real Excel file (.xlsx). Falls back to a CSV that Excel opens if the server has no ZipArchive. */
function send_sheet(string $name, array $head, array $rows, string $sheetName = 'Orders'): void {
  if (!class_exists('ZipArchive')) {
    header('Content-Type: text/csv; charset=utf-8'); header("Content-Disposition: attachment; filename=\"$name.csv\"");
    $out = fopen('php://output', 'w'); fwrite($out, "\xEF\xBB\xBF"); fputcsv($out, $head);
    foreach ($rows as $r) fputcsv($out, array_map(fn($v) => is_string($v) && preg_match('/^[=+\-@]/', $v) ? "'$v" : $v, $r));
    exit;
  }
  $col = function (int $i): string { $s = ''; for ($i++; $i > 0; $i = intdiv($i - 1, 26)) $s = chr(65 + ($i - 1) % 26) . $s; return $s; };
  $x = fn($v) => htmlspecialchars((string)$v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
  $sheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><cols>';
  foreach ($head as $i => $hd) $sheet .= '<col min="' . ($i + 1) . '" max="' . ($i + 1) . '" width="' . (in_array($hd, ['Items', 'Address'], true) ? 50 : 18) . '" customWidth="1"/>';
  $sheet .= '</cols><sheetData>';
  foreach (array_merge([$head], $rows) as $r => $row) {
    $sheet .= '<row r="' . ($r + 1) . '">';
    foreach (array_values($row) as $c => $v) {
      $ref = $col($c) . ($r + 1);
      if ((is_int($v) || is_float($v)) && $r > 0) $sheet .= "<c r=\"$ref\" s=\"2\"><v>$v</v></c>";
      else $sheet .= "<c r=\"$ref\" t=\"inlineStr\" s=\"" . ($r ? 1 : 3) . "\"><is><t xml:space=\"preserve\">" . $x($v) . '</t></is></c>';
    }
    $sheet .= '</row>';
  }
  $sheet .= '</sheetData><autoFilter ref="A1:' . $col(count($head) - 1) . (count($rows) + 1) . '"/></worksheet>';
  $tmp = tempnam(sys_get_temp_dir(), 'fx'); $z = new ZipArchive(); $z->open($tmp, ZipArchive::OVERWRITE);
  $z->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/></Types>');
  $z->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
  $z->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="' . $x($sheetName) . '" sheetId="1" r:id="rId1"/></sheets><definedNames><definedName name="_xlnm._FilterDatabase" localSheetId="0" hidden="1">\'' . $x($sheetName) . '\'!$A$1:$' . $col(count($head) - 1) . '$' . (count($rows) + 1) . '</definedName></definedNames></workbook>');
  $z->addFromString('xl/_rels/workbook.xml.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/><Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>');
  $z->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><numFmts count="1"><numFmt numFmtId="164" formatCode="#,##0.00"/></numFmts><fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><name val="Calibri"/></font></fonts><fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FFF3E7CC"/></patternFill></fill></fills><borders count="1"><border/></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="4"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0" applyAlignment="1"><alignment vertical="top" wrapText="1"/></xf><xf numFmtId="164" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1" applyAlignment="1"><alignment vertical="top"/></xf><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs><cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
  $z->addFromString('xl/worksheets/sheet1.xml', $sheet);
  $z->close();
  header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
  header("Content-Disposition: attachment; filename=\"$name.xlsx\"");
  header('Content-Length: ' . filesize($tmp));
  readfile($tmp); @unlink($tmp); exit;
}
