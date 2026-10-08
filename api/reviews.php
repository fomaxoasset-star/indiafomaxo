<?php
/* FOMAXO product reviews API.
   Data lives outside the website folder so deploys never touch it:
     ../fomaxo-private/reviews.sqlite      reviews, verified orders, helpful votes
     ../fomaxo-private/photos/             uploaded review photos (re-encoded)
   (If that folder can't be created, api/data/ is used instead.)
   Optional ../fomaxo-private/config.php or api/private-config.php returns an array:
     ['admin_key_hash' => password_hash('…'), 'moderate' => false]
   Without an admin key the owner page is disabled; reviews still work. */

declare(strict_types=1);
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');

const MAX_PHOTOS = 3;
const MAX_PHOTO_BYTES = 6 * 1024 * 1024;
const MAX_TEXT = 2000;
const SUBMITS_PER_HOUR = 6;

$PRIV = getenv('FOMAXO_PRIVATE') ?: dirname(__DIR__, 2) . '/fomaxo-private';
if (!is_dir($PRIV) && !@mkdir($PRIV, 0750, true)) $PRIV = __DIR__ . '/data';   // fallback: api/data is closed to the web by its .htaccess
$CFG = is_file("$PRIV/config.php") ? (array)(require "$PRIV/config.php")
  : (is_file(__DIR__ . '/private-config.php') ? (array)(require __DIR__ . '/private-config.php') : []);

function out($data, int $code = 200): void { http_response_code($code); echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); exit; }
function fail(string $msg, int $code = 400): void { out(['error' => $msg], $code); }

