<?php
declare(strict_types=1);
/* FOMAXO India — shared checkout helpers, used by api/razorpay.php (online payment) and api/cod.php (cash on delivery).
   Prices are read from window.STORE in index.html on every order, so the website stays the one place to change
   a price. The browser only sends product ids, sizes and quantities; the total is always worked out here.
   Private files (keys, orders) live in ../fomaxo-private next to public_html, so deploys never touch them. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

date_default_timezone_set('Asia/Kolkata');
require_once __DIR__ . '/shop-db.php';

$PRIV = getenv('FOMAXO_PRIVATE') ?: dirname(__DIR__, 2) . '/fomaxo-private';
if (!is_dir($PRIV) && !@mkdir($PRIV, 0750, true)) $PRIV = __DIR__ . '/data';   // fallback: api/data is closed to the web by its .htaccess

const FOMAXO_STATES = ['Andaman and Nicobar Islands', 'Andhra Pradesh', 'Arunachal Pradesh', 'Assam', 'Bihar', 'Chandigarh',
  'Chhattisgarh', 'Dadra and Nagar Haveli and Daman and Diu', 'Delhi', 'Goa', 'Gujarat', 'Haryana', 'Himachal Pradesh',
  'Jammu and Kashmir', 'Jharkhand', 'Karnataka', 'Kerala', 'Ladakh', 'Lakshadweep', 'Madhya Pradesh', 'Maharashtra', 'Manipur',
  'Meghalaya', 'Mizoram', 'Nagaland', 'Odisha', 'Puducherry', 'Punjab', 'Rajasthan', 'Sikkim', 'Tamil Nadu', 'Telangana',
  'Tripura', 'Uttar Pradesh', 'Uttarakhand', 'West Bengal'];

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }
function input(): array { static $in; if ($in === null) $in = json_decode((string)file_get_contents('php://input'), true) ?: []; return $in; }
function rupees(int $paise): string { $r = $paise / 100; return '₹' . ($paise % 100 ? number_format($r, 2) : number_format($r)); }

/* Private settings: ../fomaxo-private/razorpay-config.php (or the razorpay_* keys in ../fomaxo-private/config.php).
   Easier option: create razorpay-config.php in public_html/api/data/ with Hostinger File Manager. That folder is closed
   to the web, and the file is moved into ../fomaxo-private on first use (read where it is if it can't be moved). */
function fomaxo_config(): array {
  global $PRIV; static $cfg;
  if ($cfg !== null) return $cfg;
  $cfg = []; $files = ["$PRIV/config.php"];
  foreach (['razorpay-config.php', 'db-config.php'] as $n) {   // db-config.php: the Hostinger MySQL database for orders and stock (api/shop-db.php)
    $drop = __DIR__ . "/data/$n";
    if (is_file($drop) && realpath($PRIV) !== realpath(__DIR__ . '/data') && @rename($drop, "$PRIV/$n")) @chmod("$PRIV/$n", 0600);
    array_push($files, $drop, "$PRIV/$n");
  }
  foreach ($files as $f) if (is_file($f)) { $c = require $f; if (is_array($c)) $cfg = $c + $cfg; }
  return $cfg;
}

/* ---- the price list, read from window.STORE in index.html ---- */
/* window.STORE from index.html as a PHP array (its object literal read as JSON: comments, unquoted keys and trailing commas
   are handled). The admin page uses it to fill in the product edit form. Returns [] if it cannot be read. */
