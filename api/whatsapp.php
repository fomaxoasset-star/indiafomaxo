<?php
declare(strict_types=1);
/* FOMAXO India — automatic WhatsApp refill reminders, review requests and STOP replies (see whatsapp-lib.php).
   php whatsapp.php                     → Hostinger cron job every 30 minutes: sends the refill reminders and review requests that are due
   GET  whatsapp.php?hub_mode=subscribe → Meta checks the webhook once, with the Verify token shown in admin
   POST whatsapp.php                    → customers' WhatsApp replies (signed by Meta with the app secret): STOP switches their WhatsApp offers off */
require __DIR__ . '/whatsapp-lib.php';

if (PHP_SAPI === 'cli') { echo json_encode(wa_send_refills() + wa_send_rvreqs()) . "\n"; exit; }

header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');
if (isset($_GET['hub_mode'])) {
  if ($_GET['hub_mode'] === 'subscribe' && hash_equals(wa_hook_token(), (string)($_GET['hub_verify_token'] ?? ''))) {
    header('Content-Type: text/plain'); echo preg_replace('/[^A-Za-z0-9_\-]/', '', (string)($_GET['hub_challenge'] ?? '')); exit;
  }
  http_response_code(403); exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $raw = (string)file_get_contents('php://input'); $sec = trim((string)(wa_config()['app_secret'] ?? ''));
  if ($sec === '' || !hash_equals('sha256=' . hash_hmac('sha256', $raw, $sec), (string)($_SERVER['HTTP_X_HUB_SIGNATURE_256'] ?? ''))) { http_response_code(403); exit; }
  $j = json_decode($raw, true);
  foreach ((array)($j['entry'] ?? []) as $e) foreach ((array)($e['changes'] ?? []) as $ch) foreach ((array)($ch['value']['messages'] ?? []) as $m) {
    $t = (string)($m['text']['body'] ?? $m['button']['text'] ?? $m['interactive']['button_reply']['title'] ?? '');
    if (wa_is_stop($t)) wa_stop((string)($m['from'] ?? ''), 'reply');
  }
  echo 'ok'; exit;
}
http_response_code(404);
