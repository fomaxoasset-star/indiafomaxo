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
/* a real order has an order number and is not an unpaid card try. A card try that was never paid (still waiting, or cancelled by hand before this rule)
   is kept quietly for the Conversion view but is not an order: it never shows in Orders, Members or totals. */
const IS_ORDER = "status <> 'awaiting' AND COALESCE(no, '') <> ''";

/* ---------------- dates: typed as dd/mm/yyyy everywhere ---------------- */
/* "06/10/2026" (also 6/10/2026, or 2026-10-06 from links and the calendar) → "2026-10-06"; anything else → '' */
function parse_day($s): string {
  $s = trim((string)$s);
  if (preg_match('~^(\d{1,2})/(\d{1,2})/(\d{4})$~', $s, $m)) [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
  elseif (preg_match('~^(\d{4})-(\d{2})-(\d{2})$~', $s, $m)) [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
  else return '';
  return $y >= 2000 && $y <= 2100 && checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : '';
}
/* "2026-10-06" → "06/10/2026" */
function dmy(string $ymd): string { return $ymd === '' ? '' : date('d/m/Y', strtotime($ymd)); }
/* a date box: type dd/mm/yyyy (admin.js adds the slashes and checks it); the calendar icon on the right opens the date picker */
function date_box(string $name, string $ymd, string $label = '', bool $required = false): string {
  return '<span class="dbox"><input type="text" name="' . h($name) . '" value="' . h(dmy($ymd)) . '" placeholder="dd/mm/yyyy" inputmode="numeric" maxlength="10" autocomplete="off" data-date'
    . ($label !== '' ? ' aria-label="' . h($label) . '"' : '') . ($required ? ' required' : '') . '>'
    . '<span class="dcal" title="Pick from the calendar"><svg viewBox="0 0 24 24" width="16" height="16" aria-hidden="true"><path fill="none" stroke="currentColor" stroke-width="1.8" d="M4 6.5h16v13H4zM4 10.5h16M8.5 3.5v5M15.5 3.5v5"/></svg>'
    . '<input type="date" tabindex="-1" aria-label="Pick ' . h($label ?: 'a date') . ' from the calendar"></span></span>';
}
/* the date bar on each page: which quick buttons it has, and where it starts */
const DATE_BARS = ['home' => [['today', 'd7', 'd30', 'year'], 'd7'], 'analytics' => [['today', 'd7', 'd30', 'year'], 'd7'], 'expenses' => [['today', 'd7', 'd30'], 'd30'],
  'sales' => [['today', 'd7', 'd30', 'all'], 'all'], 'reviews' => [['today', 'd7', 'd30', 'all'], 'all'], 'members' => [['today', 'd7', 'd30', 'all'], 'all']];
const DATE_PRESETS = ['today' => 'Today', 'd7' => '7 days', 'd30' => '30 days', 'year' => 'Year', 'all' => 'All'];
/* the first and last day of a quick button ('' for All); Year is the last 12 months */
function preset_span(string $r): array {
  $t = date('Y-m-d');
  return match ($r) { 'today' => [$t, $t], 'd7' => [date('Y-m-d', strtotime('-6 day')), $t], 'd30' => [date('Y-m-d', strtotime('-29 day')), $t],
    'year' => [date('Y-m-d', strtotime(date('Y-m-01') . ' -11 month')), $t], default => ['', ''] };
}
/* the dates a page shows: picked now (?r= and ?from= ?to=), else the last ones picked on that page, else its start. Each page remembers its own. */
function pick_dates(string $page): array {
  [$presets, $start] = DATE_BARS[$page];
  $saved = json_decode((string)shop_setting('dates'), true) ?: [];
  $r = (string)($_GET['r'] ?? ''); $save = $r !== '';
  if ($r === '' && (isset($_GET['from']) || isset($_GET['to']))) { $r = 'custom'; $save = true; }
  if ($save) { $f = parse_day($_GET['from'] ?? ''); $t = parse_day($_GET['to'] ?? ''); }
  else { $s = (array)($saved[$page] ?? ($page === 'sales' ? $saved['reports'] ?? [] : []));  /* Sales keeps the dates picked when it was called Reports */ $r = (string)($s['r'] ?? $start); $f = (string)($s['from'] ?? ''); $t = (string)($s['to'] ?? ''); }
  if ($r === 'custom') {
    if ($f === '' && $t === '') $r = in_array('all', $presets, true) ? 'all' : $start;
    else { if ($f === '') $f = $t; if ($t === '') $t = $f; if ($f > $t) [$f, $t] = [$t, $f]; }
  }
  if ($r !== 'custom' && !in_array($r, $presets, true)) $r = $start;
  if ($r !== 'custom') [$f, $t] = preset_span($r);
  if ($save) { $saved[$page] = ['r' => $r] + ($r === 'custom' ? ['from' => $f, 'to' => $t] : []); shop_set('dates', json_encode($saved)); }
  return ['page' => $page, 'r' => $r, 'from' => $f, 'to' => $t];
}
/* "Today", "7 days", "30 days", "12 months", "All dates", or the dates ("1–5 Oct") */
function period_label(array $D): string {
  return ['today' => 'Today', 'd7' => '7 days', 'd30' => '30 days', 'year' => '12 months', 'all' => 'All dates'][$D['r']] ?? date_span($D['from'], $D['to']);
}
/* the bar at the top of a page: quick buttons, From and To, Show. $keep: the page's other filters, kept when dates change */
function date_bar(array $D, array $keep = [], string $note = ''): string {
  $tab = $D['page']; [$presets] = DATE_BARS[$tab];
  $out = '<form class="dbar" method="get"><input type="hidden" name="tab" value="' . h($tab) . '">';
  foreach ($keep as $k => $v) if ((string)$v !== '') $out .= '<input type="hidden" name="' . h($k) . '" value="' . h((string)$v) . '">';
  $out .= '<span class="seg">' . implode('', array_map(fn($k) => '<a href="' . h(self_url(['tab' => $tab, 'r' => $k] + array_filter($keep, 'strlen'))) . '"' . ($D['r'] === $k ? ' class="on"' : '') . '>' . DATE_PRESETS[$k] . '</a>', $presets)) . '</span>'
    . '<label class="dfl"><span>From</span>' . date_box('from', $D['from'], 'From') . '</label><label class="dfl"><span>To</span>' . date_box('to', $D['to'], 'To') . '</label>'
    . '<button class="btn line sm">Show</button><span class="muted small dspan">' . h($D['r'] === 'all' ? 'All dates' : date_span($D['from'], $D['to']) . ($D['from'] !== $D['to'] ? ' · ' . (int)round((strtotime($D['to']) - strtotime($D['from'])) / 86400 + 1) . ' days' : '')) . h($note) . '</span></form>';
  return $out;
}

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
/* the kept photos in the order set on the page (photo_seq[]), less any ticked Remove, then new uploads (or new uploads first) */
function product_photos(string $id, array $old) {
  /* photo_seq[] is the order set with ‹ › and Make main; photos missing from it keep their old place after it */
  $seq = array_values(array_intersect(array_unique(array_map('strval', (array)($_POST['photo_seq'] ?? []))), $old));
  $keep = array_values(array_diff(array_merge($seq, array_diff($old, $seq)), array_map('strval', (array)($_POST['remove'] ?? []))));
  $new = save_images($id); if (is_string($new)) return $new;
  $new = array_map(fn($f) => "up/$f", $new);
  $main = (string)($_POST['main'] ?? '');
  $images = $main === 'new' && $new ? array_merge($new, $keep) : array_merge($keep, $new);
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
  $w[] = IS_ORDER;   // an unpaid card try is not an order: it never shows in Orders or its Excel
  if (in_array($F['status'], ['pending', 'todo'], true)) $w[] = "status IN ('new', 'paid')";
  elseif ($F['status'] === 'unpaid') $w[] = "status = 'new' AND method = 'cod' AND paid_at IS NULL";
  elseif (isset(FOMAXO_STATUSES[$F['status']])) { $w[] = 'status = ?'; $a[] = $F['status']; }
  if (in_array($F['method'], ['cod', 'online'], true)) { $w[] = 'method = ?'; $a[] = $F['method']; }
  if (($F['state'] ?? '') !== '') { $w[] = 'state = ?'; $a[] = $F['state']; }
  if (($F['coupon'] ?? '') === 'yes') $w[] = "coupon <> ''";   // the "Used a coupon" box
  elseif (($F['coupon'] ?? '') !== '') { $w[] = 'coupon = ?'; $a[] = $F['coupon']; }   // one code's chip
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $F['from'])) { $w[] = 'created >= ?'; $a[] = $F['from'] . ' 00:00:00'; }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $F['to'])) { $w[] = 'created <= ?'; $a[] = $F['to'] . ' 23:59:59'; }
  if ($F['q'] !== '') {
    $like = '%' . str_replace(['%', '_'], '', $F['q']) . '%'; $digits = substr(preg_replace('/\D/', '', $F['q']), -10);   // +91 98765 43210 finds 9876543210
    $w[] = '(no LIKE ? OR name LIKE ? OR email LIKE ? OR payment_id LIKE ? OR admin_note LIKE ? OR coupon LIKE ?' . (strlen($digits) >= 4 ? ' OR REPLACE(phone, \' \', \'\') LIKE ?' : '') . ')';
    array_push($a, $like, $like, $like, $like, $like, $like); if (strlen($digits) >= 4) $a[] = "%$digits%";
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

/* the "Used a coupon" box and its code chips: orders with a coupon, the money it took off, and each code's count, with every other filter applied */
function order_coupon_counts(array $F): array {
  [$where, $args] = order_where(['coupon' => 'yes'] + $F);
  $s = shop_db()->prepare("SELECT coupon, COUNT(*) n, COALESCE(SUM(discount), 0) d FROM orders$where GROUP BY coupon ORDER BY n DESC, coupon"); $s->execute($args);
  $out = ['n' => 0, 'off' => 0, 'codes' => []];
  foreach ($s as $r) { $out['n'] += (int)$r['n']; $out['off'] += (int)$r['d']; $out['codes'][$r['coupon']] = (int)$r['n']; }
  return $out;
}
/* a gold tag on an order that used a coupon: the code and the money it took off (goodwill codes say so) */
function order_coupon_tag(array $o): string {
  if ((string)$o['coupon'] === '') return '';
  $gw = preg_match('/^(GOODWILL|SORRY)-/', $o['coupon']);
  return '<span class="ctag' . ($gw ? ' gw' : '') . '" title="' . ($gw ? 'Goodwill coupon' : 'Used a coupon') . '"><svg viewBox="0 0 24 24" width="12" height="12" aria-hidden="true"><path fill="currentColor" d="M3 7a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2v2a3 3 0 0 0 0 6v2a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-2a3 3 0 0 0 0-6V7Zm6 1v2h2V8H9Zm0 3v2h2v-2H9Zm0 3v2h2v-2H9Z"/></svg><b>' . h($o['coupon']) . '</b>' . ((int)$o['discount'] ? '<i>−' . rupees((int)$o['discount']) . '</i>' : '')
    . (str_contains((string)($o['items'] ?? ''), '"free":1') ? '<i>Free gift</i>' : '') . '</span>';   // a free product coupon
}
/* the tracking chips: how many orders are pending, unpaid cash, delivered, cancelled and refunded, with every other filter applied */
function order_track_counts(array $F): array {
  [$where, $args] = order_where(['status' => ''] + $F);
  $s = shop_db()->prepare("SELECT SUM(CASE WHEN status IN ('new', 'paid') THEN 1 ELSE 0 END) pending, SUM(CASE WHEN status = 'new' AND method = 'cod' AND paid_at IS NULL THEN 1 ELSE 0 END) unpaid,
    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) delivered, SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) cancelled, SUM(CASE WHEN status = 'refunded' THEN 1 ELSE 0 END) refunded FROM orders$where");
  $s->execute($args); return array_map('intval', $s->fetch() ?: []);
}
const TRACK_CHIPS = ['pending' => 'Pending', 'unpaid' => 'Unpaid cash', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'];
const ORDER_ACTS = ['paid' => 'Paid', 'deliver' => '✓ Mark delivered', 'cancel' => 'Cancel order', 'refund' => 'Refund'];
/* an order's two tags: where it is (Pending, Delivered, Cancelled, Refunded) and whether it is paid */
function order_tags(array $o): string {
  if ($o['status'] === 'awaiting') return '<span class="badge st-awaiting">Not paid</span>';
  $st = in_array($o['status'], ['new', 'paid'], true) ? ['pending', 'Pending'] : [$o['status'], FOMAXO_STATUSES[$o['status']] ?? $o['status']];
  $paid = shop_is_paid($o);
  return '<span class="badge st-' . $st[0] . '">' . h($st[1]) . '</span><span class="badge ' . ($paid ? 'pd-yes">Paid' : 'pd-no">Unpaid') . '</span>';
}
/* the order page's Status list, with clear names: Pending · Unpaid (cash), Pending · Paid, Delivered, Undelivered (on a delivered order: back to Pending),
   Cancelled and Refunded. There is no "Card not paid": an unpaid card try is not an order. */
function order_status_options(array $o): array {
  $cod = $o['method'] === 'cod'; $st = $o['status'];
  $opts = [];
  if ($cod || $st === 'new') $opts['new'] = 'Pending · Unpaid';
  $opts['paid'] = 'Pending · Paid (not delivered)';
  $opts['delivered'] = 'Delivered';
  if ($st === 'delivered') $opts['undelivered'] = 'Undelivered';
  $opts['cancelled'] = 'Cancelled';
  $opts['refunded'] = 'Refunded';
  return $opts;
}
/* the three one-tap buttons of an active order (Paid or Refund · Mark delivered · Cancel order); they submit the page's #qa form.
   A button that does not apply is greyed out; Paid and Delivered stay lit once done. Cancelled and refunded orders have none. */
function order_buttons(array $o): string {
  if (!in_array($o['status'], ['new', 'paid', 'delivered'], true)) return '';
  $no = h($o['no'] ?: 'this order'); $ok = shop_order_actions($o); $cod = $o['method'] === 'cod'; $out = '';
  foreach ([$cod ? 'paid' : 'refund', 'deliver', 'cancel'] as $a) {
    $lit = $a === 'paid' && shop_is_paid($o) ? ['lit-gold', '✓ Paid'] : ($a === 'deliver' && $o['status'] === 'delivered' ? ['lit-green', '✓ Delivered'] : null);
    $cls = 'btn sm b-' . $a . ' ' . ['paid' => 'line', 'deliver' => '', 'cancel' => 'line danger', 'refund' => 'line danger'][$a];
    if ($lit) { $out .= '<button type="button" class="btn sm b-' . $a . ' ' . $lit[0] . '" disabled>' . $lit[1] . '</button>'; continue; }
    if (!in_array($a, $ok, true)) { $out .= '<button type="button" class="' . $cls . ' off" disabled>' . ORDER_ACTS[$a] . '</button>'; continue; }
    $ask = ['cancel' => "Cancel order $no? Its items go back into stock.",
      'refund' => "Mark $no as refunded? Its items go back into stock. This only records the refund: the money itself is refunded in your Razorpay dashboard."][$a] ?? '';
    $out .= '<button class="' . $cls . '" form="qa" name="q" value="' . $a . ':' . (int)$o['id'] . '"' . ($ask ? ' data-confirm="' . $ask . '"' : '') . '>' . ORDER_ACTS[$a] . '</button>';
  }
  return $out;
}
/* "Waiting N days" under a pending order older than a day; red from 3 days */
function order_waiting(array $o): string {
  if (!in_array($o['status'], ['new', 'paid'], true)) return '';
  $d = intdiv(time() - (int)strtotime($o['created']), 86400);
  return $d < 1 ? '' : '<small class="wait' . ($d >= 3 ? ' late' : '') . '">Waiting ' . $d . ' day' . ($d === 1 ? '' : 's') . '</small>';
}
/* the order tracker: Ordered → Paid → Delivered, with the date and time of each step; cancelled and refunded orders end in a red step */
function order_tracker(array $o): string {
  $t = fn($s) => $s ? h(date('d M Y, H:i', strtotime($s))) : 'Not yet';
  $paidAt = $o['paid_at'] ?: ($o['status'] === 'delivered' || shop_is_paid($o) ? ($o['delivered_at'] ?: $o['updated']) : null);
  $steps = [['Ordered', $o['created'], true], ['Paid', $paidAt, shop_is_paid($o)], ['Delivered', $o['delivered_at'], (bool)$o['delivered_at']]];
  if (in_array($o['status'], ['cancelled', 'refunded'], true)) {
    $steps = array_values(array_filter($steps, fn($x) => $x[2]));   // only the steps that happened, then the red one
    $steps[] = [FOMAXO_STATUSES[$o['status']], $o['closed_at'] ?: $o['updated'], true, 'end'];
  }
  $out = '<ol class="track">';
  foreach ($steps as $x) $out .= '<li class="' . (($x[3] ?? '') === 'end' ? 'end' : ($x[2] ? 'done' : 'todo')) . '"><i>' . (($x[3] ?? '') === 'end' ? '✕' : ($x[2] ? '✓' : '')) . '</i><b>' . h($x[0]) . '</b><small>' . ($x[2] ? $t($x[1]) : 'Not yet') . '</small></li>';
  return $out . '</ol>';
}

/* ---------------- limited-time offer ---------------- */
/* Saves the Offer page form. The quick buttons (24 hours … 7 days) count from now; otherwise the typed date and time (India time).
   Returns the message to show ('!' first when nothing was saved). */
function save_offer(): string {
  $mode = in_array($_POST['mode'] ?? '', ['end', 'always', 'off'], true) ? $_POST['mode'] : 'off';
  $o = ['mode' => $mode, 'end' => shop_offer()['end'], 'popup' => !empty($_POST['popup']), 'pct' => 0,
    'title' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['title'] ?? ''))), 0, 30),
    'sub' => mb_strtoupper(mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['sub'] ?? ''))), 0, 40)),
    'btn' => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['btn'] ?? ''))), 0, 24),
    'items' => array_values(array_intersect(array_map('strval', (array)($_POST['items'] ?? [])), array_map('strval', array_keys(fomaxo_catalog()['products'])))),
    'lines' => array_values(array_intersect(array_map('strval', (array)($_POST['lines'] ?? [])), array_map('strval', array_keys(fomaxo_catalog()['products'])))),
    'sizeL' => shop_offer_size($_POST['size_l'] ?? 0, 'L'), 'sizeP' => shop_offer_size($_POST['size_p'] ?? 0, 'P'),
    'fs' => ['L' => shop_offer_fs(is_array($_POST['fs'] ?? null) ? $_POST['fs']['l'] ?? [] : []), 'P' => shop_offer_fs(is_array($_POST['fs'] ?? null) ? $_POST['fs']['p'] ?? [] : [])]];
  $o['line'] = (bool)$o['lines'];
  /* the % in the popup: empty = the biggest real saving; never more than that */
  $pct = trim((string)($_POST['pct'] ?? '')); $note = '';
  if ($pct !== '') {
    if (!ctype_digit($pct) || (int)$pct < 1 || (int)$pct > 99) return '!Please type the % as a whole number from 1 to 99, or leave it empty for the biggest real saving.';
    [$best] = fomaxo_best_pct($o['items']);
    if ($best < 1) return '!' . ($o['items'] ? 'None of the ticked products has' : 'No product has') . ' an old price on Products yet, so there is no real saving to show. Leave the % empty, or add old prices first.';
    if ((int)$pct > $best) { $note = " You typed {$pct}%, but the biggest real saving is {$best}%, so the popup shows {$best}% OFF."; $pct = (string)$best; }
    $o['pct'] = (int)$pct;
  }
  $quick = (int)($_POST['quick'] ?? 0);
  if ($quick > 0) { $o['mode'] = 'end'; $o['end'] = date('Y-m-d H:i', time() + min($quick, 24 * 7) * 3600); }
  elseif ($mode === 'end') {
    $day = parse_day($_POST['end'] ?? ''); $t = trim((string)($_POST['end_time'] ?? ''));
    if ($day === '') return '!Please type the end date as dd/mm/yyyy, or tap 24 hours, 48 hours, 3 days or 7 days.';
    if ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t)) return '!Please pick the end time, or leave it empty for the end of that day.';
    $o['end'] = $day . ' ' . ($t ?: '23:59');
    if ($o['end'] <= date('Y-m-d H:i')) return '!That end date and time has already passed. Please pick a later one.';
  }
  shop_set('offer', json_encode($o));
  if ($o['mode'] === 'off') return 'Saved. The offer is off, so nothing shows on the website.' . $note;
  if (!$o['popup'] && !$o['line']) return 'Saved, but Popup is not ticked and no product is picked for the line, so nothing shows on the website.' . $note;
  return ($o['mode'] === 'end' ? 'Saved. The offer is on and ends ' . offer_when($o['end']) . '.' : 'Saved. The offer is on with no timer until you turn it off.') . $note;
}
/* Saves the New product popup box on the Offer page. $cat = the product list (hidden products can be picked too). */
function save_newprod(array $cat): string {
  $clean = fn(string $k) => trim(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? '')));
  $n = ['on' => !empty($_POST['np_on']), 'label' => $clean('np_label') ?: 'Coming soon', 'name' => $clean('np_name'), 'line' => $clean('np_line'), 'id' => (string)($_POST['np_id'] ?? '')];
  $fs = is_array($_POST['nfs'] ?? null) ? $_POST['nfs'] : [];
  $n['fs'] = ['L' => shop_offer_fs($fs['l'] ?? [], SHOP_NEWP_FS), 'P' => shop_offer_fs($fs['p'] ?? [], SHOP_NEWP_FS)];   // sizes from the Preview size bar
  if (mb_strlen($n['label']) > 24) return '!The type can have up to 24 characters.';
  if ($n['id'] !== '' && !isset($cat[$n['id']])) $n['id'] = '';
  if ($n['name'] === '' && $n['id'] !== '') $n['name'] = mb_substr($cat[$n['id']]['name'], 0, 40);
  if (mb_strlen($n['name']) > 40) return '!The product name can have up to 40 characters.';
  if (mb_strlen($n['line']) > 90) return '!The short line can have up to 90 characters.';
  if ($n['on'] && $n['name'] === '') return '!Please write the product name, or pick the product.';
  shop_set('newprod', json_encode($n, JSON_UNESCAPED_UNICODE));
  return !$n['on'] ? 'Saved. The new product popup is off.' : 'Saved. The “' . $n['label'] . '” popup for ' . $n['name'] . ' is on. Each visitor sees it once.';
}
/* "09/10/2026, 11:59 pm" */
function offer_when(string $ymdhi): string { return date('d/m/Y, g:i a', strtotime($ymdhi)); }
/* time left as "2d 14h left" / "3h 5m left" / "12m left" */
function offer_left(int $secs): string {
  $d = intdiv($secs, 86400); $h = intdiv($secs % 86400, 3600); $m = intdiv($secs % 3600, 60);
  return ($d ? "{$d}d {$h}h" : ($h ? "{$h}h {$m}m" : max(1, $m) . 'm')) . ' left';
}