function fomaxo_store_data(): array {
  static $data; if ($data !== null) return $data;
  $src = (string)@file_get_contents(dirname(__DIR__) . '/index.html');
  $i = strpos($src, 'window.STORE = {'); if ($i === false) return $data = [];
  $i += strlen('window.STORE = '); $n = strlen($src); $out = ''; $depth = 0;
  $skip = function (int $j) use ($src, $n): int {   // past whitespace and comments
    while ($j < $n) {
      if (ctype_space($src[$j])) { $j++; continue; }
      if ($src[$j] === '/' && ($src[$j + 1] ?? '') === '/') { $e = strpos($src, "\n", $j); $j = $e === false ? $n : $e; continue; }
      if ($src[$j] === '/' && ($src[$j + 1] ?? '') === '*') { $e = strpos($src, '*/', $j + 2); $j = $e === false ? $n : $e + 2; continue; }
      break;
    }
    return $j;
  };
  while ($i < $n) {
    $i = $skip($i); if ($i >= $n) break; $c = $src[$i];
    if ($c === '"' || $c === "'" || $c === '`') {
      $str = ''; $i++;
      while ($i < $n && $src[$i] !== $c) {
        if ($src[$i] === '\\') {
          $e = $src[$i + 1] ?? '';
          if ($e === 'u') { $str .= mb_chr(hexdec(substr($src, $i + 2, 4)), 'UTF-8'); $i += 6; continue; }
          $str .= ['n' => "\n", 't' => "\t", 'r' => "\r"][$e] ?? $e; $i += 2; continue;
        }
        $str .= $src[$i++];
      }
      $i++;
      $out .= json_encode($str, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
      continue;
    }
    if (preg_match('/\G[A-Za-z_$][\w$]*|\G-?\d+(\.\d+)?/A', $src, $m, 0, $i)) {
      $w = $m[0]; $i += strlen($w);
      if (($src[$skip($i)] ?? '') === ':') $out .= json_encode($w);
      elseif (in_array($w, ['true', 'false', 'null'], true) || is_numeric($w)) $out .= $w;
      elseif ($w === 'undefined') $out .= 'null';
      else return $data = [];   // something that is not plain data
      continue;
    }
    if ($c === ',') { $nx = $src[$skip($i + 1)] ?? ''; if ($nx !== '}' && $nx !== ']') $out .= ','; $i++; continue; }
    if ($c === '{' || $c === '[') $depth++;
    if ($c === '}' || $c === ']') $depth--;
    if (!in_array($c, ['{', '}', '[', ']', ':'], true)) return $data = [];
    $out .= $c; $i++;
    if ($depth === 0) break;
  }
  $data = json_decode($out, true);
  return $data = is_array($data) ? $data : [];
}
/* one product as written in index.html: fragrances and car perfumes from products, personal care from personalCare.products */
function fomaxo_store_product(string $id): ?array {
  $S = fomaxo_store_data();
  foreach ($S['products'] ?? [] as $p) if (($p['id'] ?? '') === $id) return $p;
  foreach ($S['personalCare']['products'] ?? [] as $p) if (($p['id'] ?? '') === $id) return $p + ['kind' => 'care'];
  return null;
}

/* Once: copies every product in index.html into the shop database, as it is today, so the admin page and the database
   hold the whole product list. If the database cannot be reached, the shop falls back to index.html as before. */
function fomaxo_seed_products(array $live): void {
  $S = fomaxo_store_data(); if (!$S) return;
  $all = array_merge($S['products'] ?? [], array_map(fn($p) => $p + ['kind' => 'care'], $S['personalCare']['products'] ?? []));
  foreach ($all as $p) {
    $id = (string)($p['id'] ?? ''); if ($id === '' || isset($live[$id])) continue;
    $care = ($p['kind'] ?? '') === 'care';
    $prices = array_filter(array_map(fn($v) => is_numeric($v) ? (float)$v : null, $care ? ['one' => $p['price'] ?? null] : (array)($p['prices'] ?? [])), fn($v) => $v !== null);
    $was = array_map(fn($v) => is_numeric($v) ? (float)$v : 0, $care ? ['one' => $p['was'] ?? 0] : (array)($p['compareAt'] ?? []));
    $edit = $p; unset($edit['id']); if ($care) unset($edit['kind']);
    shop_save_product($id, false, false, ['prices' => $prices, 'compareAt' => $was, 'edit' => $edit, 'seeded' => 1]);
  }
  shop_set('products_seeded', shop_now());
}
function fomaxo_catalog(): array {
  static $cat; if ($cat !== null) return $cat;
  $html = (string)@file_get_contents(dirname(__DIR__) . '/index.html');
  $a = strpos($html, 'window.STORE = {'); $b = $a === false ? false : strpos($html, '</script>', $a);
  if ($a === false || $b === false) return $cat = ['products' => [], 'cod' => null, 'email' => ''];
  $src = substr($html, $a, $b - $a);
  $cat = ['products' => [], 'cod' => null, 'email' => ''];

  $sections = [];
  if (($p = strpos($src, "\n  products: [")) !== false) $sections[] = substr($src, $p, (strpos($src, "\n  featured:", $p) ?: strlen($src)) - $p);
  if (($p = strpos($src, "\n  personalCare:")) !== false) $sections[] = substr($src, $p);
  foreach ($sections as $sec) {
    preg_match_all('/\{\s*id:\s*"([a-z0-9-]+)"/', $sec, $m, PREG_OFFSET_CAPTURE);
    foreach ($m[1] as $i => [$id, $at]) {
      $end = $m[0][$i + 1][1] ?? strlen($sec);
      $o = substr($sec, $at, $end - $at);
      $get = fn($k) => preg_match('/\b' . $k . ':\s*"([^"]*)"/', $o, $x) ? $x[1] : '';
      $prices = [];
      if (preg_match('/\bprices:\s*\{([^}]*)\}/', $o, $x)) {
        preg_match_all('/"?([\w]+)"?\s*:\s*([\d.]+)/', $x[1], $pp, PREG_SET_ORDER);
        foreach ($pp as [, $k, $v]) $prices[$k] = (float)$v;
      } elseif (preg_match('/\bprice:\s*([\d.]+)/', $o, $x)) {
        $prices['one'] = (float)$x[1];
      }
      if (!$prices) continue;
      $kind = $get('kind') ?: (preg_match('/\btype:\s*"/', $o) ? 'care' : '');
      $was = [];
      if (preg_match('/\bcompareAt:\s*\{([^}]*)\}/', $o, $x)) { preg_match_all('/"?([\w]+)"?\s*:\s*([\d.]+)/', $x[1], $pp, PREG_SET_ORDER); foreach ($pp as [, $k, $v]) $was[$k] = (float)$v; }
      elseif (preg_match('/\bwas:\s*([\d.]+)/', $o, $x)) $was['one'] = (float)$x[1];
      $cat['products'][$id] = ['name' => $get('name') . ($kind === 'care' ? ' ' . explode(' — ', $get('type'))[0] : ''),
        'kind' => $kind, 'vol' => $get('vol'), 'prices' => $prices, 'was' => $was, 'soldOut' => (bool)preg_match('/\bsoldOut:\s*true/', $o),
        'img' => preg_match('/\bimages?:\s*\[?\s*"([^"]+)"/', $o, $x) ? 'assets/img/' . $x[1] . '.webp' : '', 'hidden' => false, 'added' => false];
    }
  }
  /* products added, hidden or re-priced on the admin page (fomaxo.in/admin) */
  try {
    $live = shop_products();
    if (shop_setting('products_seeded') === null) { fomaxo_seed_products($live); $live = shop_products(); }
  } catch (Throwable $e) { error_log('FOMAXO shop db: ' . $e->getMessage()); $live = []; }
  foreach ($live as $id => $d) {
    if ($d['added']) {
      $kind = (string)($d['kind'] ?? '');
      $img = (string)(($d['site']['images'][0] ?? $d['site']['image'] ?? ''));
      $cat['products'][$id] = ['name' => (string)($d['name'] ?? $id) . ($kind === 'care' ? ' ' . explode(' — ', (string)($d['type'] ?? ''))[0] : ''),
        'kind' => $kind, 'vol' => (string)($d['vol'] ?? ''), 'prices' => array_map('floatval', (array)($d['prices'] ?? [])),
        'was' => array_map('floatval', (array)($d['compareAt'] ?? [])), 'soldOut' => false,
        'img' => str_starts_with($img, 'up/') ? 'api/live.php?img=' . substr($img, 3) : '', 'added' => true];
    } elseif (!isset($cat['products'][$id])) continue;
    elseif (isset($d['edit'])) {   // details edited on the admin page: name, sizes, prices and photos replace those in index.html
      $e = $d['edit']; $p = &$cat['products'][$id]; $kind = $p['kind'];
      if (isset($e['name'])) $p['name'] = (string)$e['name'] . ($kind === 'care' ? ' ' . explode(' — ', (string)($e['type'] ?? ''))[0] : '');
      if (isset($e['vol'])) $p['vol'] = (string)$e['vol'];
      $p['prices'] = array_map('floatval', (array)($d['prices'] ?? $p['prices'])); $p['was'] = array_map('floatval', (array)($d['compareAt'] ?? []));
      $img = (string)($e['images'][0] ?? $e['image'] ?? '');
      if ($img !== '') $p['img'] = str_starts_with($img, 'up/') ? 'api/live.php?img=' . substr($img, 3) : "assets/img/$img.webp";
      unset($p);
    }
    else foreach (['prices' => 'prices', 'compareAt' => 'was'] as $from => $to)
      foreach ((array)($d[$from] ?? []) as $k => $v) if (isset($cat['products'][$id]['prices'][$k]) && is_numeric($v)) $cat['products'][$id][$to][$k] = (float)$v;
    $cat['products'][$id]['hidden'] = $d['hidden'];
  }
  if (preg_match('/\bcod:\s*\{([^}]*)\}/', $src, $x)) {
    $min = preg_match('/\bmin:\s*([\d.]+)/', $x[1], $y) ? (float)$y[1] : 0;
    $fee = preg_match('/\bfee:\s*([\d.]+)/', $x[1], $y) ? (float)$y[1] : 0;
    $cat['cod'] = ['min' => (int)round($min * 100), 'fee' => (int)round($fee * 100),
      'onlyName' => preg_match('/\bonlyName:\s*"([^"]*)"/', $x[1], $y) ? $y[1] : ''];
  }
  if (preg_match('/\bcheckout:\s*\{[^}]*?\bemail:\s*"([^"]+)"/s', $src, $x)) $cat['email'] = $x[1];
  return $cat;
}

/* Prices the bag on the server. Returns ['error'=>…] or the priced order (amounts in paise). */
function fomaxo_price_order(array $in): array {
  $CAT = fomaxo_catalog()['products'];
  if (!$CAT) return ['error' => 'Checkout is unavailable right now. Please order on WhatsApp.'];
  $lines = is_array($in['lines'] ?? null) ? $in['lines'] : [];
  if (!$lines || count($lines) > 30) return ['error' => 'Your bag is empty.'];
  $items = []; $total = 0;
  try { $STOCK = shop_stock(); $COST = shop_costs(); } catch (Throwable $e) { error_log('FOMAXO shop db: ' . $e->getMessage()); return ['error' => 'Checkout is unavailable right now. Please order on WhatsApp.']; }
  $want = [];
  foreach ($lines as $l) {
    $id = is_string($l['id'] ?? null) ? $l['id'] : '';
    $opt = (string)($l['opt'] ?? '');
    $qty = (int)($l['qty'] ?? 0);
    $p = $CAT[$id] ?? null;
    /* a stock number set on the admin page wins over soldOut in index.html; a product hidden on the admin page can't be bought */
    if (!$p || !isset($p['prices'][$opt]) || !empty($p['hidden']) || ($p['soldOut'] && !isset($STOCK[$id][$opt])) || $qty < 1 || $qty > 99)
      return ['error' => 'An item in your bag is no longer available. Please refresh and try again.'];
    $size = $p['kind'] === 'set' ? "Set of $opt" : ($p['kind'] === 'care' ? $p['vol'] : ($p['kind'] === 'car' ? 'Car perfume' : "{$opt}ml"));
    $desc = '';
    if ($p['kind'] === 'set') {
      $picks = array_values(array_filter((array)($l['picks'] ?? []), fn($x) => is_string($x) && isset($CAT[$x]) && $CAT[$x]['kind'] === ''));
      if (count($picks) !== (int)$opt) return ['error' => "Please choose $opt fragrances for your set."];
      $desc = 'Fragrances: ' . implode(', ', array_map(fn($x) => $CAT[$x]['name'], $picks));
    }
    $unit = (int)round($p['prices'][$opt] * 100);
    $total += $unit * $qty;
    $name = 'FOMAXO ' . $p['name'] . ($size !== '' ? " — $size" : '');
    $was = (int)round(($p['was'][$opt] ?? 0) * 100);
    $items[] = ['id' => $id, 'opt' => $opt, 'name' => $name, 'desc' => $desc, 'unit' => $unit, 'qty' => $qty]
      + ($was > $unit ? ['was' => $was] : [])                                  // the crossed-out price, for discounts in Reports
      + (isset($COST[$id][$opt]) ? ['cost' => $COST[$id][$opt]] : []);       // cost at the time of the order, for profit & loss
    $want["$id|$opt"] = ($want["$id|$opt"] ?? 0) + $qty;
    $have = $STOCK[$id][$opt] ?? null;
    if ($have !== null && $have < $want["$id|$opt"])
      return ['error' => $have < 1 ? "$name is out of stock in this size. Please remove it from your bag." : "Only $have left of $name. Please lower the quantity in your bag."];
  }
  if ($total < 100) return ['error' => 'This order cannot be paid online. Please order on WhatsApp.'];
  $rows = array_map(fn($it) => "• {$it['qty']} x {$it['name']}" . ($it['desc'] ? " ({$it['desc']})" : '') . ' — ' . rupees($it['unit'] * $it['qty']), $items);
  return ['items' => $items, 'rows' => $rows, 'subtotal' => $total];
}

/* Delivery details from the checkout page. Returns ['error'=>…] or clean details. */
function fomaxo_customer(array $in): array {
  $c = is_array($in['customer'] ?? null) ? $in['customer'] : [];
  $t = fn($k, $max) => trim(mb_substr(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', is_string($c[$k] ?? null) ? $c[$k] : '') ?? '', 0, $max));
  $o = ['name' => $t('name', 80), 'phone' => $t('phone', 20), 'email' => $t('email', 120), 'house' => $t('house', 80), 'street' => $t('street', 120),
        'landmark' => $t('landmark', 80), 'city' => $t('city', 60), 'state' => $t('state', 60), 'pin' => $t('pin', 10), 'note' => $t('note', 300)];
  if (mb_strlen($o['name']) < 2) return ['error' => 'Please enter your full name.'];
  $d = preg_replace('/\D/', '', $o['phone']);
  if (strlen($d) === 12 && str_starts_with($d, '91')) $d = substr($d, 2);
  if (strlen($d) === 11 && $d[0] === '0') $d = substr($d, 1);
  if (!preg_match('/^[6-9]\d{9}$/', $d)) return ['error' => 'Please enter a valid 10-digit Indian mobile number.'];
  $o['phone'] = '+91 ' . substr($d, 0, 5) . ' ' . substr($d, 5);
  if (!filter_var($o['email'], FILTER_VALIDATE_EMAIL)) return ['error' => 'Please enter a valid email address.'];
  if ($o['house'] === '' || mb_strlen($o['street']) < 2 || mb_strlen($o['city']) < 2) return ['error' => 'Please enter your full delivery address.'];
  if (!in_array($o['state'], FOMAXO_STATES, true)) return ['error' => 'Please choose your state.'];
  if (!preg_match('/^[1-9]\d{5}$/', $o['pin'])) return ['error' => 'Please enter a valid 6-digit PIN code.'];
  $o['address'] = implode(', ', array_filter([$o['house'], $o['street'], $o['landmark'] !== '' ? 'Near ' . $o['landmark'] : '', $o['city'], $o['state'] . ' ' . $o['pin']]));
  return $o;
}

function fomaxo_orders_dir(): string { global $PRIV; $d = "$PRIV/orders"; if (!is_dir($d)) @mkdir($d, 0700, true); return $d; }
/* Orders themselves are saved in the shop database (api/shop-db.php), numbered FMX-IN-1001, FMX-IN-1002 … */

/* A private copy of every order, as a spreadsheet: ../fomaxo-private/orders/orders.csv */
function fomaxo_log_order(array $row): void {
  $f = @fopen(fomaxo_orders_dir() . '/orders.csv', 'a'); if (!$f) return;
  $row = array_map(fn($v) => preg_match('/^[=+\-@]/', (string)$v) && !preg_match('/^\+?[\d\s()\-]+$/', (string)$v) ? "'" . $v : $v, $row);   // stop spreadsheet formulas
  @fputcsv($f, $row); fclose($f);
}

/* Verified Purchaser: a private review link for the products in an order (paid online, or cash on delivery). It goes into the same
   reviews.sqlite that api/reviews.php reads, so reviews written from #/review?t=… carry the badge (once per product).
   Returns the token, or '' if the products aren't known or the reviews store can't be opened. */
function fomaxo_review_token(array $rec): string {
  global $PRIV;
  $ids = array_values(array_unique(array_filter((array)($rec['ids'] ?? []), fn($x) => is_string($x) && preg_match('/^[a-z0-9-]{1,48}$/', $x))));
  if (!$ids) return '';
  try {
    $db = new PDO("sqlite:$PRIV/reviews.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('PRAGMA busy_timeout=4000;');
    $db->exec("CREATE TABLE IF NOT EXISTS orders(id INTEGER PRIMARY KEY, token TEXT NOT NULL UNIQUE, customer TEXT NOT NULL, phone TEXT NOT NULL DEFAULT '',
      products TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL)");
    $token = bin2hex(random_bytes(16));
    $db->prepare('INSERT INTO orders(token, customer, phone, products, note, created) VALUES(?,?,?,?,?,?)')
      ->execute([$token, mb_substr((string)$rec['cust']['name'], 0, 60), preg_replace('/[^0-9+]/', '', (string)$rec['cust']['phone']),
        json_encode($ids), mb_substr($rec['no'] . (!empty($rec['payment']) ? ' · ' . $rec['payment'] : '') . (!empty($rec['test']) ? ' · TEST' : ''), 0, 120), time()]);
    return $token;
  } catch (Throwable $e) { error_log('FOMAXO review link: ' . $e->getMessage()); return ''; }
}
function fomaxo_review_url(string $token): string {
  $host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'fomaxo.in'));
  return "https://$host/#/review?t=$token";
}

/* Emails the store and the customer about a confirmed order (paid online, or cash on delivery). */
/* The shop owner's own browser: marked by a cookie set while signed in to /admin, so analytics leave those visits out. */
function fomaxo_owner_token(): string {
  $k = shop_setting('track_secret'); if (!$k) { $k = bin2hex(random_bytes(16)); shop_set('track_secret', $k); }
  return hash_hmac('sha256', 'owner', $k);
}
function fomaxo_is_admin_visitor(): bool {
  $c = (string)($_COOKIE['fx_owner'] ?? '');
  try { return $c !== '' && hash_equals(fomaxo_owner_token(), $c); } catch (Throwable $e) { return false; }
}

/* where order emails and password reset links go: the email set in Admin → Settings, else STORE.email in index.html */
function fomaxo_store_email(): string {
  try { $e = (string)shop_setting('notify_email'); } catch (Throwable $x) { $e = ''; }
  return $e !== '' ? $e : (fomaxo_catalog()['email'] ?: 'fomaxoasset@gmail.com');
}
/* mail(), or for local testing (FOMAXO_MAIL_LOG set) a line in that file instead */
function fomaxo_mail(string $to, string $subject, string $body, string $headers): bool {
  if ($log = getenv('FOMAXO_MAIL_LOG')) return (bool)@file_put_contents($log, json_encode(['to' => $to, 'subject' => $subject, 'body' => $body]) . "\n", FILE_APPEND);
  return @mail($to, $subject, $body, $headers);
}
function fomaxo_send_emails(array $rec, string $how): void {
  $store = fomaxo_store_email();
  $c = $rec['cust']; $total = rupees((int)$rec['total']);
  $host = preg_replace('/^www\./', '', preg_replace('/[^A-Za-z0-9.\-]/', '', $_SERVER['HTTP_HOST'] ?? 'fomaxo.in'));
  $from = "FOMAXO <orders@$host>";
  $subj = fn($s) => '=?UTF-8?B?' . base64_encode($s) . '?=';
  $lines = implode("\n", $rec['rows']) . (!empty($rec['codFee']) ? "\n• Cash on delivery fee — " . rupees((int)$rec['codFee']) : '');
  $body = "NEW ORDER {$rec['no']} — $how\n" . date('d M Y, H:i') . " (IST)\n" . (!empty($rec['payment']) ? "Razorpay payment: {$rec['payment']}\n" : '') . "\n$lines\n\n"
        . ($how === 'Cash on delivery' ? "TO COLLECT ON DELIVERY: $total" : "TOTAL PAID: $total") . "\nDelivery: Free\n\n"
        . "Name: {$c['name']}\nMobile: {$c['phone']}\nEmail: {$c['email']}\nAddress: {$c['address']}\n" . ($c['note'] ? "Note: {$c['note']}\n" : '');
  fomaxo_mail($store, $subj("New order {$rec['no']} — $total ($how)"), $body, "From: $from\r\nReply-To: {$c['email']}\r\nContent-Type: text/plain; charset=UTF-8");
  $cb = "Thank you for your order, {$c['name']}.\n\nOrder number: {$rec['no']}\n\n$lines\n\n"
      . ($how === 'Cash on delivery' ? "Total to pay on delivery: $total" : "Total paid: $total") . "\nDelivery: Free, to {$c['address']}\n\n"
      . "We will WhatsApp you on {$c['phone']} about your delivery.\n\n"
      . (!empty($rec['review']) ? "Once your order arrives, tell us what you think. Reviews written from this link show the ✓ VERIFIED PURCHASER badge:\n" . fomaxo_review_url($rec['review']) . "\n\n" : '')
      . "FOMAXO\nhttps://$host";
  fomaxo_mail($c['email'], $subj("Your FOMAXO order {$rec['no']}"), $cb, "From: $from\r\nReply-To: $store\r\nContent-Type: text/plain; charset=UTF-8");
}
