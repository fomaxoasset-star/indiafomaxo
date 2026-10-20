<?php
declare(strict_types=1);
/* A customer's receipt (api/receipt-lib.php): fomaxo.in/api/receipt.php?n=FMX-IN-1001&t=… — the link admin sends on WhatsApp or by email. */
require __DIR__ . '/receipt-lib.php';
header('X-Robots-Tag: noindex, nofollow');
$no = (string)($_GET['n'] ?? '');
$o = null;
if (preg_match('/^[A-Z0-9-]{3,40}$/', $no) && receipt_ok($no, $_GET['t'] ?? '')) {
  receipt_cols();
  $s = shop_db()->prepare("SELECT * FROM orders WHERE no = ? AND status <> 'awaiting'"); $s->execute([$no]); $o = $s->fetch() ?: null;
}
if (!$o) { http_response_code(404); header('Content-Type: text/plain; charset=UTF-8'); echo 'This receipt link is not valid. Please ask FOMAXO on WhatsApp for a new one.'; exit; }
header('Content-Type: text/html; charset=UTF-8');
header('Cache-Control: private, no-store');
echo receipt_page($o);