/* ---------------- coupons ---------------- */
/* Adds or changes a coupon from the Coupons page form. Returns the message to show ('!' first when nothing was saved). */
function save_coupon(): string {
  $code = coupon_clean((string)($_POST['code'] ?? '')); $editing = !empty($_POST['editing']);
  if (strlen($code) < 3 || strlen($code) > 20) return '!Please write a code of 3 to 20 letters or numbers, like WELCOME10.';
  $kind = in_array($_POST['kind'] ?? '', ['amt', 'free'], true) ? $_POST['kind'] : 'pct'; $v = trim((string)($_POST['value'] ?? ''));
  $free = ['', ''];
  if ($kind === 'free') {   // a free product coupon: the product and size it adds at ₹0; nothing is taken off
    $free = explode('|', (string)($_POST['free'] ?? '') . '|', 3); $p = fomaxo_catalog()['products'][$free[0]] ?? null;
    if (!$p || $p['kind'] === 'set' || !isset($p['prices'][$free[1]])) return '!Please choose the free product.';
    $v = '0';
  } else {
    if (!is_numeric($v) || $v <= 0) return '!Please write how much the coupon takes off.';
    if ($kind === 'pct' && ($v > 99 || (float)$v != (int)$v)) return '!A % coupon can take off 1 to 99%, in whole numbers.';
  }
  $value = $kind === 'pct' ? (int)$v : (int)round((float)$v * 100);
  $min = trim((string)($_POST['min_order'] ?? '')); if ($min !== '' && (!is_numeric($min) || $min < 0)) return '!Please write the minimum order in rupees, or leave it empty.';
  /* the time limit: a date (dd/mm/yyyy) and an hour for each end; no hour = from the start of the day / to the end of it */
  $when = function (string $k, string $whole) {
    $d = trim((string)($_POST[$k] ?? '')); $t = trim((string)($_POST[$k . '_time'] ?? ''));
    if ($d === '') return $t === '' ? '' : null;
    $day = parse_day($d); if ($day === '' || ($t !== '' && !preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t))) return null;
    return $day . ' ' . ($t ?: $whole);
  };
  $starts = $when('starts', '00:00'); if ($starts === null) return '!Please type the start date as dd/mm/yyyy (and a time if you want one), or leave it empty.';
  $ends = $when('ends', '23:59'); if ($ends === null) return '!Please type the end date as dd/mm/yyyy (and a time if you want one), or leave it empty.';
  if ($starts !== '' && $ends !== '' && $ends <= $starts) return '!The coupon has to end after it starts.';
  $uses = trim((string)($_POST['max_uses'] ?? '')); if ($uses !== '' && (!ctype_digit($uses))) return '!Please write the usage limit as a number, or leave it empty.';
  $s = shop_db()->prepare('SELECT created FROM coupons WHERE code = ?'); $s->execute([$code]); $was = $s->fetchColumn();
  if ($was !== false && !$editing) return "!$code already exists. Pick another code, or edit $code in the list.";
  shop_upsert('coupons', ['code'], ['code' => $code, 'kind' => $kind, 'value' => $value, 'min_order' => $min === '' ? 0 : (int)round((float)$min * 100),
    'starts' => $starts, 'ends' => $ends, 'max_uses' => $uses === '' ? 0 : min(1000000, (int)$uses),
    'stack' => ($_POST['stack'] ?? '') === '1' ? 1 : 0, 'free_id' => $free[0], 'free_opt' => $free[1], 'per_cust' => !empty($_POST['per_cust']) ? 1 : 0, 'active' => !empty($_POST['active']) ? 1 : 0, 'created' => $was ?: shop_now()]);
  return $code . ($was !== false ? ' is saved.' : ' is ready.') . (empty($_POST['active']) ? ' It is off until you switch it on.' : ($starts > date('Y-m-d H:i') ? ' It works at checkout from ' . coupon_when($starts) . '.' : ' Shoppers can use it at checkout.'));
}

