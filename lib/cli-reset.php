<?php
// FOMAXO India Store System - start fresh from the server's command line (a one-time Hostinger cron job).
//   php cli-reset.php inventory          what is in the app now (changes nothing)
//   php cli-reset.php run <id>           backs up, then deletes every sale, the activity log, the stock history
//                                        and all customers; receipt numbers start again at 10000. Stock counts,
//                                        products, staff, stores, coupons and settings stay. fomaxo.in is never touched.
// <id> is any word; a run with the same id happens only once, so a cron that fires twice cannot delete twice.
if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}
require __DIR__ . '/core.php';
require __DIR__ . '/razorpay.php';
require __DIR__ . '/sales.php';
require __DIR__ . '/schema.php';

if (cfg() === false || !is_installed()) {
    echo "The store app is not set up yet (install.php has not been run). Nothing to do.\n";
    exit(0);
}
migrate();

function counts()
{
    $out = [];
    foreach (['sales', 'sale_items', 'stock_moves', 'activity', 'customers', 'users', 'stores', 'products', 'coupons'] as $t) {
        $out[$t] = (int) q1("SELECT COUNT(*) AS n FROM $t")['n'];
    }
    $r = q1("SELECT MIN(created_at) AS a, MAX(created_at) AS b, SUM(CASE WHEN status = 'paid' THEN total ELSE 0 END) AS t FROM sales");
    $out['sales_from'] = $r['a'];
    $out['sales_to'] = $r['b'];
    $out['paid_total'] = inr((int) $r['t']);
    $out['receipts'] = array_column(q('SELECT receipt_no FROM sales ORDER BY id DESC LIMIT 5')->fetchAll(), 'receipt_no');
    return $out;
}

$mode = $argv[1] ?? 'inventory';
if ($mode === 'inventory') {
    echo json_encode(counts(), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
    exit(0);
}
if ($mode !== 'run' || !preg_match('/^[a-z0-9-]{4,40}$/', $argv[2] ?? '')) {
    echo "Use: php cli-reset.php inventory | run <id>\n";
    exit(1);
}
$key = 'cli_reset_' . $argv[2];
if (meta_get($key)) {
    echo 'Already done (' . meta_get($key) . "). Nothing deleted again.\n";
    exit(0);
}
$me = q1("SELECT * FROM users WHERE role = 'manager' AND active = 1 ORDER BY id LIMIT 1");
$before = counts();

try {
    // Sales first (its own backup), then the rest of the old records with a second backup.
    $r = sales_reset($me, false);
    $file = history_reset($me, true, true, true);
} catch (ApiError $e) {
    echo 'Stopped: ' . $e->getMessage() . "\n";
    exit(1);
}
meta_set($key, now());
log_activity($me, 'reset', 'Started fresh: all old sales, activity, stock history and customers cleared. Backups: ' . $r['backup'] . ', ' . $file);

echo json_encode(['before' => $before, 'deleted_sales' => $r['deleted'], 'backups' => [$r['backup'], $file], 'after' => counts()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), "\n";
