<?php
declare(strict_types=1);
/* FOMAXO India — WhatsApp Business (Meta Cloud API): automatic refill reminders and STOP replies.
   Used by admin → Members → Refill reminders (the Automatic sending box), the customer page (Stop button) and api/whatsapp.php
   (the Hostinger cron job every 30 minutes, and the webhook Meta sends customers' replies to).
   The access token, phone number ID and app secret are typed in admin and saved in fomaxo-private/whatsapp-config.php,
   above public_html, never in the code. */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }
require_once __DIR__ . '/store-lib.php';
require_once __DIR__ . '/refill-lib.php';

function wa_config_file(): string { global $PRIV; return "$PRIV/whatsapp-config.php"; }
function wa_config(): array {
  static $c = null; if ($c !== null) return $c;
  $f = wa_config_file(); $r = is_file($f) ? require $f : [];
  return $c = is_array($r) ? array_change_key_case($r) : [];
}
/* saves the WhatsApp Business details typed in admin; an empty value keeps what was saved before */
function wa_config_save(array $new): bool {
  $f = wa_config_file(); $c = is_file($f) ? (array)(require $f) : [];
  foreach ($new as $k => $v) if ($v !== '') $c[$k] = $v;
  $ok = @file_put_contents($f, "<?php\n// WhatsApp Business (Meta Cloud API) details for FOMAXO India (written by fomaxo.in/admin → Members → Refill reminders)\nreturn " . var_export($c, true) . ";\n", LOCK_EX) !== false;
  if ($ok) { @chmod($f, 0600); if (function_exists('opcache_invalidate')) @opcache_invalidate($f, true); }
  return $ok;
}
/* true when the access token and phone number ID are saved */
function wa_ready(): bool { $c = wa_config(); return trim((string)($c['access_token'] ?? '')) !== '' && trim((string)($c['phone_number_id'] ?? '')) !== ''; }

/* an Indian mobile as WhatsApp wants it: 98765 43210, +91 98765 43210, 098765 43210 → 919876543210 */
function wa_number(string $phone): ?string { $d = coupon_phone($phone); return preg_match('/^[6-9]\d{9}$/', $d) ? "91$d" : null; }

/* sends an approved template with its body variables in order ({{1}}, {{2}} …); returns [ok, message id or what went wrong] */
function wa_template(string $to, string $name, string $lang, array $vars, array $c): array {
  $ver = preg_replace('/[^v0-9.]/', '', (string)($c['api_version'] ?? 'v21.0')) ?: 'v21.0';
  $api = rtrim((string)($c['api'] ?? 'https://graph.facebook.com'), '/');   // 'api' only for testing against a pretend server
  $body = ['messaging_product' => 'whatsapp', 'to' => $to, 'type' => 'template', 'template' => [
    'name' => $name, 'language' => ['code' => $lang],
    'components' => [['type' => 'body', 'parameters' => array_map(fn($v) => ['type' => 'text', 'text' => (string)$v], $vars)]]]];
  $ch = curl_init("$api/$ver/" . rawurlencode(trim((string)$c['phone_number_id'])) . '/messages');
  curl_setopt_array($ch, [CURLOPT_POST => true, CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 20,
    CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . trim((string)$c['access_token']), 'Content-Type: application/json'],
    CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
  $res = curl_exec($ch); $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE); $err = curl_error($ch); curl_close($ch);
  $d = is_string($res) ? json_decode($res, true) : null;
  if ($code === 200 && !empty($d['messages'][0]['id'])) return [true, (string)$d['messages'][0]['id']];
  return [false, "HTTP $code " . ($d['error']['message'] ?? ($err ?: (string)$res))];
}

/* ---- STOP: the customer replied STOP on WhatsApp (api/whatsapp.php) or FOMAXO tapped Stop on their admin page.
   WhatsApp offers go off on all their orders, so no more automatic messages, until they tick offers again on a new order. ---- */
function wa_stops(): array { return json_decode((string)shop_setting('wa_stop'), true) ?: []; }
function wa_stop(string $phone, string $how): bool {
  if (($k = coupon_phone($phone)) === '') return false;
  $ids = [];
  foreach (shop_db()->query('SELECT id, phone FROM orders WHERE wa_optin = 1') as $o) if (coupon_phone((string)$o['phone']) === $k) $ids[] = (int)$o['id'];
  if ($ids) shop_db()->exec('UPDATE orders SET wa_optin = 0 WHERE id IN (' . implode(',', $ids) . ')');
  $s = wa_stops(); $s[$k] = date('Y-m-d H:i') . ' ' . ($how === 'reply' ? 'reply' : 'admin');
  shop_set('wa_stop', json_encode($s));
  return true;
}
/* the whole reply is a stop word (English, Hindi or Hinglish), so "don't stop" or a longer message never counts */
function wa_is_stop(string $t): bool {
  $t = trim(mb_strtolower(preg_replace('/[\s\p{P}]+/u', ' ', $t) ?? ''));
  return in_array($t, ['stop', 'unsubscribe', 'stop promotions', 'stop messages', 'stop all',
    'बंद', 'बंद करो', 'बंद करें', 'बन्द', 'बन्द करो', 'बन्द करें', 'रोको', 'रोकें', 'रुको', 'रोक दो', 'मत भेजो', 'मत भेजें',
    'band', 'band karo', 'band kare', 'band karein', 'stop karo', 'roko', 'mat bhejo'], true);
}
/* the Verify token FOMAXO pastes into Meta's webhook settings */
function wa_hook_token(): string { return substr(hash_hmac('sha256', 'wa-hook', refill_key()), 0, 32); }