/* A goodwill coupon for a customer who had a late delivery or a faulty product: a new code like GOODWILL-7K2Q, % off,
   for their one mobile number (last 10 digits), one use, no end date. Returns [the code or '', the message to show]. */
function make_goodwill_coupon(): array {
  $phone = coupon_phone((string)($_POST['phone'] ?? '')); $v = trim((string)($_POST['pct'] ?? ''));
  if (!preg_match('/^[6-9]\d{9}$/', $phone)) return ['', '!Please type the customer’s 10-digit mobile number.'];
  if (!ctype_digit($v) || (int)$v < 1 || (int)$v > 99) return ['', '!Please write the % off, from 1 to 99.'];
  $abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';   // no 0/O or 1/I, so it is easy to read out
  $s = shop_db()->prepare('SELECT 1 FROM coupons WHERE code = ?');
  do { $code = 'GOODWILL-'; for ($i = 0; $i < 4; $i++) $code .= $abc[random_int(0, 31)]; $s->execute([$code]); } while ($s->fetchColumn());
  shop_upsert('coupons', ['code'], ['code' => $code, 'kind' => 'pct', 'value' => (int)$v, 'min_order' => 0, 'starts' => '', 'ends' => '',
    'max_uses' => 1, 'stack' => 0, 'active' => 1, 'phone' => $phone, 'created' => shop_now()]);
  return [$code, "$code is ready: " . (int)$v . '% off for ' . phone_fmt($phone) . '. Tap Send on WhatsApp.'];
}
/* the WhatsApp link that sends a goodwill coupon to its customer, with a short ready message */
function goodwill_wa(array $c): string {
  return 'https://wa.me/91' . $c['phone'] . '?text=' . rawurlencode('Hi, this is FOMAXO. As a goodwill gesture for your last order, here is ' . (int)$c['value'] . '% off your next order with the code '
    . $c['code'] . '. Type it at checkout on fomaxo.in with this mobile number. It works one time and has no end date.');
}

