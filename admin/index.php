<?php
declare(strict_types=1);
/* FOMAXO India — private admin page: fomaxo.in/admin
   Home, Products, Stock, Orders (FMX-IN-1001 …), Coupons, Offer (the limited-time offer), Analytics, Expenses, Reports (profit & loss), Members, Reviews and Settings. Everything is kept in the
   shop database (api/shop-db.php). Helpers are in admin/lib.php; styles in admin.css, charts and phone switches in admin.js.

   Password: create  public_html/api/data/admin-password.txt  in Hostinger File Manager with your password as its only
   line, then open fomaxo.in/admin. The page stores a scrambled copy (a hash) and deletes the file. A forgotten password
   can also be reset from the sign-in page: the link goes to the order notification email set in Settings. */
require dirname(__DIR__) . '/api/store-lib.php';
require dirname(__DIR__) . '/api/geo-lib.php';
require __DIR__ . '/lib.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
header('X-Frame-Options: DENY');
header('Referrer-Policy: same-origin');
header('X-Content-Type-Options: nosniff');

const ADMIN_PER_PAGE = 100;
const ASSET_V = '51';
const EXPENSE_CATEGORIES = ['Stock purchase', 'Packaging', 'Delivery & courier', 'Ads & marketing', 'Payment gateway fees', 'Rent', 'Salaries', 'Website & software', 'Travel', 'Other'];

$https = ($_SERVER['HTTPS'] ?? '') !== '' && $_SERVER['HTTPS'] !== 'off' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
session_name('fomaxo_admin');
session_set_cookie_params(['lifetime' => 0, 'path' => '/admin', 'secure' => $https, 'httponly' => true, 'samesite' => 'Strict']);
session_start();
if (empty($_SESSION['csrf'])) $_SESSION['csrf'] = bin2hex(random_bytes(16));
$CSRF = $_SESSION['csrf'];
$csrfField = '<input type="hidden" name="csrf" value="' . h($CSRF) . '">';