function db(): PDO {
  global $PRIV; static $db;
  if ($db) return $db;
  if (!is_dir($PRIV) && !@mkdir($PRIV, 0750, true)) fail('Reviews are unavailable right now.', 500);
  $db = new PDO("sqlite:$PRIV/reviews.sqlite", null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
  $db->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=4000;');
  $db->exec(<<<'SQL'
    CREATE TABLE IF NOT EXISTS reviews(
      id INTEGER PRIMARY KEY, product TEXT NOT NULL, rating INTEGER NOT NULL, body TEXT NOT NULL,
      name TEXT NOT NULL, anonymous INTEGER NOT NULL DEFAULT 0, verified INTEGER NOT NULL DEFAULT 0,
      order_id INTEGER, photos TEXT NOT NULL DEFAULT '[]', helpful INTEGER NOT NULL DEFAULT 0,
      status TEXT NOT NULL DEFAULT 'live', ip TEXT NOT NULL, created INTEGER NOT NULL);
    CREATE INDEX IF NOT EXISTS rv_product ON reviews(product, status);
    CREATE TABLE IF NOT EXISTS orders(
      id INTEGER PRIMARY KEY, token TEXT NOT NULL UNIQUE, customer TEXT NOT NULL, phone TEXT NOT NULL DEFAULT '',
      products TEXT NOT NULL, note TEXT NOT NULL DEFAULT '', created INTEGER NOT NULL);
    CREATE TABLE IF NOT EXISTS votes(review_id INTEGER NOT NULL, ip TEXT NOT NULL, PRIMARY KEY(review_id, ip));
    SQL);
  $cols = array_column($db->query('PRAGMA table_info(reviews)')->fetchAll(), 'name');
  if (!in_array('city', $cols, true)) $db->exec("ALTER TABLE reviews ADD COLUMN city TEXT NOT NULL DEFAULT ''");
  if (!in_array('country', $cols, true)) $db->exec("ALTER TABLE reviews ADD COLUMN country TEXT NOT NULL DEFAULT ''");
  if (!in_array('reply', $cols, true)) $db->exec("ALTER TABLE reviews ADD COLUMN reply TEXT NOT NULL DEFAULT ''");          // FOMAXO's answer, written in Admin → Reviews
  if (!in_array('mobile', $cols, true)) $db->exec("ALTER TABLE reviews ADD COLUMN mobile TEXT NOT NULL DEFAULT ''");        // optional, private: only Admin → Reviews sees it (WhatsApp)
  return $db;
}

function ipHash(): string {
  global $PRIV; db();
  $f = "$PRIV/salt"; if (!is_file($f)) file_put_contents($f, bin2hex(random_bytes(16)));
  return hash('sha256', trim((string)file_get_contents($f)) . ($_SERVER['REMOTE_ADDR'] ?? ''));
}
function input(): array {
  static $in; if ($in !== null) return $in;
  $in = $_POST ?: (json_decode((string)file_get_contents('php://input'), true) ?: []);
  return $in;
}
function str($v, int $max): string { $s = trim(preg_replace('/\s+/u', ' ', (string)$v) ?? ''); return mb_substr($s, 0, $max); }
function validProduct($id): string { $id = (string)$id; if (!preg_match('/^[a-z0-9-]{1,48}$/', $id)) fail('Unknown product.'); return $id; }

/* "Ahmed Saleem" → "Ahmed S."; only used when a verified buyer leaves the name blank, typed names show as written */
/* "arjun menon" → "Arjun Menon": first letter of every word up, the rest left as typed */
function cap(string $s): string {
  return preg_replace_callback("/(^|[\\s\\-'(.,])(\\p{Ll})/u", fn($m) => $m[1] . mb_strtoupper($m[2]), $s) ?? $s;
}
function displayName(string $n): string {
  $parts = preg_split('/\s+/u', trim($n)) ?: [];
  if (!$parts || $parts[0] === '') return 'Customer';
  $first = mb_convert_case(mb_substr($parts[0], 0, 24), MB_CASE_TITLE);
  return count($parts) > 1 ? $first . ' ' . mb_strtoupper(mb_substr(end($parts), 0, 1)) . '.' : $first;
}

function publicReview(array $r): array {
  return ['id' => (int)$r['id'], 'product' => $r['product'], 'rating' => (int)$r['rating'], 'text' => $r['body'],
    'name' => $r['anonymous'] ? 'Anonymous' : $r['name'], 'verified' => (bool)$r['verified'],
    'city' => $r['anonymous'] ? '' : ($r['city'] ?? ''), 'country' => $r['anonymous'] ? '' : ($r['country'] ?? ''),   // anonymous: no place, no flag
    'photos' => array_map(fn($p) => 'api/reviews.php?action=photo&f=' . rawurlencode($p), json_decode($r['photos'], true) ?: []),
    'helpful' => (int)$r['helpful'], 'date' => gmdate('Y-m-d', (int)$r['created']), 'reply' => $r['reply'] ?? ''];
}

function orderByToken(string $t): ?array {
  if (!preg_match('/^[a-f0-9]{32}$/', $t)) return null;
  $s = db()->prepare('SELECT * FROM orders WHERE token = ?'); $s->execute([$t]);
  return $s->fetch() ?: null;
}

function requireAdmin(): void {
  global $CFG;
  $hash = $CFG['admin_key_hash'] ?? '';
  $key = $_SERVER['HTTP_X_ADMIN_KEY'] ?? '';
  if (!$hash) fail('The owner page is not set up yet.', 403);
  if (!$key || !password_verify($key, $hash)) { usleep(400000); fail('Wrong owner key.', 403); }
}

/* Re-encode an uploaded image with GD: strips metadata and anything that isn't pixels. */
function savePhoto(array $f): ?string {
  global $PRIV;
  if (($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) return null;
  if ($f['size'] > MAX_PHOTO_BYTES) fail('Each photo must be under 6 MB.');
  $info = @getimagesize($f['tmp_name']);
  $type = $info[2] ?? 0;
  $img = match ($type) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name']), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name']),
    IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name']), default => false };
  if (!$img) fail('Photos must be JPG, PNG or WebP images.');
  $w = imagesx($img); $h = imagesy($img); $k = min(1, 1600 / max($w, $h));
  if ($k < 1) { $img = imagescale($img, (int)round($w * $k), (int)round($h * $k)); }
  $dir = "$PRIV/photos"; if (!is_dir($dir)) mkdir($dir, 0750, true);
  $name = bin2hex(random_bytes(12)) . '.jpg';
  $bg = imagecreatetruecolor(imagesx($img), imagesy($img)); imagefill($bg, 0, 0, imagecolorallocate($bg, 255, 255, 255));
  imagecopy($bg, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));
  imagejpeg($bg, "$dir/$name", 82);
  return $name;
}

function uploadedPhotos(): array {
  if (empty($_FILES['photos'])) return [];
  $f = $_FILES['photos'];
  if (!is_array($f['name'])) $f = array_map(fn($v) => [$v], $f);
  $n = count($f['name']); if ($n > MAX_PHOTOS) fail('You can add up to ' . MAX_PHOTOS . ' photos.');
  $saved = [];
  for ($i = 0; $i < $n; $i++) {
    $p = savePhoto(['error' => $f['error'][$i], 'size' => $f['size'][$i], 'tmp_name' => $f['tmp_name'][$i]]);
    if ($p) $saved[] = $p;
  }
  return $saved;
}

