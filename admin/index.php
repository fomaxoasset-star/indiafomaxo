<?php
declare(strict_types=1);
/* FOMAXO India — private admin page: fomaxo.in/admin
   Orders (FMX-IN-1001 …) with payment type and status, an Excel download, stock per product and size, and products
   added, hidden or re-priced. Everything is kept in the shop database (api/shop-db.php).

   Password: create  public_html/api/data/admin-password.txt  in Hostinger File Manager with your password as its only
   line, then open fomaxo.in/admin. The page stores a scrambled copy (a hash) and deletes the file. Do the same again
   any time to reset a forgotten password. */
require dirname(__DIR__) . '/api/store-lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

const ADMIN_PER_PAGE = 100;
const EXPENSE_CATEGORIES = ['Stock purchase', 'Packaging', 'Shipping & courier', 'Marketing & ads', 'Payment gateway fees', 'Salaries', 'Rent', 'Website & software', 'Travel', 'Other'];
const ADMIN_CSS = <<<'CSS'
:root{--bg:#0c0b09;--panel:#16140f;--panel2:#1d1a14;--line:#2e2a21;--text:#efe8da;--muted:#a59c89;--gold:#c9a45c;--gold2:#e3c68a;--red:#d46a5a;--green:#7fb38a;--blue:#7ea4d6;--amber:#d9a648}
*{box-sizing:border-box}html{-webkit-text-size-adjust:100%}body{margin:0;background:var(--bg);color:var(--text);font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif}
a{color:var(--gold2)}header{display:flex;align-items:center;justify-content:space-between;padding:16px 24px;border-bottom:1px solid var(--line);background:#0a0907;position:sticky;top:0;z-index:5}
.brand{color:var(--gold);text-decoration:none;letter-spacing:.32em;font-weight:600}.brand span{color:var(--muted);letter-spacing:.16em;font-weight:400;font-size:12px;margin-left:6px;text-transform:uppercase}.site{font-size:13px;color:var(--muted)}
main{max-width:1180px;margin:0 auto;padding:24px 16px 80px}main.center{display:flex;justify-content:center;padding-top:9vh}
h2{font-weight:500;font-size:20px;margin:0 0 12px;letter-spacing:.02em}h4{margin:0 0 6px;font-size:12px;text-transform:uppercase;letter-spacing:.14em;color:var(--gold)}
.card{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:22px;margin:0 0 18px}.narrow{max-width:460px;width:100%}
label{display:flex;flex-direction:column;gap:6px;font-size:12px;color:var(--muted);letter-spacing:.04em}label small{color:var(--muted);opacity:.8}
input,select,textarea{background:#0f0e0b;border:1px solid var(--line);color:var(--text);border-radius:7px;padding:10px 11px;font:inherit;font-size:14px;width:100%}
input:focus,select:focus,textarea:focus{outline:none;border-color:var(--gold)}input[type=checkbox]{width:auto}
.btn{display:inline-flex;align-items:center;justify-content:center;gap:8px;background:var(--gold);color:#17130b;border:1px solid var(--gold);border-radius:7px;padding:10px 18px;font:inherit;font-weight:600;font-size:14px;cursor:pointer;text-decoration:none;white-space:nowrap}
.btn:hover{background:var(--gold2)}.btn.line{background:transparent;color:var(--gold2)}.btn.sm{padding:7px 13px;font-size:13px}.btn.danger{background:transparent;border-color:var(--red);color:var(--red)}
.login label{margin:14px 0 16px}.login .btn{width:100%}.err{color:var(--red)}.ok{color:var(--green)}.muted{color:var(--muted)}.small{font-size:12px}
.steps{padding-left:20px;line-height:1.9}form.card{display:flex;flex-direction:column;gap:12px}form.card h2{margin-bottom:0}.flash{background:#1f2a1f;border:1px solid #33503a;color:#cfe8d3;padding:10px 14px;border-radius:8px}
.tabs{display:flex;gap:4px;border-bottom:1px solid var(--line);margin-bottom:18px;overflow-x:auto}.tabs a{padding:10px 16px;text-decoration:none;color:var(--muted);border-bottom:2px solid transparent;white-space:nowrap}.tabs a.on{color:var(--gold2);border-color:var(--gold)}.tabs .out{margin-left:auto}
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px}.stat{background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:12px 14px;text-decoration:none;color:var(--text);display:flex;flex-direction:column}.stat.on{border-color:var(--gold)}
.stat b{font-size:24px;font-weight:500;color:var(--text)}.stat.st-new{border-top:3px solid var(--amber)}.stat.st-paid{border-top:3px solid var(--green)}.stat.st-delivered{border-top:3px solid var(--blue)}.stat.st-cancelled{border-top:3px solid var(--red)}.stat span{font-size:12px;text-transform:uppercase;letter-spacing:.12em;color:var(--muted)}.stat small{color:var(--muted)}
.filters{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end;margin-bottom:6px}.filters label{min-width:140px}.filters .grow{flex:1;min-width:200px}
.order{background:var(--panel);border:1px solid var(--line);border-left:3px solid var(--line);border-radius:8px;margin-bottom:8px}.order[open]{background:var(--panel2)}
.order summary{display:grid;grid-template-columns:100px 140px 1fr 100px 170px 110px;gap:12px;align-items:center;padding:12px 14px;cursor:pointer;list-style:none}.order summary::-webkit-details-marker{display:none}
.order .no{color:var(--gold2);font-weight:600}.order .dt,.order .pm{color:var(--muted);font-size:13px}.order .cu{display:flex;flex-direction:column}.order .cu small{color:var(--muted)}.order .tt{font-weight:600}
.od{display:grid;grid-template-columns:1.2fr 1fr 1fr;gap:20px;padding:4px 16px 18px}.od ul{margin:0;padding-left:18px}.od p{margin:8px 0}.stform{display:flex;flex-direction:column;gap:10px;margin-top:10px}
.badge{display:inline-block;font-size:11px;letter-spacing:.08em;text-transform:uppercase;padding:4px 8px;border-radius:99px;border:1px solid currentColor;text-align:center}
.st-new{color:var(--amber)}.st-paid{color:var(--green)}.st-delivered{color:var(--blue)}.st-cancelled{color:var(--red)}.st-awaiting{color:var(--muted)}
.order.os-new{border-left-color:var(--amber)}.order.os-paid{border-left-color:var(--green)}.order.os-delivered{border-left-color:var(--blue)}.order.os-cancelled{border-left-color:var(--red);opacity:.75}
.exp-row{display:flex;flex-wrap:wrap;gap:10px;align-items:flex-end}.exp-row label{min-width:150px}.exp-row .grow{flex:1;min-width:220px}
.monthnav{display:flex;align-items:center;gap:12px}.monthnav h2{margin:0;min-width:150px;text-align:center}.monthnav select{width:auto}
.stats.two{grid-template-columns:1fr 2fr;margin-top:14px}.stat.cats{display:grid;grid-template-columns:repeat(auto-fill,minmax(180px,1fr));gap:6px 18px;align-content:center}.stat.cats div{display:flex;justify-content:space-between;gap:8px;font-size:13px}.stat.cats div span{text-transform:none;letter-spacing:0;font-size:13px;color:var(--text)}.stat.cats div b{font-size:14px;font-weight:600}.dash .grid td small{display:block}
.card-t{background:var(--panel);border:1px solid var(--line);border-radius:10px;overflow:hidden}.grid .r{text-align:right}.grid tfoot td{font-weight:600;border-top:1px solid var(--gold);padding:10px 8px}
.pnl tr.future td{color:var(--muted);opacity:.6}.neg{color:var(--red)}.warn{color:var(--amber)}.stat.profit b{color:var(--green)}.stat.loss b{color:var(--red)}
.dash{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:6px}.dash .card{margin:0}.dash .row-head h2{margin:0}.quick{display:flex;flex-wrap:wrap;gap:8px;margin-top:16px}.stats + h2{margin-top:6px}
@media(max-width:820px){.dash{grid-template-columns:1fr}}
.linkbtn{background:none;border:0;color:var(--red);cursor:pointer;font:inherit;font-size:13px;padding:0}
@media(max-width:820px){.stats.two{grid-template-columns:1fr}.pnl{display:block;overflow-x:auto;white-space:nowrap}.card-t{display:block;overflow-x:auto}}
.pager{display:flex;gap:6px;flex-wrap:wrap;margin-top:14px}.pager a{padding:6px 11px;border:1px solid var(--line);border-radius:6px;text-decoration:none}.pager a.on{border-color:var(--gold)}
table.grid{width:100%;border-collapse:collapse;margin-top:14px}.grid th{text-align:left;font-size:11px;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);padding:8px;border-bottom:1px solid var(--line)}
.grid td{padding:7px 8px;border-bottom:1px solid #221f18;vertical-align:middle}.grid tr.first td{border-top:1px solid var(--line)}.grid td b{display:block}.grid td small{color:var(--muted);font-size:12px}.grid input{max-width:120px}
.inline{flex-direction:row;align-items:center;gap:10px;font-size:14px;color:var(--text)}.inline input{width:80px}.sticky{position:sticky;bottom:0;background:linear-gradient(transparent,var(--panel) 30%);padding:18px 0 4px}
.row-head{display:flex;justify-content:space-between;align-items:center;gap:12px}.row-head h2{margin:0}
.prod{display:grid;grid-template-columns:64px 220px 1fr auto;gap:16px;align-items:center;background:var(--panel);border:1px solid var(--line);border-radius:10px;padding:12px 14px;margin-bottom:8px}
.prod.off{opacity:.55}.pimg{width:64px;height:64px;border-radius:8px;overflow:hidden;background:#000}.pimg img{width:100%;height:100%;object-fit:cover}.pinfo{display:flex;flex-direction:column;gap:4px}.pinfo small{color:var(--muted)}
.pprices{display:flex;flex-direction:column;gap:6px}.pprices div{display:grid;grid-template-columns:90px 1fr 1fr;gap:8px;align-items:end}.pprices span{font-size:13px;color:var(--muted);padding-bottom:10px}
.pact{display:flex;gap:6px}.pact form{margin:0}.chk{flex-direction:row;align-items:center;gap:8px;color:var(--text);font-size:13px}
#add summary{list-style:none;cursor:pointer}#add summary h2{margin:0}#add summary::-webkit-details-marker{display:none}
.addf{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-top:16px}.addf .wide{grid-column:1/-1}.notes{display:grid;grid-template-columns:repeat(3,1fr);gap:12px}.srow{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:8px}
[hidden]{display:none!important}
@media(max-width:820px){.stats{grid-template-columns:repeat(2,1fr)}.order summary{grid-template-columns:1fr auto;gap:4px 10px}.order .dt,.order .pm{grid-column:1}.order .tt{grid-row:1;grid-column:2;text-align:right}.order .badge{grid-column:2;grid-row:2}
.od{grid-template-columns:1fr}.prod{grid-template-columns:56px 1fr}.pimg{width:56px;height:56px}.pprices,.pact{grid-column:1/-1}.pprices div{grid-template-columns:70px 1fr 1fr}.addf,.notes,.srow{grid-template-columns:1fr 1fr}.grid input{max-width:90px}header{padding:14px 16px}}
CSS;

$https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('fomaxo_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();

function h($s): string { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }
function inr_paise(int $p): string { return rupees($p); }
function self_url(array $q = []): string { return '/admin/' . ($q ? '?' . http_build_query($q) : ''); }
function go(array $q = [], string $flash = ''): void { if ($flash !== '') $_SESSION['flash'] = $flash; header('Location: ' . self_url($q), true, 303); exit; }

try { shop_db(); } catch (Throwable $e) {
  error_log('FOMAXO admin db: ' . $e->getMessage());
  page('Admin', '<div class="card"><h2>The shop database could not be opened</h2><p>Please try again in a minute. If it keeps happening, check db-config.php in fomaxo-private.</p></div>', false);
}

/* ---------------- password ---------------- */
$drop = dirname(__DIR__) . '/api/data/admin-password.txt';
$setupMsg = '';
if (is_file($drop)) {
  $pw = trim((string)file_get_contents($drop));
  if (mb_strlen($pw) < 8) $setupMsg = 'The password in admin-password.txt is shorter than 8 characters. Please write a longer one.';
  else { shop_set('admin_hash', password_hash($pw, PASSWORD_DEFAULT)); @unlink($drop); session_regenerate_id(true); $_SESSION = []; $setupMsg = 'ok'; }
}
$hash = shop_setting('admin_hash');
if (!$hash) {
  page('Set your password', '<div class="card narrow"><h2>Set your admin password</h2>' . ($setupMsg && $setupMsg !== 'ok' ? '<p class="err">' . h($setupMsg) . '</p>' : '') . '
    <ol class="steps"><li>Open <b>Hostinger → Websites → fomaxo.in → File Manager</b>.</li>
    <li>Go to <b>public_html/api/data</b>.</li>
    <li>Create a new file named <b>admin-password.txt</b>.</li>
    <li>Type your password in it (at least 8 characters) and save.</li>
    <li>Reload this page. It saves your password safely and deletes the file.</li></ol>
    <p class="muted">To change a forgotten password later, do the same again.</p></div>', false);
}

/* ---------------- sign in ---------------- */
$ipKey = substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16);
function fails(string $ipKey): array { $all = json_decode((string)shop_setting('login_fails'), true) ?: []; return array_values(array_filter((array)($all[$ipKey] ?? []), fn($t) => $t > time() - 900)); }
function set_fails(string $ipKey, array $list): void { $all = json_decode((string)shop_setting('login_fails'), true) ?: []; $all = array_filter($all, fn($v) => max((array)$v ?: [0]) > time() - 900); if ($list) $all[$ipKey] = $list; else unset($all[$ipKey]); shop_set('login_fails', json_encode($all)); }

if (($_GET['do'] ?? '') === 'logout') { $_SESSION = []; session_destroy(); go(); }
if (empty($_SESSION['ok']) || ($_SESSION['hash'] ?? '') !== substr($hash, -12) || ($_SESSION['seen'] ?? 0) < time() - 8 * 3600) {
  $err = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['password'])) {
    $f = fails($ipKey);
    if (count($f) >= 5) $err = 'Too many tries. Please wait 15 minutes.';
    elseif (password_verify((string)$_POST['password'], $hash)) {
      set_fails($ipKey, []); session_regenerate_id(true);
      $_SESSION = ['ok' => 1, 'hash' => substr($hash, -12), 'seen' => time(), 'csrf' => bin2hex(random_bytes(16))];
      go();
    } else { $f[] = time(); set_fails($ipKey, $f); $err = 'That password is not right.'; }
  }
  page('Sign in', '<form class="card narrow login" method="post" autocomplete="off"><h2>FOMAXO admin</h2>'
    . ($setupMsg === 'ok' ? '<p class="ok">Your password is saved and the file was deleted. Sign in below.</p>' : '')
    . ($err ? '<p class="err">' . h($err) . '</p>' : '')
    . '<label>Password<input type="password" name="password" autofocus required autocomplete="current-password"></label><button class="btn">Sign in</button></form>', false);
}
$_SESSION['seen'] = time();
$CSRF = $_SESSION['csrf'];
$csrfField = '<input type="hidden" name="csrf" value="' . h($CSRF) . '">';

/* ---------------- the product list (index.html + admin changes) ---------------- */
$CAT = fomaxo_catalog()['products'];
$LIVE = shop_products();
function opt_label(array $p, string $opt): string { return $p['kind'] === 'care' ? ($p['vol'] ?: 'Standard') : ($p['kind'] === 'car' ? 'Car perfume' : ($p['kind'] === 'set' ? "Set of $opt" : "{$opt}ml")); }
function kind_label(string $k): string { return ['' => 'Fragrance', 'car' => 'Car fragrance', 'care' => 'Personal care', 'set' => 'Gift set'][$k] ?? $k; }

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) go([], 'Your session expired. Please try again.');
  $a = (string)($_POST['action'] ?? ''); $back = json_decode((string)($_POST['back'] ?? '[]'), true) ?: [];
  $back = array_intersect_key($back, array_flip(['tab', 'status', 'method', 'q', 'from', 'to', 'page']));

  if ($a === 'status') {
    shop_set_status((int)($_POST['id'] ?? 0), (string)($_POST['status'] ?? ''), mb_substr(trim((string)($_POST['admin_note'] ?? '')), 0, 500));
    go($back, 'Order updated.');
  }
  if ($a === 'stock') {
    foreach ((array)($_POST['stock'] ?? []) as $id => $opts) {
      if (!isset($CAT[$id])) continue;
      foreach ((array)$opts as $opt => $v) {
        if (!isset($CAT[$id]['prices'][$opt])) continue;
        $v = trim((string)$v);
        shop_set_stock((string)$id, (string)$opt, $v === '' ? null : max(0, (int)$v));
      }
    }
    foreach ((array)($_POST['cost'] ?? []) as $id => $opts) {
      if (!isset($CAT[$id])) continue;
      foreach ((array)$opts as $opt => $v) {
        if (!isset($CAT[$id]['prices'][$opt])) continue;
        $v = trim((string)$v);
        shop_set_cost((string)$id, (string)$opt, $v === '' || !is_numeric($v) ? null : (int)round((float)$v * 100));
      }
    }
    if (isset($_POST['low_stock'])) shop_set('low_stock', (string)max(0, min(99, (int)$_POST['low_stock'])));
    go(['tab' => 'stock'], 'Stock and costs saved.');
  }
  if ($a === 'product') {
    $id = (string)($_POST['id'] ?? ''); $p = $CAT[$id] ?? null;
    if (!$p) go(['tab' => 'products'], 'That product was not found.');
    $d = $LIVE[$id] ?? ['added' => false, 'hidden' => false, 'sort' => 0];
    $prices = []; $was = [];
    foreach ($p['prices'] as $opt => $_) {
      $v = (string)($_POST['price'][$opt] ?? ''); if (is_numeric($v) && $v >= 1) $prices[$opt] = round((float)$v, 2);
      $w = trim((string)($_POST['was'][$opt] ?? '')); if ($w === '') $was[$opt] = 0; elseif (is_numeric($w)) $was[$opt] = round((float)$w, 2);
    }
    if (count($prices) !== count($p['prices'])) go(['tab' => 'products'], 'Please give every size a price.');
    $hidden = empty($_POST['show']);
    $data = array_diff_key($d, array_flip(['added', 'hidden', 'sort']));
    $data['prices'] = $prices; $data['compareAt'] = $was;
    if ($d['added'] && isset($data['site'])) {
      if (($data['kind'] ?? '') === 'care') { $data['site']['price'] = $prices['one'] ?? 0; $data['site']['was'] = $was['one'] ?: null; }
      else { $data['site']['prices'] = $prices; $data['site']['compareAt'] = array_filter($was); }
    }
    shop_save_product($id, $d['added'], $hidden, $data, $d['sort']);
    go(['tab' => 'products'], $p['name'] . ($hidden ? ' is hidden from the website.' : ' is saved.'));
  }
  if ($a === 'delete') {
    $id = (string)($_POST['id'] ?? '');
    if (!empty($LIVE[$id]['added'])) {
      shop_db()->prepare('DELETE FROM products WHERE id = ?')->execute([$id]);
      shop_db()->prepare('DELETE FROM stock WHERE product = ?')->execute([$id]);
      foreach (glob("$PRIV/product-images/$id-*") ?: [] as $f) @unlink($f);
      go(['tab' => 'products'], 'Product removed.');
    }
    go(['tab' => 'products']);
  }
  if ($a === 'add') { $msg = add_product($CAT); go(['tab' => 'products'] + ($msg === '' ? [] : ['add' => 1]), $msg === '' ? 'Product added. It is on the website now.' : $msg); }
  if ($a === 'expense') {
    $day = (string)($_POST['day'] ?? ''); $amt = (string)($_POST['amount'] ?? ''); $cat = (string)($_POST['category'] ?? '');
    $back = ['tab' => 'expenses', 'month' => substr($day, 0, 7)];
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $day) || !strtotime($day)) go($back, 'Please choose the date of the expense.');
    if (!is_numeric($amt) || $amt <= 0) go($back, 'Please write the amount in rupees.');
    if (!in_array($cat, EXPENSE_CATEGORIES, true)) $cat = 'Other';
    shop_db()->prepare('INSERT INTO expenses(day, category, note, amount, created) VALUES(?,?,?,?,?)')
      ->execute([$day, $cat, mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['note'] ?? ''))), 0, 200), (int)round((float)$amt * 100), shop_now()]);
    go($back, 'Expense added: ' . rupees((int)round((float)$amt * 100)) . ' on ' . date('d M Y', strtotime($day)) . '.');
  }
  if ($a === 'expense_delete') {
    shop_db()->prepare('DELETE FROM expenses WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    go(['tab' => 'expenses', 'month' => (string)($_POST['month'] ?? '')], 'Expense deleted.');
  }
  if ($a === 'mysql') {
    $err = shop_move_to_mysql(trim((string)($_POST['db_name'] ?? '')), trim((string)($_POST['db_user'] ?? '')), (string)($_POST['db_pass'] ?? ''));
    go(['tab' => 'settings'], $err === '' ? 'Connected. Orders, stock and products are now saved in your Hostinger MySQL database.' : $err);
  }
  if ($a === 'password') {
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify((string)($_POST['current'] ?? ''), $hash)) go(['tab' => 'settings'], 'Your current password is not right.');
    if (mb_strlen($new) < 8) go(['tab' => 'settings'], 'The new password needs at least 8 characters.');
    if ($new !== (string)($_POST['again'] ?? '')) go(['tab' => 'settings'], 'The two new passwords are different.');
    $nh = password_hash($new, PASSWORD_DEFAULT); shop_set('admin_hash', $nh); $_SESSION['hash'] = substr($nh, -12);
    go(['tab' => 'settings'], 'Password changed.');
  }
  go();
}

