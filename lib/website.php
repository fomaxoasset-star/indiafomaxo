<?php
// Read-only look at the fomaxo.in shop database (same Hostinger account), to recognise customers who
// ordered on the website and to accept the website's coupon codes. Nothing on the website is ever changed from here.

function web_db()
{
    static $pdo = false;
    if ($pdo !== false) {
        return $pdo;
    }
    $pdo = null;
    // The shop keeps its login in fomaxo-private/db-config.php (MySQL); without one it uses fomaxo-private/shop.sqlite.
    $priv = cfg('website_private_dir', dirname(dirname(__DIR__), 2) . '/fomaxo.in/fomaxo-private');
    $file = $priv . '/db-config.php';
    try {
        if (@is_file($file) && @is_readable($file)) {
            $c = require $file;
            if (!is_array($c) || empty($c['db_name']) || empty($c['db_user'])) {
                return null;
            }
            $pdo = new PDO('mysql:host=' . ($c['db_host'] ?? '127.0.0.1') . ';port=' . (int) ($c['db_port'] ?? 3306) . ';dbname=' . $c['db_name'] . ';charset=utf8mb4',
                $c['db_user'], (string) ($c['db_pass'] ?? ''), [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_TIMEOUT => 4]);
        } elseif (@is_file($priv . '/shop.sqlite')) {
            $pdo = new PDO('sqlite:' . $priv . '/shop.sqlite', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
            $pdo->exec('PRAGMA busy_timeout = 3000');
        }
    } catch (Throwable $e) {
        error_log('FOMAXO POS India: website database not reachable: ' . $e->getMessage());
        $pdo = null;
    }
    return $pdo;
}

/** The last 10 digits of an Indian mobile ('' when it has fewer), so +91 98765 43210 and 098765 43210 match. */
function phone10($p)
{
    $d = preg_replace('/\D/', '', (string) $p);
    return strlen($d) >= 10 ? substr($d, -10) : '';
}

/** The customer's details and order history on fomaxo.in, found by mobile (or email). Null if none. */
function web_customer($phone, $email = '')
{
    $pdo = web_db();
    if (!$pdo) {
        return null;
    }
    $p10 = phone10($phone);
    $email = strtolower(trim((string) $email));
    if ($p10 === '' && $email === '') {
        return null;
    }
    try {
        // Phones are saved as "+91 98765 43210": compare the last 10 digits in PHP (works on MySQL and SQLite alike).
        $st = $pdo->prepare("SELECT name, phone, email, total, status, created FROM orders
                             WHERE test = 0 AND status IN ('new', 'paid', 'delivered') AND (phone LIKE ? OR LOWER(email) = ?)
                             ORDER BY created DESC LIMIT 400");
        $st->execute(['%' . ($p10 !== '' ? substr($p10, -5) : '#no#'), $email !== '' ? $email : '#no#']);
        $rows = array_values(array_filter($st->fetchAll(), function ($r) use ($p10, $email) {
            return ($p10 !== '' && phone10($r['phone']) === $p10) || ($email !== '' && strtolower(trim($r['email'])) === $email);
        }));
    } catch (Throwable $e) {
        error_log('FOMAXO POS India: website customer lookup failed: ' . $e->getMessage());
        return null;
    }
    if (!$rows) {
        return null;
    }
    $year = date('Y');
    $spent = 0;
    $yearSpent = 0;
    foreach ($rows as $r) {
        $paise = (int) $r['total'];   // the shop keeps totals in paise
        $spent += $paise;
        if (substr($r['created'], 0, 4) === $year) {
            $yearSpent += $paise;
        }
    }
    $last = $rows[0];
    $em = '';
    foreach ($rows as $r) {
        if (trim($r['email']) !== '') {
            $em = strtolower(trim($r['email']));
            break;
        }
    }
    return [
        'name' => trim((string) $last['name']),
        'phone' => trim((string) $last['phone']),
        'email' => $em,
        'orders' => count($rows),
        'spent' => $spent,
        'year_spend' => $yearSpent,
        'last_order' => substr($last['created'], 0, 10),
    ];
}

/** This year's website spend for a mobile number (for FOMAXO Elite tiers). */
function web_year_spend($phone)
{
    static $cache = [];
    $p10 = phone10($phone);
    if ($p10 === '') {
        return 0;
    }
    if (!isset($cache[$p10])) {
        $w = web_customer($phone);
        $cache[$p10] = $w ? (int) $w['year_spend'] : 0;
    }
    return $cache[$p10];
}

/** A coupon code made on fomaxo.in/admin → Coupons, as the till needs it. Fails with the reason when it cannot be used. */
function web_coupon_row($code, $phone = '')
{
    $pdo = web_db();
    if (!$pdo) {
        fail('Could not check the coupon with fomaxo.in. Try again.', 502);
    }
    $st = $pdo->prepare('SELECT * FROM coupons WHERE code = ?');
    $st->execute([$code]);
    $c = $st->fetch();
    if (!$c || !(int) $c['active']) {
        fail('This coupon code is not valid.');
    }
    $now = date('Y-m-d H:i');
    $ends = (string) $c['ends'];
    $ends = strlen($ends) === 10 ? $ends . ' 23:59' : $ends;
    if ((string) ($c['starts'] ?? '') !== '' && $c['starts'] > $now) {
        fail('This coupon code starts on ' . date('j M Y', strtotime($c['starts'])) . '.');
    }
    if ($ends !== '' && $ends < $now) {
        fail('This coupon code has expired.');
    }
    $used = "status NOT IN ('awaiting', 'cancelled', 'refunded')";
    $u = $pdo->prepare("SELECT phone FROM orders WHERE coupon = ? AND $used");
    $u->execute([$code]);
    $phones = $u->fetchAll(PDO::FETCH_COLUMN);
    $uses = count($phones) + coupon_uses($code);
    if ((string) ($c['phone'] ?? '') !== '') {
        if (phone10($phone) === '') {
            fail('This coupon is for one mobile number. Add the customer (with their mobile) first, then apply the code.');
        }
        if (phone10($phone) !== (string) $c['phone']) {
            fail('This coupon is for a different mobile number.');
        }
        if ($uses >= max(1, (int) $c['max_uses'])) {
            fail('This coupon has already been used.');
        }
    }
    if ((int) $c['max_uses'] > 0 && $uses >= (int) $c['max_uses']) {
        fail('This coupon code has been fully used.');
    }
    if (!empty($c['per_cust'])) {
        if (phone10($phone) === '') {
            fail('This coupon is one use per customer. Add the customer (with their mobile) first, then apply the code.');
        }
        foreach ($phones as $ph) {
            if (phone10($ph) === phone10($phone)) {
                fail('This customer has already used this coupon.');
            }
        }
    }
    return $c;
}