/* ---- automatic refill reminders: the set number of days after a customer's latest order (refill_time, default 45, 11 AM–8 PM India time),
   to customers who ticked "Send me offers on WhatsApp", one per order. Approved template 'refill_reminder' (Marketing, English), body variables:
   {{1}} first name, {{2}} perfumes, {{3}} days since the order, {{4}} % off, {{5}} coupon code, {{6}} review link. Failed sends are tried again up to 3 times. ---- */
const REFILL_TRIES = 4;   // the first try + 3 more
function wa_send_refills(int $max = 20): array {
  global $PRIV;
  if (shop_setting('refill_auto') !== '1') return ['refills' => 0, 'note' => 'automatic refill reminders are off'];
  if (!wa_ready()) return ['refills' => 0, 'note' => 'WhatsApp Business details missing'];
  $tm = refill_time(); $h = (int)date('G');
  if ($h < $tm['from'] || $h >= $tm['to']) return ['refills' => 0, 'note' => 'outside sending hours'];
  $lock = @fopen("$PRIV/whatsapp.lock", 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['refills' => 0, 'note' => 'already running'];
  $c = wa_config(); $tpl = (string)($c['refill_template'] ?? 'refill_reminder'); $lang = (string)($c['refill_language'] ?? 'en');
  $tries = json_decode((string)shop_setting('refill_tries'), true) ?: []; $sent = 0; $failed = 0;
  foreach (refill_due() as $o) {
    if ($sent + $failed >= $max) break;
    if ($o['sent'] || !$o['optin'] || $o['days'] < $tm['days'] || ($tries[$o['no']] ?? 0) >= REFILL_TRIES || !($to = wa_number((string)$o['phone']))) continue;
    $p = refill_parts($o); $ok = false;
    $vars = [$p['first'] !== '' ? $p['first'] : 'there', $p['perfumes'], $p['days'], $p['offer'], $p['code'], $p['review'] ?: 'https://fomaxo.in'];
    if (!$p['review']) [$ok, $info] = wa_template($to, $tpl . '_plain', $lang, array_slice($vars, 0, 5), $c);   // already reviewed (all or part): refill_reminder_plain, without the review request
    if (!$ok) [$ok, $info] = wa_template($to, $tpl, $lang, $vars, $c);   // not reviewed, or refill_reminder_plain is not approved by Meta yet
    if ($ok) { refill_mark((string)$o['no'], 'auto'); $sent++; unset($tries[$o['no']]); shop_set('refill_auto_err', ''); }
    else {
      $tries[$o['no']] = ($tries[$o['no']] ?? 0) + 1; $failed++;
      shop_set('refill_auto_err', date('d/m g:i A') . ' · ' . $o['no'] . ': ' . mb_substr($info, 0, 200));
      error_log("FOMAXO refill WhatsApp {$o['no']}: $info");
    }
    shop_set('refill_tries', json_encode($tries));
  }
  flock($lock, LOCK_UN); fclose($lock);
  return ['refills' => $sent, 'refills_failed' => $failed];
}

/* ---- automatic review requests (admin → Orders → Review requests): the set days after a customer's latest order, within the sending hours (India time),
   only to customers who ticked "Send me offers on WhatsApp", once per order, never once anything in the order is reviewed. Approved templates (Marketing, English):
   review_ask (coupon off): {{1}} first name, {{2}} perfumes, {{3}} review link; review_ask_coupon (coupon on): the same + {{4}} % off, {{5}} code.
   Failed sends are tried again, up to 3 tries in all; the last problem shows in red on the page. ---- */
const RVREQ_TRIES = 3;
function wa_send_rvreqs(int $max = 20): array {
  global $PRIV;
  require_once __DIR__ . '/review-req-lib.php';
  $set = rq_set();
  if (!$set['auto']) return ['reviews' => 0, 'note' => 'automatic review requests are off'];
  if (!wa_ready()) return ['reviews' => 0, 'note' => 'WhatsApp Business details missing'];
  $h = (int)date('G');
  if ($h < $set['from'] || $h >= $set['to']) return ['reviews' => 0, 'note' => 'outside sending hours'];
  $lock = @fopen("$PRIV/whatsapp-rv.lock", 'c'); if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return ['reviews' => 0, 'note' => 'already running'];
  $c = wa_config(); $tries = json_decode((string)shop_setting('rvreq_tries'), true) ?: []; $sent = 0; $failed = 0;
  foreach (rq_due() as $o) {
    if ($sent + $failed >= $max) break;
    if ($o['sent'] || !$o['optin'] || $o['stopped'] || ($tries[$o['no']] ?? 0) >= RVREQ_TRIES || !($to = wa_number((string)$o['phone']))) continue;
    $p = rq_parts($o); $vars = [$p['first'], $p['perfumes'], $p['review']];
    if ($p['code']) { $vars[] = $p['pct']; $vars[] = $p['code']; }
    [$ok, $info] = wa_template($to, $p['code'] ? 'review_ask_coupon' : 'review_ask', 'en', $vars, $c);
    if ($ok) { rq_mark((string)$o['no'], 'auto'); $sent++; unset($tries[$o['no']]); shop_set('rvreq_auto_err', ''); }
    else {
      $tries[$o['no']] = ($tries[$o['no']] ?? 0) + 1; $failed++;
      shop_set('rvreq_auto_err', date('d/m g:i A') . ' · ' . $o['no'] . ': ' . mb_substr($info, 0, 200));
      error_log("FOMAXO review request WhatsApp {$o['no']}: $info");
    }
    shop_set('rvreq_tries', json_encode($tries));
  }
  flock($lock, LOCK_UN); fclose($lock);
  return ['reviews' => $sent, 'reviews_failed' => $failed];
}