/* Adds a product from the form. Returns '' or what is wrong. */
function add_product(array $CAT): string {
  global $PRIV;
  $kind = (string)($_POST['kind'] ?? ''); if (!in_array($kind, ['', 'car', 'care'], true)) return 'Please choose a category.';
  $t = fn($k, $max) => trim(mb_substr(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? '')) ?? '', 0, $max));
  $name = $t('name', 60); $type = $t('type', 80); $short = $t('short', 200);
  $desc = array_values(array_filter(array_map(fn($x) => trim(mb_substr($x, 0, 1200)), preg_split('/\n\s*\n/', str_replace("\r", '', (string)($_POST['description'] ?? '')))), 'strlen'));
  if (mb_strlen($name) < 2) return 'Please give the product a name.';
  if ($type === '') return $kind === 'care' ? 'Please write the product type, for example Moisturizing Shampoo.' : 'Please write the product type, for example Eau de Parfum.';
  $sizes = []; $prices = []; $was = []; $stock = [];
  foreach ([0, 1, 2] as $i) {
    $price = (string)($_POST['price'][$i] ?? ''); if ($price === '') continue;
    if (!is_numeric($price) || $price < 1) return 'Please write each price as a number of rupees.';
    $opt = $kind === '' ? (string)(int)($_POST['size'][$i] ?? 0) : 'one';
    if ($kind === '' && (int)$opt < 1) return 'Please write the size in ml for each price.';
    if (isset($prices[$opt])) return $kind === '' ? 'Each size can only be listed once.' : 'Personal care and car perfume have one price. Please fill in only the first row.';
    $sizes[] = $kind === '' ? (int)$opt : 'one'; $prices[$opt] = round((float)$price, 2);
    $w = (string)($_POST['was'][$i] ?? ''); if (is_numeric($w) && $w > $price) $was[$opt] = round((float)$w, 2);
    $s = trim((string)($_POST['stock'][$i] ?? '')); if ($s !== '') $stock[$opt] = max(0, (int)$s);
  }
  if (!$prices) return 'Please add a price.';
  $base = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name . ($kind === 'care' ? ' ' . $type : ''))), '-') ?: 'product';
  $base = substr(($kind === 'care' ? 'pc-' : '') . $base, 0, 40); $id = $base; $n = 2;
  while (isset($CAT[$id])) $id = $base . '-' . $n++;
  $images = save_images($id);
  if (is_string($images)) return $images;
  if (!$images) return 'Please add at least one photo.';
  $imgKeys = array_map(fn($f) => "up/$f", $images);
  $vol = $kind === 'care' ? $t('vol', 20) : '';
  if ($kind === 'care') {
    $site = ['id' => $id, 'kind' => 'care', 'name' => $name, 'type' => $type, 'cat' => in_array($_POST['cat'] ?? '', ['hair', 'body', 'face', 'lips'], true) ? $_POST['cat'] : 'body',
      'price' => $prices['one'], 'was' => $was['one'] ?? null, 'vol' => $vol, 'image' => $imgKeys[0], 'extraImages' => array_slice($imgKeys, 1),
      'short' => $short ?: $type, 'description' => $desc ?: [$short ?: $type], 'badge' => !empty($_POST['new_tag']) ? 'New' : ''];
  } else {
    $tier = in_array($_POST['tier'] ?? '', ['elite', 'signature', 'prestige'], true) ? $_POST['tier'] : '';
    $site = ['id' => $id, 'name' => $name, 'family' => $type, 'short' => $short ?: $type, 'description' => $desc ?: [$short ?: $type],
      'sizes' => $sizes, 'prices' => $prices, 'compareAt' => $was, 'images' => $imgKeys, 'url' => ''] + ($kind === 'car' ? ['kind' => 'car'] : []) + ($tier ? ['tier' => $tier] : [])
      + (!empty($_POST['new_tag']) ? ['tag' => 'New'] : []);
    $notes = array_filter(['top' => $t('top', 120), 'heart' => $t('heart', 120), 'base' => $t('base', 120)]);
    if ($kind === '' && count($notes) === 3) $site['notes'] = $notes;
  }
  shop_save_product($id, true, false, ['kind' => $kind, 'name' => $name, 'type' => $type, 'vol' => $vol, 'prices' => $prices, 'compareAt' => $was, 'site' => $site], (int)time());
  foreach ($stock as $opt => $q) shop_set_stock($id, (string)$opt, $q);
  return '';
}
/* Saves up to 4 uploaded photos for a new product, re-encoded (WebP when the server can, else JPEG), at most 1600px. */
function save_images(string $id) {
  global $PRIV;
  $f = $_FILES['photos'] ?? null; if (!$f || !is_array($f['name'])) return [];
  $dir = "$PRIV/product-images"; if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return 'Photos could not be saved on the server.';
  if (!function_exists('imagecreatetruecolor')) return 'This server cannot process photos (GD is missing).';
  $out = [];
  foreach ($f['name'] as $i => $_) {
    if (($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
    if (count($out) >= 4) break;
    if ($f['error'][$i] !== UPLOAD_ERR_OK || $f['size'][$i] > 12 * 1024 * 1024) return 'Each photo must be a JPG, PNG or WebP under 12 MB.';
    $type = (@getimagesize($f['tmp_name'][$i]) ?: [2 => 0])[2];
    $img = match ($type) { IMAGETYPE_JPEG => @imagecreatefromjpeg($f['tmp_name'][$i]), IMAGETYPE_PNG => @imagecreatefrompng($f['tmp_name'][$i]),
      IMAGETYPE_WEBP => @imagecreatefromwebp($f['tmp_name'][$i]), default => false };
    if (!$img) return 'Each photo must be a JPG, PNG or WebP.';
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

/* ---------------- orders: filters, list and Excel ---------------- */
$tab = in_array($_GET['tab'] ?? '', ['home', 'orders', 'stock', 'products', 'expenses', 'pnl', 'settings'], true) ? $_GET['tab'] : 'home';
$F = ['status' => (string)($_GET['status'] ?? ''), 'method' => (string)($_GET['method'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')),
      'from' => (string)($_GET['from'] ?? ''), 'to' => (string)($_GET['to'] ?? '')];
function order_where(array $F): array {
  $w = []; $a = [];
  if ($F['status'] === 'awaiting') $w[] = "status = 'awaiting'";
  elseif (isset(FOMAXO_STATUSES[$F['status']])) { $w[] = 'status = ?'; $a[] = $F['status']; }
  else $w[] = "status <> 'awaiting'";
  if (in_array($F['method'], ['cod', 'online'], true)) { $w[] = 'method = ?'; $a[] = $F['method']; }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $F['from'])) { $w[] = 'created >= ?'; $a[] = $F['from'] . ' 00:00:00'; }
  if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $F['to'])) { $w[] = 'created <= ?'; $a[] = $F['to'] . ' 23:59:59'; }
  if ($F['q'] !== '') {
    $like = '%' . str_replace(['%', '_'], '', $F['q']) . '%'; $digits = preg_replace('/\D/', '', $F['q']);
    $w[] = '(no LIKE ? OR name LIKE ? OR email LIKE ? OR payment_id LIKE ?' . (strlen($digits) >= 4 ? ' OR REPLACE(phone, \' \', \'\') LIKE ?' : '') . ')';
    array_push($a, $like, $like, $like, $like); if (strlen($digits) >= 4) $a[] = "%$digits%";
  }
  return [' WHERE ' . implode(' AND ', $w), $a];
}
function pay_label(array $o): string { return $o['method'] === 'cod' ? 'Cash on delivery' : 'Online (Razorpay)' . ($o['test'] ? ' · TEST' : ''); }

/* ---------------- profit & loss ---------------- */
/* Sales are orders that are New, Paid or Delivered (not cancelled, not unfinished payments, not Razorpay test orders), by order date.
   Product cost is the cost per item saved on the Stock tab, as it was when the order was placed (or today's cost for older orders). */
function pnl_year(int $year): array {
  $m = []; for ($i = 1; $i <= 12; $i++) $m[sprintf('%04d-%02d', $year, $i)] = ['orders' => 0, 'sales' => 0, 'fees' => 0, 'cost' => 0, 'nocost' => 0, 'expenses' => 0];
  $costs = shop_costs();
  $s = shop_db()->prepare("SELECT created, total, cod_fee, items FROM orders WHERE status IN ('new', 'paid', 'delivered') AND test = 0 AND created >= ? AND created < ?");
  $s->execute(["$year-01-01", ($year + 1) . '-01-01']);
  foreach ($s as $o) {
    $k = substr($o['created'], 0, 7); if (!isset($m[$k])) continue;
    $m[$k]['orders']++; $m[$k]['sales'] += (int)$o['total']; $m[$k]['fees'] += (int)$o['cod_fee'];
    foreach (json_decode((string)$o['items'], true) ?: [] as $it) {
      $c = $it['cost'] ?? ($costs[$it['id'] ?? ''][(string)($it['opt'] ?? '')] ?? null);
      if ($c === null) $m[$k]['nocost'] += (int)($it['qty'] ?? 0); else $m[$k]['cost'] += (int)$c * (int)($it['qty'] ?? 0);
    }
  }
  $e = shop_db()->prepare('SELECT day, amount FROM expenses WHERE day >= ? AND day < ?'); $e->execute(["$year-01-01", ($year + 1) . '-01-01']);
  foreach ($e as $x) { $k = substr($x['day'], 0, 7); if (isset($m[$k])) $m[$k]['expenses'] += (int)$x['amount']; }
  foreach ($m as &$r) { $r['gross'] = $r['sales'] - $r['cost']; $r['net'] = $r['gross'] - $r['expenses']; } unset($r);
  return $m;
}
function pnl_sum(array $rows): array {
  $t = ['orders' => 0, 'sales' => 0, 'fees' => 0, 'cost' => 0, 'nocost' => 0, 'expenses' => 0, 'gross' => 0, 'net' => 0];
  foreach ($rows as $r) foreach ($t as $k => $_) $t[$k] += $r[$k];
  return $t;
}
function pnl_years(): array {
  $y = [(int)date('Y')];
  foreach (shop_db()->query("SELECT DISTINCT substr(created, 1, 4) y FROM orders UNION SELECT DISTINCT substr(day, 1, 4) FROM expenses") as $r) if ((int)$r['y'] > 2000) $y[] = (int)$r['y'];
  $y = array_unique($y); rsort($y); return $y;
}
$pyear = (int)($_GET['year'] ?? date('Y')); if ($pyear < 2000 || $pyear > 2100) $pyear = (int)date('Y');
if (($_GET['do'] ?? '') === 'pnl_excel') {
  $rows = []; $n = fn($p) => round($p / 100, 2);
  foreach (pnl_year($pyear) as $k => $r) $rows[] = [date('M Y', strtotime("$k-01")), $r['orders'], $n($r['sales']), $n($r['cost']), $n($r['gross']), $n($r['expenses']), $n($r['net']), $r['nocost'] ?: ''];
  $t = pnl_sum(pnl_year($pyear)); $rows[] = ["Total $pyear", $t['orders'], $n($t['sales']), $n($t['cost']), $n($t['gross']), $n($t['expenses']), $n($t['net']), $t['nocost'] ?: ''];
  send_sheet("FOMAXO-profit-and-loss-$pyear", ['Month', 'Orders', 'Sales (₹)', 'Product cost (₹)', 'Gross profit (₹)', 'Expenses (₹)', 'Net profit / loss (₹)', 'Items with no cost set'], $rows, 'Profit and loss');
}
if (($_GET['do'] ?? '') === 'expenses_excel') {
  $s = shop_db()->prepare('SELECT * FROM expenses WHERE day >= ? AND day < ? ORDER BY day, id'); $s->execute(["$pyear-01-01", ($pyear + 1) . '-01-01']);
  $rows = array_map(fn($x) => [$x['day'], $x['category'], $x['note'], round($x['amount'] / 100, 2)], $s->fetchAll());
  send_sheet("FOMAXO-expenses-$pyear", ['Date', 'Category', 'Details', 'Amount (₹)'], $rows, 'Expenses');
}

if (($_GET['do'] ?? '') === 'excel') {
  [$where, $args] = order_where($F);
  $s = shop_db()->prepare("SELECT * FROM orders$where ORDER BY id"); $s->execute($args);
  $head = ['Order no', 'Date', 'Status', 'Payment type', 'Razorpay payment ID', 'Items', 'Units', 'Subtotal (₹)', 'COD fee (₹)', 'Total (₹)',
    'Customer', 'Mobile', 'Email', 'Address', 'City', 'State', 'PIN code', 'Customer note', 'Admin note', 'Paid at'];
  $rows = [];
  foreach ($s as $o) {
    $items = json_decode((string)$o['items'], true) ?: [];
    $rows[] = [$o['no'] ?: '(not paid)', substr($o['created'], 0, 16), FOMAXO_STATUSES[$o['status']] ?? $o['status'], pay_label($o), $o['payment_id'],
      implode("\n", array_map(fn($r) => ltrim($r, '• '), explode("\n", (string)$o['rows_text']))), array_sum(array_map(fn($i) => (int)($i['qty'] ?? 0), $items)),
      ($o['total'] - $o['cod_fee']) / 100, $o['cod_fee'] / 100, $o['total'] / 100, $o['name'], $o['phone'], $o['email'], $o['address'], $o['city'], $o['state'], $o['pin'],
      $o['note'], $o['admin_note'], $o['paid_at'] ? substr($o['paid_at'], 0, 16) : ''];
  }
  send_sheet('FOMAXO-orders-' . date('Y-m-d'), $head, $rows);
}

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

/* ---------------- pages ---------------- */
$flash = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
$tabs = ['home' => 'Dashboard', 'orders' => 'Orders', 'stock' => 'Stock', 'products' => 'Products', 'expenses' => 'Expenses', 'pnl' => 'Profit &amp; loss', 'settings' => 'Settings'];
$nav = '<nav class="tabs">' . implode('', array_map(fn($k, $v) => '<a href="' . h(self_url(['tab' => $k])) . '"' . ($k === $tab ? ' class="on"' : '') . ">$v</a>", array_keys($tabs), $tabs)) . '<a class="out" href="' . h(self_url(['do' => 'logout'])) . '">Sign out</a></nav>';
$body = $nav . ($flash ? '<p class="flash">' . h($flash) . '</p>' : '');

if ($tab === 'home') {
  $year = (int)date('Y'); $cur = date('Y-m'); $rows = pnl_year($year); $m = $rows[$cur]; $y = pnl_sum($rows);
  $money = fn($p) => ($p < 0 ? '−' . rupees(-$p) : rupees($p));
  $pl = fn($p) => '<div class="stat ' . ($p < 0 ? 'loss' : 'profit') . '"><b>' . $money($p) . '</b><span>' . ($p < 0 ? 'Loss' : 'Profit') . '</span>';
  $body .= '<h2>' . h(date('F Y')) . '</h2><div class="stats">'
    . '<a class="stat" href="' . h(self_url(['tab' => 'orders', 'from' => "$cur-01", 'to' => date('Y-m-t')])) . '"><b>' . rupees($m['sales']) . '</b><span>Sales this month</span><small>' . $m['orders'] . ' order' . ($m['orders'] === 1 ? '' : 's') . '</small></a>'
    . '<div class="stat"><b>' . rupees($m['cost']) . '</b><span>Product cost</span><small>' . ($m['nocost'] ? '<span class="warn">' . $m['nocost'] . ' items have no cost set</span>' : 'From cost per item') . '</small></div>'
    . '<a class="stat" href="' . h(self_url(['tab' => 'expenses'])) . '"><b>' . rupees($m['expenses']) . '</b><span>Expenses this month</span><small>Add an expense</small></a>'
    . $pl($m['net']) . '<small>Sales minus product cost and expenses</small></div></div>';
  $body .= '<h2>' . $year . ' so far</h2><div class="stats">'
    . '<div class="stat"><b>' . rupees($y['sales']) . '</b><span>Sales this year</span><small>' . $y['orders'] . ' order' . ($y['orders'] === 1 ? '' : 's') . '</small></div>'
    . '<div class="stat"><b>' . rupees($y['cost']) . '</b><span>Product cost</span><small>' . ($y['nocost'] ? '<span class="warn">' . $y['nocost'] . ' items have no cost set</span>' : '&nbsp;') . '</small></div>'
    . '<div class="stat"><b>' . rupees($y['expenses']) . '</b><span>Expenses this year</span><small>&nbsp;</small></div>'
    . $pl($y['net']) . '<small><a href="' . h(self_url(['tab' => 'pnl'])) . '">See every month</a></small></div></div>';
  /* orders waiting for you */
  $todo = shop_db()->query("SELECT * FROM orders WHERE status IN ('new', 'paid') ORDER BY id DESC LIMIT 8")->fetchAll();
  $nTodo = (int)shop_db()->query("SELECT COUNT(*) FROM orders WHERE status IN ('new', 'paid')")->fetchColumn();
  $body .= '<div class="dash"><div class="card"><div class="row-head"><h2>Orders to send</h2><a class="btn line sm" href="' . h(self_url(['tab' => 'orders'])) . '">All orders</a></div>';
  if (!$todo) $body .= '<p class="muted">Nothing waiting. New and paid orders show here until you mark them Delivered.</p>';
  else {
    $body .= '<p class="muted small">' . $nTodo . ' order' . ($nTodo === 1 ? '' : 's') . ' not yet delivered. Open one in Orders to change its status.</p><table class="grid"><tbody>';
    foreach ($todo as $o) $body .= '<tr><td><a href="' . h(self_url(['tab' => 'orders', 'q' => $o['no']])) . '"><b>' . h($o['no']) . '</b></a><small>' . h(date('d M', strtotime($o['created']))) . '</small></td><td>' . h($o['name']) . '<small>' . h($o['method'] === 'cod' ? 'Cash on delivery' : 'Paid online') . '</small></td><td class="r">' . rupees((int)$o['total']) . '</td><td class="r"><span class="badge st-' . h($o['status']) . '">' . h(FOMAXO_STATUSES[$o['status']]) . '</span></td></tr>';
    $body .= '</tbody></table>';
  }
  $body .= '</div>';
  /* stock running low */
  $STOCK = shop_stock(); $low = shop_low_stock(); $alerts = [];
  foreach ($CAT as $id => $p) { if (!empty($p['hidden'])) continue; foreach ($p['prices'] as $opt => $_) { $v = $STOCK[$id][$opt] ?? null; if ($v !== null && $v <= $low) $alerts[] = [$p, (string)$opt, $v]; } }
  usort($alerts, fn($a, $b) => $a[2] <=> $b[2]);
  $body .= '<div class="card"><div class="row-head"><h2>Stock running low</h2><a class="btn line sm" href="' . h(self_url(['tab' => 'stock'])) . '">Update stock</a></div>';
  if (!$alerts) $body .= '<p class="muted">Everything counted has more than ' . $low . ' left.' . ($STOCK ? '' : ' Write your stock on the Stock tab so the website can show “Only 3 left” and “Sold out”.') . '</p>';
  else { $body .= '<table class="grid"><tbody>'; foreach (array_slice($alerts, 0, 10) as [$p, $opt, $v]) $body .= '<tr><td><b>' . h($p['name']) . '</b><small>' . h(opt_label($p, $opt)) . '</small></td><td class="r">' . ($v < 1 ? '<span class="badge st-cancelled">Sold out</span>' : '<span class="badge st-new">' . $v . ' left</span>') . '</td></tr>'; $body .= '</tbody></table>' . (count($alerts) > 10 ? '<p class="muted small">and ' . (count($alerts) - 10) . ' more</p>' : ''); }
  $body .= '</div></div>';
  $body .= '<div class="quick"><a class="btn" href="' . h(self_url(['tab' => 'expenses'])) . '">+ Add an expense</a><a class="btn line" href="' . h(self_url(['tab' => 'products', 'add' => 1])) . '#add">+ Add a product</a><a class="btn line" href="' . h(self_url(['do' => 'excel'])) . '">Download all orders (Excel)</a><a class="btn line" href="' . h(self_url(['do' => 'pnl_excel', 'year' => $year])) . '">Download ' . $year . ' profit &amp; loss (Excel)</a></div>';
}

if ($tab === 'orders') {
  $counts = [];
  foreach (shop_db()->query('SELECT status, COUNT(*) n, SUM(total) t FROM orders GROUP BY status') as $r) $counts[$r['status']] = [(int)$r['n'], (int)$r['t']];
  $page = max(1, (int)($_GET['page'] ?? 1));
  [$where, $args] = order_where($F);
  $cnt = shop_db()->prepare("SELECT COUNT(*) FROM orders$where"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
  $s = shop_db()->prepare("SELECT * FROM orders$where ORDER BY id DESC LIMIT " . ADMIN_PER_PAGE . ' OFFSET ' . (($page - 1) * ADMIN_PER_PAGE)); $s->execute($args);
  $orders = $s->fetchAll();
  $q = array_filter($F + ['tab' => 'orders']);
  $body .= '<div class="stats">';
  foreach (['new', 'paid', 'delivered', 'cancelled'] as $st) $body .= '<a class="stat st-' . $st . ($F['status'] === $st ? ' on' : '') . '" href="' . h(self_url(['tab' => 'orders', 'status' => $st])) . '"><b>' . ($counts[$st][0] ?? 0) . '</b><span>' . FOMAXO_STATUSES[$st] . '</span><small>' . inr_paise($counts[$st][1] ?? 0) . '</small></a>';
  $body .= '</div>';
  $sel = fn($name, $opts, $cur) => "<select name=\"$name\">" . implode('', array_map(fn($k, $v) => '<option value="' . h($k) . '"' . ((string)$k === $cur ? ' selected' : '') . '>' . h($v) . '</option>', array_keys($opts), $opts)) . '</select>';
  $body .= '<form class="filters" method="get"><input type="hidden" name="tab" value="orders">'
    . '<label>Status' . $sel('status', ['' => 'All orders', 'new' => 'New', 'paid' => 'Paid', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'awaiting' => 'Unfinished online payments'], $F['status']) . '</label>'
    . '<label>Payment' . $sel('method', ['' => 'Cash and online', 'cod' => 'Cash on delivery', 'online' => 'Online (Razorpay)'], $F['method']) . '</label>'
    . '<label>From<input type="date" name="from" value="' . h($F['from']) . '"></label><label>To<input type="date" name="to" value="' . h($F['to']) . '"></label>'
    . '<label class="grow">Search<input type="search" name="q" value="' . h($F['q']) . '" placeholder="Order no, name, mobile or email"></label>'
    . '<button class="btn line">Show</button><a class="btn" href="' . h(self_url($q + ['do' => 'excel'])) . '">Download Excel</a></form>';
  $body .= '<p class="muted">' . $total . ' order' . ($total === 1 ? '' : 's') . ($F['status'] === 'awaiting' ? '. These customers opened online payment but did not finish it. They have no order number and took no stock.' : '') . '</p>';
  if (!$orders) $body .= '<div class="card"><p>No orders yet' . (array_filter($F) ? ' for this filter' : '') . '.</p></div>';
  $back = h(json_encode($q + ['page' => $page]));
  foreach ($orders as $o) {
    $rows = array_map(fn($r) => '<li>' . h(ltrim($r, '• ')) . '</li>', array_filter(explode("\n", (string)$o['rows_text'])));
    $body .= '<details class="order os-' . h($o['status']) . '"><summary>'
      . '<span class="no">' . h($o['no'] ?: 'Not paid') . '</span><span class="dt">' . h(date('d M Y, H:i', strtotime($o['created']))) . '</span>'
      . '<span class="cu"><b>' . h($o['name']) . '</b><small>' . h($o['phone']) . '</small></span>'
      . '<span class="tt">' . inr_paise((int)$o['total']) . '</span><span class="pm">' . h(pay_label($o)) . '</span>'
      . '<span class="badge st-' . h($o['status']) . '">' . h(FOMAXO_STATUSES[$o['status']] ?? $o['status']) . '</span></summary>'
      . '<div class="od"><div><h4>Items</h4><ul>' . implode('', $rows) . ($o['cod_fee'] ? '<li>Cash on delivery fee — ' . inr_paise((int)$o['cod_fee']) . '</li>' : '') . '</ul><p><b>Total ' . inr_paise((int)$o['total']) . '</b></p></div>'
      . '<div><h4>Delivery</h4><p>' . h($o['name']) . '<br>' . h($o['address']) . '</p><p><a href="tel:' . h(preg_replace('/[^0-9+]/', '', $o['phone'])) . '">' . h($o['phone']) . '</a> · <a href="https://wa.me/' . h(preg_replace('/\D/', '', $o['phone'])) . '" target="_blank" rel="noopener">WhatsApp</a><br><a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a></p>'
      . ($o['note'] ? '<p class="muted">Customer note: ' . h($o['note']) . '</p>' : '') . '</div>'
      . '<div><h4>Payment</h4><p>' . h(pay_label($o)) . ($o['payment_id'] ? '<br><small>' . h($o['payment_id']) . '</small>' : '') . ($o['paid_at'] ? '<br><small>Paid ' . h(date('d M Y, H:i', strtotime($o['paid_at']))) . '</small>' : '') . '</p>'
      . '<form method="post" class="stform">' . $csrfField . '<input type="hidden" name="action" value="status"><input type="hidden" name="id" value="' . (int)$o['id'] . '"><input type="hidden" name="back" value="' . $back . '">'
      . '<label>Status' . $sel('status', array_diff_key(FOMAXO_STATUSES, $o['status'] === 'awaiting' ? [] : ['awaiting' => 1]), $o['status']) . '</label>'
      . '<label>Your note<input name="admin_note" value="' . h($o['admin_note']) . '" maxlength="500" placeholder="Courier, tracking number…"></label><button class="btn sm">Save</button></form>'
      . '<p class="muted small">Cancelling puts the items back in stock.</p></div></div></details>';
  }
  if ($total > ADMIN_PER_PAGE) {
    $body .= '<div class="pager">';
    for ($i = 1; $i <= (int)ceil($total / ADMIN_PER_PAGE); $i++) $body .= '<a' . ($i === $page ? ' class="on"' : '') . ' href="' . h(self_url($q + ['page' => $i])) . '">' . $i . '</a>';
    $body .= '</div>';
  }
}

if ($tab === 'stock') {
  $STOCK = shop_stock(); $COSTS = shop_costs(); $low = shop_low_stock();
  $body .= '<form method="post" class="card">' . $csrfField . '<input type="hidden" name="action" value="stock"><h2>Stock and cost</h2>'
    . '<p class="muted">Write how many you have of each size. Every order takes its items off by itself, and a cancelled order puts them back. Leave a box empty to not count that size (it never sells out). At 0 the website shows <b>Sold out</b>.</p>'
    . '<label class="inline">Show “Only N left” from <input type="number" name="low_stock" min="0" max="99" value="' . $low . '"> left or fewer</label>'
    . '<p class="muted"><b>Cost per item</b> is what one piece costs you (buying or making it). Profit &amp; loss uses it to work out your profit on each sale.</p>'
    . '<table class="grid"><thead><tr><th>Product</th><th>Size</th><th>In stock</th><th>Cost per item ₹</th><th>On the website</th></tr></thead><tbody>';
  foreach ($CAT as $id => $p) {
    $first = true;
    foreach ($p['prices'] as $opt => $_) {
      $v = $STOCK[$id][$opt] ?? null;
      $state = !empty($p['hidden']) ? '<span class="badge st-cancelled">Hidden</span>' : ($v === null ? ($p['soldOut'] ? '<span class="badge st-cancelled">Out of stock (index.html)</span>' : '<span class="muted">Not counted</span>')
        : ($v < 1 ? '<span class="badge st-cancelled">Sold out</span>' : ($v <= $low ? '<span class="badge st-new">Only ' . $v . ' left</span>' : '<span class="badge st-paid">In stock</span>')));
      $body .= '<tr' . ($first ? ' class="first"' : '') . '><td>' . ($first ? '<b>' . h($p['name']) . '</b><small>' . h(kind_label($p['kind'])) . '</small>' : '') . '</td><td>' . h(opt_label($p, (string)$opt)) . '</td>'
        . '<td><input type="number" min="0" max="99999" name="stock[' . h($id) . '][' . h((string)$opt) . ']" value="' . ($v === null ? '' : $v) . '" placeholder="—"></td>'
        . '<td><input type="number" min="0" step="0.01" name="cost[' . h($id) . '][' . h((string)$opt) . ']" value="' . (isset($COSTS[$id][$opt]) ? h(rtrim(rtrim(number_format($COSTS[$id][$opt] / 100, 2, '.', ''), '0'), '.')) : '') . '" placeholder="—"></td><td>' . $state . '</td></tr>';
      $first = false;
    }
  }
  $body .= '</tbody></table><div class="sticky"><button class="btn">Save stock and costs</button></div></form>';
}

if ($tab === 'products') {
  $body .= '<div class="row-head"><h2>Products</h2><a class="btn" href="' . h(self_url(['tab' => 'products', 'add' => 1])) . '#add">Add a product</a></div>'
    . '<p class="muted">Untick <b>On website</b> to remove a product from the shop (you can bring it back any time). Products you added here can also be deleted. Prices are in rupees; the crossed-out price is optional.</p>';
  foreach ($CAT as $id => $p) {
    $form = '<form method="post" class="prod' . (!empty($p['hidden']) ? ' off' : '') . '">' . $csrfField . '<input type="hidden" name="action" value="product"><input type="hidden" name="id" value="' . h($id) . '">'
      . '<div class="pimg">' . ($p['img'] ? '<img src="/' . h($p['img']) . '" alt="" loading="lazy">' : '') . '</div>'
      . '<div class="pinfo"><b>' . h($p['name']) . '</b><small>' . h(kind_label($p['kind'])) . (!empty($p['added']) ? ' · added here' : '') . '</small>'
      . '<label class="chk"><input type="checkbox" name="show" value="1"' . (empty($p['hidden']) ? ' checked' : '') . '> On website</label></div><div class="pprices">';
    foreach ($p['prices'] as $opt => $v) $form .= '<div><span>' . h(opt_label($p, (string)$opt)) . '</span><label>Price ₹<input type="number" step="0.01" min="1" name="price[' . h((string)$opt) . ']" value="' . h((string)$v) . '" required></label><label>Was ₹<input type="number" step="0.01" min="0" name="was[' . h((string)$opt) . ']" value="' . h(!empty($p['was'][$opt]) ? (string)$p['was'][$opt] : '') . '"></label></div>';
    $form .= '</div><div class="pact"><button class="btn sm">Save</button></form>';
    if (!empty($p['added'])) $form .= '<form method="post" onsubmit="return confirm(\'Delete ' . h(addslashes($p['name'])) . ' for good?\')">' . $csrfField . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . h($id) . '"><button class="btn sm danger">Delete</button></form>';
    $body .= $form . '</div>';
  }
  $open = !empty($_GET['add']);
  $body .= '<details class="card" id="add"' . ($open ? ' open' : '') . '><summary><h2>Add a product</h2></summary><form method="post" enctype="multipart/form-data" class="addf">' . $csrfField . '<input type="hidden" name="action" value="add">'
    . '<label>Category<select name="kind" id="kind"><option value="">Fragrance (perfume)</option><option value="car">Car fragrance</option><option value="care">Personal care</option></select></label>'
    . '<label>Name<input name="name" required maxlength="60" placeholder="e.g. Velvet Oud"></label>'
    . '<label>Type<input name="type" required maxlength="80" placeholder="e.g. Eau de Parfum, or Moisturizing Shampoo"></label>'
    . '<label class="k-frag">Fragrance tier<select name="tier"><option value="">None</option><option value="elite">Elite</option><option value="signature">Signature</option><option value="prestige">Prestige</option></select></label>'
    . '<label class="k-care" hidden>Personal care section<select name="cat"><option value="hair">Hair</option><option value="body">Body</option><option value="face">Face</option><option value="lips">Lips</option></select></label>'
    . '<label class="k-care" hidden>Size shown<input name="vol" maxlength="20" placeholder="e.g. 250ml"></label>'
    . '<label class="wide">Short line<input name="short" maxlength="200" placeholder="One sentence shown under the name"></label>'
    . '<label class="wide">Description<textarea name="description" rows="4" maxlength="3000" placeholder="Leave an empty line between paragraphs"></textarea></label>'
    . '<div class="wide k-frag notes"><label>Top notes<input name="top" maxlength="120"></label><label>Heart notes<input name="heart" maxlength="120"></label><label>Base notes<input name="base" maxlength="120"></label></div>'
    . '<div class="wide sizes"><p class="muted small">Prices and stock. <span class="k-frag">One row per size (ml).</span><span class="k-one" hidden>Fill in the first row only.</span></p>';
  foreach ([0, 1, 2] as $i) $body .= '<div class="srow' . ($i ? ' k-frag' : '') . '"><label class="k-frag">Size (ml)<input type="number" name="size[]" min="1" max="1000"' . ($i === 0 ? ' value="50"' : '') . '></label><label>Price ₹<input type="number" step="0.01" min="1" name="price[]"></label><label>Was ₹ <small>(optional)</small><input type="number" step="0.01" min="0" name="was[]"></label><label>Stock <small>(optional)</small><input type="number" min="0" name="stock[]"></label></div>';
  $body .= '</div><label class="wide">Photos (1 to 4, the first is the main photo)<input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple required></label>'
    . '<label class="chk"><input type="checkbox" name="new_tag" value="1" checked> Show a “New” label</label>'
    . '<div class="wide"><button class="btn">Add product</button></div></form></details>'
    . '<script>(function(){var k=document.getElementById("kind");function u(){var v=k.value;document.querySelectorAll(".k-frag").forEach(function(e){e.hidden=v!==""});document.querySelectorAll(".k-care").forEach(function(e){e.hidden=v!=="care"});document.querySelectorAll(".k-one").forEach(function(e){e.hidden=v===""});}k.onchange=u;u();})();</script>';
}

if ($tab === 'expenses') {
  $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
  $prev = date('Y-m', strtotime("$month-01 -1 month")); $next = date('Y-m', strtotime("$month-01 +1 month"));
  $s = shop_db()->prepare('SELECT * FROM expenses WHERE day >= ? AND day < ? ORDER BY day DESC, id DESC'); $s->execute(["$month-01", "$next-01"]);
  $list = $s->fetchAll(); $total = array_sum(array_column($list, 'amount'));
  $byCat = []; foreach ($list as $x) $byCat[$x['category']] = ($byCat[$x['category']] ?? 0) + (int)$x['amount']; arsort($byCat);
  $today = date('Y-m-d'); $defDay = substr($today, 0, 7) === $month ? $today : "$month-01";
  $body .= '<form method="post" class="card exp-add">' . $csrfField . '<input type="hidden" name="action" value="expense"><h2>Add an expense</h2><div class="exp-row">'
    . '<label>Date<input type="date" name="day" value="' . h($defDay) . '" required></label>'
    . '<label>Category<select name="category">' . implode('', array_map(fn($c) => '<option>' . h($c) . '</option>', EXPENSE_CATEGORIES)) . '</select></label>'
    . '<label class="grow">Details <small>(optional)</small><input name="note" maxlength="200" placeholder="e.g. 200 gift boxes, Instagram ads"></label>'
    . '<label>Amount ₹<input type="number" name="amount" min="0.01" step="0.01" required></label><button class="btn">Add</button></div></form>';
  $body .= '<div class="row-head"><div class="monthnav"><a class="btn line sm" href="' . h(self_url(['tab' => 'expenses', 'month' => $prev])) . '">‹</a><h2>' . h(date('F Y', strtotime("$month-01"))) . '</h2><a class="btn line sm" href="' . h(self_url(['tab' => 'expenses', 'month' => $next])) . '">›</a></div>'
    . '<a class="btn line" href="' . h(self_url(['do' => 'expenses_excel', 'year' => substr($month, 0, 4)])) . '">Download ' . h(substr($month, 0, 4)) . ' expenses (Excel)</a></div>';
  $body .= '<div class="stats two"><div class="stat"><b>' . rupees($total) . '</b><span>Spent this month</span><small>' . count($list) . ' expense' . (count($list) === 1 ? '' : 's') . '</small></div><div class="stat cats">'
    . ($byCat ? implode('', array_map(fn($c, $v) => '<div><span>' . h($c) . '</span><b>' . rupees($v) . '</b></div>', array_keys($byCat), $byCat)) : '<span>No expenses yet this month</span>') . '</div></div>';
  if ($list) {
    $body .= '<table class="grid card-t"><thead><tr><th>Date</th><th>Category</th><th>Details</th><th class="r">Amount</th><th></th></tr></thead><tbody>';
    foreach ($list as $x) $body .= '<tr><td>' . h(date('d M', strtotime($x['day']))) . '</td><td>' . h($x['category']) . '</td><td>' . h($x['note']) . '</td><td class="r">' . rupees((int)$x['amount']) . '</td>'
      . '<td class="r"><form method="post" onsubmit="return confirm(\'Delete this expense?\')">' . $csrfField . '<input type="hidden" name="action" value="expense_delete"><input type="hidden" name="id" value="' . (int)$x['id'] . '"><input type="hidden" name="month" value="' . h($month) . '"><button class="linkbtn">Delete</button></form></td></tr>';
    $body .= '</tbody></table>';
  }
}

if ($tab === 'pnl') {
  $rows = pnl_year($pyear); $t = pnl_sum($rows); $cur = date('Y-m');
  $money = fn($p) => '<span class="' . ($p < 0 ? 'neg' : '') . '">' . ($p < 0 ? '−' . rupees(-$p) : rupees($p)) . '</span>';
  $body .= '<div class="row-head"><div class="monthnav"><h2>Profit &amp; loss</h2><form method="get"><input type="hidden" name="tab" value="pnl"><select name="year" onchange="this.form.submit()">'
    . implode('', array_map(fn($y) => '<option' . ($y === $pyear ? ' selected' : '') . '>' . $y . '</option>', pnl_years())) . '</select></form></div>'
    . '<a class="btn line" href="' . h(self_url(['do' => 'pnl_excel', 'year' => $pyear])) . '">Download ' . $pyear . ' (Excel)</a></div>';
  $body .= '<div class="stats">'
    . '<div class="stat"><b>' . rupees($t['sales']) . '</b><span>Sales ' . $pyear . '</span><small>' . $t['orders'] . ' orders</small></div>'
    . '<div class="stat"><b>' . rupees($t['cost']) . '</b><span>Product cost</span><small>Gross profit ' . strip_tags($money($t['gross'])) . '</small></div>'
    . '<div class="stat"><b>' . rupees($t['expenses']) . '</b><span>Expenses</span><small><a href="' . h(self_url(['tab' => 'expenses'])) . '">Add expenses</a></small></div>'
    . '<div class="stat ' . ($t['net'] < 0 ? 'loss' : 'profit') . '"><b>' . strip_tags($money($t['net'])) . '</b><span>' . ($t['net'] < 0 ? 'Net loss' : 'Net profit') . '</span><small>' . ($t['sales'] ? round($t['net'] / $t['sales'] * 100) . '% of sales' : '—') . '</small></div></div>';
  $body .= '<table class="grid card-t pnl"><thead><tr><th>Month</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">Product cost</th><th class="r">Gross profit</th><th class="r">Expenses</th><th class="r">Net profit / loss</th></tr></thead><tbody>';
  foreach ($rows as $k => $r) {
    $future = $k > $cur;
    $body .= '<tr' . ($future ? ' class="future"' : '') . '><td>' . h(date('F', strtotime("$k-01"))) . '</td><td class="r">' . $r['orders'] . '</td><td class="r">' . rupees($r['sales']) . '</td><td class="r">' . rupees($r['cost']) . ($r['nocost'] ? ' <small class="warn" title="Items sold with no cost per item set">+' . $r['nocost'] . ' items with no cost</small>' : '') . '</td>'
      . '<td class="r">' . $money($r['gross']) . '</td><td class="r">' . rupees($r['expenses']) . '</td><td class="r"><b>' . $money($r['net']) . '</b></td></tr>';
  }
  $body .= '</tbody><tfoot><tr><td>Total ' . $pyear . '</td><td class="r">' . $t['orders'] . '</td><td class="r">' . rupees($t['sales']) . '</td><td class="r">' . rupees($t['cost']) . '</td><td class="r">' . $money($t['gross']) . '</td><td class="r">' . rupees($t['expenses']) . '</td><td class="r"><b>' . $money($t['net']) . '</b></td></tr></tfoot></table>';
  /* every year side by side */
  $body .= '<h2 style="margin-top:28px">By year</h2><table class="grid card-t pnl"><thead><tr><th>Year</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">Product cost</th><th class="r">Expenses</th><th class="r">Net profit / loss</th></tr></thead><tbody>';
  foreach (pnl_years() as $y) { $yt = pnl_sum(pnl_year($y)); $body .= '<tr><td><a href="' . h(self_url(['tab' => 'pnl', 'year' => $y])) . '">' . $y . '</a></td><td class="r">' . $yt['orders'] . '</td><td class="r">' . rupees($yt['sales']) . '</td><td class="r">' . rupees($yt['cost']) . '</td><td class="r">' . rupees($yt['expenses']) . '</td><td class="r"><b>' . $money($yt['net']) . '</b></td></tr>'; }
  $body .= '</tbody></table>';
  $body .= '<p class="muted small">Sales are orders marked New, Paid or Delivered, by order date, including the cash on delivery fee. Cancelled orders, unfinished online payments and Razorpay test orders are left out. Product cost uses the <a href="' . h(self_url(['tab' => 'stock'])) . '">cost per item</a> on the Stock tab. Add Razorpay fees, courier bills and other running costs under Expenses.' . ($t['nocost'] ? ' <b class="warn">' . $t['nocost'] . ' items sold this year have no cost per item set, so profit looks higher than it is.</b>' : '') . '</p>';
}

if ($tab === 'settings') {
  $body .= '<form method="post" class="card narrow" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="password"><h2>Change your password</h2>'
    . '<label>Current password<input type="password" name="current" required autocomplete="current-password"></label>'
    . '<label>New password <small>(at least 8 characters)</small><input type="password" name="new" required minlength="8" autocomplete="new-password"></label>'
    . '<label>New password again<input type="password" name="again" required minlength="8" autocomplete="new-password"></label><button class="btn">Change password</button>'
    . '<p class="muted small">Forgot it? Create admin-password.txt in public_html/api/data with a new password and open this page.</p></form>';
  if (shop_is_mysql()) $body .= '<div class="card narrow"><h2>Database</h2><p class="muted">Orders, stock and products are saved in your Hostinger MySQL database.</p></div>';
  else $body .= '<form method="post" class="card narrow" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="mysql"><h2>Database</h2>'
    . '<p class="muted">Orders, stock and products are saved on Hostinger in a private file (shop.sqlite). To keep them in your Hostinger MySQL database instead:</p>'
    . '<ol class="steps small"><li>In Hostinger, open <b>Websites → fomaxo.in → Databases → Management</b>.</li><li>Next to <b>u934663824_fomaxoin</b>, choose <b>Change password</b> and set a new one.</li><li>Type that password below and press Connect. Everything saved so far is copied across.</li></ol>'
    . '<label>Database name<input name="db_name" value="u934663824_fomaxoin" required></label><label>Database user<input name="db_user" value="u934663824_fomaxoin" required></label>'
    . '<label>Database password<input type="password" name="db_pass" required autocomplete="new-password"></label><button class="btn">Connect</button></form>';
}

page($tabs[$tab], $body, true);

function page(string $title, string $body, bool $in): void {
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="robots" content="noindex,nofollow">'
    . '<title>' . h($title) . ' · FOMAXO admin</title><style>' . ADMIN_CSS . '</style></head><body><header><a class="brand" href="/admin/">FOMAXO <span>Admin</span></a><a class="site" href="/" target="_blank" rel="noopener">View website ↗</a></header><main' . ($in ? '' : ' class="center"') . '>' . $body . '</main></body></html>';
  exit;
}