/* the WhatsApp message that shares a free product coupon: WhatsApp opens and the owner picks the customer (no emoji: wa.me shows them as "?") */
function free_coupon_wa(array $c): string {
  $min = (int)$c['min_order'];
  return 'https://wa.me/?text=' . rawurlencode('Hi, this is FOMAXO. Here is a free ' . coupon_free_name($c) . ' for you' . ($min ? ' when you shop for ' . rupees($min) . ' or more' : ' with your next order')
    . '. Use the code ' . $c['code'] . ' at checkout on fomaxo.in.' . (!empty($c['per_cust']) ? ' It works one time per customer.' : '')
    . (coupon_ends($c) !== '' ? ' Valid till ' . coupon_when(coupon_ends($c)) . '.' : ''));
}

/* ---------------- members (repeat customers) ---------------- */
function member_min(): int { return max(1, min(999, (int)(shop_setting('member_min') ?? '5'))); }
/* rupees; a customer who spent this much is a member too, whatever their order count (empty or 0 turns it off) */
function member_spend(): int { return max(0, min(10000000, (int)(shop_setting('member_spend') ?? '0'))); }
/* customers with $min or more orders, or who spent ₹$spend or more, grouped by mobile (last 10 digits), or email when there is no mobile; cancelled, unfinished and test orders left out; most spent first */
function members(int $min, int $spend, string $q = '', string $from = '', string $to = ''): array {
  $M = [];
  /* with dates picked, only the orders placed in them count */
  $s = shop_db()->prepare("SELECT id, no, created, status, method, total, name, phone, email, address, state FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0"
    . ($from !== '' ? ' AND created >= ? AND created <= ?' : '') . ' ORDER BY created, id');
  $s->execute($from !== '' ? ["$from 00:00:00", "$to 23:59:59"] : []);
  foreach ($s as $o) {
    [$key, $digits] = customer_key($o);
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
/* one customer = one mobile number (last 10 digits), or one email when there is no mobile */
function customer_key(array $o): array {
  $digits = substr(preg_replace('/\D/', '', (string)$o['phone']), -10);
  return [strlen($digits) === 10 ? "m:$digits" : (trim((string)$o['email']) !== '' ? 'e:' . strtolower(trim((string)$o['email'])) : ''), strlen($digits) === 10 ? $digits : ''];
}
/* everything about one customer: every order (cancelled ones counted apart; unpaid card tries are not orders), their reviews and their visits to the shop */
function customer(string $key): ?array {
  $all = [];
  foreach (shop_db()->query("SELECT * FROM orders WHERE " . IS_ORDER . " AND test = 0 ORDER BY created, id") as $o) if (customer_key($o)[0] === $key) $all[] = $o;
  if (!$all) return null;
  $sales = array_values(array_filter($all, fn($o) => in_array($o['status'], ['new', 'paid', 'delivered'], true)));
  $last = end($all); $c = ['key' => $key, 'phone' => customer_key($last)[1], 'name' => $last['name'], 'email' => '', 'address' => $last['address'], 'state' => $last['state'], 'all' => array_reverse($all)];
  foreach ($all as $o) if ($o['email'] !== '') $c['email'] = $o['email'];
  $c['count'] = count($sales); $c['spent'] = array_sum(array_map(fn($o) => (int)$o['total'], $sales)); $c['avg'] = $c['count'] ? intdiv($c['spent'], $c['count']) : 0;
  $c['first'] = $sales ? $sales[0]['created'] : ''; $c['last'] = $sales ? end($sales)['created'] : '';
  $c['cancelled'] = count(array_filter($all, fn($o) => $o['status'] === 'cancelled'));
  $c['reviews'] = reviews_list(['tokens' => array_values(array_filter(array_column($all, 'review'))), 'phone' => $c['phone']]);
  $c['web'] = customer_web($c['phone']);
  return $c;
}
/* the shop visits of one customer: only from the browser they typed their mobile in at checkout (Left at checkout / buy) */
function customer_web(string $phone): array {
  $w = ['visits' => [], 'seconds' => 0, 'pages' => 0, 'products' => [], 'left' => 0, 'first' => 0, 'last' => 0, 'source' => '', 'device' => ''];
  if ($phone === '') return $w;
  $db = shop_db();
  $s = $db->prepare('SELECT vid, ordered FROM leads WHERE phone = ?'); $s->execute([$phone]); $vids = [];
  foreach ($s as $r) { $vids[$r['vid']] = 1; if (!(int)$r['ordered']) $w['left']++; }
  if (!$vids) return $w;
  $in = implode(',', array_fill(0, count($vids), '?')); $vids = array_keys($vids);
  $s = $db->prepare("SELECT * FROM visits WHERE vid IN ($in) ORDER BY started DESC LIMIT 300"); $s->execute($vids); $w['visits'] = $s->fetchAll();
  $s = $db->prepare("SELECT sid, product, COUNT(*) n FROM events WHERE vid IN ($in) AND type = 'product' AND product <> '' GROUP BY sid, product"); $s->execute($vids);
  $bySid = [];
  foreach ($s as $r) { $w['products'][$r['product']] = ($w['products'][$r['product']] ?? 0) + (int)$r['n']; $bySid[$r['sid']][] = $r['product']; }
  arsort($w['products']);
  foreach ($w['visits'] as &$v) { $v['secs'] = max(0, (int)$v['last'] - (int)$v['started']); $v['viewed'] = array_values(array_unique($bySid[$v['sid']] ?? [])); $w['seconds'] += $v['secs']; $w['pages'] += (int)$v['pages']; }
  unset($v);
  if ($w['visits']) { $first = end($w['visits']); $w['first'] = (int)$first['started']; $w['last'] = (int)$w['visits'][0]['last']; $w['source'] = $first['source']; $w['device'] = $w['visits'][0]['device']; }
  return $w;
}
function duration(int $secs): string {
  if ($secs < 60) return $secs . 's';
  $m = intdiv($secs, 60); return $m < 60 ? $m . ' min' : intdiv($m, 60) . ' h ' . ($m % 60) . ' min';
}

/* ---------------- reviews (api/reviews.php keeps them in fomaxo-private/reviews.sqlite) ---------------- */
function reviews_db(): ?PDO {
  global $PRIV; static $db = false;
  if ($db !== false) return $db;
  if (!is_file("$PRIV/reviews.sqlite")) return $db = null;
  $db = new PDO("sqlite:$PRIV/reviews.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $db->exec('PRAGMA busy_timeout=4000;');
  return $db;
}
/* reviews with the mobile of the order they came from (verified purchasers). $f: q (words, name or mobile), verified (1 / 0), status, tokens, phone, from and to (written in those days) */
function reviews_list(array $f = []): array {
  $db = reviews_db(); if (!$db) return [];
  try {
    $rows = $db->query("SELECT r.*, COALESCE(o.phone, '') phone, COALESCE(o.token, '') token, COALESCE(o.customer, '') customer FROM reviews r LEFT JOIN orders o ON o.id = r.order_id ORDER BY r.created DESC")->fetchAll();
  } catch (Throwable $e) { return []; }
  $q = mb_strtolower(trim((string)($f['q'] ?? ''))); $qd = preg_replace('/\D/', '', $q);
  $out = [];
  foreach ($rows as $r) {
    $r['phone'] = substr(preg_replace('/\D/', '', (string)$r['phone']), -10);
    if (isset($f['tokens']) && !in_array($r['token'], $f['tokens'], true) && !($f['phone'] !== '' && $r['phone'] === $f['phone'])) continue;
    if (isset($f['verified']) && (int)$r['verified'] !== (int)$f['verified']) continue;
    if (isset($f['product']) && $r['product'] !== $f['product']) continue;
    if (isset($f['stars']) && (int)round((float)$r['rating']) !== (int)$f['stars']) continue;
    if (isset($f['from'], $f['to']) && ((int)$r['created'] < strtotime($f['from']) || (int)$r['created'] >= strtotime($f['to'] . ' +1 day'))) continue;
    if ($q !== '' && !str_contains(mb_strtolower($r['body'] . ' ' . $r['name'] . ' ' . $r['customer']), $q) && !(strlen($qd) >= 4 && str_contains($r['phone'], $qd))) continue;
    $out[] = $r;
  }
  return $out;
}
function review_set(int $id, string $status): void {
  if (!in_array($status, ['live', 'hidden'], true) || !($db = reviews_db())) return;
  $db->prepare('UPDATE reviews SET status = ? WHERE id = ?')->execute([$status, $id]);
}
/* delete a review for good: the row, its helpful votes and its photo files */
function review_delete(int $id): void {
  global $PRIV; if (!($db = reviews_db())) return;
  $s = $db->prepare('SELECT photos FROM reviews WHERE id = ?'); $s->execute([$id]);
  foreach (json_decode((string)$s->fetchColumn(), true) ?: [] as $p) @unlink("$PRIV/photos/" . basename($p));
  $db->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]); $db->prepare('DELETE FROM votes WHERE review_id = ?')->execute([$id]);
}
/* tap-to-add emoji under the review reply box, in this order */
const REPLY_EMOJI = ['🙏', '❤️', '😊', '✨', '🎁', '👍', '😍', '🥰', '🌸', '💐', '🤗', '😢'];
function review_has_reply(int $id): bool {
  if (!($db = reviews_db()) || !in_array('reply', array_column($db->query('PRAGMA table_info(reviews)')->fetchAll(), 'name'), true)) return false;
  $s = $db->prepare('SELECT reply FROM reviews WHERE id = ?'); $s->execute([$id]); return trim((string)$s->fetchColumn()) !== '';
}
/* FOMAXO's public answer under a review; an empty text removes it */
function review_reply(int $id, string $text): void {
  if (!($db = reviews_db())) return;
  if (!in_array('reply', array_column($db->query('PRAGMA table_info(reviews)')->fetchAll(), 'name'), true)) $db->exec("ALTER TABLE reviews ADD COLUMN reply TEXT NOT NULL DEFAULT ''");
  $text = mb_substr(trim(str_replace("\r", '', $text)), 0, 1000);
  $db->prepare('UPDATE reviews SET reply = ? WHERE id = ?')->execute([$text, $id]);
}
function top_reviewers_min(): int { return max(1, min(99, (int)(shop_setting('top_reviewers') ?? '2'))); }
function stars(float $r): string { $f = (int)round($r); return '<span class="stars" aria-label="' . round($r, 1) . ' out of 5">' . str_repeat('★', $f) . '<i>' . str_repeat('★', 5 - $f) . '</i></span>'; }

function phone_fmt(string $digits): string { return $digits === '' ? '' : '+91 ' . substr($digits, 0, 5) . ' ' . substr($digits, 5); }

/* ---------------- reports (profit & loss) ---------------- */
function pay_fee_pct(): float { return max(0, min(10, (float)(shop_setting('pay_fee') ?? '2'))); }
/* orders, sales, discounts given, payment fees, cost of goods, expenses, gross and net profit, from $from to $to (Y-m-d),
   one row per day ('D', keys Y-m-d), month ('M', keys Y-m) or year ('Y', keys Y) */
function report_rows(string $from, string $to, string $unit = 'M'): array {
  $len = ['D' => 10, 'M' => 7, 'Y' => 4][$unit]; $m = [];
  $zero = ['orders' => 0, 'sales' => 0, 'discounts' => 0, 'coupons' => 0, 'online' => 0, 'fees' => 0, 'cost' => 0, 'nocost' => 0, 'expenses' => 0];
  for ($t = strtotime($unit === 'D' ? $from : ($unit === 'M' ? substr($from, 0, 7) . '-01' : substr($from, 0, 4) . '-01-01')), $end = strtotime($to); $t <= $end; $t = strtotime(['D' => '+1 day', 'M' => '+1 month', 'Y' => '+1 year'][$unit], $t))
    $m[substr(date('Y-m-d', $t), 0, $len)] = $zero;
  $costs = shop_costs(); $pct = pay_fee_pct();
  $s = shop_db()->prepare("SELECT created, total, method, items, discount FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0 AND created >= ? AND created <= ?");
  $s->execute(["$from 00:00:00", "$to 23:59:59"]);
  foreach ($s as $o) {
    $k = substr($o['created'], 0, $len); if (!isset($m[$k])) continue;
    $m[$k]['orders']++; $m[$k]['sales'] += (int)$o['total']; $m[$k]['coupons'] += (int)$o['discount'];
    if ($o['method'] === 'online') $m[$k]['online'] += (int)$o['total'];
    foreach (json_decode((string)$o['items'], true) ?: [] as $it) {
      $q = (int)($it['qty'] ?? 0);
      if (!empty($it['was']) && $it['was'] > ($it['unit'] ?? 0)) $m[$k]['discounts'] += ((int)$it['was'] - (int)$it['unit']) * $q;
      $c = $it['cost'] ?? ($costs[$it['id'] ?? ''][(string)($it['opt'] ?? '')] ?? null);
      if ($c === null) $m[$k]['nocost'] += $q; else $m[$k]['cost'] += (int)$c * $q;
    }
  }
  $e = shop_db()->prepare('SELECT day, amount FROM expenses WHERE day >= ? AND day <= ?'); $e->execute([$from, $to]);
  foreach ($e as $x) { $k = substr($x['day'], 0, $len); if (isset($m[$k])) $m[$k]['expenses'] += (int)$x['amount']; }
  foreach ($m as &$r) { $r['fees'] = (int)round($r['online'] * $pct / 100); $r['gross'] = $r['sales'] - $r['fees'] - $r['cost']; $r['net'] = $r['gross'] - $r['expenses']; } unset($r);
  return $m;
}
/* month by month for one year */
function report_year(int $year): array { return report_rows("$year-01-01", "$year-12-31", 'M'); }
/* the Sales table for the dates picked: day by day up to 3 months, month by month for longer, by year for All */
function report_view(array $D): array {
  if ($D['r'] === 'all') {
    $first = (string)shop_db()->query("SELECT MIN(d) FROM (SELECT MIN(substr(created, 1, 10)) d FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0 UNION ALL SELECT MIN(day) FROM expenses) x")->fetchColumn();
    $from = parse_day($first) ?: date('Y-01-01'); $to = max(date('Y-m-d'), $from);
    return ['unit' => 'Y', 'from' => $from, 'to' => $to, 'rows' => report_rows($from, $to, 'Y')];
  }
  $unit = (strtotime($D['to']) - strtotime($D['from'])) / 86400 + 1 > 92 ? 'M' : 'D';
  return ['unit' => $unit, 'from' => $D['from'], 'to' => $D['to'], 'rows' => report_rows($D['from'], $D['to'], $unit)];
}
function report_sum(array $rows): array {
  $t = ['orders' => 0, 'sales' => 0, 'discounts' => 0, 'coupons' => 0, 'online' => 0, 'fees' => 0, 'cost' => 0, 'nocost' => 0, 'expenses' => 0, 'gross' => 0, 'net' => 0];
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
  /* the Gift page (#/gift): how many people opened it, and how many times */
  $g = $q('SELECT COUNT(DISTINCT vid) v, COUNT(*) n FROM events WHERE type = \'view\' AND (path = \'/gift\' OR path LIKE \'/gift/%\') AND ts >= ? AND ts <= ?', $a)->fetch();
  $out['gift'] = ['visitors' => (int)($g['v'] ?? 0), 'views' => (int)($g['n'] ?? 0)];
  $out['funnel'] = [];
  foreach (FUNNEL as $t => $_) $out['funnel'][$t] = (int)$q('SELECT COUNT(DISTINCT sid) FROM events WHERE type = ? AND ts >= ? AND ts <= ?', [$t, ...$a])->fetchColumn();
  /* where visitors come from: visitors, visits and visits that bought, per source */
  $out['sources'] = [];
  foreach ($q('SELECT source, COUNT(DISTINCT vid) v, COUNT(DISTINCT sid) n FROM events WHERE type = \'view\' AND ts >= ? AND ts <= ? GROUP BY source ORDER BY v DESC, n DESC', $a) as $r) $out['sources'][$r['source']] = ['visitors' => (int)$r['v'], 'visits' => (int)$r['n'], 'bought' => 0];
  foreach ($q('SELECT source, COUNT(DISTINCT sid) n FROM events WHERE type = \'buy\' AND ts >= ? AND ts <= ? GROUP BY source', $a) as $r) if (isset($out['sources'][$r['source']])) $out['sources'][$r['source']]['bought'] = (int)$r['n'];
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
  return $out;
}
/* Analytics → Conversion: what turns visits into orders. Only real orders count (new, paid or delivered; not test, cancelled or refunded).
   Campaign names, the Razorpay step and homepage scroll are counted from setting conv_since (the day they started being sent). */
function conversion_stats(string $from, string $to, array $CAT): array {
  $db = shop_db(); $a = ["$from 00:00:00", "$to 23:59:59"];
  $q = function (string $sql, array $args = []) use ($db) { $s = $db->prepare($sql); $s->execute($args); return $s; };
  $since = (string)(shop_setting('conv_since') ?? date('Y-m-d'));
  $orders = $q('SELECT no, total, items FROM orders WHERE status IN ' . SALE_STATUSES . ' AND test = 0 AND created >= ? AND created <= ?', $a)->fetchAll();

  /* 1. products: visits that opened it → visits that added it → orders and units (free items not counted) */
  $pr = [];
  foreach ($q("SELECT product, COUNT(DISTINCT CASE WHEN type = 'product' THEN sid END) v, COUNT(DISTINCT CASE WHEN type = 'add' THEN sid END) b
               FROM events WHERE type IN ('product', 'add') AND product <> '' AND ts >= ? AND ts <= ? GROUP BY product", $a) as $r)
    $pr[$r['product']] = ['viewed' => (int)$r['v'], 'bag' => (int)$r['b'], 'orders' => 0, 'units' => 0];
  foreach ($orders as $o) {
    $seen = [];
    foreach (json_decode((string)$o['items'], true) ?: [] as $it) {
      $id = (string)($it['id'] ?? ''); if ($id === '' || !empty($it['free']) || (int)($it['unit'] ?? 0) <= 0) continue;
      $pr[$id] ??= ['viewed' => 0, 'bag' => 0, 'orders' => 0, 'units' => 0];
      $pr[$id]['units'] += max(1, (int)($it['qty'] ?? 1));
      if (!isset($seen[$id])) { $seen[$id] = 1; $pr[$id]['orders']++; }
    }
  }
  foreach ($pr as $id => &$p) $p['name'] = $CAT[$id]['name'] ?? $id; unset($p);
  uasort($pr, fn($x, $y) => [$y['viewed'], $y['orders']] <=> [$x['viewed'], $x['orders']]);

  /* 2. checkout drop-off, per visit */
  $n = fn(string $t) => (int)$q('SELECT COUNT(DISTINCT sid) FROM events WHERE type = ? AND ts >= ? AND ts <= ?', [$t, ...$a])->fetchColumn();
  $steps = ['checkout' => $n('checkout'), 'typed' => (int)$q('SELECT COUNT(*) FROM leads WHERE created >= ? AND created <= ?', $a)->fetchColumn(),
    'pay' => $n('pay'), 'card' => $n('card'), 'buy' => $n('buy')];
  /* boxes left empty by people who typed details and did not order (then or later) */
  $paidPhones = [];
  foreach ($db->query("SELECT phone, created FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0") as $o) $paidPhones[substr(preg_replace('/\D/', '', (string)$o['phone']), -10)][] = $o['created'];
  $empty = ['people' => 0, 'name' => 0, 'phone' => 0, 'state' => 0, 'address' => 0, 'email' => 0];
  foreach ($q('SELECT name, phone, state, address, email, ordered, created FROM leads WHERE updated >= ? AND updated <= ?', $a) as $l) {
    if ((int)$l['ordered']) continue;
    foreach ($paidPhones[$l['phone']] ?? [] as $c) if ($l['phone'] !== '' && $c >= substr($l['created'], 0, 16)) continue 2;
    $empty['people']++;
    foreach (['name', 'phone', 'state', 'address', 'email'] as $k) if (trim((string)$l[$k]) === '') $empty[$k]++;
  }
  $unpaid = $q("SELECT COUNT(*) n, COALESCE(SUM(total), 0) t FROM orders WHERE status = 'awaiting' AND test = 0 AND created >= ? AND created <= ?", $a)->fetch();

  /* 3. campaigns: each visit by where it came from (utm_source, and utm_campaign since conv_since), the visits that bought and what they spent */
  $t0 = strtotime($from); $t1 = strtotime("$to +1 day");
  $camp = [];
  foreach ($q('SELECT source, campaign, COUNT(*) n FROM visits WHERE started >= ? AND started < ? GROUP BY source, campaign', [$t0, $t1]) as $r)
    $camp[$r['source'] . '|' . $r['campaign']] = ['source' => (string)$r['source'], 'campaign' => (string)$r['campaign'], 'visits' => (int)$r['n'], 'bought' => 0, 'revenue' => 0];
  $rev = []; foreach ($orders as $o) $rev[strtolower((string)$o['no'])] = (int)$o['total'];
  $done = [];
  foreach ($q("SELECT e.sid, e.product, v.source, v.campaign FROM events e JOIN visits v ON v.sid = e.sid WHERE e.type = 'buy' AND v.started >= ? AND v.started < ?", [$t0, $t1]) as $r) {
    $k = $r['source'] . '|' . $r['campaign']; if (!isset($camp[$k]) || !isset($rev[$r['product']])) continue;
    if (!isset($done[$r['sid']])) { $done[$r['sid']] = 1; $camp[$k]['bought']++; }
    $camp[$k]['revenue'] += $rev[$r['product']]; unset($rev[$r['product']]);
  }
  uasort($camp, fn($x, $y) => [$y['bought'], $y['visits']] <=> [$x['bought'], $x['visits']]);

  /* 4. homepage scroll: visits that opened the homepage, and how many of them reached 25 / 50 / 75 / 100 % of it */
  $sa = [max($a[0], "$since 00:00:00"), $a[1]];
  $home = (int)$q("SELECT COUNT(DISTINCT sid) FROM events WHERE type = 'view' AND path = '/' AND ts >= ? AND ts <= ?", $sa)->fetchColumn();
  $depth = [25 => 0, 50 => 0, 75 => 0, 100 => 0];
  foreach ($q("SELECT qty, COUNT(DISTINCT sid) n FROM events WHERE type = 'scroll' AND ts >= ? AND ts <= ? GROUP BY qty", $sa) as $r) if (isset($depth[(int)$r['qty']])) $depth[(int)$r['qty']] = min($home, (int)$r['n']);

  return ['since' => $since, 'products' => $pr, 'steps' => $steps, 'empty' => $empty, 'unpaid' => ['n' => (int)$unpaid['n'], 'total' => (int)$unpaid['t']],
    'campaigns' => array_values($camp), 'home' => $home, 'depth' => $depth];
}
/* top countries, and visitors by Indian state, for the dates picked at the top (distinct visitors, from api/track.php's lookup) */
function geo_stats(string $from, string $to): array {
  $a = [strtotime($from), strtotime("$to +1 day")];
  $s = shop_db()->prepare("SELECT country, COUNT(DISTINCT vid) n FROM visits WHERE started >= ? AND started < ? AND country <> '' GROUP BY country ORDER BY n DESC LIMIT 30"); $s->execute($a);
  $out['countries'] = array_column($s->fetchAll(), 'n', 'country');
  $s = shop_db()->prepare("SELECT region, COUNT(DISTINCT vid) n FROM visits WHERE started >= ? AND started < ? AND country = 'IN' AND region <> '' GROUP BY region ORDER BY n DESC"); $s->execute($a);
  $out['states'] = array_column($s->fetchAll(), 'n', 'region');
  return $out;
}
/* "21–27 Sep", "28 Sep – 4 Oct", "21 Sep" (with the year when it is not this year) */
function date_span(string $from, string $to): string {
  $a = strtotime($from); $b = strtotime($to); $yr = date('Y', $b) !== date('Y') ? ' ' . date('Y', $b) : '';
  if ($from === $to) return date('j M', $a) . $yr;
  if (date('Y-m', $a) === date('Y-m', $b)) return date('j', $a) . '–' . date('j M', $b) . $yr;
  return date('j M', $a) . (date('Y', $a) !== date('Y', $b) ? date(' Y', $a) : '') . ' – ' . date('j M', $b) . $yr;
}
/* "Left at checkout": everyone who typed their details at checkout (kept for good; the page shows the dates picked), newest first, with the order they placed later if any.
   Lines removed with their ✕ are left out (still in the table, as removed = 1). */
function checkout_leads(?string $from = null, ?string $to = null): array {
  $db = shop_db();
  if ($from !== null && $to !== null) { $s = $db->prepare('SELECT * FROM leads WHERE removed = 0 AND updated >= ? AND updated <= ? ORDER BY updated DESC'); $s->execute(["$from 00:00:00", "$to 23:59:59"]); $leads = $s->fetchAll(); }
  else $leads = $db->query('SELECT * FROM leads WHERE removed = 0 ORDER BY updated DESC')->fetchAll();
  if (!$leads) return [];
  $orders = [];
  foreach ($db->query("SELECT no, phone, created FROM orders WHERE " . IS_ORDER . " AND test = 0 ORDER BY created") as $o) $orders[substr(preg_replace('/\D/', '', (string)$o['phone']), -10)][] = $o;
  foreach ($leads as &$l) {
    $l['later'] = '';
    foreach ($orders[$l['phone']] ?? [] as $o) if ($o['created'] >= substr($l['created'], 0, 16)) { $l['later'] = (string)$o['no']; break; }
    if ($l['later'] === '' && (int)$l['ordered']) $l['later'] = 'yes';
  }
  unset($l);
  return $leads;
}
function lead_items(array $l): string { return implode(', ', array_map(fn($b) => $b['qty'] . ' × ' . $b['name'], json_decode((string)$l['bag'], true) ?: [])); }

/* chart series for the dashboard, for the dates picked: by hour for one day, by day up to 3 months, by month for longer */
function series(string $from, string $to): array {
  $db = shop_db(); $days = (int)round((strtotime($to) - strtotime($from)) / 86400) + 1;
  $unit = $days === 1 ? 'H' : ($days <= 92 ? 'D' : 'M'); $len = ['H' => 13, 'D' => 10, 'M' => 7][$unit];
  $keys = []; $labels = []; $full = [];
  if ($unit === 'H') for ($i = 0; $i < 24; $i++) { $t = strtotime("$from 00:00:00") + $i * 3600; $keys[] = date('Y-m-d H', $t); $labels[] = date('ga', $t); $full[] = date('ga', $t) . '–' . date('ga', $t + 3600) . ', ' . date('D j M', $t); }
  elseif ($unit === 'D') for ($t = strtotime($from); $t <= strtotime($to); $t = strtotime('+1 day', $t)) { $keys[] = date('Y-m-d', $t); $labels[] = $days > 7 ? date('j M', $t) : date('D', $t); $full[] = date('D j M Y', $t); }
  else for ($t = strtotime(substr($from, 0, 7) . '-01'); $t <= strtotime($to); $t = strtotime('+1 month', $t)) { $keys[] = date('Y-m', $t); $labels[] = date(substr($from, 0, 4) !== substr($to, 0, 4) ? "M 'y" : 'M', $t); $full[] = date('F Y', $t); }
  $a = ["$from 00:00:00", "$to 23:59:59"];
  $sv = array_fill_keys($keys, 0); $vv = array_fill_keys($keys, 0);
  $s = $db->prepare("SELECT substr(created, 1, $len) k, SUM(total) t FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0 AND created >= ? AND created <= ? GROUP BY substr(created, 1, $len)");
  $s->execute($a); foreach ($s as $x) if (isset($sv[$x['k']])) $sv[$x['k']] = (int)$x['t'];
  $s = $db->prepare("SELECT substr(ts, 1, $len) k, COUNT(DISTINCT vid) n FROM events WHERE type = 'view' AND ts >= ? AND ts <= ? GROUP BY substr(ts, 1, $len)");
  $s->execute($a); foreach ($s as $x) if (isset($vv[$x['k']])) $vv[$x['k']] = (int)$x['n'];
  $s = $db->prepare("SELECT COUNT(DISTINCT vid) FROM events WHERE type = 'view' AND ts >= ? AND ts <= ?"); $s->execute($a);
  return ['sales' => ['labels' => $labels, 'full' => $full, 'values' => array_values($sv)], 'visitors' => ['labels' => $labels, 'full' => $full, 'values' => array_values($vv), 'total' => (int)$s->fetchColumn()]];
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
