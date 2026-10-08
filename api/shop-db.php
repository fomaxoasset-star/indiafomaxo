<?php
declare(strict_types=1);
/* FOMAXO India — the shop database: orders (FMX-IN-1001, FMX-IN-1002 …), stock per product and size, products added or
   hidden on the admin page, and admin settings. Used by store-lib.php, live.php and admin/index.php.

   It is a MySQL database on Hostinger when fomaxo-private/db-config.php (or api/data/db-config.php, which is moved there
   on first use) returns ['db_name' => …, 'db_user' => …, 'db_pass' => …]. Without it, the same tables live in
   fomaxo-private/shop.sqlite, so the shop keeps working either way. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

const FOMAXO_FIRST_ORDER = 1001;
const FOMAXO_STATUSES = ['awaiting' => 'Awaiting payment', 'new' => 'New', 'paid' => 'Paid', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'refunded' => 'Refunded'];

function shop_db(): PDO {
  global $PRIV, $SHOP_DB;
  if ($SHOP_DB) return $SHOP_DB;
  $cfg = fomaxo_config();
  if (!empty($cfg['db_name']) && !empty($cfg['db_user'])) $SHOP_DB = shop_mysql($cfg);
  else { $SHOP_DB = new PDO("sqlite:$PRIV/shop.sqlite", null, null, SHOP_PDO); $SHOP_DB->exec('PRAGMA journal_mode=WAL; PRAGMA busy_timeout=5000;'); }
  shop_schema($SHOP_DB);
  if ((int)(shop_setting('schema') ?? '0') < 2) {   // columns added after the tables first went live
    foreach (["visits ADD country VARCHAR(2) NOT NULL DEFAULT ''", "visits ADD region VARCHAR(60) NOT NULL DEFAULT ''", "leads ADD email VARCHAR(120) NOT NULL DEFAULT ''",
      "leads ADD state VARCHAR(60) NOT NULL DEFAULT ''", "leads ADD address VARCHAR(300) NOT NULL DEFAULT ''"] as $alter)
      try { $SHOP_DB->exec("ALTER TABLE $alter"); } catch (Throwable $e) { /* already there */ }
    shop_set('schema', '2');
  }
  if (shop_setting('schema') === '2') {   // order tracking: when it was delivered, and when it was cancelled or refunded
    foreach (["orders ADD delivered_at VARCHAR(19) NULL", "orders ADD closed_at VARCHAR(19) NULL"] as $alter)
      try { $SHOP_DB->exec("ALTER TABLE $alter"); } catch (Throwable $e) { /* already there */ }
    /* orders delivered or cancelled before this: their last change is the best date there is */
    $SHOP_DB->exec("UPDATE orders SET delivered_at = updated WHERE status = 'delivered' AND delivered_at IS NULL AND updated <> ''");
    $SHOP_DB->exec("UPDATE orders SET closed_at = updated WHERE status = 'cancelled' AND closed_at IS NULL AND updated <> ''");
    shop_set('schema', '3');
  }
  if (shop_setting('schema') === '3') {   // coupon codes: the code used on an order and the money it took off
    foreach (["orders ADD coupon VARCHAR(24) NOT NULL DEFAULT ''", "orders ADD discount INT NOT NULL DEFAULT 0"] as $alter)
      try { $SHOP_DB->exec("ALTER TABLE $alter"); } catch (Throwable $e) { /* already there */ }
    shop_set('schema', '4');
  }
  if (shop_setting('schema') === '4') {   // coupon time limits: a start, and an end that can carry an hour ('Y-m-d H:i')
    try { $SHOP_DB->exec("ALTER TABLE coupons ADD starts VARCHAR(16) NOT NULL DEFAULT ''"); } catch (Throwable $e) { /* already there */ }
    if ($SHOP_DB->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') $SHOP_DB->exec("ALTER TABLE coupons MODIFY ends VARCHAR(16) NOT NULL DEFAULT ''");
    shop_set('schema', '5');
  }
  if (shop_setting('schema') === '5') {   // the WhatsApp opt-in tick at checkout: 1 = the shopper asked for order updates and offers on WhatsApp
    try { $SHOP_DB->exec("ALTER TABLE orders ADD wa_optin INT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already there */ }
    shop_set('schema', '6');
  }
  if (shop_setting('schema') === '6') {   // Left at checkout: 1 = the owner removed this line from the list with its ✕ (the row is kept)
    try { $SHOP_DB->exec("ALTER TABLE leads ADD removed INT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already there */ }
    shop_set('schema', '7');
  }
  if (shop_setting('schema') === '7') {   // Analytics → Conversion: the utm_campaign name each visit came with; conv_since = the day campaigns, the Razorpay step and homepage scroll started being counted
    try { $SHOP_DB->exec("ALTER TABLE visits ADD campaign VARCHAR(60) NOT NULL DEFAULT ''"); } catch (Throwable $e) { /* already there */ }
    if (shop_setting('conv_since') === null) shop_set('conv_since', date('Y-m-d'));
    shop_set('schema', '8');
  }
  if (shop_setting('schema') === '8') {   // coupons: 0 = "Use the bigger offer" (coupon or website offer, whichever saves more), 1 = "Use both" (offer first, then the coupon)
    try { $SHOP_DB->exec("ALTER TABLE coupons ADD stack INT NOT NULL DEFAULT 0"); } catch (Throwable $e) { /* already there */ }
    shop_set('schema', '9');
  }
  if (shop_setting('schema') === '9') {   // goodwill coupons: phone = the last 10 digits of the one mobile number the code works for ('' = any shopper)
    try { $SHOP_DB->exec("ALTER TABLE coupons ADD phone VARCHAR(10) NOT NULL DEFAULT ''"); } catch (Throwable $e) { /* already there */ }
    shop_set('schema', '10');
  }
  if (shop_setting('schema') === '10') {   // free product coupons: kind 'free' adds this product (id) in this size (opt) to the order at ₹0
    foreach (["coupons ADD free_id VARCHAR(48) NOT NULL DEFAULT ''", "coupons ADD free_opt VARCHAR(16) NOT NULL DEFAULT ''"] as $alter)
      try { $SHOP_DB->exec("ALTER TABLE $alter"); } catch (Throwable $e) { /* already there */ }
    shop_set('schema', '11');
  }
  if (shop_setting('order_counter') === null) { shop_set('order_counter', (string)(FOMAXO_FIRST_ORDER - 1)); shop_import_json_orders(); }
  if (shop_setting('fresh_start') === null) shop_fresh_start();
  return $SHOP_DB;
}
/* Once, when this version first runs: the owner asked to start from zero. Every order so far (tests) is copied to
   fomaxo-private/orders-before-fresh-start-<date>.json, then removed, and numbering starts again at FMX-IN-1001. */
function shop_fresh_start(): void {
  global $PRIV;
  $db = shop_db(); $all = $db->query('SELECT * FROM orders ORDER BY id')->fetchAll();
  if ($all && @file_put_contents("$PRIV/orders-before-fresh-start-" . date('Y-m-d-His') . '.json', json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) === false) return;   // no backup, no delete
  $db->exec('DELETE FROM orders');
  shop_set('order_counter', (string)(FOMAXO_FIRST_ORDER - 1));
  shop_set('fresh_start', shop_now());
}
const SHOP_PDO = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC];
function shop_mysql(array $cfg): PDO {
  return new PDO('mysql:host=' . ($cfg['db_host'] ?? '127.0.0.1') . ';port=' . (int)($cfg['db_port'] ?? 3306) . ";dbname={$cfg['db_name']};charset=utf8mb4",
    (string)$cfg['db_user'], (string)($cfg['db_pass'] ?? ''), SHOP_PDO + [PDO::ATTR_TIMEOUT => 8]);
}
function shop_schema(PDO $db): void {
  $my = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  $auto = $my ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
  $text = $my ? 'MEDIUMTEXT' : 'TEXT';
  $tail = $my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : '';
  foreach ([
    "CREATE TABLE IF NOT EXISTS orders(
      id $auto, no VARCHAR(24) NULL UNIQUE, ref VARCHAR(64) NOT NULL UNIQUE,
      created VARCHAR(19) NOT NULL, paid_at VARCHAR(19) NULL, method VARCHAR(8) NOT NULL, status VARCHAR(12) NOT NULL,
      total INT NOT NULL, cod_fee INT NOT NULL DEFAULT 0, items $text NOT NULL, rows_text $text NOT NULL,
      name VARCHAR(80) NOT NULL, phone VARCHAR(24) NOT NULL, email VARCHAR(120) NOT NULL, address VARCHAR(400) NOT NULL,
      city VARCHAR(60) NOT NULL DEFAULT '', state VARCHAR(60) NOT NULL DEFAULT '', pin VARCHAR(10) NOT NULL DEFAULT '',
      note VARCHAR(300) NOT NULL DEFAULT '', payment_id VARCHAR(64) NOT NULL DEFAULT '', test INT NOT NULL DEFAULT 0,
      review VARCHAR(40) NOT NULL DEFAULT '', stock_taken INT NOT NULL DEFAULT 0, admin_note VARCHAR(500) NOT NULL DEFAULT '',
      updated VARCHAR(19) NOT NULL DEFAULT '', delivered_at VARCHAR(19) NULL, closed_at VARCHAR(19) NULL,
      coupon VARCHAR(24) NOT NULL DEFAULT '', discount INT NOT NULL DEFAULT 0, wa_optin INT NOT NULL DEFAULT 0)$tail",
    "CREATE TABLE IF NOT EXISTS stock(product VARCHAR(48) NOT NULL, opt VARCHAR(16) NOT NULL, qty INT NOT NULL, PRIMARY KEY(product, opt))$tail",
    "CREATE TABLE IF NOT EXISTS products(id VARCHAR(48) NOT NULL PRIMARY KEY, added INT NOT NULL DEFAULT 0, hidden INT NOT NULL DEFAULT 0,
      data $text NOT NULL, sort INT NOT NULL DEFAULT 0, updated VARCHAR(19) NOT NULL DEFAULT '')$tail",
    "CREATE TABLE IF NOT EXISTS settings(k VARCHAR(40) NOT NULL PRIMARY KEY, v $text NOT NULL)$tail",
    "CREATE TABLE IF NOT EXISTS costs(product VARCHAR(48) NOT NULL, opt VARCHAR(16) NOT NULL, cost INT NOT NULL, PRIMARY KEY(product, opt))$tail",
    "CREATE TABLE IF NOT EXISTS expenses(id $auto, day VARCHAR(10) NOT NULL, category VARCHAR(40) NOT NULL, note VARCHAR(200) NOT NULL DEFAULT '',
      amount INT NOT NULL, created VARCHAR(19) NOT NULL)$tail",
    /* visitor analytics (api/track.php): anonymous visitor and visit ids made in the browser, no IP address or cookies from others */
    "CREATE TABLE IF NOT EXISTS events(id $auto, ts VARCHAR(19) NOT NULL, vid VARCHAR(16) NOT NULL, sid VARCHAR(16) NOT NULL, type VARCHAR(10) NOT NULL,
      source VARCHAR(16) NOT NULL DEFAULT '', path VARCHAR(120) NOT NULL DEFAULT '', product VARCHAR(48) NOT NULL DEFAULT '', qty INT NOT NULL DEFAULT 0, device VARCHAR(8) NOT NULL DEFAULT '')$tail",
    "CREATE TABLE IF NOT EXISTS online(vid VARCHAR(16) NOT NULL PRIMARY KEY, last INT NOT NULL)$tail",
    /* "Left at checkout": what a shopper had typed and had in the bag, one row per visit, until they buy */
    "CREATE TABLE IF NOT EXISTS leads(sid VARCHAR(16) NOT NULL PRIMARY KEY, vid VARCHAR(16) NOT NULL, created VARCHAR(19) NOT NULL, updated VARCHAR(19) NOT NULL,
      name VARCHAR(80) NOT NULL DEFAULT '', phone VARCHAR(16) NOT NULL DEFAULT '', step VARCHAR(10) NOT NULL DEFAULT '', bag $text NOT NULL, total INT NOT NULL DEFAULT 0,
      ordered INT NOT NULL DEFAULT 0, email VARCHAR(120) NOT NULL DEFAULT '', state VARCHAR(60) NOT NULL DEFAULT '', address VARCHAR(300) NOT NULL DEFAULT '')$tail",
    /* one row per visit: when it started and was last seen (pages and the once-a-minute ping), for time on site */
    "CREATE TABLE IF NOT EXISTS visits(sid VARCHAR(16) NOT NULL PRIMARY KEY, vid VARCHAR(16) NOT NULL, started INT NOT NULL, last INT NOT NULL,
      source VARCHAR(16) NOT NULL DEFAULT '', device VARCHAR(8) NOT NULL DEFAULT '', pages INT NOT NULL DEFAULT 0,
      country VARCHAR(2) NOT NULL DEFAULT '', region VARCHAR(60) NOT NULL DEFAULT '')$tail",
    /* coupon codes made on the admin page: kind 'pct' (value = % off), 'amt' (value = paise off) or 'free' (free_id in size free_opt added free, value 0); min_order in paise, starts and ends 'Y-m-d H:i' ('' = no limit; an old end of only 'Y-m-d' lasts the whole day),
       max_uses 0 = no limit. Uses are counted from the orders that carry the code. phone = a goodwill coupon's one mobile number (last 10 digits, '' = anyone). */
    "CREATE TABLE IF NOT EXISTS coupons(code VARCHAR(24) NOT NULL PRIMARY KEY, kind VARCHAR(4) NOT NULL, value INT NOT NULL, min_order INT NOT NULL DEFAULT 0,
      ends VARCHAR(16) NOT NULL DEFAULT '', max_uses INT NOT NULL DEFAULT 0, active INT NOT NULL DEFAULT 1, created VARCHAR(19) NOT NULL,
      starts VARCHAR(16) NOT NULL DEFAULT '', stack INT NOT NULL DEFAULT 0, phone VARCHAR(10) NOT NULL DEFAULT '',
      free_id VARCHAR(48) NOT NULL DEFAULT '', free_opt VARCHAR(16) NOT NULL DEFAULT '')$tail",
  ] as $sql) $db->exec($sql);
  foreach (['CREATE INDEX ev_ts ON events(ts)', 'CREATE INDEX ev_type ON events(type, ts)', 'CREATE INDEX ld_up ON leads(updated)', 'CREATE INDEX vs_vid ON visits(vid)', 'CREATE INDEX ev_vid ON events(vid)'] as $sql)
    try { $db->exec($my ? $sql : str_replace('CREATE INDEX', 'CREATE INDEX IF NOT EXISTS', $sql)); } catch (Throwable $e) { /* already there (MySQL) */ }
}