function page(string $title, string $body, bool $in, string $tab = '', array $tabs = []): void {
  $nav = fn($cls) => '<nav class="' . $cls . '">' . implode('', array_map(fn($k, $v) => '<a href="' . h(self_url($k === 'home' ? [] : ['tab' => $k])) . '"' . ($k === $tab ? ' class="on"' : '') . ">$v</a>", array_keys($tabs), $tabs)) . '</nav>';
  echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover"><meta name="robots" content="noindex,nofollow">'
    . '<title>' . h($title) . ' · FOMAXO admin</title><link rel="preconnect" href="https://fonts.googleapis.com"><link rel="preconnect" href="https://fonts.gstatic.com" crossorigin><link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Cinzel:wght@500;600;700&amp;family=Jost:wght@400;500;700&amp;family=Manrope:wght@400;500;600;700&amp;display=swap"><link rel="stylesheet" href="/admin/admin.css?v=' . ASSET_V . '"></head><body' . ($in ? ' class="app"' : '') . '>'
    . '<header><a class="brand" href="/admin/">FOMAXO <span>Admin</span></a>' . ($in ? $nav('tabs') : '<span class="sp"></span>')
    . '<span class="hlinks"><a class="site" href="/" target="_blank" rel="noopener"><span class="full">View website ↗</span><span class="short">Website ↗</span></a>' . ($in ? '<a href="' . h(self_url(['do' => 'logout'])) . '">Sign out</a>' : '') . '</span></header>'
    . ($in ? '<div class="mbar"><button type="button" class="tprev" aria-label="Previous tab">‹</button>' . $nav('mtabs') . '<button type="button" class="tnext" aria-label="Next tab">›</button></div>' : '') . '<main' . ($in ? '' : ' class="center"') . '>' . $body . '</main>'
    . ($in ? '<script src="/admin/admin.js?v=' . ASSET_V . '"></script>' : '') . '</body></html>';
  exit;
}

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
  else { shop_set('admin_hash', password_hash($pw, PASSWORD_DEFAULT)); @unlink($drop); session_regenerate_id(true); $_SESSION = ['csrf' => $CSRF]; $setupMsg = 'ok'; }
}
$hash = shop_setting('admin_hash');
if (!$hash) {
  page('Set your password', '<div class="card narrow"><h2>Set your admin password</h2>' . ($setupMsg && $setupMsg !== 'ok' ? '<p class="err">' . h($setupMsg) . '</p>' : '') . '
    <ol class="steps"><li>Open <b>Hostinger → Websites → fomaxo.in → File Manager</b>.</li>
    <li>Go to <b>public_html/api/data</b>.</li>
    <li>Create a new file named <b>admin-password.txt</b>.</li>
    <li>Type your password in it (at least 8 characters) and save.</li>
    <li>Reload this page. It saves your password safely and deletes the file.</li></ol></div>', false);
}

/* ---------------- sign in, and password reset by email ---------------- */
$ipKey = substr(hash('sha256', $_SERVER['REMOTE_ADDR'] ?? ''), 0, 16);
function fails(string $ipKey, string $key = 'login_fails'): array { $all = json_decode((string)shop_setting($key), true) ?: []; return array_values(array_filter((array)($all[$ipKey] ?? []), fn($t) => $t > time() - 900)); }
function set_fails(string $ipKey, array $list, string $key = 'login_fails'): void { $all = json_decode((string)shop_setting($key), true) ?: []; $all = array_filter($all, fn($v) => max((array)$v ?: [0]) > time() - 3600); if ($list) $all[$ipKey] = $list; else unset($all[$ipKey]); shop_set($key, json_encode($all)); }
function mask_email(string $e): string { [$u, $d] = array_pad(explode('@', $e, 2), 2, ''); return mb_substr($u, 0, 2) . str_repeat('•', max(2, mb_strlen($u) - 2)) . '@' . $d; }
function site_base(): string {
  $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
  if (preg_match('/^(www\.)?fomaxo\.in$/', $host)) return 'https://' . $host;
  if (preg_match('/^(127\.0\.0\.1|localhost)(:\d+)?$/', $host)) return 'http://' . $host;   // testing on this computer
  return 'https://fomaxo.in';
}
$reset = json_decode((string)shop_setting('reset'), true) ?: [];
$resetOk = fn(string $tok) => $tok !== '' && !empty($reset['hash']) && ($reset['until'] ?? 0) > time() && hash_equals($reset['hash'], hash('sha256', $tok));

if (($_GET['do'] ?? '') === 'logout') { $_SESSION = []; session_destroy(); go(); }
if (empty($_SESSION['ok']) || ($_SESSION['hash'] ?? '') !== substr($hash, -12) || ($_SESSION['seen'] ?? 0) < time() - 8 * 3600) {
  $err = ''; $msg = $_SESSION['flash'] ?? ''; unset($_SESSION['flash']);
  $post = $_SERVER['REQUEST_METHOD'] === 'POST' && hash_equals($CSRF, (string)($_POST['csrf'] ?? ''));
  /* reset link from the email */
  $tok = (string)($_GET['reset'] ?? $_POST['reset'] ?? '');
  if ($tok !== '') {
    if (!$resetOk($tok)) page('Reset password', '<div class="card narrow"><h2>This link has expired</h2><p class="muted">Reset links work once, for 30 minutes. Please ask for a new one.</p><a class="btn" href="/admin/?forgot=1">Email me a new link</a></div>', false);
    if ($post) {
      $new = (string)($_POST['new'] ?? '');
      if (mb_strlen($new) < 8) $err = 'The new password needs at least 8 characters.';
      elseif ($new !== (string)($_POST['again'] ?? '')) $err = 'The two passwords are different.';
      else { shop_set('admin_hash', password_hash($new, PASSWORD_DEFAULT)); shop_set('reset', '{}'); set_fails($ipKey, []); $_SESSION['flash'] = 'Your new password is saved. Sign in with it below.'; go(); }
    }
    page('Reset password', '<form class="card narrow login" method="post" autocomplete="off">' . $csrfField . '<input type="hidden" name="reset" value="' . h($tok) . '"><h2>Choose a new password</h2>'
      . ($err ? '<p class="err">' . h($err) . '</p>' : '')
      . '<label>New password <small>(at least 8 characters)</small><input type="password" name="new" required minlength="8" autocomplete="new-password" autofocus></label>'
      . '<label>New password again<input type="password" name="again" required minlength="8" autocomplete="new-password"></label><button class="btn">Save new password</button></form>', false);
  }
  $to = fomaxo_store_email();
  if (isset($_GET['forgot'])) {
    if ($post) {
      $sent = fails($ipKey, 'reset_sent');
      if (count($sent) >= 3) $err = 'A reset link was already sent a few times. Please check your email, or wait 15 minutes.';
      else {
        $t = bin2hex(random_bytes(24)); shop_set('reset', json_encode(['hash' => hash('sha256', $t), 'until' => time() + 1800]));
        $link = site_base() . '/admin/?reset=' . $t;
        fomaxo_mail($to, '=?UTF-8?B?' . base64_encode('Reset your FOMAXO admin password') . '?=',
          "Someone (hopefully you) asked to reset the password for the FOMAXO admin page.\n\nOpen this link within 30 minutes to choose a new password:\n$link\n\nIf you did not ask for this, you can ignore this email. Your password stays the same.\n\nFOMAXO",
          'From: FOMAXO <orders@fomaxo.in>' . "\r\nContent-Type: text/plain; charset=UTF-8");
        $sent[] = time(); set_fails($ipKey, $sent, 'reset_sent');
        $_SESSION['flash'] = 'A reset link is on its way to ' . mask_email($to) . '. It works for 30 minutes.'; go();
      }
    }
    page('Forgot password', '<form class="card narrow login" method="post">' . $csrfField . '<h2>Forgot your password?</h2>' . ($err ? '<p class="err">' . h($err) . '</p>' : '')
      . '<p class="muted">We will email a reset link to <b>' . h(mask_email($to)) . '</b>, the order notification email in Settings.</p><button class="btn">Email me a reset link</button>'
      . '<p class="small"><a href="/admin/">Back to sign in</a></p></form>', false);
  }
  if ($post && isset($_POST['password'])) {
    $f = fails($ipKey);
    if (count($f) >= 5) $err = 'Too many tries. Please wait 15 minutes, or reset your password by email.';
    elseif (password_verify((string)$_POST['password'], $hash)) {
      set_fails($ipKey, []); session_regenerate_id(true);
      $_SESSION = ['ok' => 1, 'hash' => substr($hash, -12), 'seen' => time(), 'csrf' => bin2hex(random_bytes(16))];
      go();
    } else { $f[] = time(); set_fails($ipKey, $f); $err = 'That password is not right.'; }
  }
  page('Sign in', '<form class="card narrow login" method="post" autocomplete="off">' . $csrfField . '<h2>FOMAXO admin</h2>'
    . ($setupMsg === 'ok' ? '<p class="ok">Your password is saved and the file was deleted. Sign in below.</p>' : '')
    . ($msg ? '<p class="ok">' . h($msg) . '</p>' : '') . ($err ? '<p class="err">' . h($err) . '</p>' : '')
    . '<label>Password<input type="password" name="password" autofocus required autocomplete="current-password"></label><button class="btn">Sign in</button>'
    . '<p class="small"><a href="/admin/?forgot=1">Forgot password?</a></p></form>', false);
}
$_SESSION['seen'] = time();
/* this browser is the owner's: analytics leave its visits to the shop out */
setcookie('fx_owner', fomaxo_owner_token(), ['expires' => time() + 400 * 86400, 'path' => '/', 'secure' => $https, 'httponly' => true, 'samesite' => 'Lax']);

/* ---------------- the product list (database, falling back to index.html) ---------------- */
$CAT = fomaxo_catalog()['products'];
$LIVE = shop_products();

/* ---------------- actions ---------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  if (!hash_equals($CSRF, (string)($_POST['csrf'] ?? ''))) go([], '!Your session expired. Please try again.');
  $a = (string)($_POST['action'] ?? ''); $back = json_decode((string)($_POST['back'] ?? '[]'), true) ?: [];
  $back = array_intersect_key($back, array_flip(['tab', 'status', 'method', 'q', 'from', 'to', 'state', 'page']));

  if ($a === 'status') {
    $oid = (int)($_POST['id'] ?? 0); $ns = (string)($_POST['status'] ?? '');
    if ($ns === 'undelivered') {   // back to Pending: a cash order is unpaid again unless it was marked paid before delivery
      $s = shop_db()->prepare('SELECT * FROM orders WHERE id = ?'); $s->execute([$oid]); $o = $s->fetch();
      if (!$o || $o['status'] !== 'delivered') go($back, '!' . ($o['no'] ?? 'That order') . ' is not delivered, so nothing was changed.');
      $ns = $o['method'] === 'cod' && $o['paid_at'] === $o['delivered_at'] ? 'new' : 'paid';
    }
    shop_set_status($oid, $ns, mb_substr(trim((string)($_POST['admin_note'] ?? '')), 0, 500));
    $s = shop_db()->prepare('SELECT * FROM orders WHERE id = ?'); $s->execute([$oid]); $o = $s->fetch();
    go($back, $o ? ($o['no'] ?: 'The order') . ' is ' . (in_array($o['status'], ['new', 'paid'], true) ? 'Pending · ' . (shop_is_paid($o) ? 'Paid' : 'Unpaid') : FOMAXO_STATUSES[$o['status']] ?? $o['status']) . (in_array($o['status'], ['cancelled', 'refunded'], true) ? '. Its items are back in stock.' : '. Saved.') : 'Order updated.');
  }
  if ($a === 'quick') {   // one-tap order buttons: back to the same filtered list
    [$act, $oid] = array_pad(explode(':', (string)($_POST['q'] ?? ''), 2), 2, '0');
    go($back, shop_order_action((int)$oid, $act));
  }
  if ($a === 'stock') {
    foreach ((array)($_POST['stock'] ?? []) as $id => $opts) {
      if (!isset($CAT[$id])) continue;
      foreach ((array)$opts as $opt => $v) { if (!isset($CAT[$id]['prices'][$opt])) continue; $v = trim((string)$v); shop_set_stock((string)$id, (string)$opt, $v === '' ? null : max(0, (int)$v)); }
    }
    foreach ((array)($_POST['cost'] ?? []) as $id => $opts) {
      if (!isset($CAT[$id])) continue;
      foreach ((array)$opts as $opt => $v) { if (!isset($CAT[$id]['prices'][$opt])) continue; $v = trim((string)$v); shop_set_cost((string)$id, (string)$opt, $v === '' || !is_numeric($v) ? null : (int)round((float)$v * 100)); }
    }
    if (isset($_POST['low_stock'])) shop_set('low_stock', (string)max(0, min(99, (int)$_POST['low_stock'])));
    go(['tab' => 'stock'], 'Stock saved.');
  }
  if ($a === 'member_min') { shop_set('member_min', (string)max(1, min(999, (int)($_POST['member_min'] ?? 5)))); go(['tab' => 'members'], 'Members now need ' . member_min() . ' or more orders.'); }
  if ($a === 'member_spend') {
    $v = trim((string)($_POST['member_spend'] ?? '')); shop_set('member_spend', (string)max(0, min(10000000, (int)$v)));
    go(['tab' => 'members'], member_spend() ? 'Customers who spent ₹' . number_format(member_spend()) . ' or more are members too.' : 'The amount filter is off.');
  }
  if ($a === 'review') {
    $st = (string)($_POST['status'] ?? ''); review_set((int)($_POST['id'] ?? 0), $st);
    go(['tab' => 'reviews'] + array_intersect_key($back, array_flip(['q', 'v'])), $st === 'hidden' ? 'Review hidden from the website.' : 'Review is on the website.');
  }
  if ($a === 'review_delete') { review_delete((int)($_POST['id'] ?? 0)); go(['tab' => 'reviews'] + array_intersect_key($back, array_flip(['q', 'v'])), 'Review deleted.'); }
  if ($a === 'review_reply') {
    $txt = isset($_POST['delete']) ? '' : (string)($_POST['reply'] ?? ''); review_reply((int)($_POST['id'] ?? 0), $txt);
    go(['tab' => 'reviews'] + array_intersect_key($back, array_flip(['q', 'v'])), trim($txt) === '' ? 'Reply removed from the website.' : 'Reply is on the website under the review.');
  }
  if ($a === 'top_reviewers') { shop_set('top_reviewers', (string)max(1, min(99, (int)($_POST['top_reviewers'] ?? 2)))); go(['tab' => 'reviews'] + array_intersect_key($back, array_flip(['q', 'v'])), 'Top reviewers now need ' . top_reviewers_min() . ' or more reviews.'); }
  if ($a === 'low_stock') { shop_set('low_stock', (string)max(0, min(99, (int)($_POST['low_stock'] ?? 5)))); go(['tab' => 'stock'], (int)$_POST['low_stock'] ? 'The shop shows “Only X left” from ' . (int)$_POST['low_stock'] . ' left.' : '“Only X left” is turned off.'); }
  if ($a === 'show') {
    $id = (string)($_POST['id'] ?? ''); $d = $LIVE[$id] ?? null;
    if (!isset($CAT[$id])) go(['tab' => 'products'], 'That product was not found.');
    $d = $d ?? ['added' => false, 'hidden' => false, 'sort' => 0, 'prices' => $CAT[$id]['prices'], 'compareAt' => $CAT[$id]['was']];
    $hidden = empty($_POST['show']);
    shop_save_product($id, $d['added'], $hidden, array_diff_key($d, array_flip(['added', 'hidden', 'sort'])), $d['sort']);
    go(['tab' => 'products'], $CAT[$id]['name'] . ($hidden ? ' is hidden from the website.' : ' is on the website.'));
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
  if ($a === 'edit') { $id = (string)($_POST['id'] ?? ''); $msg = edit_product($id, $CAT, $LIVE); go(['tab' => 'products'] + ($msg === '' ? [] : ['edit' => $id]), $msg === '' ? trim((string)($_POST['name'] ?? 'The product')) . ' is saved.' : '!' . $msg); }
  if ($a === 'add') { $msg = add_product($CAT); go(['tab' => 'products'] + ($msg === '' ? [] : ['add' => 1]), $msg === '' ? 'Product added.' : '!' . $msg); }
  if ($a === 'expense') {
    $day = parse_day($_POST['day'] ?? ''); $amt = (string)($_POST['amount'] ?? ''); $cat = (string)($_POST['category'] ?? '');
    $back = ['tab' => 'expenses'];
    if ($day === '') go($back, '!Please type the date of the expense as dd/mm/yyyy.');
    if (!in_array($cat, EXPENSE_CATEGORIES, true)) go($back, '!Please choose a category.');
    if (!is_numeric($amt) || $amt <= 0) go($back, '!Please write the amount in rupees.');
    shop_db()->prepare('INSERT INTO expenses(day, category, note, amount, created) VALUES(?,?,?,?,?)')
      ->execute([$day, $cat, mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST['note'] ?? ''))), 0, 200), (int)round((float)$amt * 100), shop_now()]);
    go($back, 'Expense added: ' . rupees((int)round((float)$amt * 100)) . ' on ' . date('d M Y', strtotime($day)) . '.');
  }
  if ($a === 'coupon_save') { $msg = save_coupon(); $code = coupon_clean((string)($_POST['code'] ?? '')); go(['tab' => 'coupons'] + ($msg[0] === '!' && !empty($_POST['editing']) ? ['edit' => $code] : []), $msg); }
  if ($a === 'coupon_on') {
    $code = coupon_clean((string)($_POST['code'] ?? '')); $on = ($_POST['on'] ?? '') === '1';
    shop_db()->prepare('UPDATE coupons SET active = ? WHERE code = ?')->execute([$on ? 1 : 0, $code]);
    go(['tab' => 'coupons'], $on ? "$code is on. Shoppers can use it at checkout." : "$code is off. It no longer works at checkout.");
  }
  if ($a === 'coupon_delete') {
    $code = coupon_clean((string)($_POST['code'] ?? ''));
    shop_db()->prepare('DELETE FROM coupons WHERE code = ?')->execute([$code]);
    go(['tab' => 'coupons'], "$code is deleted. Orders that used it still show the code.");
  }
  if ($a === 'offer_save') { $msg = save_offer(); go(['tab' => 'offer'], $msg); }
  if ($a === 'newprod_save') { go(['tab' => 'offer'], save_newprod($CAT)); }
  if ($a === 'offer_off') { shop_set('offer', json_encode(['mode' => 'off'] + array_diff_key(shop_offer(), ['mode' => 1]))); go(['tab' => 'offer'], 'The offer is off. Nothing shows on the website.'); }
  if ($a === 'lead_remove') {   // Left at checkout ✕: hide that line from the list (admin.js sends it without reloading the page)
    shop_db()->prepare('UPDATE leads SET removed = 1 WHERE sid = ?')->execute([(string)($_POST['sid'] ?? '')]);
    if (!empty($_POST['js'])) { header('Content-Type: application/json'); echo '{"ok":true}'; exit; }
    go(['tab' => 'analytics'], 'Removed from Left at checkout.');
  }
  if ($a === 'expense_delete') {
    shop_db()->prepare('DELETE FROM expenses WHERE id = ?')->execute([(int)($_POST['id'] ?? 0)]);
    go(['tab' => 'expenses'], 'Expense deleted.');
  }
  if ($a === 'settings') {
    $email = trim((string)($_POST['notify_email'] ?? ''));
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) go(['tab' => 'settings'], '!Please write a valid email address.');
    shop_set('notify_email', $email);
    $fee = trim((string)($_POST['pay_fee'] ?? '2')); if (is_numeric($fee)) shop_set('pay_fee', (string)max(0, min(10, round((float)$fee, 2))));
    go(['tab' => 'settings'], 'Settings saved.');
  }
  if ($a === 'cod') {
    $num = fn(string $k) => trim((string)($_POST[$k] ?? ''));
    $min = $num('cod_min'); $max = $num('cod_max'); $fee = $num('cod_fee');
    $back = ['tab' => 'settings', 'pane' => 'cod'];
    if (!preg_match('/^\d{1,7}$/', $min)) go($back, '!Please write the minimum order in whole rupees, for example 1000.');
    if ($max !== '' && !preg_match('/^\d{1,7}$/', $max)) go($back, '!Please write the maximum order in whole rupees, or leave it empty for no limit.');
    if (!preg_match('/^\d{1,5}$/', $fee)) go($back, '!Please write the cash on delivery fee in whole rupees. 0 means no fee.');
    $min = (int)$min; $max = (int)$max; $fee = (int)$fee;
    if ($max > 0 && $max <= $min) go($back, '!The maximum must be more than the minimum (₹' . number_format($min) . ').');
    shop_set('cod', json_encode(['min' => $min, 'max' => $max, 'fee' => $fee]));
    go($back, 'Saved. Cash on delivery from ₹' . number_format($min) . ($max ? ', under ₹' . number_format($max) : '') . ($fee ? ', fee ₹' . number_format($fee) : ', no fee') . '.');
  }
  if ($a === 'ads') {
    $ads = []; $names = ['meta' => 'Meta Pixel ID', 'tiktok' => 'TikTok Pixel ID', 'ga4' => 'Google Analytics ID', 'gads' => 'Google Ads ID', 'gadsLabel' => 'Purchase conversion label'];
    foreach (SHOP_ADS as $k => $re) {
      $v = preg_replace('/\s+/', '', (string)($_POST[$k] ?? '')) ?? '';
      if ($k !== 'gadsLabel') $v = strtoupper($v);
      if ($v !== '' && !preg_match($re, $v)) go(['tab' => 'settings', 'pane' => 'ads'], '!The ' . $names[$k] . ' doesn’t look right. Please copy it again, or leave the box empty.');
      $ads[$k] = $v;
    }
    shop_set('ads', json_encode($ads));
    $on = array_keys(array_filter(['Meta' => $ads['meta'], 'TikTok' => $ads['tiktok'], 'Google' => $ads['ga4'] . $ads['gads']]));
    go(['tab' => 'settings', 'pane' => 'ads'], $on ? 'Saved. ' . implode(', ', $on) . ' tracking is now on the website.' : 'Saved. No ad tracking is on the website.');
  }
  if (in_array($a, ['store_add', 'store_save', 'store_remove', 'stores_show'], true)) {
    $loc = stores_all(); $i = (int)($_POST['i'] ?? -1);
    if ($a === 'stores_show') $loc['show'] = ($_POST['show'] ?? '') === '1';
    elseif ($a === 'store_remove') { if (!isset($loc['stores'][$i])) go(['tab' => 'stores']); array_splice($loc['stores'], $i, 1); }
    else {
      $f = fn(string $k, int $n) => mb_substr(trim(preg_replace('/\s+/u', ' ', (string)($_POST[$k] ?? ''))), 0, $n);
      $link = trim((string)($_POST['link'] ?? ''));
      if ($link !== '' && !preg_match('~^https://(www\.|maps\.)?(google\.[a-z.]+/maps|maps\.google\.[a-z.]+|maps\.app\.goo\.gl|goo\.gl/maps)~i', $link))
        go(['tab' => 'stores'], '!Please paste a Google Maps link (it starts with https://maps.app.goo.gl or https://www.google.com/maps), or leave it empty.');
      $one = ['name' => $f('name', 80) ?: 'FOMAXO Store', 'address' => $f('address', 300), 'hours' => $f('hours', 120), 'link' => mb_substr($link, 0, 600)];
      if ($one['address'] === '' && $one['link'] === '') go(['tab' => 'stores'], '!Please paste the store’s Google Maps link or write its address.');
      if ($a === 'store_add') { if (count($loc['stores']) >= 20) go(['tab' => 'stores'], '!You can show up to 20 stores.'); $loc['stores'][] = $one; }
      elseif (isset($loc['stores'][$i])) $loc['stores'][$i] = $one;
    }
    shop_set('store_loc', json_encode($loc, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    go(['tab' => 'stores'], ['store_add' => 'Store added. It now shows on the Contact page.', 'store_save' => 'Store saved.', 'store_remove' => 'Store removed.', 'stores_show' => $loc['show'] ? 'Stores now show on the Contact page.' : 'Stores are now hidden on the Contact page.'][$a]);
  }
  if ($a === 'mysql') {
    $err = shop_move_to_mysql(trim((string)($_POST['db_name'] ?? '')), trim((string)($_POST['db_user'] ?? '')), (string)($_POST['db_pass'] ?? ''));
    go(['tab' => 'settings'], $err === '' ? 'Connected. Everything is now saved in your Hostinger MySQL database.' : '!' . $err);
  }
  if ($a === 'password') {
    $new = (string)($_POST['new'] ?? '');
    if (!password_verify((string)($_POST['current'] ?? ''), $hash)) go(['tab' => 'settings'], '!Your current password is not right.');
    if (mb_strlen($new) < 8) go(['tab' => 'settings'], '!The new password needs at least 8 characters.');
    if ($new !== (string)($_POST['again'] ?? '')) go(['tab' => 'settings'], '!The two new passwords are different.');
    $nh = password_hash($new, PASSWORD_DEFAULT); shop_set('admin_hash', $nh); $_SESSION['hash'] = substr($nh, -12);
    go(['tab' => 'settings'], 'Password changed.');
  }
  go();
}

/* ---------------- downloads ---------------- */
$tab = in_array($_GET['tab'] ?? '', ['products', 'stock', 'orders', 'coupons', 'offer', 'analytics', 'expenses', 'reports', 'members', 'reviews', 'stores', 'settings'], true) ? $_GET['tab'] : 'home';
$F = ['status' => (string)($_GET['status'] ?? ''), 'method' => (string)($_GET['method'] ?? ''), 'q' => trim((string)($_GET['q'] ?? '')),
      'from' => parse_day($_GET['from'] ?? ''), 'to' => parse_day($_GET['to'] ?? ''), 'state' => in_array($_GET['state'] ?? '', FOMAXO_STATES, true) ? $_GET['state'] : ''];
$pyear = (int)($_GET['year'] ?? date('Y')); if ($pyear < 2000 || $pyear > 2100) $pyear = (int)date('Y');
$do = (string)($_GET['do'] ?? '');
if ($do === 'excel') {
  [$where, $args] = order_where($F);
  $s = shop_db()->prepare("SELECT * FROM orders$where ORDER BY id"); $s->execute($args);
  $head = ['Order no', 'Date', 'Status', 'Payment type', 'Razorpay payment ID', 'Items', 'Units', 'Subtotal (₹)', 'Coupon', 'Coupon discount (₹)', 'COD fee (₹)', 'Total (₹)',
    'Customer', 'Mobile', 'WhatsApp offers', 'Email', 'Address', 'City', 'State', 'PIN code', 'Customer note', 'Your note', 'Paid at'];
  $rows = [];
  foreach ($s as $o) {
    $items = json_decode((string)$o['items'], true) ?: [];
    $rows[] = [$o['no'] ?: '(not paid)', substr($o['created'], 0, 16), FOMAXO_STATUSES[$o['status']] ?? $o['status'], pay_label($o), $o['payment_id'],
      implode("\n", array_map(fn($r) => ltrim($r, '• '), explode("\n", (string)$o['rows_text']))), array_sum(array_map(fn($i) => (int)($i['qty'] ?? 0), $items)),
      ($o['total'] - $o['cod_fee'] + $o['discount']) / 100, $o['coupon'], $o['discount'] / 100, $o['cod_fee'] / 100, $o['total'] / 100, $o['name'], $o['phone'], (int)($o['wa_optin'] ?? 0) ? 'Yes' : 'No', $o['email'], $o['address'], $o['city'], $o['state'], $o['pin'],
      $o['note'], $o['admin_note'], $o['paid_at'] ? substr($o['paid_at'], 0, 16) : ''];
  }
  send_sheet('FOMAXO-orders-' . date('Y-m-d'), $head, $rows);
}
if ($do === 'report_excel') {
  $n = fn($p) => round($p / 100, 2); $rows = [];
  /* ?year= (the Home page link) gives that year month by month; otherwise the dates picked on Reports, as its table shows them */
  $V = isset($_GET['year']) ? ['unit' => 'M', 'from' => "$pyear-01-01", 'to' => "$pyear-12-31", 'rows' => report_year($pyear)] : report_view(pick_dates('reports'));
  $kl = fn($k) => ['D' => fn($k) => date('d/m/Y', strtotime($k)), 'M' => fn($k) => date('M Y', strtotime("$k-01")), 'Y' => fn($k) => (string)$k][$V['unit']]($k);
  foreach ($V['rows'] as $k => $r) $rows[] = [$kl($k), $r['orders'], $n($r['sales']), $n($r['discounts']), $n($r['coupons']), $n($r['fees']), $n($r['cost']), $n($r['gross']), $n($r['expenses']), $n($r['net']), $r['nocost'] ?: ''];
  $t = report_sum($V['rows']); $rows[] = ['Total', $t['orders'], $n($t['sales']), $n($t['discounts']), $n($t['coupons']), $n($t['fees']), $n($t['cost']), $n($t['gross']), $n($t['expenses']), $n($t['net']), $t['nocost'] ?: ''];
  send_sheet('FOMAXO-profit-and-loss-' . $V['from'] . '-to-' . $V['to'], [['D' => 'Day', 'M' => 'Month', 'Y' => 'Year'][$V['unit']], 'Orders', 'Sales (₹)', 'Discounts given (₹)', 'Coupon discounts (₹)', 'Payment fees (₹)', 'Cost of goods (₹)', 'Gross profit (₹)', 'Expenses (₹)', 'Net profit / loss (₹)', 'Items sold with no cost set'], $rows, 'Profit and loss');
}
if ($do === 'members_excel') {
  $rows = array_map(fn($m) => [$m['name'], phone_fmt($m['phone']), $m['email'], $m['address'], $m['state'], $m['count'], round($m['spent'] / 100, 2), round($m['avg'] / 100, 2),
    substr($m['first'], 0, 10), substr($m['last'], 0, 10), implode(', ', array_map(fn($o) => $o['no'], $m['orders']))], members(member_min(), member_spend(), (string)($_GET['q'] ?? ''), ...array_values(array_intersect_key(pick_dates('members'), ['from' => 1, 'to' => 1]))));
  send_sheet('FOMAXO-members-' . date('Y-m-d'), ['Name', 'Mobile', 'Email', 'Latest address', 'State', 'Orders', 'Total spent (₹)', 'Average order (₹)', 'First order', 'Last order', 'Order numbers'], $rows, 'Members');
}
if ($do === 'leads_excel') {
  $okDay = fn(string $d) => (bool)preg_match('/^\d{4}-\d{2}-\d{2}$/', $d);
  $dd = $okDay($F['from']) && $okDay($F['to']) ? [min($F['from'], $F['to']), max($F['from'], $F['to'])] : [];   // the dates picked on Analytics
  $rows = array_map(fn($l) => [substr($l['updated'], 0, 16), $l['name'], $l['phone'] ? phone_fmt($l['phone']) : '', $l['email'], $l['state'], $l['address'], lead_items($l), round($l['total'] / 100, 2),
    $l['step'] === 'payment' ? 'Payment page' : 'Details', $l['later'] === '' ? 'No' : ($l['later'] === 'yes' ? 'Yes' : $l['later'])], checkout_leads(...$dd));
  send_sheet('FOMAXO-left-at-checkout-' . ($dd ? implode('-to-', $dd) : date('Y-m-d')), ['Date', 'Name', 'Mobile', 'Email', 'State', 'Address', 'Products', 'Bag value (₹)', 'Left at', 'Ordered later'], $rows, 'Left at checkout');
}
if ($do === 'expenses_excel') {
  $D = pick_dates('expenses');
  $s = shop_db()->prepare('SELECT * FROM expenses WHERE day >= ? AND day <= ? ORDER BY day, id'); $s->execute([$D['from'], $D['to']]);
  send_sheet('FOMAXO-expenses-' . $D['from'] . '-to-' . $D['to'], ['Date', 'Category', 'Details', 'Amount (₹)'], array_map(fn($x) => [$x['day'], $x['category'], $x['note'], round($x['amount'] / 100, 2)], $s->fetchAll()), 'Expenses');
}

/* ---------------- pages ---------------- */
$tabs = ['home' => 'Home', 'products' => 'Products', 'stock' => 'Stock', 'orders' => 'Orders', 'coupons' => 'Coupons', 'offer' => 'Offer', 'reviews' => 'Reviews', 'analytics' => 'Analytics', 'expenses' => 'Expenses', 'reports' => 'Reports', 'members' => 'Members', 'stores' => 'Stores', 'settings' => 'Settings'];
$flash = (string)($_SESSION['flash'] ?? ''); unset($_SESSION['flash']);
$body = $flash !== '' ? '<p class="flash' . ($flash[0] === '!' ? ' bad' : '') . '">' . h(ltrim($flash, '!')) . '</p>' : '';
$sw = fn(string $for, array $panes) => '<div class="sw" data-for="' . $for . '"><div class="seg">' . implode('', array_map(fn($k, $v, $i) => '<button type="button" data-show="' . $k . '"' . ($i ? '' : ' class="on"') . ">$v</button>", array_keys($panes), $panes, array_keys(array_keys($panes)))) . '</div></div>';
$thumbOf = fn(string $id, string $cls = 'th') => thumb($CAT[$id] ?? null, $cls);
$orderThumb = function (array $o) use ($thumbOf) { $it = (json_decode((string)$o['items'], true) ?: [])[0] ?? []; return $thumbOf((string)($it['id'] ?? ''), 'th sm'); };
require __DIR__ . '/pages.php';
page(html_entity_decode($tabs[$tab]), $body, true, $tab, $tabs);