$action = $_GET['action'] ?? '';
$method = $_SERVER['REQUEST_METHOD'];

try {
  switch ($action) {

    case 'summary': {
      $rows = db()->query("SELECT product, AVG(rating) a, COUNT(*) n FROM reviews WHERE status = 'live' GROUP BY product")->fetchAll();
      $o = []; foreach ($rows as $r) $o[$r['product']] = ['rating' => round((float)$r['a'], 1), 'reviews' => (int)$r['n']];
      out(['products' => (object)$o]);
    }

    case 'list': {
      $p = validProduct($_GET['product'] ?? '');
      $order = ($_GET['sort'] ?? '') === 'helpful' ? 'helpful DESC, created DESC' : 'created DESC';
      $s = db()->prepare("SELECT * FROM reviews WHERE product = ? AND status = 'live' ORDER BY $order LIMIT 200"); $s->execute([$p]);
      $list = array_map('publicReview', $s->fetchAll());
      $dist = [5 => 0, 4 => 0, 3 => 0, 2 => 0, 1 => 0]; foreach ($list as $r) $dist[$r['rating']]++;
      $n = count($list);
      out(['reviews' => $list, 'count' => $n, 'rating' => $n ? round(array_sum(array_column($list, 'rating')) / $n, 1) : 0, 'distribution' => $dist]);
    }

    case 'order': {
      $o = orderByToken((string)($_GET['token'] ?? '')); if (!$o) fail('This review link is not valid.', 404);
      $s = db()->prepare('SELECT product FROM reviews WHERE order_id = ?'); $s->execute([$o['id']]);
      out(['customer' => $o['customer'], 'products' => json_decode($o['products'], true), 'reviewed' => array_column($s->fetchAll(), 'product')]);
    }

    case 'submit': {
      if ($method !== 'POST') fail('Use POST.', 405);
      $in = input();
      if (!empty($in['website'])) out(['ok' => true]);                          // honeypot: bots fill every field
      $ip = ipHash();
      // buyers writing from their order's review link have no limit: they can review every product they bought.
      // only reviews without a link (anyone on the website) keep the spam guard of SUBMITS_PER_HOUR per connection
      if (empty($in['token'])) {
        $s = db()->prepare('SELECT COUNT(*) FROM reviews WHERE ip = ? AND created > ? AND verified = 0'); $s->execute([$ip, time() - 3600]);
        if ($s->fetchColumn() >= SUBMITS_PER_HOUR) fail('Too many reviews from your connection. Please try again later.', 429);
      }

      $product = validProduct($in['product'] ?? '');
      $rating = (int)($in['rating'] ?? 0); if ($rating < 1 || $rating > 5) fail('Please choose a star rating from 1 to 5.');
      $text = trim(mb_substr(str_replace("\r", '', (string)($in['text'] ?? '')), 0, MAX_TEXT));
      // the words are up to the customer: stars alone, a single emoji or a short line are all fine
      $anon = !empty($in['anonymous']) && $in['anonymous'] !== 'false';
      $rawName = cap(str($in['name'] ?? '', 60));
      if (!$anon && $rawName === '') fail('Please enter your name, or choose to post anonymously.');
      $city = cap(str($in['city'] ?? '', 60));                                       // optional, e.g. "Pune, Maharashtra"
      $country = cap(str($in['country'] ?? '', 40));                                 // optional, picked from the form's list
      if ($anon) { $city = ''; $country = ''; }
      if ($country !== '' && !preg_match('/^[\p{L} .,()\'-]+$/u', $country)) $country = '';
      $mobile = substr(preg_replace('/\D/', '', (string)($in['mobile'] ?? '')) ?? '', -10); if (!preg_match('/^[6-9]\d{9}$/', $mobile)) $mobile = '';   // a 10-digit Indian mobile, or nothing

      $verified = 0; $orderId = null;
      if (!empty($in['token'])) {
        $o = orderByToken((string)$in['token']);
        if (!$o) fail('This review link is not valid.');
        if (!in_array($product, json_decode($o['products'], true) ?: [], true)) fail('This product is not part of that order.');
        $s = db()->prepare('SELECT 1 FROM reviews WHERE order_id = ? AND product = ?'); $s->execute([$o['id'], $product]);
        if ($s->fetchColumn()) fail('You have already reviewed this product from that order.');
        $verified = 1; $orderId = (int)$o['id'];
        if ($rawName === '') $rawName = displayName($o['customer']);   // left blank: the order name, shortened
      }

      $photos = uploadedPhotos();
      $status = !empty($CFG['moderate']) ? 'pending' : 'live';
      db()->prepare('INSERT INTO reviews(product, rating, body, name, anonymous, verified, order_id, photos, status, ip, created, city, country, mobile) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?)')
        ->execute([$product, $rating, $text, $rawName, $anon ? 1 : 0, $verified, $orderId, json_encode($photos), $status, $ip, time(), $city, $country, $mobile]);
      out(['ok' => true, 'pending' => $status === 'pending']);
    }

    case 'helpful': {
      if ($method !== 'POST') fail('Use POST.', 405);
      $id = (int)(input()['id'] ?? 0);
      $ins = db()->prepare('INSERT OR IGNORE INTO votes(review_id, ip) SELECT id, ? FROM reviews WHERE id = ? AND status = \'live\'');
      $ins->execute([ipHash(), $id]);
      if ($ins->rowCount()) db()->prepare('UPDATE reviews SET helpful = helpful + 1 WHERE id = ?')->execute([$id]);
      $s = db()->prepare('SELECT helpful FROM reviews WHERE id = ?'); $s->execute([$id]);
      out(['helpful' => (int)$s->fetchColumn()]);
    }

    case 'photo': {
      $f = (string)($_GET['f'] ?? '');
      if (!preg_match('/^[a-f0-9]{24}\.jpg$/', $f) || !is_file("$PRIV/photos/$f")) { http_response_code(404); exit; }
      header('Content-Type: image/jpeg'); header('Cache-Control: public, max-age=31536000, immutable');
      header_remove('X-Content-Type-Options'); readfile("$PRIV/photos/$f"); exit;
    }

    /* ---------- owner only ---------- */

    case 'admin_order': {   // record a paid order and get the customer's review link
      if ($method !== 'POST') fail('Use POST.', 405);
      requireAdmin(); $in = input();
      $customer = str($in['customer'] ?? '', 60); if ($customer === '') fail('Enter the customer name.');
      $products = array_values(array_unique(array_map('validProduct', (array)($in['products'] ?? []))));
      if (!$products) fail('Choose at least one product.');
      $token = bin2hex(random_bytes(16));
      db()->prepare('INSERT INTO orders(token, customer, phone, products, note, created) VALUES(?,?,?,?,?,?)')
        ->execute([$token, $customer, preg_replace('/[^0-9+]/', '', (string)($in['phone'] ?? '')), json_encode($products), str($in['note'] ?? '', 120), time()]);
      out(['token' => $token]);
    }

    case 'admin_list': {
      requireAdmin();
      $reviews = db()->query('SELECT * FROM reviews ORDER BY created DESC LIMIT 300')->fetchAll();
      $orders = db()->query('SELECT id, token, customer, phone, products, note, created FROM orders ORDER BY created DESC LIMIT 200')->fetchAll();
      out(['reviews' => array_map(fn($r) => publicReview($r) + ['status' => $r['status'], 'realName' => $r['name']], $reviews),
        'orders' => array_map(fn($o) => ['token' => $o['token'], 'customer' => $o['customer'], 'phone' => $o['phone'], 'products' => json_decode($o['products'], true), 'note' => $o['note'], 'date' => gmdate('Y-m-d', (int)$o['created'])], $orders)]);
    }

    case 'admin_set': {     // status: live | hidden | delete
      if ($method !== 'POST') fail('Use POST.', 405);
      requireAdmin(); $in = input(); $id = (int)($in['id'] ?? 0); $st = (string)($in['status'] ?? '');
      if ($st === 'delete') {
        $s = db()->prepare('SELECT photos FROM reviews WHERE id = ?'); $s->execute([$id]);
        foreach (json_decode((string)$s->fetchColumn(), true) ?: [] as $p) @unlink("$PRIV/photos/" . basename($p));
        db()->prepare('DELETE FROM reviews WHERE id = ?')->execute([$id]); db()->prepare('DELETE FROM votes WHERE review_id = ?')->execute([$id]);
      } elseif (in_array($st, ['live', 'hidden'], true)) {
        db()->prepare('UPDATE reviews SET status = ? WHERE id = ?')->execute([$st, $id]);
      } else fail('Unknown status.');
      out(['ok' => true]);
    }

    default: fail('Unknown action.', 404);
  }
} catch (PDOException $e) {
  error_log('reviews: ' . $e->getMessage());
  fail('Reviews are unavailable right now.', 500);
}