/* Admin page: move the shop from shop.sqlite into a Hostinger MySQL database. Checks the login, copies every order, stock number,
   product and setting across (only into an empty database), then saves the login in fomaxo-private/db-config.php.
   Returns '' or what went wrong. */
function shop_move_to_mysql(string $name, string $user, string $pass): string {
  global $PRIV, $SHOP_DB;
  $cfg = ['db_name' => $name, 'db_user' => $user, 'db_pass' => $pass];
  try { $dst = shop_mysql($cfg); } catch (Throwable $e) { error_log('FOMAXO connect MySQL: ' . $e->getMessage()); return 'Could not sign in to that database. Please check the database name, user and password in Hostinger → Databases.'; }
  if (!is_dir($PRIV) && !@mkdir($PRIV, 0750, true)) return 'The private folder could not be created on the server.';
  try {
    shop_schema($dst);
    $src = shop_db();
    if ($src->getAttribute(PDO::ATTR_DRIVER_NAME) === 'sqlite' && !(int)$dst->query('SELECT COUNT(*) FROM orders')->fetchColumn()) {
      $dst->beginTransaction();
      foreach (['settings', 'stock', 'products', 'orders', 'costs', 'expenses', 'events', 'online', 'leads', 'visits', 'coupons'] as $t) {
        $dst->exec("DELETE FROM $t");
        foreach ($src->query("SELECT * FROM $t") as $r) {
          $cols = array_keys($r);
          $dst->prepare("INSERT INTO $t(" . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($r));
        }
      }
      $dst->commit();
    }
  } catch (Throwable $e) { if ($dst->inTransaction()) $dst->rollBack(); error_log('FOMAXO move to MySQL: ' . $e->getMessage()); return 'The data could not be copied into MySQL, so nothing was changed. Please try again.'; }
  $file = "$PRIV/db-config.php";
  if (@file_put_contents($file, "<?php\n/* FOMAXO India shop database (Hostinger MySQL), saved from fomaxo.in/admin. Keep private. */\nreturn " . var_export($cfg, true) . ";\n", LOCK_EX) === false) return 'The database login could not be saved on the server.';
  @chmod($file, 0600);
  $SHOP_DB = null;
  return '';
}
function shop_is_mysql(): bool { return shop_db()->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql'; }
function shop_now(): string { return date('Y-m-d H:i:s'); }

/* runs $fn inside one transaction; SQLite takes the write lock up front so two orders never get the same number */
function shop_tx(callable $fn) {
  $db = shop_db();
  if (shop_is_mysql()) $db->beginTransaction(); else $db->exec('BEGIN IMMEDIATE');
  try { $r = $fn($db); if (shop_is_mysql()) $db->commit(); else $db->exec('COMMIT'); return $r; }
  catch (Throwable $e) { try { if (shop_is_mysql()) $db->rollBack(); else $db->exec('ROLLBACK'); } catch (Throwable $x) {} throw $e; }
}
function shop_upsert(string $table, array $keys, array $row): void {
  $cols = array_keys($row); $ph = implode(',', array_fill(0, count($cols), '?'));
  $upd = array_values(array_diff($cols, $keys));
  $sql = "INSERT INTO $table(" . implode(',', $cols) . ") VALUES($ph) " . (shop_is_mysql()
    ? 'ON DUPLICATE KEY UPDATE ' . implode(',', array_map(fn($c) => "$c = VALUES($c)", $upd))
    : 'ON CONFLICT(' . implode(',', $keys) . ') DO UPDATE SET ' . implode(',', array_map(fn($c) => "$c = excluded.$c", $upd)));
  shop_db()->prepare($sql)->execute(array_values($row));
}

function shop_setting(string $k): ?string { $s = shop_db()->prepare('SELECT v FROM settings WHERE k = ?'); $s->execute([$k]); $v = $s->fetchColumn(); return $v === false ? null : (string)$v; }
function shop_set(string $k, string $v): void { shop_upsert('settings', ['k'], ['k' => $k, 'v' => $v]); }
/* The stores set on the admin page (Settings → Stores), shown on the Contact page with a Google Map each.
   null = never saved, so the site uses STORE.contact.stores in index.html. */
function shop_store_location(): ?array {
  $v = shop_setting('store_loc'); if ($v === null) return null;
  $d = json_decode($v, true); return is_array($d) && isset($d['stores']) ? $d : null;
}
/* The stores as the admin page edits them: the saved list, or the ones in index.html until the first save. */
function stores_all(): array {
  return shop_store_location() ?? ['show' => true, 'stores' => array_values(array_map(fn($st) => array_intersect_key((array)$st, array_flip(['name', 'address', 'hours', 'link'])), fomaxo_store_data()['contact']['stores'] ?? []))];
}
/* A place for the map from a pasted Google Maps link: the pin's lat,lng, the place name or the search words.
   Short share links (maps.app.goo.gl) carry none of these, so the address is used for the map then. */
function shop_map_query_from_link(string $url): string {
  if (preg_match('~[?&](?:q|query|destination)=([^&#]+)~', $url, $m)) return trim(urldecode(str_replace('+', ' ', $m[1])));
  if (preg_match('~!3d(-?\d+\.\d+)!4d(-?\d+\.\d+)~', $url, $m)) return "$m[1],$m[2]";
  if (preg_match('~/place/([^/@?#]+)~', $url, $m)) return trim(urldecode(str_replace('+', ' ', $m[1])));
  if (preg_match('~@(-?\d+\.\d+),(-?\d+\.\d+)~', $url, $m)) return "$m[1],$m[2]";
  return '';
}
function shop_low_stock(): int { return max(0, (int)(shop_setting('low_stock') ?? 5)); }

/* ---------------- order numbers ---------------- */
/* Next number in the one sequence shared by cash and online orders. Call inside shop_tx(). */
function shop_next_no(PDO $db): string {
  $db->prepare("UPDATE settings SET v = " . (shop_is_mysql() ? 'CAST(v AS UNSIGNED) + 1' : 'CAST(v AS INTEGER) + 1') . " WHERE k = 'order_counter'")->execute();
  $s = $db->prepare("SELECT v FROM settings WHERE k = 'order_counter'"); $s->execute();
  return 'FMX-IN-' . (int)$s->fetchColumn();
}

/* ---------------- stock ---------------- */
/* [product => [opt => qty]] for every product and size that has a stock number (blank in admin = not counted) */
function shop_stock(): array {
  $out = [];
  foreach (shop_db()->query('SELECT product, opt, qty FROM stock') as $r) $out[$r['product']][$r['opt']] = (int)$r['qty'];
  return $out;
}
function shop_set_stock(string $id, string $opt, ?int $qty): void {
  if ($qty === null) shop_db()->prepare('DELETE FROM stock WHERE product = ? AND opt = ?')->execute([$id, $opt]);
  else shop_upsert('stock', ['product', 'opt'], ['product' => $id, 'opt' => $opt, 'qty' => max(0, min(99999, $qty))]);
}
/* Takes the items of an order off the stock. $strict: refuse (return the message) when there isn't enough;
   otherwise (payment already taken) go down to 0. Call inside shop_tx(). */
function shop_take_stock(PDO $db, array $items, bool $strict): string {
  foreach (shop_group_items($items) as [$id, $opt, $qty, $name]) {
    $s = $db->prepare('SELECT qty FROM stock WHERE product = ? AND opt = ?' . (shop_is_mysql() ? ' FOR UPDATE' : '')); $s->execute([$id, $opt]);
    $have = $s->fetchColumn();
    if ($have === false) continue;                       // stock not counted for this product and size
    $have = (int)$have;
    if ($strict && $have < $qty) return $have < 1 ? "$name is out of stock in this size. Please remove it from your bag." : "Only $have left of $name. Please lower the quantity in your bag.";
    $db->prepare('UPDATE stock SET qty = ? WHERE product = ? AND opt = ?')->execute([max(0, $have - $qty), $id, $opt]);
  }
  return '';
}
function shop_return_stock(PDO $db, array $items): void {
  foreach (shop_group_items($items) as [$id, $opt, $qty]) $db->prepare('UPDATE stock SET qty = qty + ? WHERE product = ? AND opt = ?')->execute([$qty, $id, $opt]);
}
function shop_group_items(array $items): array {
  $g = [];
  foreach ($items as $it) { $k = $it['id'] . '|' . $it['opt']; $g[$k] = [$it['id'], (string)$it['opt'], ($g[$k][2] ?? 0) + (int)$it['qty'], $it['name']]; }
  return array_values($g);
}

/* ---------------- what each product costs you (for profit & loss), in paise ---------------- */
function shop_costs(): array {
  $out = [];
  foreach (shop_db()->query('SELECT product, opt, cost FROM costs') as $r) $out[$r['product']][$r['opt']] = (int)$r['cost'];
  return $out;
}
function shop_set_cost(string $id, string $opt, ?int $paise): void {
  if ($paise === null) shop_db()->prepare('DELETE FROM costs WHERE product = ? AND opt = ?')->execute([$id, $opt]);
  else shop_upsert('costs', ['product', 'opt'], ['product' => $id, 'opt' => $opt, 'cost' => max(0, $paise)]);
}

/* ---------------- products added or changed on the admin page ---------------- */
function shop_products(): array {
  $out = [];
  foreach (shop_db()->query('SELECT id, added, hidden, data, sort FROM products ORDER BY sort, id') as $r)
    $out[$r['id']] = ['added' => (bool)$r['added'], 'hidden' => (bool)$r['hidden'], 'sort' => (int)$r['sort']] + (json_decode((string)$r['data'], true) ?: []);
  return $out;
}
function shop_save_product(string $id, bool $added, bool $hidden, array $data, int $sort = 0): void {
  shop_upsert('products', ['id'], ['id' => $id, 'added' => $added ? 1 : 0, 'hidden' => $hidden ? 1 : 0,
    'data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), 'sort' => $sort, 'updated' => shop_now()]);
}

/* ---------------- orders ---------------- */
function shop_order_row(array $rec): array {
  $c = $rec['cust'];
  return ['ref' => $rec['ref'], 'no' => $rec['no'] ?? null, 'created' => $rec['created'] ?? shop_now(), 'paid_at' => $rec['paid_at'] ?? null,
    'method' => $rec['method'], 'status' => $rec['status'], 'total' => (int)$rec['total'], 'cod_fee' => (int)($rec['codFee'] ?? 0),
    'items' => json_encode($rec['items'] ?? [], JSON_UNESCAPED_UNICODE), 'rows_text' => implode("\n", $rec['rows'] ?? []),
    'name' => $c['name'], 'phone' => $c['phone'], 'email' => $c['email'], 'address' => $c['address'], 'city' => $c['city'] ?? '',
    'state' => $c['state'] ?? '', 'pin' => $c['pin'] ?? '', 'note' => $c['note'] ?? '', 'payment_id' => $rec['payment'] ?? '',
    'test' => !empty($rec['test']) ? 1 : 0, 'review' => $rec['review'] ?? '', 'stock_taken' => !empty($rec['stock_taken']) ? 1 : 0, 'updated' => shop_now(),
    'coupon' => (string)($rec['coupon'] ?? ''), 'discount' => (int)($rec['discount'] ?? 0), 'wa_optin' => !empty($c['wa']) ? 1 : 0];
}
function shop_insert_order(PDO $db, array $rec): void {
  $row = shop_order_row($rec); $cols = array_keys($row);
  $db->prepare('INSERT INTO orders(' . implode(',', $cols) . ') VALUES(' . implode(',', array_fill(0, count($cols), '?')) . ')')->execute(array_values($row));
}
/* an order row as the record shape the checkout code uses */
function shop_rec(array $r): array {
  return ['ref' => $r['ref'], 'no' => $r['no'], 'created' => $r['created'], 'paid_at' => $r['paid_at'], 'method' => $r['method'], 'status' => $r['status'],
    'total' => (int)$r['total'], 'codFee' => (int)$r['cod_fee'], 'items' => json_decode((string)$r['items'], true) ?: [],
    'rows' => $r['rows_text'] === '' ? [] : explode("\n", (string)$r['rows_text']),
    'ids' => array_values(array_unique(array_column(json_decode((string)$r['items'], true) ?: [], 'id'))),
    'cust' => ['name' => $r['name'], 'phone' => $r['phone'], 'email' => $r['email'], 'address' => $r['address'], 'city' => $r['city'],
      'state' => $r['state'], 'pin' => $r['pin'], 'note' => $r['note'], 'wa' => (int)($r['wa_optin'] ?? 0)],
    'payment' => $r['payment_id'], 'test' => (bool)$r['test'], 'review' => $r['review'], 'stock_taken' => (bool)$r['stock_taken'],
    'paid' => $r['paid_at'], 'cod' => $r['method'] === 'cod', 'admin_note' => $r['admin_note'], 'id' => (int)$r['id'],
    'coupon' => (string)($r['coupon'] ?? ''), 'discount' => (int)($r['discount'] ?? 0)];
}
function shop_find_order(string $ref, bool $lock = false, ?PDO $db = null): ?array {
  $s = ($db ?: shop_db())->prepare('SELECT * FROM orders WHERE ref = ?' . ($lock && shop_is_mysql() ? ' FOR UPDATE' : '')); $s->execute([$ref]);
  $r = $s->fetch(); return $r ? shop_rec($r) : null;
}

/* Admin: change an order's status. Cancelling or refunding puts its items back in stock; undoing that takes them again.
   Delivered saves the delivery time; a cash order counts as paid once delivered. Cancelled and refunded save when. */
function shop_set_status(int $id, string $status, ?string $note = null): void {
  if (!isset(FOMAXO_STATUSES[$status]) || $status === 'awaiting') return;   // "card not paid" is not an order status: an unpaid card try is not an order
  shop_tx(function (PDO $db) use ($id, $status, $note) {
    $s = $db->prepare('SELECT * FROM orders WHERE id = ?' . (shop_is_mysql() ? ' FOR UPDATE' : '')); $s->execute([$id]);
    $r = $s->fetch(); if (!$r || ($r['method'] === 'online' && !$r['no'])) return;   // an unpaid card try becomes an order only when Razorpay confirms the payment
    $items = json_decode((string)$r['items'], true) ?: []; $taken = (int)$r['stock_taken'];
    $no = $r['no']; $was = $r['status']; $now = shop_now(); $cod = $r['method'] === 'cod';
    $paid = $r['paid_at']; $dlv = $r['delivered_at']; $closed = $r['closed_at'];
    $back = ['cancelled', 'awaiting', 'refunded'];
    if (in_array($status, $back, true) && $taken) { shop_return_stock($db, $items); $taken = 0; }
    elseif (!in_array($status, $back, true) && !$taken && $no) { shop_take_stock($db, $items, false); $taken = 1; }
    if ($status === 'delivered' && $was !== 'delivered') { $dlv = $now; $paid = $paid ?: $now; }
    elseif ($was === 'delivered' && $status !== 'delivered' && $status !== 'refunded') {   // moved back from Delivered in the status list: a cash order paid only by delivering is unpaid again
      if ($cod && $paid === $dlv) $paid = null;
      $dlv = null;
    }
    if ($status === 'paid') $paid = $paid ?: $now;
    if ($status === 'new' && $cod) $paid = null;
    if (in_array($status, ['cancelled', 'refunded'], true)) { if ($was !== $status) $closed = $now; } else $closed = null;
    $db->prepare('UPDATE orders SET status = ?, stock_taken = ?, no = ?, admin_note = ?, paid_at = ?, delivered_at = ?, closed_at = ?, updated = ? WHERE id = ?')
      ->execute([$status, $taken, $no, $note ?? $r['admin_note'], $paid, $dlv, $closed, $now, $id]);
  });
}
/* Is the money in? Online orders once Razorpay confirmed; cash orders once marked Paid or delivered. */
function shop_is_paid(array $o): bool { return $o['status'] !== 'awaiting' && (!empty($o['paid_at']) || in_array($o['status'], ['paid', 'delivered'], true)); }
/* The one-tap buttons that work on an order now: paid (unpaid cash orders), deliver and cancel (pending), refund (paid or delivered online orders). */
function shop_order_actions(array $o): array {
  $st = $o['status']; $a = [];
  if ($st === 'new' && $o['method'] === 'cod' && !shop_is_paid($o)) $a[] = 'paid';
  if (in_array($st, ['new', 'paid'], true)) array_push($a, 'deliver', 'cancel');
  if ($o['method'] === 'online' && in_array($st, ['new', 'paid', 'delivered'], true) && shop_is_paid($o)) $a[] = 'refund';
  return $a;
}
/* Admin one-tap button. Checks the button is still allowed for this order. Returns the message to show. */
function shop_order_action(int $id, string $act): string {
  $s = shop_db()->prepare('SELECT * FROM orders WHERE id = ?'); $s->execute([$id]); $o = $s->fetch();
  if (!$o) return '!That order was not found.';
  $no = $o['no'] ?: 'This order';
  if (!in_array($act, shop_order_actions($o), true)) return "!$no has changed since the page opened, so nothing was done. Please check it and try again.";
  shop_set_status($id, ['paid' => 'paid', 'deliver' => 'delivered', 'cancel' => 'cancelled', 'refund' => 'refunded'][$act]);
  return $no . ['paid' => ' is now paid.', 'deliver' => ' is now delivered.', 'cancel' => ' is cancelled. Its items are back in stock.', 'refund' => ' is refunded. Its items are back in stock.'][$act];
}

/* ---------------- coupon codes ---------------- */
const COUPON_USED = "status NOT IN ('awaiting', 'cancelled', 'refunded')";   // an order counts as a use once placed or paid; cancelling gives the use back
function coupon_clean(string $code): string { return strtoupper(preg_replace('/[^A-Za-z0-9-]/', '', $code) ?? ''); }
function shop_coupons(): array {
  $used = [];
  foreach (shop_db()->query('SELECT coupon, COUNT(*) n, COALESCE(SUM(discount), 0) d, COALESCE(SUM(total), 0) t FROM orders WHERE coupon <> \'\' AND ' . COUPON_USED . ' AND test = 0 GROUP BY coupon') as $r)
    $used[$r['coupon']] = ['uses' => (int)$r['n'], 'given' => (int)$r['d'], 'sales' => (int)$r['t']];
  $out = [];
  foreach (shop_db()->query('SELECT * FROM coupons ORDER BY created DESC, code') as $c) $out[$c['code']] = $c + ($used[$c['code']] ?? ['uses' => 0, 'given' => 0, 'sales' => 0]);
  return $out;
}
function shop_coupon_uses(PDO $db, string $code): int { $s = $db->prepare('SELECT COUNT(*) FROM orders WHERE coupon = ? AND ' . COUPON_USED); $s->execute([$code]); return (int)$s->fetchColumn(); }
/* Once an order has used it up, a goodwill (or refill) coupon and a free product coupon with a usage limit delete themselves,
   so the Coupons list keeps only codes still waiting to be used. The order keeps the code. Call inside shop_tx(), after the order is saved as placed or paid. */
function shop_coupon_spent(PDO $db, string $code): void {
  if ($code === '') return;
  $s = $db->prepare('SELECT * FROM coupons WHERE code = ?'); $s->execute([$code]); $c = $s->fetch();
  if (!$c || ((string)$c['phone'] === '' && ($c['kind'] !== 'free' || !(int)$c['max_uses']))) return;
  if (shop_coupon_uses($db, $code) >= max(1, (int)$c['max_uses'])) $db->prepare('DELETE FROM coupons WHERE code = ?')->execute([$code]);
}
/* when a coupon starts and stops working, as 'Y-m-d H:i' ('' = no limit); the end minute still works */
function coupon_starts(array $c): string { return (string)($c['starts'] ?? ''); }
function coupon_ends(array $c): string { $e = (string)$c['ends']; return strlen($e) === 10 ? "$e 23:59" : $e; }
/* 'soon' (not started yet), 'over' (past its end) or '' (inside its time limit) */
function coupon_time(array $c): string {
  $now = date('Y-m-d H:i');
  return coupon_starts($c) !== '' && coupon_starts($c) > $now ? 'soon' : (coupon_ends($c) !== '' && coupon_ends($c) < $now ? 'over' : '');
}
/* "10 Oct 2026, 6:00 pm" */
function coupon_when(string $ymdhi): string { return date('j M Y, g:i a', strtotime($ymdhi)); }
function coupon_label(array $c): string {
  if ($c['kind'] === 'free') return ((int)$c['min_order'] ? 'Spend ' . rupees((int)$c['min_order']) . ', get ' : 'Free ') . coupon_free_name($c) . ((int)$c['min_order'] ? ' free' : '');
  return $c['kind'] === 'pct' ? (int)$c['value'] . '% off' : rupees((int)$c['value']) . ' off';
}
/* a free product coupon's product and size, like "Gold 10ml" */
function coupon_free_name(array $c): string {
  $p = fomaxo_catalog()['products'][(string)($c['free_id'] ?? '')] ?? null;
  return $p ? trim($p['name'] . ' ' . fomaxo_size_label($p, (string)$c['free_opt'])) : 'a product';
}
/* how the coupon mixes with the website offer (multi-buy), set with the two ring dots on Admin → Coupons */
function coupon_stack_label(array $c): string { return !empty($c['stack']) ? 'Use both' : 'Bigger offer'; }
/* the last 10 digits of a mobile number ('' when it has fewer), so +91 98765 43210, 098765 43210 and 9876543210 all match */
function coupon_phone(string $phone): string { $d = preg_replace('/\D/', '', $phone) ?? ''; return strlen($d) >= 10 ? substr($d, -10) : ''; }
/* Checks a code for a bag of $subtotal paise. $offer = what the website offer (multi-buy) takes off this bag, in paise.
   $phone = the shopper's mobile number, which a goodwill coupon (one mobile number, one use) must match.
   Returns ['error' => …] or ['code', 'discount' (coupon, paise), 'offer' (multi-buy kept, paise), 'stack', 'label'], plus 'free' => [id, opt] for a free product coupon.
   "Use the bigger offer": the coupon or the offer, whichever saves more. "Use both": the offer first, then the coupon off the rest.
   The minimum order counts the bag before any discount. The bag always keeps at least ₹1 to pay, so online payment still works. */
function shop_coupon_apply(string $code, int $subtotal, ?PDO $db = null, int $offer = 0, string $phone = ''): array {
  $db = $db ?: shop_db(); $code = coupon_clean($code);
  if ($code === '') return ['error' => 'Please type your coupon code.'];
  $s = $db->prepare('SELECT * FROM coupons WHERE code = ?'); $s->execute([$code]); $c = $s->fetch();
  if (!$c || !(int)$c['active']) return ['error' => "$code is not a valid coupon code."];
  if (coupon_time($c) === 'soon') return ['error' => "Coupon $code starts on " . coupon_when(coupon_starts($c)) . '.'];
  if (coupon_time($c) === 'over') return ['error' => "Coupon $code has expired."];
  if ((string)($c['phone'] ?? '') !== '') {   // a goodwill coupon: only for the mobile number it was made for, and only once
    if (coupon_phone($phone) === '') return ['error' => "Coupon $code is for one mobile number. Please type your mobile number in the delivery details first, then apply the code."];
    if (coupon_phone($phone) !== $c['phone']) return ['error' => "Coupon $code is for a different mobile number. Please use the mobile number it was sent to."];
    if (shop_coupon_uses($db, $code) >= max(1, (int)$c['max_uses'])) return ['error' => "Coupon $code has already been used. It works one time only."];
  }
  if ((int)$c['max_uses'] > 0 && shop_coupon_uses($db, $code) >= (int)$c['max_uses']) return ['error' => "Coupon $code has been fully used."];
  if ($c['kind'] === 'free') {   // a free product: added to the order at ₹0 (fomaxo_add_free), nothing taken off, the website offer kept
    if ($subtotal < (int)$c['min_order']) return ['error' => 'Spend ' . rupees((int)$c['min_order']) . ' to get your free ' . coupon_free_name($c) . '. Add ' . rupees((int)$c['min_order'] - $subtotal) . ' more to your bag.'];
    return ['code' => $code, 'discount' => 0, 'offer' => max(0, min($offer, $subtotal - 100)), 'stack' => true, 'label' => coupon_label($c), 'ends' => coupon_ends($c),
      'free' => ['id' => (string)$c['free_id'], 'opt' => (string)$c['free_opt']]];
  }
  if ($subtotal < (int)$c['min_order']) return ['error' => "Coupon $code is for orders of " . rupees((int)$c['min_order']) . ' and above. Add ' . rupees((int)$c['min_order'] - $subtotal) . ' more to use it.'];
  $stack = !empty($c['stack']); $offer = max(0, min($offer, $subtotal - 100));
  $base = $stack ? $subtotal - $offer : $subtotal;
  $off = $c['kind'] === 'pct' ? (int)round($base * min(100, max(0, (int)$c['value'])) / 100) : (int)$c['value'];
  $off = max(0, min($off, $base - 100));
  if (!$stack) { if ($off >= $offer) $offer = 0; else $off = 0; }   // the bigger saving wins; a tie goes to the coupon
  return ['code' => $code, 'discount' => $off, 'offer' => $offer, 'stack' => $stack, 'label' => coupon_label($c), 'ends' => coupon_ends($c)];
}

/* ---------------- limited-time offer (Admin → Offer) ---------------- */
/* Kept in the setting 'offer': mode 'end' (counts down to `end`, 'Y-m-d H:i' India time), 'always' (no clock) or 'off';
   `popup` and `line` say what the website shows. It never changes prices. */
function shop_offer(): array {
  $o = json_decode((string)shop_setting('offer'), true);
  $o = is_array($o) ? $o : []; if (!is_array($o['fs'] ?? null)) $o['fs'] = [];
  $r = ['mode' => in_array($o['mode'] ?? '', ['end', 'always'], true) ? $o['mode'] : 'off', 'end' => (string)($o['end'] ?? ''),
    'popup' => (bool)($o['popup'] ?? true), 'line' => (bool)($o['line'] ?? true), 'pct' => max(0, min(99, (int)($o['pct'] ?? 0))),
    'items' => array_values(array_map('strval', array_filter((array)($o['items'] ?? []), 'is_scalar'))),
    'lines' => array_values(array_map('strval', array_filter((array)($o['lines'] ?? []), 'is_scalar'))),
    /* the popup's words ('' = the standard ones: LIMITED TIME OFFER / on selected fragrances / Shop the offer) */
    'title' => (string)($o['title'] ?? ''), 'sub' => (string)($o['sub'] ?? ''), 'btn' => (string)($o['btn'] ?? ''),
    /* how wide each product picture is in the popup, in px, on a laptop and on a phone (set in the admin Preview) */
    'sizeL' => shop_offer_size($o['sizeL'] ?? 0, 'L'), 'sizeP' => shop_offer_size($o['sizeP'] ?? 0, 'P'),
    /* the popup's words size, in % of the standard, on a laptop (L) and a phone (P): top line, % off, under the %, button */
    'fs' => ['L' => shop_offer_fs($o['fs']['L'] ?? []), 'P' => shop_offer_fs($o['fs']['P'] ?? [])]];   // items: products in the popup ([] = every product with an old price); lines: products with the line by prices
  /* saved before the line had its own products: it showed on the popup's products, or on every product with an old price */
  if (!array_key_exists('lines', $o) && $r['line']) $r['lines'] = $r['items'] ?: fomaxo_sale_ids();
  $r['line'] = (bool)$r['lines'];
  return $r;
}
/* the popup's product picture width: [smallest, default, biggest] in px, for laptop (L) and phone (P); anything else → the default */
const SHOP_OFFER_SIZE = ['L' => [90, 150, 220], 'P' => [80, 120, 160]];
function shop_offer_size($v, string $d): int {
  [$lo, $def, $hi] = SHOP_OFFER_SIZE[$d]; $v = (int)$v;
  return $v >= $lo && $v <= $hi ? $v : $def;
}
/* the popup's words size: [smallest, biggest] in % of the standard (100); anything else → 100 */
const SHOP_OFFER_FS = ['title' => [80, 200], 'pct' => [60, 160], 'sub' => [70, 200], 'btn' => [80, 160]];
/* the same for the New product popup: top line, picture, name, short line, button */
const SHOP_NEWP_FS = ['title' => [80, 200], 'img' => [60, 200], 'name' => [60, 160], 'line' => [70, 200], 'btn' => [80, 160]];
function shop_offer_fs($f, array $ranges = SHOP_OFFER_FS): array {
  $f = is_array($f) ? $f : []; $r = [];
  foreach ($ranges as $k => [$lo, $hi]) { $v = (int)($f[$k] ?? 0); $r[$k] = $v >= $lo && $v <= $hi ? $v : 100; }
  return $r;
}
/* the New product popup (Admin → Offer): on, kind 'soon' (Coming soon) or 'new' (Just arrived), name, a short line, and a product id ('' = none) */
function shop_newprod(): array {
  $n = json_decode((string)shop_setting('newprod'), true); $n = is_array($n) ? $n : []; $fs = is_array($n['fs'] ?? null) ? $n['fs'] : [];
  return ['fs' => ['L' => shop_offer_fs($fs['L'] ?? [], SHOP_NEWP_FS), 'P' => shop_offer_fs($fs['P'] ?? [], SHOP_NEWP_FS)], 'on' => (bool)($n['on'] ?? false), 'kind' => ($n['kind'] ?? '') === 'new' ? 'new' : 'soon', 'name' => (string)($n['name'] ?? ''), 'line' => (string)($n['line'] ?? ''), 'id' => (string)($n['id'] ?? ''),
    'label' => (string)($n['label'] ?? '') !== '' ? (string)$n['label'] : (($n['kind'] ?? '') === 'new' ? 'Just arrived' : 'Coming soon')];
}
/* what the website gets (STORE_LIVE.offer): null when nothing should show, also once the end has passed.
   end = the end as milliseconds since 1970 (0 = always on), so every phone counts to the same moment */
function shop_offer_live(): ?array {
  $o = shop_offer();
  if ($o['mode'] === 'off' || (!$o['popup'] && !$o['line'])) return null;
  $end = 0;
  if ($o['mode'] === 'end') { $t = strtotime($o['end']); if ($t === false || $t <= time()) return null; $end = $t * 1000; }
  $live = ['end' => $end, 'popup' => $o['popup'], 'line' => $o['line']];
  if ($o['items']) $live['items'] = $o['items'];
  if ($o['lines']) $live['lines'] = $o['lines'];
  foreach (['title', 'sub', 'btn'] as $k) if ($o[$k] !== '') $live[$k] = $o[$k];
  $live['size'] = [$o['sizeL'], $o['sizeP']];   // the popup's product pictures: laptop and phone width in px
  $live['fs'] = [$o['fs']['L'], $o['fs']['P']];   // the popup's words: laptop and phone size in %
  if ($o['pct'] > 0) $live['pct'] = min($o['pct'], fomaxo_best_pct($o['items'])[0]);   // a typed % never goes above the real biggest saving
  return $live;
}
/* what the website gets for the New product popup (STORE_LIVE.newProduct): null when off. key changes when the popup changes, so each visitor sees each one once. */
function shop_newprod_live(): ?array {
  $n = shop_newprod();
  if (!$n['on'] || $n['name'] === '') return null;
  $p = $n['id'] !== '' ? (fomaxo_catalog()['products'][$n['id']] ?? null) : null;
  $show = $p && empty($p['hidden']);   // a product on the website gets "Shop now"; otherwise "Explore FOMAXO"
  return ['kind' => $show ? 'new' : 'soon', 'label' => $n['label'], 'name' => $n['name'], 'line' => $n['line'], 'id' => $show ? $n['id'] : '', 'img' => $p['img'] ?? '',
    'fs' => [$n['fs']['L'], $n['fs']['P']],   // sizes set in the admin Preview, in % (laptop, phone)
    'key' => substr(md5($n['label'] . '|' . $n['name'] . '|' . $n['id']), 0, 10)];
}

/* Ad tags set on Admin → Settings → Ads (STORE_LIVE.ads): Meta Pixel ID, TikTok Pixel ID, Google Analytics 4 ID (G-…),
   Google Ads ID (AW-…) and its purchase conversion label. Empty boxes are left out; nothing loads on the website until one is filled in. */
const SHOP_ADS = ['meta' => '/^\d{10,20}$/', 'tiktok' => '/^[A-Z0-9]{10,30}$/', 'ga4' => '/^G-[A-Z0-9]{4,16}$/', 'gads' => '/^AW-\d{6,15}$/', 'gadsLabel' => '/^[A-Za-z0-9_-]{4,40}$/'];
function shop_ads(): array {
  $a = json_decode((string)shop_setting('ads'), true); $a = is_array($a) ? $a : [];
  $out = [];
  foreach (SHOP_ADS as $k => $re) { $v = (string)($a[$k] ?? ''); if ($v !== '' && preg_match($re, $v)) $out[$k] = $v; }
  if (!isset($out['gads'])) unset($out['gadsLabel']);
  return $out;
}

/* Site pages turned off on Admin → Settings → Site pages (STORE_LIVE.pagesOff). A page that is off leaves the top menu,
   the phone menu and the footer, and its address opens the home page. Home, products, checkout and policies always stay. */
const SHOP_PAGES = ['fragrances' => 'Fragrance', 'personal-care' => 'Personal Care', 'gift' => 'Gift', 'combo' => 'Combo', 'collections' => 'Collections',
  'about' => 'About FOMAXO', 'contact' => 'Contact', 'partnership' => 'Franchise', 'dubai' => 'Visit Dubai'];
function shop_pages_off(): array {
  $o = json_decode((string)shop_setting('pages_off'), true);
  return is_array($o) ? array_values(array_intersect(array_keys(SHOP_PAGES), $o)) : [];
}

/* Cash on delivery limits set on Admin → Settings → Cash on delivery, in rupees: min = COD from this order amount,
   max = COD only for orders under this amount after any coupon (0 = no limit), fee = added to every COD order (0 = none).
   Until they are saved there, the values in index.html (STORE.checkout.cod) are used. */
function shop_cod(array $def): array {
  $c = json_decode((string)shop_setting('cod'), true);
  if (!is_array($c)) return $def + ['max' => 0];
  return ['min' => max(0, (int)($c['min'] ?? 0)), 'max' => max(0, (int)($c['max'] ?? 0)), 'fee' => max(0, (int)($c['fee'] ?? 0))];
}

/* Orders saved as JSON files before the database existed (fomaxo-private/orders/*.json) are copied in once, keeping their numbers. */
function shop_import_json_orders(): void {
  $dir = fomaxo_orders_dir();
  foreach (glob("$dir/*.json") ?: [] as $f) {
    $rec = json_decode((string)file_get_contents($f), true);
    if (!is_array($rec) || empty($rec['cust']['name']) || empty($rec['no'])) continue;
    $ref = basename($f, '.json'); $cod = !empty($rec['cod']);
    try {
      shop_insert_order(shop_db(), ['ref' => $ref, 'no' => !$cod && empty($rec['paid']) ? null : $rec['no'], 'created' => ($rec['created'] ?? '') . ':00',
        'paid_at' => !empty($rec['paid']) ? $rec['paid'] . ':00' : null, 'method' => $cod ? 'cod' : 'online',
        'status' => $cod ? 'new' : (!empty($rec['paid']) ? 'paid' : 'awaiting'), 'total' => (int)($rec['total'] ?? 0), 'codFee' => (int)($rec['codFee'] ?? 0),
        'items' => array_map(fn($id) => ['id' => $id, 'opt' => '', 'qty' => 1, 'name' => $id, 'unit' => 0], (array)($rec['ids'] ?? [])),
        'rows' => (array)($rec['rows'] ?? []), 'cust' => $rec['cust'] + ['city' => '', 'state' => '', 'pin' => '', 'note' => ''],
        'payment' => $rec['payment'] ?? '', 'test' => !empty($rec['test']), 'review' => $rec['review'] ?? '']);
    } catch (Throwable $e) { /* already there */ }
  }
}
