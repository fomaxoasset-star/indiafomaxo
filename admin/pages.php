<?php
declare(strict_types=1);
/* FOMAXO India admin: the pages. Included by admin/index.php, which has signed the owner in and set $tab, $CAT, $LIVE, $body … */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

$sel = fn($name, $opts, $cur) => "<select name=\"$name\">" . implode('', array_map(fn($k, $v) => '<option value="' . h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . h($v) . '</option>', array_keys($opts), $opts)) . '</select>';
$pct = fn($v) => $v === null ? '—' : round($v) . '%';

/* ============ Dashboard ============ */
if ($tab === 'home') {
  $todo = []; foreach (shop_db()->query("SELECT method, COUNT(*) n, SUM(total) t FROM orders WHERE status IN ('new', 'paid') AND test = 0 GROUP BY method") as $r) $todo[$r['method']] = [(int)$r['n'], (int)$r['t']];
  /* the date bar: the sales box and both graphs follow it; the to-deliver and this-month boxes do not */
  $D = pick_dates('home'); $PL = period_label($D);
  [$tn, $tt] = sales_between($D['from'] . ' 00:00:00', $D['to'] . ' 23:59:59');
  $rep = report_year((int)date('Y')); $m = $rep[date('Y-m')];
  $body .= date_bar($D) . '<div class="dash">'
    . '<div class="kpis n5" style="--n:5">'
    . '<a class="kpi k-new" href="' . h(self_url(['tab' => 'orders', 'status' => 'todo', 'method' => 'cod'])) . '"><span>COD orders to deliver</span><b>' . ($todo['cod'][0] ?? 0) . '</b><small>' . rupees($todo['cod'][1] ?? 0) . ' to collect</small></a>'
    . '<a class="kpi k-paid" href="' . h(self_url(['tab' => 'orders', 'status' => 'todo', 'method' => 'online'])) . '"><span>Online orders to deliver</span><b>' . ($todo['online'][0] ?? 0) . '</b><small>Paid online</small></a>'
    . '<div class="kpi wrap"><span>Sales · ' . h($PL) . '</span><b>' . rupees($tt) . '</b><small>' . $tn . ' order' . ($tn === 1 ? '' : 's') . '</small></div>'
    . '<a class="kpi" href="' . h(self_url(['tab' => 'sales'])) . '"><span>Sales this month</span><b>' . rupees($m['sales']) . '</b><small>' . $m['orders'] . ' order' . ($m['orders'] === 1 ? '' : 's') . '</small></a>'
    . '<a class="kpi ' . ($m['net'] < 0 ? 'bad' : 'good') . '" href="' . h(self_url(['tab' => 'sales'])) . '"><span>' . ($m['net'] < 0 ? 'Loss' : 'Profit') . ' this month</span><b>' . money($m['net']) . '</b><small>' . ($m['nocost'] ? $m['nocost'] . ' items with no cost set' : 'after costs and expenses') . '</small></a></div>';
  $body .= $sw('#dashCharts', ['sales' => 'Sales', 'visitors' => 'Visitors']) . '<div id="dashCharts" class="panes" style="display:contents">';
  foreach (['sales' => 'Sales', 'visitors' => 'Visitors'] as $k => $label)
    $body .= '<div class="box c-' . ($k === 'sales' ? 'sales on' : 'vis') . '" data-pane="' . $k . '" data-chart="' . $k . '" data-caption="' . h(($k === 'sales' ? '' : 'visitors · ') . $PL) . '"><div class="bh"><h3>' . $label . '</h3><span class="ctot"></span></div>'
      . '<div class="chart" role="img" aria-label="' . $label . ' chart"><svg></svg><div class="tip" hidden></div></div></div>';
  $body .= '</div>';
  /* stock alerts and latest orders */
  $STOCK = shop_stock(); $low = shop_low_stock(); $alerts = [];
  foreach ($CAT as $id => $p) { if (!empty($p['hidden'])) continue; foreach ($p['prices'] as $opt => $_) { $v = $STOCK[$id][$opt] ?? null; if ($v !== null && $v <= max($low, 0)) $alerts[] = [$id, $p, (string)$opt, $v]; } }
  usort($alerts, fn($a, $b) => $a[3] <=> $b[3]);
  $latest = shop_db()->query("SELECT * FROM orders WHERE " . IS_ORDER . " ORDER BY id DESC LIMIT 30")->fetchAll();
  $body .= $sw('#dashLists', ['orders' => 'Latest orders', 'stock' => 'Stock alerts']) . '<div id="dashLists" class="panes" style="display:contents">'
    . '<div class="box c-stock" data-pane="stock"><div class="bh"><h3>Stock alerts</h3><a class="btn line sm" href="' . h(self_url(['tab' => 'stock'])) . '">Update stock</a></div><div class="bb">';
  if (!$alerts) $body .= '<p class="empty">' . ($STOCK ? 'Nothing is running low.' : 'Write your stock on the Stock page so the shop can show “Only 3 left” and “Sold out”.') . '</p>';
  foreach ($alerts as [$id, $p, $opt, $v]) $body .= '<a class="li" href="' . h(self_url(['tab' => 'stock'])) . '">' . $thumbOf($id, 'th sm') . '<span class="grow"><b>' . h($p['name']) . '</b><small>' . h(opt_label($p, $opt)) . '</small></span>' . ($v < 1 ? '<span class="badge st-cancelled">Sold out</span>' : '<span class="badge st-new">' . $v . ' left</span>') . '</a>';
  $body .= '</div></div><div class="box c-orders on" data-pane="orders"><div class="bh"><h3>Latest orders</h3><a class="btn line sm" href="' . h(self_url(['tab' => 'orders'])) . '">All orders</a></div><div class="bb">';
  if (!$latest) $body .= '<p class="empty">No orders yet. New orders show here as they come in.</p>';
  foreach ($latest as $o) $body .= '<a class="li" href="' . h(self_url(['tab' => 'orders', 'q' => $o['no']])) . '">' . $orderThumb($o) . '<span class="grow"><b>' . h($o['no']) . ' · ' . h($o['name']) . '</b><small>' . h(date('d M, H:i', strtotime($o['created']))) . ' · ' . ($o['method'] === 'cod' ? 'COD' : 'Online') . '</small></span><span>' . rupees((int)$o['total']) . '</span><span class="badge st-' . h($o['status']) . '">' . h(FOMAXO_STATUSES[$o['status']]) . '</span></a>';
  $body .= '</div></div></div>';
  $body .= '<div class="quick"><a class="btn" href="' . h(self_url(['tab' => 'expenses'])) . '">+ Add an expense</a><a class="btn" href="' . h(self_url(['tab' => 'products', 'add' => 1])) . '">+ Add a product</a>'
    . '<a class="btn line" href="' . h(self_url(['do' => 'excel'])) . '">Download all orders (Excel)</a><a class="btn line" href="' . h(self_url(['do' => 'report_excel', 'year' => date('Y')])) . '">Download ' . date('Y') . ' profit &amp; loss (Excel)</a></div></div>';
  $body .= '<script type="application/json" id="chartData">' . json_encode(series($D['from'], $D['to']), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . '</script>';
}

/* ============ Orders ============ */
/* ✕ in front of a name on Review requests / Refill reminders: takes that order off the list (admin.js asks first) */
$rmX = fn(string $list, array $o) => '<button type="button" class="xbtn rmx" data-rmx="' . $list . '" data-no="' . h((string)$o['no']) . '" data-who="' . h($o['name'] ?: 'this customer') . '" title="Remove from this list" aria-label="Remove ' . h($o['name'] ?: 'this customer') . ' from this list">✕</button>';
/* Review requests and Refill reminders: one switch at the top flips between the two lists (Refill reminders used to sit under Members) */
$rqTabs = function (string $on, string $right = ''): string {   // right: the date bar, at the right end of the same row
  require_once dirname(__DIR__) . '/api/whatsapp-lib.php'; require_once dirname(__DIR__) . '/api/review-req-lib.php';
  $n = fn(array $due) => count(array_filter($due, fn($o) => !$o['sent'] && !$o['stopped']));
  $t = ['ask' => ['Review requests', $n(rq_due())], 'refill' => ['Refill reminders', $n(refill_due())]];
  return '<div class="row ctop rqtop"><a class="btn line sm" href="' . h(self_url(['tab' => 'orders'])) . '">← Orders</a><nav class="rqtabs" aria-label="WhatsApp lists">'
    . implode('', array_map(fn($k, $v) => '<a href="' . h(self_url(['tab' => 'orders', $k => 1])) . '"' . ($k === $on ? ' class="on" aria-current="page"' : '') . '>' . $v[0] . ($v[1] ? ' <i>' . $v[1] . '</i>' : '') . '</a>', array_keys($t), $t)) . '</nav>' . $right . '</div>';
};
if ($tab === 'orders' && isset($_GET['ask'])) {
  /* Review requests: each customer's latest order 7 days (setting) after it was placed, until 30 days after that, while nothing in it is reviewed.
     A tap on WhatsApp opens the ready message and marks the order Sent; Automatic sending does it by itself for customers who ticked WhatsApp offers. */
  require_once dirname(__DIR__) . '/api/whatsapp-lib.php'; require_once dirname(__DIR__) . '/api/review-req-lib.php';
  $set = rq_set(); $due = rq_due(); $asked = rq_asked();
  $D = pick_dates('rvreq') + ['tab' => 'orders'];   // Today / 7 days / 30 days / From–To: by the day the order was placed
  if ($D['r'] !== 'all') { $inD = fn($o) => ($d = substr((string)$o['created'], 0, 10)) >= $D['from'] && $d <= $D['to']; $due = array_filter($due, $inD); $asked = array_filter($asked, $inD); } $waOk = wa_ready(); $autoOn = $waOk && $set['auto']; $err = (string)shop_setting('rvreq_auto_err');
  $open = count(array_filter($due, fn($o) => !$o['sent'] && !$o['stopped']));
  $hsel = fn(string $nm, int $a, int $b, int $v) => '<select id="' . $nm . '" name="' . $nm . '">' . implode('', array_map(fn($x) => '<option value="' . $x . '"' . ($x === $v ? ' selected' : '') . '>' . refill_hour($x) . '</option>', range($a, $b))) . '</select>';
  $sw = fn(string $nm, bool $on, string $lbl) => '<div class="rfsw" role="radiogroup" aria-label="' . $lbl . '"><label><input type="radio" name="' . $nm . '" value="1"' . ($on ? ' checked' : '') . '><span>On</span></label><label><input type="radio" name="' . $nm . '" value=""' . ($on ? '' : ' checked') . '><span>Off</span></label></div>';
  $body .= $rqTabs('ask', date_bar($D, ['ask' => 1]));
  $body .= '<details class="box rfauto"><summary><b>Settings</b><span class="rfst' . ($autoOn ? ' on' : '') . '">' . ($autoOn ? 'Auto on' : 'Auto off') . '</span><span class="muted small rfsum">'
    . $set['days'] . ' days after the order · coupon ' . ($set['coupon'] ? $set['pct'] . '% on' : 'off') . ' · automatic ' . ($autoOn ? 'on, ' . refill_hour($set['from']) . '–' . refill_hour($set['to']) : 'off') . ($set['drop'] ? ' · remove not reviewed after ' . $set['drop_days'] . ' days' : '') . '</span></summary>'
    . '<form method="post" class="bb rff" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="rq_set">'
    . '<div class="rfg three"><label>Days after order<input name="rq_days" type="number" min="1" max="120" inputmode="numeric" value="' . $set['days'] . '"></label>'
    . '<label>Send from' . $hsel('rq_from', 0, 23, $set['from']) . '</label><label>Until' . $hsel('rq_to', 1, 24, $set['to']) . '</label></div>'
    . '<div class="rqsw"><div><span class="lb">Coupon in the message</span>' . $sw('rq_coupon', $set['coupon'], 'Coupon in the message') . '</div>'
    . '<label class="rqn">% off<input name="rq_pct" type="number" min="1" max="99" inputmode="numeric" value="' . $set['pct'] . '"></label>'
    . '<div><span class="lb">Automatic sending</span>' . $sw('rq_auto', $autoOn, 'Automatic sending') . '</div>'
    . '<div><span class="lb">Remove not reviewed</span>' . $sw('rq_drop', $set['drop'], 'Remove not reviewed') . '</div>'
    . '<label class="rqn">After days<input name="rq_drop_days" type="number" min="1" max="365" inputmode="numeric" value="' . $set['drop_days'] . '"></label></div>'
    . '<p class="muted small" style="margin:0">' . ($waOk ? 'India time. Automatic sending goes only to customers who ticked “Send me offers on WhatsApp”, once per order, using the WhatsApp details saved on <a href="' . h(self_url(['tab' => 'orders', 'refill' => 1])) . '">Refill reminders</a> and your approved templates review_ask (coupon off) and review_ask_coupon (coupon on).' : 'To send automatically, first save the WhatsApp details on <a href="' . h(self_url(['tab' => 'orders', 'refill' => 1])) . '">Refill reminders</a>.')
    . ' The coupon is single use, works only with that customer’s mobile, and each customer only ever gets one code.</p>'
    . ($err !== '' && $autoOn ? '<p class="small rferr">Last problem: ' . h($err) . '</p>' : '')
    . '<div class="rfrow"><button class="btn sm">Save</button></div></form></details>';
  $st = array_count_values(array_map('rq_state', $asked));
  $body .= '<div class="kpis n3 rfk rq3" style="--n:3"><div class="kpi"><span>To ask</span><b data-rqn="todo">' . $open . '</b></div><div class="kpi"><span>Sent</span><b data-rqn="sent">' . count($asked) . '</b></div><div class="kpi"><span>Reviewed</span><b>' . ($st['rv'] ?? 0) . '</b></div></div>';
  $rows = '';
  foreach ($due as $k => $o) {
    $p = rq_parts($o); $ph = coupon_phone((string)$o['phone']); $a = $asked[$o['no']] ?? null;
    if ($o['sent'] && !$a) continue;   // asked long ago and never reviewed: removed after the set days
    $act = $o['stopped'] ? '<span class="rstop" title="Replied STOP on WhatsApp">Stopped</span>'
      : ($a ? rq_badge($a) : '')   // once sent, the button says Sent dd/mm (sent by itself: the same, its tooltip says so)
        . str_replace('title="Send on WhatsApp"', 'title="' . ($o['sent'] && $o['auto'] ? 'Sent by itself' : 'Send on WhatsApp') . '"', wa_btn((string)$o['phone'], (string)$o['name'], rq_head($p), rq_tail(), 'rq:' . $o['no'], $o['sent'] ? 'Sent ' . date('d/m', strtotime($o['sent'])) : 'WhatsApp', 'btn sm wag' . ($o['sent'] ? ' line' : '')));
    $rows .= '<tr data-st="' . ($a ? rq_state($a) : 'ask') . '"' . ($o['sent'] || $o['stopped'] ? ' class="dim2"' : '') . '><td>' . $rmX('ask', $o) . '<a href="' . h(self_url(['tab' => 'members', 'c' => $k])) . '"><b>' . h($o['name'] ?: 'No name') . '</b></a>' . ($o['optin'] ? '<span class="wtag" title="Ticked at checkout: send me order updates and offers on WhatsApp">WhatsApp ✓</span>' : '')
      . '<small>' . h(phone_fmt($ph)) . ' · ' . h($p['perfumes']) . '</small></td>'
      . '<td class="nw"><b>' . $o['days'] . ' days</b><small>' . h(date('d/m/Y', strtotime((string)$o['created']))) . ' · ' . h($o['no']) . '</small></td><td class="r nw ra">' . $act . '</td></tr>';
  }
  /* asked orders that left the list above (reviewed, or older): still shown with their review status */
  $inDue = array_flip(array_column($due, 'no'));
  foreach ($asked as $no => $a) {
    if (isset($inDue[$no])) continue;
    $p = rq_parts($a);
    $rows .= '<tr data-st="' . rq_state($a) . '" class="dim2"><td>' . $rmX('ask', $a) . '<a href="' . h(self_url(['tab' => 'members', 'c' => 'm:' . coupon_phone((string)$a['phone'])])) . '"><b>' . h($a['name'] ?: 'No name') . '</b></a><small>' . h(phone_fmt(coupon_phone((string)$a['phone']))) . ' · ' . h($p['perfumes']) . '</small></td>'
      . '<td class="nw"><b>' . h(date('d/m/Y', strtotime((string)$a['created']))) . '</b><small>' . h($no) . '</small></td>'
      . '<td class="r nw ra">' . rq_badge($a) . str_replace('title="Send on WhatsApp"', 'title="' . ($a['auto'] ? 'Sent by itself' : 'Send on WhatsApp') . '"', wa_btn((string)$a['phone'], (string)$a['name'], rq_head($p), rq_tail(), 'rq:' . $no, 'Sent ' . date('d/m', strtotime($a['sent'])), 'btn sm wag line')) . '</td></tr>';
  }
  $body .= '<div class="seg rqseg" role="group" aria-label="Show">' . implode('', array_map(fn($k, $l) => '<button type="button" data-rqf="' . $k . '"' . ($k === '' ? ' class="on"' : '') . '>' . $l . '</button>',
    ['', 'rv', 'part', 'not', 'ask'], ['All', 'Reviewed ' . ($st['rv'] ?? 0), 'Partly ' . ($st['part'] ?? 0), 'Not reviewed ' . ($st['not'] ?? 0), 'To ask&nbsp;<span data-rqn="ask">' . $open . '</span>'])) . '</div>';
  $body .= '<div class="box fill" data-csrf="' . h($CSRF) . '"><div class="bb np">'
    . ($rows ? '<table class="grid rflist rqlist"><thead><tr><th>Customer</th><th>Order</th><th class="r"></th></tr></thead><tbody>' . $rows . '</tbody></table>'
      : '<p class="empty">' . ($D['r'] !== 'all' ? 'No orders placed on these dates are on this list. Tap All to see everyone.' : 'Nobody to ask right now. Orders show here ' . $set['days'] . ' to ' . $set['list_to'] . ' days after they are placed, until the customer reviews them.') . '</p>')
    . '<p class="muted small rfnote">Each customer’s latest order, ' . $set['days'] . ' to ' . $set['list_to'] . ' days after it was placed, while nothing in it is reviewed. WhatsApp opens with a ready message and the order’s private review link (Verified Purchaser); the order then shows Sent. Anyone who reviewed all or part of an order is never asked again for it, and their Refill reminder has no review request. Reviewed and partly reviewed orders leave this list ' . RQ_DONE_DAYS . ' days after their latest review; the customer’s next order shows here as usual.' . ($set['drop'] ? ' Orders asked but not reviewed leave this list ' . $set['drop_days'] . ' days after sending.' : '') . '</p></div></div>';
} elseif ($tab === 'orders' && isset($_GET['refill'])) {
  /* Refill reminders: customers whose latest order was about 45 days ago (a bottle runs low around then), each with a ready WhatsApp message and a REFILL- coupon.
     Tapping WhatsApp notes Sent for that order, so nobody is reminded twice for the same order. Automatic sending (WhatsApp Business API) is set up in the box at the top. */
  require_once dirname(__DIR__) . '/api/whatsapp-lib.php';
  $due = refill_due(); $tm = refill_time(); $waOk = wa_ready(); $all = count($due);
  $D = pick_dates('refill') + ['tab' => 'orders'];   // Today / 7 days / 30 days / From–To: by the reminder day (order date + Days after order)
  $remDay = fn($o) => date('Y-m-d', strtotime(substr((string)$o['created'], 0, 10) . ' +' . $tm['days'] . ' days'));
  if ($D['r'] !== 'all') $due = array_filter($due, fn($o) => ($d = $remDay($o)) >= $D['from'] && $d <= $D['to']);
  $rq = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));   // search: name, mobile, order no or perfume
  if ($rq !== '') { $qd = preg_replace('/\D/', '', $rq); $qd = strlen($qd) >= 4 ? substr($qd, -10) : '';
    $due = array_filter($due, fn($o) => mb_stripos((string)$o['name'] . ' ' . $o['no'] . ' ' . $o['items'], $rq) !== false || ($qd !== '' && str_contains(coupon_phone((string)$o['phone']), $qd))); } $hookOk = trim((string)(wa_config()['app_secret'] ?? '')) !== '';
  $autoOn = $waOk && shop_setting('refill_auto') === '1'; $err = (string)shop_setting('refill_auto_err'); $nStop = count(wa_stops());
  $hsel = fn(string $nm, int $a, int $b, int $v) => '<select id="' . $nm . '" name="' . $nm . '">' . implode('', array_map(fn($x) => '<option value="' . $x . '"' . ($x === $v ? ' selected' : '') . '>' . refill_hour($x) . '</option>', range($a, $b))) . '</select>';
  $open = count(array_filter($due, fn($o) => !$o['sent'] && !$o['stopped']));
  $body .= $rqTabs('refill', date_bar($D, ['refill' => 1, 'q' => $rq]));
  $body .= '<details class="box rfauto"><summary><b>Automatic sending</b><span class="rfst' . ($autoOn ? ' on' : '') . '">' . ($autoOn ? 'On' : 'Off') . '</span><span class="muted small rfsum">'
    . ($autoOn ? 'sent by itself to customers who ticked WhatsApp offers, ' . $tm['days'] . ' days after the order, ' . refill_hour($tm['from']) . '–' . refill_hour($tm['to']) . ', ' . refill_pct() . '% off' . (refill_min() ? ' from ' . rupees(refill_min() * 100) : '') : 'messages are sent only when you tap WhatsApp') . '</span></summary>'
    . '<form method="post" class="bb rff" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="refill_auto">'
    . '<div class="rfg two"><label>WhatsApp access token<input name="wa_token" type="password" autocomplete="new-password" placeholder="' . ($waOk ? 'Saved' : 'From Meta → WhatsApp → API Setup') . '"></label>'
    . '<label>Phone number ID<input name="wa_phone_id" inputmode="numeric" autocomplete="off" placeholder="' . ($waOk ? 'Saved' : 'e.g. 123456789012345') . '"></label></div>'
    . '<label>App secret · for STOP replies<input name="wa_secret" type="password" autocomplete="new-password" placeholder="' . ($hookOk ? 'Saved' : 'Meta → your app → App settings → Basic') . '"></label>'
    . '<p class="muted small rfhook">STOP replies switch the customer off by themselves. In Meta → WhatsApp → Configuration → Webhook, paste Callback URL <b class="sel">https://fomaxo.in/api/whatsapp.php</b> and Verify token <b class="sel">' . h(wa_hook_token()) . '</b>, then subscribe to messages.' . ($nStop ? ' Stopped so far: ' . $nStop . '.' : '') . '</p>'
    . '<div class="rfg three"><label>Days after order<input name="rf_days" type="number" min="1" max="365" inputmode="numeric" value="' . $tm['days'] . '"></label>'
    . '<label>Send from' . $hsel('rf_from', 0, 23, $tm['from']) . '</label><label>Until' . $hsel('rf_to', 1, 24, $tm['to']) . '</label></div>'
    . '<div class="rfg two"><label>Coupon % off<input name="rf_pct" type="number" min="1" max="99" inputmode="numeric" value="' . refill_pct() . '"></label>'
    . '<label>Minimum order ₹<input name="rf_min" type="number" min="0" step="1" inputmode="numeric" value="' . (refill_min() ?: '') . '" placeholder="None"></label></div>'
    . '<p class="muted small" style="margin:0">The message says: <b>' . h(refill_offer(refill_pct(), refill_min())) . '</b></p>'
    . '<div class="rfrow"><div class="rfsw" role="radiogroup" aria-label="Automatic sending"><label><input type="radio" name="auto_on" value="1"' . ($autoOn ? ' checked' : '') . '><span>On</span></label><label><input type="radio" name="auto_on" value=""' . ($autoOn ? '' : ' checked') . '><span>Off</span></label></div>'
    . '<button class="btn sm">Save</button></div>'
    . ($err !== '' && $autoOn ? '<p class="small rferr">Last problem: ' . h($err) . '</p>' : '')
    . '<p class="muted small" style="margin:0">India time. Only customers who ticked “Send me offers on WhatsApp” at checkout get it by itself, once per order, using your approved template refill_reminder.</p></form></details>';
  $body .= '<div class="kpis n2 rfk" style="--n:2"><div class="kpi"><span>To remind</span><b data-rfn="todo">' . $open . '</b></div><div class="kpi"><span>Sent</span><b data-rfn="sent">' . count(array_filter($due, fn($o) => (bool)$o['sent'])) . '</b></div></div>';
  $body .= '<div class="rfbar"><form method="post" class="rfdays" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="refill_days"><span>Show</span>'
    . '<input name="rf_lfrom" type="number" min="1" max="730" inputmode="numeric" required value="' . $tm['list_from'] . '" aria-label="From day">'
    . '<span>to</span><input name="rf_lto" type="number" min="1" max="730" inputmode="numeric" required value="' . $tm['list_to'] . '" aria-label="To day">'
    . '<span>days<i> after the latest order</i></span><button class="btn sm">Save</button></form>'
    . '<form method="get" class="rfq"><input type="hidden" name="tab" value="orders"><input type="hidden" name="refill" value="1"><input type="search" name="q" value="' . h($rq) . '" placeholder="Name, mobile, order no or perfume" aria-label="Search refill reminders"><button class="btn line sm">Search</button></form></div>';
  $body .= '<div class="box fill" data-csrf="' . h($CSRF) . '"><div class="bb np">';
  if (!$due) $body .= '<p class="empty">' . ($all ? ($rq !== '' ? 'Nobody on this list matches “' . h($rq) . '”. ' : '') . ($D['r'] !== 'all' ? 'No reminders fall on these dates. ' : '') . 'Tap All or clear the search to see everyone.' : 'Nobody is due right now. Customers show here ' . $tm['list_from'] . ' to ' . $tm['list_to'] . ' days after their latest order.') . '</p>';
  else {
    $body .= '<table class="grid rflist"><thead><tr><th>Customer</th><th>Last order</th><th class="r"></th></tr></thead><tbody>';
    foreach ($due as $k => $o) {
      $p = refill_parts($o); $ph = coupon_phone((string)$o['phone']);
      $act = $o['stopped'] ? '<span class="rstop" title="Replied STOP on WhatsApp">Stopped</span>'
        : ($o['sent'] ? ''
          : '<span class="rdue" title="' . $tm['days'] . ' days after the order (Days after order)">' . ($o['days'] >= $tm['days'] ? 'Reminder due' : 'Reminder ' . h(date('d/m', strtotime(substr((string)$o['created'], 0, 10) . ' +' . $tm['days'] . ' days')))) . '</span>')
          . '<button type="button" class="btn sm' . ($o['sent'] ? ' line' : '') . '" data-rf="' . h($o['no']) . '" data-phone="' . h($ph) . '"' . wt_attr($ph) . ' data-who="' . h($o['name'] ?: phone_fmt($ph)) . '"'
          . ' data-first="' . h($p['first'] !== '' ? $p['first'] : 'there') . '" data-perfumes="' . h($p['perfumes']) . '" data-days="' . $p['days'] . '" data-review="' . h((string)$p['review']) . '"' . ($o['sent'] && $o['auto'] ? ' title="Sent by itself"' : '') . '>' . WA_SVG . '<span>' . ($o['sent'] ? 'Sent ' . h(date('d/m', strtotime($o['sent']))) : 'WhatsApp') . '</span></button>';
      $body .= '<tr' . ($o['sent'] || $o['stopped'] ? ' class="dim2"' : '') . '><td>' . $rmX('refill', $o) . '<a href="' . h(self_url(['tab' => 'members', 'c' => $k])) . '"><b>' . h($o['name'] ?: 'No name') . '</b></a>' . ($o['optin'] ? '<span class="wtag" title="Ticked at checkout: send me order updates and offers on WhatsApp">WhatsApp ✓</span>' : '')
        . '<small>' . h(phone_fmt($ph)) . ' · ' . h($p['perfumes']) . '</small></td>'
        . '<td class="nw"><b>' . $o['days'] . ' days</b><small>' . h(date('d/m/Y', strtotime((string)$o['created']))) . ' · ' . h($o['no']) . '</small></td><td class="r nw ra">' . $act . '</td></tr>';
    }
    $body .= '</tbody></table>';
  }
  $body .= wa_box('rfWa', 'gfree', 'pct', refill_pct(), refill_min(), 'A new REFILL- code just for this customer: one use, only with their mobile number. It is made when you tap Open WhatsApp.');
  $body .= '<p class="muted small rfnote">Customers whose latest order was ' . $tm['list_from'] . ' to ' . $tm['list_to'] . ' days ago. Each order gets one message: a tap or the automatic one. Once they order again they leave this list.</p></div></div>';
} elseif ($tab === 'orders' && isset($_GET['trash'])) {
  /* Trash: orders closed with the ✕ on Orders, newest closed first. Put back (one, or the ticked ones) returns an order as it was. */
  $rows = shop_db()->query('SELECT no, closed, row_json FROM orders_trash ORDER BY closed DESC, no DESC')->fetchAll();
  $body .= '<div class="row ctop trtop"><a class="btn line sm" href="' . h(self_url(['tab' => 'orders'])) . '">‹ Back to Orders</a><h2 class="trh">Trash <span class="trn">' . count($rows) . '</span></h2></div>'
    . '<p class="muted small trnote">Closed orders are kept here. They leave every list, money total, Analytics and Excel. They still count in the number of orders on Sales, Home, Analytics and Members, but not in the COD and online boxes on Orders, and their order number is never given again. Put back returns an order as it was.</p>';
  $body .= '<form method="post" class="box fill trform">' . $csrfField . '<input type="hidden" name="action" value="untrash">';
  if (!$rows) $body .= '<div class="bb"><p class="empty">Trash is empty.</p></div>';
  else {
    $body .= '<div class="bh trbar"><label class="trall"><input type="checkbox" data-trall> Select all</label><button class="btn sm" data-trsel disabled>Put back selected (0)</button></div>'
      . '<div class="bb np"><table class="grid trlist"><thead><tr><th class="tck"></th><th>Order</th><th>Customer</th><th class="r">Total</th><th>Status · closed</th><th class="r"></th></tr></thead><tbody>';
    foreach ($rows as $t) {
      $o = (json_decode((string)$t['row_json'], true) ?: []) + ['no' => $t['no'], 'created' => '', 'name' => '', 'phone' => '', 'total' => 0, 'method' => '', 'status' => '', 'paid_at' => null];
      $body .= '<tr><td class="tck"><input type="checkbox" name="nos[]" value="' . h($t['no']) . '" aria-label="Select ' . h($t['no']) . '"></td>'
        . '<td class="nw"><b>' . h($t['no']) . '</b><small>' . h(date('d/m/Y, H:i', strtotime((string)$o['created']))) . '</small></td>'
        . '<td><b>' . h($o['name'] ?: 'No name') . '</b><small>' . h((string)$o['phone']) . '</small></td>'
        . '<td class="r nw"><b>' . rupees((int)$o['total']) . '</b><small>' . ($o['method'] === 'cod' ? 'Cash on delivery' : 'Online (Razorpay)') . '</small></td>'
        . '<td class="nw"><span class="tags">' . order_tags($o) . '</span><small>Closed ' . h(date('d/m/Y', strtotime((string)$t['closed']))) . '</small></td>'
        . '<td class="r"><button class="btn sm line" name="one" value="' . h($t['no']) . '">Put back</button></td></tr>';
    }
    $body .= '</tbody></table></div>';
  }
  $body .= '</form>';
} elseif ($tab === 'orders') {
  require_once dirname(__DIR__) . '/api/whatsapp-lib.php'; require_once dirname(__DIR__) . '/api/review-req-lib.php';
  require_once dirname(__DIR__) . '/api/receipt-lib.php'; receipt_cols();   // each order's Receipt box (courier, tracking no, send)   // orders due a review request get a green WhatsApp Review button
  $rqAsk = []; foreach (rq_due() as $x) if (!$x['sent'] && !$x['stopped']) $rqAsk[$x['no']] = $x;
  /* every order's green WhatsApp button: the review request message with the order's Verified Purchaser link, whatever the day (FOMAXO can change
     the words in WhatsApp before sending); tapping marks the order Sent dd/mm. An order without a review link opens a plain chat. */
  $rqSent = json_decode((string)shop_setting('rvreq_sent'), true) ?: [];
  $waBtn = function (array $o) use ($rqSent): string {
    if (coupon_phone((string)$o['phone']) === '') return '';
    if ($o['no'] === '' || !preg_match('/^[a-f0-9]{32}$/', (string)$o['review']))
      return wa_btn_ph((string)$o['phone'], (string)$o['name'], order_hello($o), 'rqwa');
    $sd = (string)($rqSent[$o['no']] ?? '');
    return wa_btn((string)$o['phone'], (string)$o['name'], rq_head(rq_parts($o)), rq_tail(), 'rq:' . $o['no'], $sd !== '' ? 'Sent ' . date('d/m', strtotime(substr($sd, 0, 10))) : 'WhatsApp', 'rqwa' . ($sd !== '' ? ' done' : ''));
  };
  $SUM = order_summary($F);
  $page = max(1, (int)($_GET['page'] ?? 1));
  [$where, $args] = order_where($F);
  $cnt = shop_db()->prepare("SELECT COUNT(*) FROM orders$where"); $cnt->execute($args); $total = (int)$cnt->fetchColumn();
  $s = shop_db()->prepare("SELECT * FROM orders$where ORDER BY id DESC LIMIT " . ADMIN_PER_PAGE . ' OFFSET ' . (($page - 1) * ADMIN_PER_PAGE)); $s->execute($args);
  $orders = $s->fetchAll();
  $q = array_filter($F + ['tab' => 'orders']);
  $body .= '<div class="kpis n4" style="--n:4">'
    . '<a class="kpi k-new' . ($F['method'] === 'cod' ? ' on' : '') . '" href="' . h(self_url(array_filter(['method' => $F['method'] === 'cod' ? '' : 'cod'] + $q))) . '"><span>COD orders</span><b>' . $SUM['cod'][0] . '</b></a>'
    . '<a class="kpi k-paid' . ($F['method'] === 'online' ? ' on' : '') . '" href="' . h(self_url(array_filter(['method' => $F['method'] === 'online' ? '' : 'online'] + $q))) . '"><span>Online orders</span><b>' . $SUM['online'][0] . '</b></a>'
    . '<div class="kpi k-new"><span>COD amount</span><b>' . rupees($SUM['cod'][1]) . '</b></div>'
    . '<div class="kpi k-paid"><span>Online amount</span><b>' . rupees($SUM['online'][1]) . '</b></div></div>';
  $TC = order_track_counts($F);
  $body .= '<div class="steps5" aria-label="Orders by step">';
  foreach (TRACK_CHIPS as $k => $label) $body .= '<a class="step tc-' . $k . ($F['status'] === $k ? ' on' : '') . '" href="' . h(self_url(array_filter(['status' => $F['status'] === $k ? '' : $k] + $q))) . '"><span>' . $label . '</span><b>' . ($TC[$k] ?? 0) . '</b></a>';
  $body .= '</div>';
  /* who used a coupon: one gold box that shows only those orders, then a chip per code */
  $CC = order_coupon_counts(['coupon' => ''] + $F); $cOn = $F['coupon'] !== '';
  $body .= '<div class="cpbar"><a class="cpbox' . ($F['coupon'] === 'yes' ? ' on' : '') . '" href="' . h(self_url(array_filter(['coupon' => $F['coupon'] === 'yes' ? '' : 'yes'] + $q))) . '"><span>Used a coupon</span><b>' . $CC['n'] . '</b><small>' . ($CC['off'] ? rupees($CC['off']) . ' off in all' : ($CC['n'] ? 'Free gifts' : 'No coupon orders')) . '</small></a>';
  if ($CC['codes']) {
    $body .= '<div class="chips" aria-label="Orders by coupon code">';
    foreach ($CC['codes'] as $code => $n) $body .= '<a class="chip' . ($F['coupon'] === (string)$code ? ' on' : '') . '" href="' . h(self_url(array_filter(['coupon' => $F['coupon'] === (string)$code ? '' : $code] + $q))) . '"><span class="cpn">' . h($code) . '</span> <b>' . $n . '</b></a>';
    $body .= '</div>';
  }
  $body .= '</div>';
  if ($SUM['states']) {
    $body .= '<div class="chips" aria-label="Orders by state">';
    foreach ($SUM['states'] as $st => $n) $body .= '<a class="chip' . ($F['state'] === $st ? ' on' : '') . '" href="' . h(self_url(array_filter(['state' => $F['state'] === $st ? '' : $st] + $q))) . '">' . h($st) . ' <b>' . $n . '</b></a>';
    $body .= '</div>';
  }
  $nf = count(array_filter([$F['status'], $F['method'], $F['from'], $F['to']], 'strlen'));
  /* Today / 7 days / 30 days / All fill in From and To (All empties them); the other filters stay */
  $qd = array_diff_key($q, ['from' => 1, 'to' => 1, 'page' => 1]); $dseg = '';
  foreach (['today', 'd7', 'd30', 'all'] as $k) { [$a, $b] = preset_span($k); $dseg .= '<a href="' . h(self_url(array_filter(['from' => $a, 'to' => $b] + $qd))) . '"' . ($F['from'] === $a && $F['to'] === $b ? ' class="on"' : '') . '>' . DATE_PRESETS[$k] . '</a>'; }
  $body .= '<form class="filters' . ($nf ? ' open' : '') . '" id="ordFilters" method="get"><input type="hidden" name="tab" value="orders">' . ($F['state'] !== '' ? '<input type="hidden" name="state" value="' . h($F['state']) . '">' : '') . ($cOn ? '<input type="hidden" name="coupon" value="' . h($F['coupon']) . '">' : '')
    . '<label class="fx">Status' . $sel('status', ['' => 'All orders'] + TRACK_CHIPS + (in_array($F['status'], ['todo', 'new', 'paid'], true) ? [$F['status'] => ['todo' => 'Pending', 'new' => 'New', 'paid' => 'Paid'][$F['status']]] : []), $F['status']) . '</label>'
    . '<label class="fx">Payment' . $sel('method', ['' => 'COD and online', 'cod' => 'Cash on delivery (COD)', 'online' => 'Online (card / UPI)'], $F['method']) . '</label>'
    . '<label class="dq"><span class="hide-m">Dates</span><span class="seg">' . $dseg . '</span></label>'
    . '<label class="fx dfl">From' . date_box('from', $F['from'], 'From') . '</label><label class="fx dfl">To' . date_box('to', $F['to'], 'To') . '</label>'
    . '<label class="grow"><span class="hide-m">Search</span><input type="search" name="q" value="' . h($F['q']) . '" placeholder="Order no, name, mobile or coupon" aria-label="Search orders"></label>'
    . '<button type="button" class="btn line show-m-i" data-open="#ordFilters">Filters' . ($nf ? ' (' . $nf . ')' : '') . '</button><button class="btn line">Show</button><a class="btn" href="' . h(self_url($q + ['do' => 'excel'])) . '">Excel</a></form>';
  $back = h(json_encode($q + ['page' => $page]));
  $body .= '<form id="qa" method="post" hidden>' . $csrfField . '<input type="hidden" name="action" value="quick"><input type="hidden" name="back" value="' . $back . '"></form>';
  $body .= '<form id="trf" method="post" hidden>' . $csrfField . '<input type="hidden" name="action" value="trash"><input type="hidden" name="back" value="' . $back . '"></form>';
  $body .= '<form id="obk" method="post" hidden>' . $csrfField . '<input type="hidden" name="action" value="bulk"><input type="hidden" name="back" value="' . $back . '"></form>';
  $body .= '<div class="box fill" data-csrf="' . h($CSRF) . '"><div class="bh obh"><span class="muted small">' . $total . ' order' . ($total === 1 ? '' : 's') . '<span class="ohint">. Tap a button to update an order, or tap the order to see it.</span></span>'
    . '<span class="obulk">' . ($orders ? '<label class="oall"><input type="checkbox" data-oall> Select all</label><span class="obsel" hidden><b data-on>0</b> ticked'
    . '<button class="btn xs line" form="obk" name="act" value="paid" data-ask="Mark {n} ticked orders as paid? Only unpaid cash orders change.">Paid</button>'
    . '<button class="btn xs" form="obk" name="act" value="deliver" data-ask="Mark {n} ticked orders as delivered?">Delivered</button>'
    . '<button class="btn xs line danger" form="obk" name="act" value="close" data-ask="Close {n} ticked orders? They leave Sales, the boxes and every count, and are kept in Trash.">Close</button></span>' : '') . '</span>'
    . '<span class="ohb">' . sound_btn() . (($trN = shop_trash_count()) ? '<a class="btn xs line trbtn" href="' . h(self_url(['tab' => 'orders', 'trash' => 1])) . '">Trash (' . $trN . ')</a>' : '') . '<a class="btn xs rqbtn" href="' . h(self_url(['tab' => 'orders', 'ask' => 1])) . '">Review requests (' . count($rqAsk) . ')</a></span></div><div class="bb np olist has-rq">';
  if (!$orders) $body .= '<p class="empty">No orders' . (array_filter($F) ? ' for this filter' : ' yet') . '.</p>';
  $seenO = (string)shop_setting('seen_orders');   // orders placed since Orders was last opened get a red NEW tag (it goes on the next visit)
  foreach ($orders as $o) {
    $items = json_decode((string)$o['items'], true) ?: [];
    $btns = order_buttons($o);
    $body .= '<details class="order os-' . h($o['status']) . '"' . (count($orders) === 1 ? ' open' : '') . ' data-s="' . h(mb_strtolower(implode(' ', [$o['no'], $o['name'], $o['email'], $o['payment_id'], $o['admin_note'], $o['coupon']]))) . '" data-p="' . h(preg_replace('/\D/', '', (string)$o['phone'])) . '"><summary><span class="ox">'
      . '<input type="checkbox" class="otk" form="obk" name="ids[]" value="' . (int)$o['id'] . '" aria-label="Tick ' . h($o['no']) . '">'
      . '<button class="xbtn" form="trf" name="id" value="' . (int)$o['id'] . '" data-confirm="Close ' . h($o['no']) . '? It leaves Sales, the boxes and every count, and is kept in Trash." title="Close this order (kept in Trash)" aria-label="Close ' . h($o['no']) . '">✕</button>' . $orderThumb($o) . '</span>'
      . '<span class="no">' . h($o['no'] ?: 'Not paid') . ($seenO !== '' && $o['created'] > $seenO ? ' <span class="onew">NEW</span>' : '') . order_waiting($o) . '</span><span class="dt">' . h(date('d M Y, H:i', strtotime($o['created']))) . '</span>'
      . '<span class="cu"><b>' . h($o['name']) . '</b><small>' . h($o['phone']) . ((int)($o['wa_optin'] ?? 0) ? ' <span class="wa-in" title="Ticked at checkout: send me order updates and offers on WhatsApp">✓ WhatsApp</span>' : '') . '</small>' . order_coupon_tag($o) . '</span>'
      . '<span class="tt">' . rupees((int)$o['total']) . '<small>' . ($o['method'] === 'cod' ? 'Cash on delivery' : 'Online') . ($o['test'] ? ' · TEST' : '') . '</small></span>'
      . '<span class="tags">' . order_tags($o) . '</span><span class="acts">' . $btns . $waBtn($o) . '</span></summary>'
      . '<div class="otop">' . order_tracker($o) . '</div>'
      . '<div class="od"><div class="items"><h4>Items</h4>';
    foreach ($items as $it) $body .= '<div class="li">' . $thumbOf((string)($it['id'] ?? ''), 'th sm') . '<span class="grow"><b>' . h($it['name'] ?? $it['id'] ?? '') . '</b><small>' . (int)($it['qty'] ?? 0) . ' × ' . (!empty($it['free']) ? '<b class="cfree">Free</b>' : rupees((int)($it['unit'] ?? 0))) . ($it['desc'] ?? '' ? ' · ' . h($it['desc']) : '') . '</small></span></div>';
    $body .= ($o['coupon'] !== '' ? '<p class="small muted">Coupon <b class="cpn">' . h($o['coupon']) . '</b> −' . rupees((int)$o['discount']) . '</p>' : '')
      . ($o['cod_fee'] ? '<p class="small muted">Cash on delivery fee ' . rupees((int)$o['cod_fee']) . '</p>' : '') . '<p><b>Total ' . rupees((int)$o['total']) . '</b></p></div>'
      . '<div><h4>Delivery</h4><p>' . h($o['name']) . '<br>' . h($o['address']) . '</p><p><a href="tel:' . h(preg_replace('/[^0-9+]/', '', $o['phone'])) . '">' . h($o['phone']) . '</a> ' . wa_btn_ph((string)$o['phone'], (string)$o['name'], order_hello($o), 'btn xs wag') . '<br><a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a></p>'
      . '<p class="small ' . ((int)($o['wa_optin'] ?? 0) ? 'wa-in">✓ Wants order updates and offers on WhatsApp' : 'muted">Did not tick WhatsApp offers') . '</p>'
      . ($o['note'] ? '<p class="muted">Customer note: ' . h($o['note']) . '</p>' : '') . '</div>'
      . '<div><h4>Payment</h4><p>' . h(pay_label($o)) . ($o['payment_id'] ? '<br><small class="muted">' . h($o['payment_id']) . '</small>' : '') . ($o['paid_at'] ? '<br><small class="muted">Paid ' . h(date('d M Y, H:i', strtotime($o['paid_at']))) . '</small>' : '') . '</p>'
      . '<form method="post" class="stform">' . $csrfField . '<input type="hidden" name="action" value="status"><input type="hidden" name="id" value="' . (int)$o['id'] . '"><input type="hidden" name="back" value="' . $back . '">'
      . '<label>Status <small class="muted">(saves when you pick)</small>' . str_replace('<select name="status">', '<select name="status" data-autosave data-no="' . h($o['no']) . '">', $sel('status', order_status_options($o), $o['status'])) . '</label>'
      . '<label>Your note<input name="admin_note" value="' . h($o['admin_note']) . '" maxlength="500" placeholder="Only you see this"></label>'
      . '<div class="qbtns"><button class="btn line">Save</button></div></form>'
      . '<p class="muted small">Cancelling or refunding puts the items back in stock.</p></div>' . receipt_card($o, $csrfField, $back) . '</div></details>';
  }
  $body .= '<datalist id="couriers"><option value="Delhivery"><option value="Blue Dart"><option value="DTDC"><option value="India Post"><option value="Xpressbees"><option value="Ekart"><option value="Shiprocket"></datalist>';
  if ($total > ADMIN_PER_PAGE) {
    $body .= '<div class="pager">';
    for ($i = 1; $i <= (int)ceil($total / ADMIN_PER_PAGE); $i++) $body .= '<a' . ($i === $page ? ' class="on"' : '') . ' href="' . h(self_url($q + ['page' => $i])) . '">' . $i . '</a>';
    $body .= '</div>';
  }
  $body .= '</div></div>';
}

/* ============ Coupons ============ */
if ($tab === 'coupons') {
  /* one-use coupons used before they deleted themselves (api/cod.php, api/razorpay.php) go now too */
  shop_tx(function (PDO $db) { foreach ($db->query("SELECT code FROM coupons WHERE phone <> '' OR (kind = 'free' AND max_uses > 0)")->fetchAll(PDO::FETCH_COLUMN) as $cc) shop_coupon_spent($db, (string)$cc); });
  $CP = shop_coupons(); $ed = coupon_clean((string)($_GET['edit'] ?? '')); $E = $CP[$ed] ?? null; $today = date('Y-m-d');
  if ($E && $E['phone'] !== '') $E = null;   // a goodwill coupon is made once and not edited
  $goodwill = fn(array $c) => (string)($c['phone'] ?? '') !== '';   // a goodwill coupon: one mobile number, one use, no end date
  $state = fn(array $c) => $goodwill($c) && $c['uses'] ? ['used', 'Used'] : (!(int)$c['active'] ? ['off', 'Off'] : ($goodwill($c) ? (coupon_time($c) === 'over' ? ['end', 'Expired'] : ['on', 'Not used']) : (coupon_time($c) === 'over' ? ['end', 'Expired'] : ((int)$c['max_uses'] && $c['uses'] >= (int)$c['max_uses'] ? ['end', 'Used up'] : (coupon_time($c) === 'soon' ? ['soon', 'Starts later'] : ['on', 'On'])))));
  /* a time limit in the list: "6 Oct, 6:00 pm" (the year only when it is not this year; no hour for a whole day) */
  $at = fn(string $v, string $whole) => $v === '' ? '' : date(substr($v, 0, 4) === date('Y') ? 'j M' : 'j M Y', strtotime($v)) . (substr($v, 11) === $whole ? '' : ', ' . date('g:i a', strtotime($v)));
  $limit = function (array $c) use ($at) { $s = $at(coupon_starts($c), '00:00'); $e = $at(coupon_ends($c), '23:59');
    return $s && $e ? "$s → $e" : ($s ? "From $s" : ($e ? "Until $e" : '')); };
  $tbox = fn(string $name, string $v, string $whole) => '<div class="cpt2">' . date_box($name, substr($v, 0, 10), $name === 'starts' ? 'Starts on' : 'Ends on')
    . '<input type="time" name="' . $name . '_time" value="' . h(strlen($v) > 10 && substr($v, 11) !== $whole ? substr($v, 11, 5) : '') . '" aria-label="' . ($name === 'starts' ? 'Start time' : 'End time') . '"></div>';
  $num = fn($p) => $p % 100 ? number_format($p / 100, 2, '.', '') : (string)intdiv($p, 100);
  $panes = ['list' => 'Your coupons', 'add' => $E ? 'Edit ' . h($E['code']) : 'Add a coupon'];
  $body .= $sw('#cpPanes', $E ? array_reverse($panes, true) : $panes) . '<div class="exp panes" id="cpPanes">'
    . '<form method="post" class="box' . ($E ? ' on' : '') . '" data-pane="add">' . $csrfField . '<input type="hidden" name="action" value="coupon_save">' . ($E ? '<input type="hidden" name="editing" value="1">' : '')
    . '<div class="bh"><h3>' . ($E ? 'Edit ' . h($E['code']) : 'Add a coupon') . '</h3>' . ($E ? '<a class="btn line sm" href="' . h(self_url(['tab' => 'coupons'])) . '">New coupon</a>' : '') . '</div><div class="bb cpf" style="padding-top:12px">'
    . '<label>Code<span class="cpcode"><input name="code" maxlength="20" required value="' . h($E['code'] ?? '') . '" placeholder="WELCOME10" autocapitalize="characters" autocomplete="off" spellcheck="false"' . ($E ? ' readonly' : '') . '>'
    . ($E ? '' : '<button type="button" class="btn line sm" data-mkcode title="Make a code for me">Make code</button>') . '</span></label>'   // Make code fills in one like FOMAXO-7K2Q (admin.js)
    . '<div class="cpv"><label>Coupon gives<span class="seg ck">'
    . '<label><input type="radio" name="kind" value="pct"' . (($E['kind'] ?? 'pct') === 'pct' ? ' checked' : '') . '>% off</label>'
    . '<label><input type="radio" name="kind" value="amt"' . (($E['kind'] ?? '') === 'amt' ? ' checked' : '') . '>₹ off</label>'
    . '<label><input type="radio" name="kind" value="free"' . (($E['kind'] ?? '') === 'free' ? ' checked' : '') . '>Free product</label></span></label></div>'
    . '<div class="cp2"><label class="cpamt">Amount<input type="number" name="value" min="1" step="any" inputmode="decimal" value="' . h($E && $E['kind'] !== 'free' ? ($E['kind'] === 'pct' ? (string)$E['value'] : $num((int)$E['value'])) : '') . '" placeholder="10"></label>'
    . '<label class="cpfree">Free product' . free_pick('free', $E) . '</label>'
    . '<label><span class="cpmin">Minimum order ₹</span><span class="cpspend">Spend at least ₹</span><input type="number" name="min_order" min="0" step="any" inputmode="decimal" value="' . h($E && $E['min_order'] ? $num((int)$E['min_order']) : '') . '" placeholder="None"></label></div>'
    . '<div class="cpf cpx">'
    /* how the coupon mixes with the website offer (multi-buy): one ring dot must be picked; new and old coupons start on "Use the bigger offer" */
    . '<div class="cpst" role="radiogroup" aria-label="With the website offer">'
    . '<label><input type="radio" name="stack" value="0"' . (empty($E['stack']) ? ' checked' : '') . ' required><span><b>Use the bigger offer</b><i>Coupon or website offer, whichever saves more</i></span></label>'
    . '<label><input type="radio" name="stack" value="1"' . (!empty($E['stack']) ? ' checked' : '') . '><span><b>Use both</b><i>Website offer first, then the coupon on top</i></span></label></div>'
    . '<div class="cptl"><b>Time limit</b> <small class="muted">(optional; no time = the whole day)</small></div>'
    . '<label class="cprow"><span>Starts</span>' . $tbox('starts', $E ? coupon_starts($E) : '', '00:00') . '</label>'
    . '<label class="cprow"><span>Ends</span>' . $tbox('ends', $E ? coupon_ends($E) : '', '23:59') . '</label>'
    . '<label class="cprow cpuse"><span>Usage limit</span><input type="number" name="max_uses" min="1" step="1" inputmode="numeric" value="' . h($E && $E['max_uses'] ? (string)$E['max_uses'] : '') . '" placeholder="No limit (total orders)"></label></div>'
    . '<label class="cpon"><input type="checkbox" name="per_cust" value="1"' . (!empty($E['per_cust']) ? ' checked' : '') . '> One use per customer (mobile number)</label>'
    . '<label>WhatsApp mobile<input name="wa_to" type="tel" inputmode="tel" maxlength="16" value="' . h(($E['wa_to'] ?? '') !== '' ? phone_fmt($E['wa_to']) : '') . '" placeholder="Optional · 98765 43210" autocomplete="off"></label>'
    . '<div class="cpsave"><button class="btn">' . ($E ? 'Save ' . h($E['code']) : 'Add coupon') . '</button><button class="btn wag" name="sendwa" value="1">' . WA_SVG . '<span>WhatsApp</span></button></div>'
    . '<p class="muted small" style="margin:0">Shoppers type the code in “Have a coupon code?” at checkout. It works for cash on delivery and online payment, and the discount is worked out on the server.</p></div></form>'
    . '<div class="box' . ($E ? '' : ' on') . '" data-pane="list"><div class="bh"><h3>Your coupons · ' . count($CP) . '</h3></div>';
  /* Goodwill coupon: the owner types a customer's mobile number and picks % off, ₹ off or a free product; a new one-use code for that number only, no end date */
  $M = $CP[coupon_clean((string)($_GET['made'] ?? ''))] ?? null; $N = $M && !$goodwill($M) && (int)$M['active'] ? $M : null; $M = $M && $goodwill($M) ? $M : null;
  $body .= '<form method="post" class="sry">' . $csrfField . '<input type="hidden" name="action" value="coupon_goodwill">'
    . '<div class="sry-h"><b>Goodwill coupon</b><small class="muted">For a late delivery or a faulty product: % off, ₹ off or a free product. One use, only this mobile number. No end date unless you pick one. It deletes itself once used.</small></div>'
    . '<div class="sry-f"><input name="phone" type="tel" inputmode="tel" maxlength="16" required placeholder="Mobile" aria-label="Customer mobile number" autocomplete="off">'
    . '<select name="gkind" class="sry-kind" aria-label="Coupon gives"><option value="pct">% off</option><option value="amt">₹ off</option><option value="free">Free product</option></select>'
    . '<input name="pct" class="sry-val" type="number" min="1" step="1" inputmode="numeric" placeholder="10" aria-label="How much off">'
    . str_replace('class="fpick"', 'class="fpick sry-free"', free_pick('gfree', null))
    . '<label class="sry-end">' . str_replace('placeholder="dd/mm/yyyy"', 'placeholder="Ends (optional)"', date_box('ends', '', 'End date (optional)')) . '</label>'
    . '<button class="btn">Make coupon</button></div>'
    . ($M ? '<div class="sry-done"><span><b class="cpn">' . h($M['code']) . '</b> ' . h(coupon_label($M)) . ' · ' . h(phone_fmt($M['phone'])) . (coupon_ends($M) !== '' ? ' · till ' . h(date('j M Y', strtotime(coupon_ends($M)))) : '') . '</span>'
      . wa_btn((string)$M['phone'], phone_fmt($M['phone']), goodwill_text($M), '', 'cp:' . $M['code'], 'Send on WhatsApp', 'btn sm wag', true) . '</div>' : '')
    . '</form>'
    /* WhatsApp next to Add coupon: the coupon is saved, then its WhatsApp box opens by itself (to the WhatsApp mobile, if one was typed) */
    . ($N ? '<span hidden>' . wa_btn((string)$N['wa_to'], (string)$N['wa_to'] !== '' ? phone_fmt($N['wa_to']) : $N['code'], coupon_share_text($N), '', 'cp:' . $N['code'], 'WhatsApp', 'btn sm wag cpopen', true) . '</span>'
      . '<script>addEventListener("load",function(){var b=document.querySelector(".cpopen");if(b)b.click()})</script>' : '')
    . '<div class="bb np">';
  if (!$CP) $body .= '<p class="empty">No coupons yet. Add one, like WELCOME10 for 10% off.</p>';
  else {
    $body .= '<table class="grid cpt"><thead><tr><th>Code</th><th>Discount</th><th class="hide-m">Minimum</th><th class="hide-m">Time limit</th><th class="r">Used</th><th class="r hide-m">Sales</th><th class="r hide-m">Given off</th><th></th></tr></thead><tbody>';
    $cpSent = json_decode((string)shop_setting('coupon_wa'), true) ?: [];   // coupon code → day its WhatsApp was tapped (shows Sent dd/mm)
    foreach ($CP as $c) {
      [$sk, $sl] = $state($c);
      $wa = $goodwill($c) ? (!$c['uses'] && str_starts_with($c['code'], 'GOODWILL-') ? goodwill_text($c) : '') : ((int)$c['active'] ? coupon_share_text($c) : '');   // CART-, REFILL-, REVIEW- and COMEBACK- codes go out from their own WhatsApp messages
      $used = $c['uses'] . ((int)$c['max_uses'] ? ' / ' . (int)$c['max_uses'] : '');
      $body .= '<tr class="cs-' . $sk . '"><td><b class="cpn">' . h($c['code']) . '</b><small><span class="badge cb-' . $sk . '">' . $sl . '</span></small></td>'
        . ($goodwill($c) ? '<td>' . h(coupon_label($c)) . ' <span class="cpstag goodwill">' . (['REFILL-' => 'Refill', 'CART-' => 'Left cart', 'REVIEW-' => 'Review', 'COMEBACK-' => 'Left at checkout', 'THANKS-' => 'WhatsApp'][preg_replace('/^([A-Z]+-).*$/', '$1', $c['code'])] ?? 'Goodwill') . '</span><small class="sry-ph">For ' . h(phone_fmt($c['phone'])) . '</small>' . ($limit($c) !== '' ? '<small class="show-m">' . h($limit($c)) . '</small>' : '') . '</td>'
          . '<td class="hide-m"><span class="muted">None</span></td><td class="hide-m">' . ($limit($c) !== '' ? h($limit($c)) : '<span class="muted">No end date</span>') . '</td>'
        : '<td>' . h(coupon_label($c)) . ($c['kind'] === 'free' ? ' <span class="cpstag free">Free product</span>' : (!empty($c['stack']) ? ' <span class="cpstag both">' . coupon_stack_label($c) . '</span>' : ''))   // only "Use both" is tagged; the bigger offer is the usual
          . (!empty($c['per_cust']) ? ' <span class="cpstag">1 per customer</span>' : '') . (($c['wa_to'] ?? '') !== '' ? '<small class="sry-ph">WhatsApp ' . h(phone_fmt($c['wa_to'])) . '</small>' : '') . '<small class="show-m">' . h(implode(' · ', array_filter([(int)$c['min_order'] ? 'Min ' . rupees((int)$c['min_order']) : '', $limit($c)]))) . '</small></td>'
          . '<td class="hide-m">' . ((int)$c['min_order'] ? rupees((int)$c['min_order']) : '<span class="muted">None</span>') . '</td>'
          . '<td class="hide-m">' . ($limit($c) !== '' ? h($limit($c)) : '<span class="muted">No limit</span>') . '</td>')
        . '<td class="r"><a href="' . h(self_url(['tab' => 'orders', 'coupon' => $c['code']])) . '" title="See the orders">' . $used . '</a></td>'
        . '<td class="r hide-m">' . rupees($c['sales']) . '</td><td class="r hide-m">' . rupees($c['given']) . '</td>'
        . '<td class="r nw"><form method="post" class="cpa" data-csrf="' . h($CSRF) . '">' . $csrfField . '<input type="hidden" name="code" value="' . h($c['code']) . '"><input type="hidden" name="on" value="' . ((int)$c['active'] ? '0' : '1') . '">'
        . ($wa !== '' ? wa_btn((string)($goodwill($c) ? $c['phone'] : ($c['wa_to'] ?? '')), $goodwill($c) ? phone_fmt($c['phone']) : (($c['wa_to'] ?? '') !== '' ? phone_fmt($c['wa_to']) : $c['code']), $wa, '', 'cp:' . $c['code'], ($sd = $cpSent[$c['code']] ?? '') !== '' ? 'Sent ' . date('d/m', strtotime($sd)) : 'WhatsApp', 'btn sm wag sry-wa' . ($sd !== '' ? ' line' : ''), true) : '')   // once tapped, the button itself says Sent dd/mm
        . '<button class="btn line sm" name="action" value="coupon_on">' . ((int)$c['active'] ? 'Turn off' : 'Turn on') . '</button>'
        . (!$goodwill($c) ? '<a class="btn line sm" href="' . h(self_url(['tab' => 'coupons', 'edit' => $c['code']])) . '">Edit</a>' : '')
        . '<button class="linkbtn" name="action" value="coupon_delete" data-confirm="Delete coupon ' . h($c['code']) . '?' . ($c['uses'] ? ' Orders that used it keep the code.' : '') . '">Delete</button></form></td></tr>';
    }
    $body .= '</tbody></table><p class="muted small" style="padding:0 12px">Used counts orders placed or paid with the code (cancelled and refunded orders give the use back). Tap the number to see those orders. Goodwill coupons, and free product coupons with a usage limit, delete themselves once used up; their orders keep the code.</p>';
  }
  $body .= '</div></div></div>';
}

/* ============ Offer: the limited-time offer popup and countdown lines, and the New product popup, on the website ============ */
if ($tab === 'offer') {
  $O = shop_offer(); $left = $O['mode'] === 'end' && $O['end'] !== '' ? strtotime($O['end']) - time() : 0;
  $what = implode(' + ', array_filter([$O['popup'] ? 'popup' : '', $O['lines'] ? 'line by prices on ' . ($O['linesAll'] ? 'all ' : '') . count($O['lines']) . ' product' . (count($O['lines']) > 1 ? 's' : '') : '']));
  $best = fomaxo_best_pct($O['items'])[0];
  [$sk, $status] = match (true) {
    $O['mode'] === 'off' => ['off', 'Off. Nothing shows on the website.'],
    $what === '' => ['off', 'Nothing shows: tick Popup, or pick products for the line.'],
    $O['mode'] === 'end' && $left <= 0 => ['end', 'Ended ' . offer_when($O['end']) . '. Nothing shows now.'],
    $O['mode'] === 'end' => ['on', 'On until ' . offer_when($O['end']) . ' (' . offer_left($left) . '): ' . $what . '.'],
    default => ['on', 'On, no timer: ' . $what . '.'],
  };
  $end = $O['end'];
  $radio = fn(string $v, string $label) => '<label><input type="radio" name="mode" value="' . $v . '"' . ($O['mode'] === $v ? ' checked' : '') . '>' . $label . '</label>';
  $tick = fn(string $k, string $label) => '<label class="cpon"><input type="checkbox" name="' . $k . '" value="1"' . ($O[$k] ? ' checked' : '') . '><span>' . $label . '</span></label>';
  /* products that can be in the offer: on the website, with an old price; the biggest % each one saves */
  $sale = [];
  foreach ($CAT as $id => $p) { if (!empty($p['hidden'])) continue; $n = 0;
    foreach ($p['prices'] as $k => $v) { $w = (float)($p['was'][$k] ?? 0); if ($w > $v && $v > 0) $n = max($n, (int)round(($w - $v) / $w * 100)); }
    if ($n) $sale[(string)$id] = $n; }
  /* what the preview needs about a product: photo, name and the price of its size with the biggest saving */
  $pv = function (string $id) use ($CAT) { $p = $CAT[$id]; $bk = null; $bn = -1;
    foreach ($p['prices'] as $k => $v) { $w = (float)($p['was'][$k] ?? 0); $n = $w > $v && $v > 0 ? ($w - $v) / $w : 0; if ($n > $bn) [$bn, $bk] = [$n, $k]; }
    $r = fn($v) => '₹' . number_format((float)$v, fmod((float)$v, 1) ? 2 : 0);
    $w = (float)($p['was'][$bk] ?? 0);
    return ' data-name="' . h($p['name']) . '" data-img="' . h($p['img'] ? '/' . $p['img'] : '') . '" data-price="' . h($r($p['prices'][$bk])) . '" data-was="' . h($w > $p['prices'][$bk] ? $r($w) : '') . '"'; };
  arsort($sale);
  /* a product picker: type or pick a name to add it; only the picked ones show, untick to take one out */
  $picker = function (string $k, array $on, string $none, string $ph, string $extra = '') use ($sale, $CAT, $pv, $thumbOf) {
    $c = '';
    foreach ($sale as $id => $n) $c .= '<label class="ofit" title="' . h($CAT[$id]['name']) . '"><input type="checkbox" name="' . $k . '[]" value="' . h($id) . '" data-pct="' . $n . '"' . $pv($id) . (in_array($id, $on, true) ? ' checked' : '') . '>'
      . $thumbOf($id, 'th sm') . '<span>' . h($CAT[$id]['name']) . '</span><small>' . $n . '% off</small></label>';
    return '<div class="ofpick"><div class="ofaddrow"><input class="ofadd" list="ofw-items" placeholder="' . $ph . '" autocomplete="off" aria-label="' . $ph . '">' . $extra . '</div>'
      . '<div class="ofits">' . $c . '<span class="ofnone muted small">' . $none . '</span></div></div>'; };
  /* a popup words box: type anything, or pick from the list (a datalist) */
  $word = fn(string $k, string $label, int $max, string $std, array $list) => '<label>' . $label . '<input name="' . $k . '" maxlength="' . $max . '" list="ofw-' . $k . '" value="' . h($O[$k]) . '" placeholder="' . h($std) . '" autocomplete="off">'
    . '<datalist id="ofw-' . $k . '">' . implode('', array_map(fn($v) => '<option value="' . h($v) . '">', $list)) . '</datalist></label>';

  $N = shop_newprod(); $np = $N['id'] !== '' ? ($CAT[$N['id']] ?? null) : null;
  $nsk = $N['on'] && $N['name'] !== '' ? 'on' : 'off';
  $nstatus = $nsk === 'on' ? 'On: “' . $N['label'] . '” for ' . $N['name'] . '.' : 'Off. No new product popup shows.';
  $opts = '<option value="">No product (no photo)</option>';
  foreach ($CAT as $id => $p) $opts .= '<option value="' . h($id) . '" data-img="' . h($p['img'] ? '/' . $p['img'] : '') . '"' . ($N['id'] === (string)$id ? ' selected' : '') . '>' . h($p['name']) . (!empty($p['hidden']) ? ' (hidden)' : '') . '</option>';

  $waFirst = ($_GET['pane'] ?? '') === 'wa';   // back on the WhatsApp pane after a switch is tapped (phones show one pane at a time)
  $body .= $sw('#ofPanes', $waFirst ? ['wa' => 'WhatsApp', 'sale' => 'Sale offer', 'new' => 'New product'] : ['sale' => 'Sale offer', 'new' => 'New product', 'wa' => 'WhatsApp']) . '<div class="exp panes ofgrid" id="ofPanes">'
    /* box 1: the sale offer */
    . '<div class="box ofr' . ($waFirst ? '' : ' on') . '" data-pane="sale"><div class="bh"><h3>Sale offer</h3></div><div class="bb">'
    . '<p class="ofst ofst-' . $sk . '">' . h($status) . '</p>'
    . '<form method="post" class="cpf" id="offerForm" data-best="' . (int)fomaxo_best_pct()[0] . '">' . $csrfField . '<input type="hidden" name="action" value="offer_save">'
    /* product picture size in the popup: changed with the sliders in Preview, saved with Save */
    . '<input type="hidden" name="size_l" value="' . $O['sizeL'] . '" data-min="' . SHOP_OFFER_SIZE['L'][0] . '" data-def="' . SHOP_OFFER_SIZE['L'][1] . '" data-max="' . SHOP_OFFER_SIZE['L'][2] . '">'
    . '<input type="hidden" name="size_p" value="' . $O['sizeP'] . '" data-min="' . SHOP_OFFER_SIZE['P'][0] . '" data-def="' . SHOP_OFFER_SIZE['P'][1] . '" data-max="' . SHOP_OFFER_SIZE['P'][2] . '">'
    . implode('', array_map(fn($d) => implode('', array_map(fn($k) => '<input type="hidden" name="fs[' . $d . '][' . $k . ']" value="' . $O['fs'][strtoupper($d)][$k] . '" data-min="' . SHOP_OFFER_FS[$k][0] . '" data-def="100" data-max="' . SHOP_OFFER_FS[$k][1] . '">', array_keys(SHOP_OFFER_FS))), ['l', 'p']))
    . '<div class="ofrow"><b>Timer</b><span class="seg ck ofm">' . $radio('end', 'Countdown') . $radio('always', 'Always on') . $radio('off', 'Off') . '</span></div>'
    . '<div class="ofend"' . ($O['mode'] === 'end' ? '' : ' hidden') . '><b>Ends</b><div class="ofendin">'
    . '<span class="qbtns">' . implode('', array_map(fn($hh, $l) => '<button class="btn line sm" name="quick" value="' . $hh . '" title="Save, ending ' . $l . ' from now">' . $l . '</button>', [24, 48, 72, 168], ['24h', '48h', '3 days', '7 days'])) . '</span>'
    . '<span class="cpt2">' . date_box('end', substr($end, 0, 10), 'Ends on')
    . '<input type="time" name="end_time" value="' . h(strlen($end) > 10 ? substr($end, 11, 5) : '') . '" aria-label="End time (India time)" title="India time; empty = 11:59 pm"></span></div></div>'
    . '<div class="ofrow"><div class="ofk"><b>Popup</b>' . $tick('popup', 'On') . '</div><div class="ofcol"><div class="ofwords">'
    . $word('title', 'Top line', 30, 'LIMITED TIME OFFER', ['Limited time offer', 'Flash sale', 'Festive sale', 'Diwali offer', 'Weekend sale', 'Mega sale', 'Special offer', 'New launch offer'])
    . '<label>% off<input type="number" name="pct" step="any" inputmode="numeric" value="' . ($O['pct'] ? (int)$O['pct'] : '') . '" placeholder="' . ($best ? "max $best" : 'No old prices') . '"' . ($best ? '' : ' disabled') . '></label>'
    . $word('sub', 'Under the %', 40, 'ON SELECTED FRAGRANCES', ['ON SELECTED PRODUCTS', 'ON SELECTED FRAGRANCES', 'ON SELECTED PERSONAL CARE', 'ON ALL FRAGRANCES', 'ON PERFUMES', 'ON GIFT SETS', 'ON EVERYTHING', 'ON YOUR FIRST ORDER'])
    . $word('btn', 'Button', 24, 'Shop the offer', ['Shop the offer', 'Shop now', 'Grab the deal', 'Shop fragrances', 'See the offer'])
    . '</div>' . ($sale ? $picker('items', $O['items'], 'None picked: the popup counts every product with an old price.', 'Add a product to the popup') : '<p class="warn small" style="margin:0">No product has an old price on Products yet, so the popup stays hidden.</p>') . '</div></div>'
    . '<div class="ofrow"><div class="ofk"><b>Line by prices</b></div>'
    . ($sale ? '<div class="ofcol oflines">' . $picker('lines', $O['lines'], 'None picked: no line shows.', 'Add a product', '<button type="button" class="btn line sm" data-ofsame title="Pick the same products as the popup">Same as popup</button>'
        . '<label class="btn line sm ofall" title="Show the line on every product with an old price, new ones too"><input type="checkbox" name="lines_all" value="1"' . ($O['linesAll'] ? ' checked' : '') . '>All products</label>')
      . '<p class="ofallon muted small">On all ' . count($sale) . ' products with an old price. Products you give an old price later get it too.</p></div>' : '<p class="muted small" style="margin:0">Needs products with an old price.</p>') . '</div>'
    . ($sale ? '<datalist id="ofw-items">' . implode('', array_map(fn($id, $n) => '<option value="' . h($CAT[$id]['name']) . '" label="' . $n . '% off">', array_keys($sale), $sale)) . '</datalist>' : '')
    . '<div class="row ofbtns"><button class="btn">Save</button><button type="button" class="btn line" data-preview="sale">Preview</button><button class="btn danger sm" name="action" value="offer_off" formnovalidate data-confirm="Turn the sale offer off? The popup and lines leave the website.">Turn off</button></div>'
    . '</form></div></div>'
    /* box 2: the New product popup */
    . '<div class="box ofr" data-pane="new"><div class="bh"><h3>New product popup</h3><label class="cpon"><input type="checkbox" name="np_on" value="1" form="newpForm"' . ($N['on'] ? ' checked' : '') . '><span>On</span></label></div><div class="bb">'
    . '<p class="ofst ofst-' . $nsk . '">' . h($nstatus) . '</p>'
    . '<form method="post" class="cpf" id="newpForm">' . $csrfField . '<input type="hidden" name="action" value="newprod_save">'
    . implode('', array_map(fn($d) => implode('', array_map(fn($k) => '<input type="hidden" name="nfs[' . $d . '][' . $k . ']" value="' . $N['fs'][strtoupper($d)][$k] . '" data-min="' . SHOP_NEWP_FS[$k][0] . '" data-def="100" data-max="' . SHOP_NEWP_FS[$k][1] . '">', array_keys(SHOP_NEWP_FS))), ['l', 'p']))
    . '<div class="npgrid"><label>Type<input name="np_label" maxlength="24" list="ofw-np_label" value="' . h($N['label']) . '" placeholder="Coming soon" autocomplete="off">'
    . '<datalist id="ofw-np_label">' . implode('', array_map(fn($v) => '<option value="' . h($v) . '">', ['Coming soon', 'Just arrived', 'New launch', 'Launching soon', 'Back in stock', 'Now available', 'Only at FOMAXO'])) . '</datalist></label>'
    . '<label>Product<select name="np_id">' . $opts . '</select></label></div><div class="npgrid">'
    . '<label>Name<input name="np_name" maxlength="40" value="' . h($N['name']) . '" placeholder="' . h($np['name'] ?? 'Royal Oud') . '"></label>'
    . '<label>Short line<input name="np_line" maxlength="90" value="' . h($N['line']) . '" placeholder="Optional"></label></div>'
    . '<div class="row ofbtns"><button class="btn">Save</button><button type="button" class="btn line" data-preview="new">Preview</button></div>'
    . '<p class="muted small" style="margin:0">Shows once per visitor, before the sale popup.</p>'
    . '</form></div></div>'
    /* box 3: the "New here? 10% off, coupon on WhatsApp" boxes, each On / Off (never saved = Off) */
    . '<div class="box ofr ofwa' . ($waFirst ? ' on' : '') . '" data-pane="wa"><div class="bh"><h3>WhatsApp popups</h3></div><div class="bb">'
    . '<form method="post" class="cpf" onchange="this.submit()">' . $csrfField . '<input type="hidden" name="action" value="wa_pops">'
    . '<p class="muted small" style="margin:0">“New here? Get 10% off, coupon on WhatsApp” for new visitors. Saves as you tap.</p>'
    . '<div class="ofwas">' . implode('', array_map(fn($k, $n) => '<label class="pg" title="' . h($n[1]) . '"><span><b>' . h($n[0]) . '</b></span><input type="checkbox" class="tgl" name="wa[]" value="' . h($k) . '"' . (shop_wa_popups()[$k] ? ' checked' : '') . ' aria-label="Show WhatsApp ' . h(strtolower($n[0])) . '"></label>', array_keys(SHOP_WA_POPUPS), SHOP_WA_POPUPS)) . '</div>'
    . '<noscript><button class="btn">Save</button></noscript>'
    . '</form></div></div></div>';
}

/* ============ Members ============ */
$ckey = (string)($_GET['c'] ?? '');
if ($tab === 'members' && $ckey !== '' && ($C = customer($ckey))) {
  /* the customer page */
  $W = $C['web']; $wa = fn(string $cls) => wa_btn_ph((string)$C['phone'], (string)$C['name'], wa_hello((string)$C['name']), $cls);
  $dt = fn($s) => $s ? h(date('d M Y', is_int($s) ? $s : strtotime($s))) : '—';
  $body .= '<div class="row ctop"><a class="btn line sm" href="' . h(self_url(['tab' => 'members'])) . '">‹ Members</a><h2>' . h($C['name']) . '</h2><span class="muted">' . h(phone_fmt($C['phone']) ?: $C['email']) . '</span><span class="sp"></span>'
    . $wa('btn sm wag') . '</div>';
  $body .= '<div class="kpis n6" style="--n:6">'
    . '<div class="kpi"><span>Orders</span><b>' . $C['count'] . '</b></div><div class="kpi"><span>Total spent</span><b>' . rupees($C['spent']) . '</b></div>'
    . '<div class="kpi"><span>Average order</span><b>' . rupees($C['avg']) . '</b></div><div class="kpi"><span>Reviews</span><b>' . count($C['reviews']) . '</b></div>'
    . '<div class="kpi"><span>Visits</span><b>' . count($W['visits']) . '</b></div><div class="kpi"><span>Time on site</span><b>' . ($W['visits'] ? duration($W['seconds']) : '—') . '</b></div></div>';
  $body .= $sw('#cPanes', ['details' => 'Details', 'web' => 'On the website', 'orders' => 'Orders', 'reviews' => 'Reviews']) . '<div class="cust panes" id="cPanes">';
  /* WhatsApp offers: Yes (ticked at checkout), Stopped (replied STOP, or stopped here), or No; Stop asks first */
  require_once dirname(__DIR__) . '/api/whatsapp-lib.php';
  $waOn = (bool)array_filter($C['all'], fn($o) => (int)($o['wa_optin'] ?? 0) === 1); $cStop = $C['phone'] !== '' && !$waOn ? (string)(wa_stops()[$C['phone']] ?? '') : '';
  $waRow = ($waOn ? '<span class="wtag" style="margin:0">Yes ✓</span>' : ($cStop !== '' ? '<span class="rstop">Stopped ' . h(date('d/m', strtotime(substr($cStop, 0, 16)))) . '</span> <span class="muted small">· ' . (str_ends_with($cStop, 'reply') ? 'replied STOP' : 'stopped by you') . '</span>' : 'No'))
    . ($C['phone'] !== '' && $cStop === '' ? '<form method="post" class="wastop">' . $csrfField . '<input type="hidden" name="action" value="wa_stop"><input type="hidden" name="phone" value="' . h($C['phone']) . '"><button class="btn line xs" data-confirm="Stop WhatsApp offers and automatic messages for ' . h($C['name']) . '?">Stop</button></form>' : '');
  /* details */
  $body .= '<div class="box on" data-pane="details"><div class="bh"><h3>Details</h3></div><div class="bb"><dl class="dl">'
    . '<dt>Mobile</dt><dd>' . ($C['phone'] ? '<a href="' . h(phone_tel($C['phone'])) . '">' . h(phone_fmt($C['phone'])) . '</a> ' . $wa('btn xs wag') : '—') . '</dd>'
    . '<dt>WhatsApp offers</dt><dd>' . $waRow . '</dd>'
    . '<dt>Email</dt><dd>' . ($C['email'] ? '<a href="mailto:' . h($C['email']) . '">' . h($C['email']) . '</a>' : '—') . '</dd>'
    . '<dt>Address</dt><dd>' . h($C['address']) . '</dd><dt>State</dt><dd>' . h($C['state'] ?: '—') . '</dd>'
    . '<dt>First order</dt><dd>' . $dt($C['first']) . '</dd><dt>Last order</dt><dd>' . $dt($C['last']) . '</dd>'
    . '<dt>Cancelled</dt><dd>' . $C['cancelled'] . '</dd></dl></div></div>';
  /* on the website */
  $body .= '<div class="box" data-pane="web"><div class="bh"><h3>On the website</h3><span class="muted small">From the device they ordered on</span></div><div class="bb">';
  if (!$W['visits']) $body .= '<p class="empty">No visits recorded yet. Visits are counted from the browser they checked out on, from when this was turned on.</p>';
  else {
    $body .= '<dl class="dl"><dt>Visits</dt><dd>' . count($W['visits']) . '</dd><dt>Time on site</dt><dd>' . duration($W['seconds']) . '</dd><dt>Pages viewed</dt><dd>' . $W['pages'] . '</dd>'
      . '<dt>First visit</dt><dd>' . $dt($W['first']) . '</dd><dt>Last visit</dt><dd>' . $dt($W['last']) . '</dd><dt>Came from</dt><dd>' . h(SOURCES[$W['source']] ?? ucfirst($W['source'])) . '</dd>'
      . '<dt>Device</dt><dd>' . h(ucfirst($W['device'])) . '</dd><dt>Left at checkout</dt><dd>' . $W['left'] . ' time' . ($W['left'] === 1 ? '' : 's') . '</dd></dl>';
    if ($W['products']) { $body .= '<h4>Products viewed</h4>'; foreach ($W['products'] as $pid => $n) $body .= '<div class="li">' . $thumbOf($pid, 'th sm') . '<span class="grow"><b>' . h($CAT[$pid]['name'] ?? $pid) . '</b></span><span class="muted small">' . $n . ' view' . ($n === 1 ? '' : 's') . '</span></div>'; }
    $body .= '<h4>Visits</h4>';
    foreach ($W['visits'] as $v) $body .= '<div class="li"><span class="grow"><b>' . h(date('d M Y, H:i', (int)$v['started'])) . '</b><small>' . h(SOURCES[$v['source']] ?? ucfirst($v['source'])) . ' · ' . h(ucfirst($v['device'])) . ' · ' . (int)$v['pages'] . ' page' . ((int)$v['pages'] === 1 ? '' : 's')
      . ($v['viewed'] ? ' · ' . h(implode(', ', array_map(fn($x) => $CAT[$x]['name'] ?? $x, $v['viewed']))) : '') . '</small></span><span class="muted small">' . duration($v['secs']) . '</span></div>';
  }
  $body .= '</div></div>';
  /* orders */
  $body .= '<div class="box" data-pane="orders"><div class="bh"><h3>Orders</h3><span class="muted small">' . count($C['all']) . ' in all</span></div><div class="bb np">';
  foreach ($C['all'] as $o) {
    $items = json_decode((string)$o['items'], true) ?: [];
    $body .= '<a class="corder os-' . h($o['status']) . '" href="' . h(self_url(array_filter(['tab' => 'orders', 'q' => $o['no'] ?: $o['name']]))) . '"><div class="ch"><b>' . h($o['no'] ?: 'Not paid') . '</b><span class="muted small">' . h(date('d M Y', strtotime($o['created']))) . ' · ' . h(pay_label($o)) . '</span><span class="sp"></span><b>' . rupees((int)$o['total']) . '</b><span class="badge st-' . h($o['status']) . '">' . h(FOMAXO_STATUSES[$o['status']] ?? $o['status']) . '</span></div>';
    foreach ($items as $it) $body .= '<div class="ci">' . $thumbOf((string)($it['id'] ?? ''), 'th xs') . '<span>' . (int)($it['qty'] ?? 0) . ' × ' . h($it['name'] ?? '') . '</span><span class="muted">' . (!empty($it['free']) ? 'Free' : rupees((int)($it['unit'] ?? 0))) . '</span></div>';
    $body .= '</a>';
  }
  $body .= '</div></div>';
  /* reviews */
  $body .= '<div class="box" data-pane="reviews"><div class="bh"><h3>Reviews</h3></div><div class="bb">';
  if (!$C['reviews']) $body .= '<p class="empty">No reviews from this customer yet.</p>';
  foreach ($C['reviews'] as $r) $body .= '<div class="rv"><div class="rvh">' . $thumbOf($r['product'], 'th xs') . '<b>' . h($CAT[$r['product']]['name'] ?? $r['product']) . '</b>' . stars((float)$r['rating']) . '<span class="sp"></span><span class="muted small">' . h(date('d M Y', (int)$r['created'])) . '</span>' . ($r['status'] !== 'live' ? '<span class="badge st-cancelled">' . ($r['status'] === 'pending' ? 'Waiting' : 'Hidden') . '</span>' : '') . '</div>' . ($r['body'] !== '' ? '<p>' . nl2br(h($r['body'])) . '</p>' : '') . '</div>';
  $body .= '</div></div></div>';
} elseif ($tab === 'members') {
  $min = member_min(); $spend = member_spend(); $mq = trim((string)($_GET['q'] ?? ''));
  $D = pick_dates('members'); $list = members($min, $spend, $mq, $D['from'], $D['to']);
  $rule = $min . ' or more orders' . ($spend ? ' or ₹' . number_format($spend) . ' or more spent' : '') . ($D['r'] === 'all' ? '' : ' in ' . ($D['r'] === 'custom' ? date_span($D['from'], $D['to']) : ($D['r'] === 'today' ? 'today' : 'the last ' . period_label($D))));
  $body .= date_bar($D, ['q' => $mq]) . '<div class="row mtool">'
    . '<form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="member_min"><label class="mrule">Orders: at least<input type="number" name="member_min" min="1" max="999" value="' . $min . '"></label><button class="btn line sm">Save</button></form>'
    . '<form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="member_spend"><label class="mrule">Spent: at least ₹<input type="number" name="member_spend" min="0" max="10000000" value="' . ($spend ?: '') . '" placeholder="off"></label><button class="btn line sm">Save</button></form>'
    . '<form method="get" class="msearch"><input type="hidden" name="tab" value="members"><input type="search" name="q" value="' . h($mq) . '" placeholder="Name, mobile or email"><button class="btn line sm">Search</button></form>'
    . '<a class="btn sm" href="' . h(self_url(array_filter(['do' => 'members_excel', 'q' => $mq]))) . '">Excel</a>'
    . '</div>';
  $body .= '<div class="box fill"><div class="bh"><span class="muted small">' . count($list) . ' member' . (count($list) === 1 ? '' : 's') . ' with ' . $rule . ', most spent first. Cancelled and test orders are left out. An empty amount box turns that filter off. Tap a member to open their page.</span></div><div class="bb np">';
  if (!$list) $body .= '<p class="empty">' . ($mq !== '' ? 'No member matches “' . h($mq) . '”.' : 'No customer has ' . $rule . ' yet. Lower the numbers above to see more.') . '</p>';
  else {
    $body .= '<table class="grid mlist"><thead><tr><th>Name</th><th class="hide-m">Mobile</th><th class="hide-m">Email</th><th class="hide-m">Address</th><th class="r">Orders</th><th class="r">Spent</th><th class="r"></th></tr></thead><tbody>';
    foreach ($list as $m) {
      $url = h(self_url(['tab' => 'members', 'c' => $m['key']]));
      $body .= '<tr data-href="' . $url . '"><td><a href="' . $url . '"><b>' . h($m['name']) . '</b></a><small class="show-m">' . h(implode(' · ', array_filter([$m['phone'] ? phone_fmt($m['phone']) : '', $m['state']]))) . '</small></td>'
        . '<td class="hide-m">' . ($m['phone'] ? h(phone_fmt($m['phone'])) : '—') . '</td>'
        . '<td class="hide-m">' . h($m['email']) . '</td><td class="hide-m"><span class="clip">' . h($m['address']) . '</span></td><td class="r">' . $m['count'] . '</td><td class="r"><b>' . rupees($m['spent']) . '</b></td>'
        . '<td class="r">' . wa_btn_ph((string)$m['phone'], (string)$m['name'], wa_hello((string)$m['name']), 'btn sm wag mwa') . '</td></tr>';
    }
    $body .= '</tbody></table><script>document.querySelectorAll("tr[data-href]").forEach(function(r){r.onclick=function(e){if(!e.target.closest("a,button"))location.href=r.dataset.href}})</script>';
  }
  $body .= '</div></div>';
}

/* ============ Stock ============ */
if ($tab === 'stock') {
  $STOCK = shop_stock(); $low = shop_low_stock(); $COST = shop_costs();
  /* warning boxes: Running low (1 up to the "Only X left" level) and Out of stock (0); sizes not counted are in neither */
  $nLow = $nOut = 0;
  foreach ($CAT as $id => $p) foreach ($p['prices'] as $opt => $_) { $v = $STOCK[$id][$opt] ?? null; if ($v === null) continue; if ($v < 1) $nOut++; elseif ($low && $v <= $low) $nLow++; }
  $body .= '<div class="swarn"><button type="button" class="sbox s-low" data-only="low" aria-pressed="false"><span>⚠ Running low</span><b>' . $nLow . '</b><small>' . ($low ? $low . ' left or fewer' : '“Only X left” is off') . '</small></button>'
    . '<button type="button" class="sbox s-out" data-only="out" aria-pressed="false"><span>Out of stock</span><b>' . $nOut . '</b><small>0 left</small></button></div>'
    . '<p class="sonly" hidden><span></span> <button type="button" class="btn line sm" data-only="">Show all</button></p>';
  $body .= '<div class="row top"><form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="low_stock">'
    . '<label class="chk" style="font-size:14px">Show “Only X left” from <input type="number" name="low_stock" min="0" max="99" value="' . $low . '" style="width:70px"> left or fewer</label><label class="chk" style="font-size:14px">Max per item per order <input type="number" name="max_per_item" min="1" max="999" placeholder="30" value="' . shop_max_per_item() . '" style="width:70px"></label><button class="btn line sm">Save</button><span class="muted small">0 turns “Only X left” off. Max empty = 30; above it customers see “Contact FOMAXO for Massive Order”.</span></form>'
    . '<input type="search" class="grow" id="stockFind" placeholder="Find a product" aria-label="Find a product" style="max-width:320px;margin-left:auto"></div>'
    . '<form method="post" class="box fill" data-watch>' . $csrfField . '<input type="hidden" name="action" value="stock"><input type="hidden" name="low_stock" value="' . $low . '">'
    . '<div class="bb np"><table class="grid stock"><thead><tr><th>Product</th><th>Size</th><th>Stock</th><th>Cost ₹</th><th class="hide-m">On the shop</th></tr></thead><tbody>';
  $lines = []; foreach ($CAT as $id => $p) foreach ($p['prices'] as $opt => $_) $lines[] = [$id, (string)$opt];
  $at = array_flip((array)(json_decode((string)shop_setting('stock_order'), true) ?: []));   // the order dragged on this page; new sizes go at the end
  $n0 = count($at); foreach ($lines as $i => $l) $lines[$i][2] = $at["$l[0]|$l[1]"] ?? $n0 + $i;
  usort($lines, fn($x, $y) => $x[2] <=> $y[2]);
  foreach ($lines as [$id, $opt]) { $p = $CAT[$id];
    {
      $v = $STOCK[$id][$opt] ?? null; $c = $COST[$id][$opt] ?? null;
      $state = !empty($p['hidden']) ? '<span class="badge st-awaiting">Hidden</span>' : ($v === null ? ($p['soldOut'] ? '<span class="badge st-cancelled">Sold out</span>' : '<span class="muted small">Not counted</span>')
        : ($v < 1 ? '<span class="badge st-cancelled">Sold out</span>' : ($low && $v <= $low ? '<span class="badge st-new">Only ' . $v . ' left</span>' : '<span class="badge st-paid">In stock</span>')));
      $body .= '<tr data-s="' . ($v === null ? '' : ($v < 1 ? 'out' : ($low && $v <= $low ? 'low' : ''))) . '" data-k="' . h("$id|$opt") . '" data-name="' . h(mb_strtolower($p['name'] . ' ' . kind_label($p['kind']))) . '"><td><div class="pc"><span class="sdrag" title="Drag to change the order of this list" aria-hidden="true"><svg width="10" height="16" viewBox="0 0 10 16" fill="currentColor"><circle cx="2" cy="2" r="1.6"/><circle cx="8" cy="2" r="1.6"/><circle cx="2" cy="8" r="1.6"/><circle cx="8" cy="8" r="1.6"/><circle cx="2" cy="14" r="1.6"/><circle cx="8" cy="14" r="1.6"/></svg></span>' . $thumbOf($id, 'th sm') . '<div><b>' . h($p['name']) . '</b><small>' . h(kind_label($p['kind'])) . '</small></div></div></td><td>' . h(opt_label($p, (string)$opt)) . '</td>'
        . '<td><input type="number" min="0" max="99999" name="stock[' . h($id) . '][' . h((string)$opt) . ']" value="' . ($v === null ? '' : $v) . '" placeholder="—"></td>'
        . '<td><input type="number" min="0" step="0.01" name="cost[' . h($id) . '][' . h((string)$opt) . ']" value="' . ($c === null ? '' : h((string)round($c / 100, 2))) . '" placeholder="—"></td><td class="hide-m">' . $state . '</td></tr>';
    }
  }
  $body .= '</tbody></table><p class="empty" id="stockNone" hidden>No product matches.</p></div><div class="bf"><button class="btn">Save</button><span class="unsaved" hidden>Not saved yet</span><span class="muted small"><b>Stock:</b> how many bottles you have. <b>Cost:</b> what one bottle costs you; Sales uses it to work out your profit. <b>Order:</b> drag the dots on the left to move a row.</span><b class="tgok sok" aria-live="polite"></b></div></form>'
    . '<script>(function(){var f=document.getElementById("stockFind"),rows=document.querySelectorAll("table.stock tbody tr"),none=document.getElementById("stockNone"),only="",note=document.querySelector(".sonly");'
    . 'function show(){var q=f.value.trim().toLowerCase(),n=0;rows.forEach(function(r){var on=(!q||r.dataset.name.indexOf(q)>-1)&&(!only||r.dataset.s===only);r.hidden=!on;if(on)n++});none.textContent=only&&!q?(only==="low"?"Nothing is running low.":"Nothing is out of stock."):"No product matches.";none.hidden=n>0;'
    . 'document.querySelectorAll(".sbox").forEach(function(b){var on=b.dataset.only===only;b.classList.toggle("on",on);b.setAttribute("aria-pressed",on)});note.hidden=!only;note.firstChild.textContent=only?"Showing only "+(only==="low"?"sizes running low.":"sizes out of stock."):""}'
    . 'f.oninput=show;document.querySelectorAll("[data-only]").forEach(function(b){b.onclick=function(){only=b.dataset.only&&b.dataset.only!==only?b.dataset.only:"";show()}});'
    /* save sends only the boxes you changed, so sizes hidden by a filter (and stock sold since the page opened) stay as they are */
    . 'f.closest(".row").nextElementSibling.addEventListener("submit",function(e){e.target.querySelectorAll("table.stock input").forEach(function(i){if(i.value===i.defaultValue)i.disabled=true})})})()</script>';
}

/* ============ Products ============ */
$editId = (string)($_GET['edit'] ?? ''); $adding = !empty($_GET['add']);
if ($tab === 'products' && ($adding || ($editId !== '' && isset($CAT[$editId])))) {
  $ep = $adding ? ['kind' => '', 'sizes' => [50], 'prices' => [], 'compareAt' => []] : product_now($editId, $LIVE);
  $kind = (string)($ep['kind'] ?? ''); $care = $kind === 'care';
  $imgs = $adding ? [] : photos_of($ep);
  $COSTS = shop_costs(); $STOCK = shop_stock(); $n = $ep['notes'] ?? []; $v = fn($k) => h((string)($ep[$k] ?? ''));
  $hidden = !$adding && !empty($CAT[$editId]['hidden']);
  $cls = fn($k) => $adding ? ' class="' . $k . '"' : '';
  $body .= '<form method="post" enctype="multipart/form-data" class="box fill" id="pf" data-watch>' . $csrfField . '<input type="hidden" name="action" value="' . ($adding ? 'add' : 'edit') . '">' . ($adding ? '' : '<input type="hidden" name="id" value="' . h($editId) . '">')
    . '<div class="bh"><h2>' . ($adding ? 'Add a product' : 'Edit ' . h($CAT[$editId]['name'])) . ' <small class="muted">' . ($adding ? '' : h(kind_label($kind))) . '</small></h2><a href="' . h(self_url(['tab' => 'products'])) . '">‹ All products</a></div><div class="bb"><div class="pform">'
    . ($adding ? '<label>Category<select name="kind" id="kind"><option value="">Fragrance (perfume)</option><option value="car">Car fragrance</option><option value="care">Personal care</option></select></label>' : '')
    . '<label>Name<input name="name" required maxlength="60" value="' . $v('name') . '" placeholder="e.g. Velvet Oud"></label>'
    . '<label>Type<input name="type" required maxlength="80" value="' . h((string)($care ? ($ep['type'] ?? '') : ($ep['family'] ?? ''))) . '" placeholder="e.g. Eau de Parfum, or Moisturizing Shampoo"></label>';
  if ($adding || $kind === '') $body .= '<label' . $cls('k-frag') . '>Collection<select name="tier">' . implode('', array_map(fn($k, $l) => '<option value="' . $k . '"' . (($ep['tier'] ?? '') === $k ? ' selected' : '') . ">$l</option>", ['', 'elite', 'signature', 'prestige'], ['None', 'Elite', 'Signature', 'Prestige'])) . '</select></label>';
  if ($adding || $care) $body .= '<label' . $cls('k-care') . '>Collection<select name="cat">' . implode('', array_map(fn($k) => '<option value="' . $k . '"' . (($ep['cat'] ?? '') === $k ? ' selected' : '') . '>' . ucfirst($k) . '</option>', ['hair', 'body', 'face', 'lips'])) . '</select></label>';
  if ($adding || $kind !== '') $body .= '<label' . $cls('k-one') . '>Size shown<input name="vol" maxlength="30" value="' . $v('vol') . '" placeholder="e.g. 250ml, or Hanging diffuser"></label>';
  $body .= '<label>Badge<input name="label" maxlength="24" value="' . h((string)($care ? ($ep['badge'] ?? '') : ($ep['tag'] ?? ''))) . '" placeholder="optional, e.g. New, Bestseller"></label>'
    . '<label class="chk" style="align-self:end;padding-bottom:8px"><input type="checkbox" name="show" value="1"' . ($hidden ? '' : ' checked') . '> Show on the website</label>'
    . '<label class="wide">Short description<input name="short" maxlength="200" value="' . $v('short') . '" placeholder="One sentence shown under the name"></label>'
    . '<label class="wide">Full description<textarea name="description" rows="5" maxlength="3000" placeholder="Leave an empty line between paragraphs">' . h(implode("\n\n", (array)($ep['description'] ?? []))) . '</textarea></label>';
  if ($adding || $kind === '') $body .= '<div class="wide notes' . ($adding ? ' k-frag' : '') . '"><label>Top notes<input name="top" maxlength="120" value="' . h((string)($n['top'] ?? '')) . '"></label><label>Heart notes<input name="heart" maxlength="120" value="' . h((string)($n['heart'] ?? '')) . '"></label><label>Base notes<input name="base" maxlength="120" value="' . h((string)($n['base'] ?? '')) . '"></label></div>'
    . '<label class="wide' . ($adding ? ' k-frag' : '') . '">Key notes <small>(used when top, heart and base are not all filled in)</small><input name="key" maxlength="160" value="' . h((string)($n['key'] ?? '')) . '"></label>';
  $rows = $care ? [['one', $ep['price'] ?? '', $ep['was'] ?? '']] : array_map(fn($sz) => [$sz, $ep['prices'][$sz] ?? '', $ep['compareAt'][$sz] ?? ''], (array)($ep['sizes'] ?? []));
  $body .= '<h3>Sizes and prices</h3><div class="wide"><p class="muted small" style="margin:0 0 8px">One row per size' . ($adding ? ' (perfumes; other products use the first row only)' : ($kind === '' ? '' : ' (this product has one)')) . '. “Original” is crossed out and customers pay the “Exclusive” price while the discount is On (Products list switch); Off, they pay the Original. “My cost” is what one costs you, for profit. Empty a row to stop selling that size.</p>';
  foreach (range(0, ($adding || $kind === '') ? 3 : 0) as $i) {
    [$sz, $pr, $wa] = $rows[$i] ?? ['', '', '']; $opt = $care || $kind === 'car' ? 'one' : (string)$sz;
    $cost = $opt !== '' && isset($COSTS[$editId][$opt]) ? rtrim(rtrim(number_format($COSTS[$editId][$opt] / 100, 2, '.', ''), '0'), '.') : '';
    $body .= '<div class="srow' . ($adding && $i ? ' k-frag' : '') . '">' . ($adding || $kind === '' ? '<label' . $cls('k-frag') . '>Size (ml)<input type="number" name="size[]" min="1" max="1000" value="' . h((string)$sz) . '"></label>' : '')
      . '<label>Original ₹<input type="number" step="0.01" min="0" name="was[]" placeholder="optional" value="' . h((string)($wa ?: '')) . '"></label>'
      . '<label>Exclusive ₹<input type="number" step="0.01" min="1" name="price[]" value="' . h((string)$pr) . '"' . ($i ? '' : ' required') . '></label>'
      . '<label>My cost ₹<input type="number" step="0.01" min="0" name="cost[]" placeholder="optional" value="' . h($cost) . '"></label>'
      . ($adding ? '<label>Stock<input type="number" min="0" name="stock[]" placeholder="not tracked"></label>' : '') . '</div>';
  }
  $body .= '</div><h3>Photos</h3><div class="wide"><p class="muted small" style="margin:0 0 8px">' . ($imgs ? 'Use ‹ › to move a photo, or Make main to put it first. The main photo shows on the shop card and first on the product page. Tap a photo to see it large, crop or turn it. ' : '') . ($adding ? '' : 'Added and removed photos are saved at once. ') . 'Add photos straight from your phone’s camera roll.</p><div class="photos" id="phs">';
  foreach ($imgs as $i => $k) $body .= '<div class="ph' . ($i ? '' : ' main') . '"><input type="hidden" name="photo_seq[]" value="' . h($k) . '"><b class="phl">' . ($i ? 'Photo ' . ($i + 1) : 'Main photo') . '</b><img src="' . h(img_url($k)) . '" alt="" loading="lazy"><span class="pedt" aria-hidden="true">✎</span>'
    . '<div class="phb"><button type="button" class="btn line sm" data-mv="-1" aria-label="Move left">‹</button><button type="button" class="btn line sm mk" data-mv="0">Make main</button><button type="button" class="btn line sm" data-mv="1" aria-label="Move right">›</button></div>'
    . '<button type="button" class="btn line sm phrm" data-rm>Remove</button></div>';
  $body .= '</div><label style="margin-top:10px">Add photos' . ($adding ? ' <small>(up to 4 at a time)</small>' : '') . '<input type="file" id="phadd" name="photos[]" accept="image/*" multiple' . ($adding ? ' required' : '') . '></label><p class="muted small" data-phmsg style="margin:4px 0 0"></p></div></div></div>'
    . '<div class="bf"><button class="btn">' . ($adding ? 'Add product' : 'Save changes') . '</button><a class="btn line" href="' . h(self_url(['tab' => 'products'])) . '">Cancel</a></div></form>';
  if ($adding) $body .= '<script>(function(){var k=document.getElementById("kind");function u(){var v=k.value;document.querySelectorAll(".k-frag").forEach(function(e){e.hidden=v!==""});document.querySelectorAll(".k-care").forEach(function(e){e.hidden=v!=="care"});document.querySelectorAll(".k-one").forEach(function(e){e.hidden=v===""});}k.onchange=u;u();})();</script>';
} elseif ($tab === 'products' && isset($_GET['videos'])) {
  /* Products → Videos: short upright videos on the home page. Tapping one on the website opens it full screen with its product's Add To Cart / Buy Now */
  /* Automatic Instagram reels are fetched by api/live.php after a shopper's page has its answer (at most once an hour), never while this page loads */
  try { video_inbox(); } catch (Throwable $e) {}   // videos sent from fomaxo.com show here straight away
  if (shop_setting('shape_fix_2810') !== '1') {   // once: the two videos Make All Videos Again turned 16:9 on 28/10/2026 go back to 9:16, as they were
    $vs = shop_videos(); foreach ($vs as &$x) foreach (['file', 'hq'] as $k) if (preg_match('/^(fomaxo-81d06340|dollar-944695cf)[.-]/', (string)($x[$k] ?? ''))) unset($x['wide']); unset($x);
    shop_videos_save($vs); shop_set('shape_fix_2810', '1');
  }
  $vids = shop_videos(); $n = count($vids); $max = upload_max(); $allOn = shop_setting('videos_off') !== '1';
  $comBox = video_com_inbox() !== ''; $sendOk = fn($v) => video_to_com_ok($v) && (($v['hq'] ?? '') === '' || !r2_conf());   // a video with a 1080p copy goes to fomaxo.com by itself (vshrink.js), no link
  $toComN = $comBox ? count(array_filter($vids, $sendOk)) : 0;   // videos not on fomaxo.com yet
  /* Product in the video: one box to type a name in, or pick from its own dropdown (the coupon free product picker, admin.js [data-ffind]) */
  $vpick = fn(string $aria) => '<span class="fpick vpick"><input type="text" name="product_text" maxlength="60" data-ffind data-free placeholder="Type anything, or pick a product" aria-label="' . $aria . '" autocomplete="off">'
    . '<input type="hidden" name="product"><ul class="fplist" hidden>'
    . implode('', array_map(fn($id, $p) => '<li data-v="' . h($id) . '">' . h($p['name']) . (!empty($p['hidden']) ? ' (hidden)' : '') . '</li>', array_keys($CAT), $CAT))
    . '<li data-v="-">No product · Shop Now button</li></ul></span>';
  $vpost = fn(array $v, string $act, string $inner, string $extra = '') => '<form method="post"' . $extra . '>' . $csrfField . '<input type="hidden" name="action" value="' . $act . '"><input type="hidden" name="vid" value="' . h($v['id']) . '">' . $inner . '</form>';
  $rows = '';
  foreach ($vids as $i => $v) {
    $ps = video_products($v, $CAT); $p = $CAT[$ps[0] ?? ''] ?? null; $off = empty($v['on']);
    $fsz = empty($v['embed']) && preg_match('~^[a-z0-9-]+\.(mp4|mov|webm)$~', (string)($v['file'] ?? '')) && is_file(video_dir() . '/' . $v['file']) ? (int)filesize(video_dir() . '/' . $v['file']) : 0;   // 0 = the video file is not on the server
    $fnote = !empty($v['embed']) ? '' : ($fsz ? ' · ' . ($fsz >= 1048576 ? round($fsz / 1048576, 1) . ' MB' : max(1, round($fsz / 1024)) . ' KB') : ' · <b class="vbad">File missing, upload again</b>');
    $mbs = function (string $f) use (&$r2hv): string {   // size of a copy on Cloudflare (or still here, waiting to copy), shown on its row (AJAY 28/10/2026)
      $b = (int)(($r2hv ??= r2_have())[$f] ?? 0); if (!$b && is_file(video_dir() . '/' . basename($f))) $b = (int)filesize(video_dir() . '/' . basename($f));
      return $b ? ' ' . ($b >= 1048576 ? round($b / 1048576, $b >= 10485760 ? 0 : 1) . ' MB' : max(1, round($b / 1024)) . ' KB') : '';
    };
    if (($v['hq'] ?? '') !== '') $fnote .= ' · 1080p' . $mbs((string)$v['hq']) . (isset(($r2hv ??= r2_have())[$v['hq']]) ? ' on Cloudflare' : ' waiting to copy');   // the sharp copy (only on Cloudflare)
    $orig = (string)($v['orig'] ?? ''); $origUp = $orig !== '' && isset(($r2hv ??= r2_have())[$orig]);   // the original from the phone, on Cloudflare: Make All Videos Again remakes from it
    if ($origUp) $fnote .= ' · original' . $mbs($orig) . ' kept';
    if (($fsz || ($v['hq'] ?? '') !== '') && empty($v['embed']) && ($r2c ??= (bool)r2_conf())) $fnote .= ' · <button type="button" class="lnk vrep" data-rep="' . h($v['id']) . '" data-n="' . ($i + 1) . '">Replace video</button>';   // any video, any time (AJAY 28/10/2026): it makes the 1080p for Cloudflare, the small copy for Hostinger and the preview, same place and settings
    $gone = !$ps && ((string)($v['product'] ?? '') !== '' || !empty($v['also'])); $hid = $ps && !array_filter($ps, fn($id) => empty($CAT[$id]['hidden']));
    $poster = $v['cover'] !== '' ? img_url($v['cover']) : ($p && $p['img'] ? '/' . $p['img'] : '');
    $mk = $fsz > 1.5 * 1048576 && empty($v['embed']) && !(($v['prev'] ?? '') !== '' && is_file(video_dir() . '/' . basename((string)$v['prev'])));   // no small preview yet: this page makes one (vshrink.js)
    $rows .= '<div class="vrow' . ($off ? ' off' : '') . '" data-vid="' . h($v['id']) . '"' . (($v['fp'] ?? '') !== '' ? ' data-fp="' . h($v['fp']) . '"' : '') . ($fsz ? ' data-sz="' . $fsz . '"' : '') . ($mk ? ' data-mkprev="1"' : '') . ($fsz && empty($v['embed']) ? ' data-pvsrc="/' . h(video_url((string)$v['file'])) . '"' . (($v['prev'] ?? '') !== '' && is_file(video_dir() . '/' . basename((string)$v['prev'])) ? ' data-pv="1"' : '') : '') . (($v['hq'] ?? '') !== '' && empty($v['sent_com']) && empty($v['from']) && isset(($r2hv ??= r2_have())[$v['hq']]) && $comBox ? ' data-hqc="1"' : '') . ($origUp ? ' data-orig="' . h(rtrim((string)r2_conf()['url'], '/') . '/' . $orig) . '"' : '') . (($v['hq'] ?? '') !== '' && isset(($r2hv ??= r2_have())[$v['hq']]) ? ' data-hqu="' . h(rtrim((string)r2_conf()['url'], '/') . '/' . $v['hq']) . '"' : '') . ($fsz && empty($v['embed']) && ($v['cover'] ?? '') === '' ? ' data-mkcover="/api/live.php?vid=' . h($v['file']) . '"' : '') . '><span class="vdrag" title="Drag to change the order" aria-hidden="true"><svg width="10" height="16" viewBox="0 0 10 16" fill="currentColor"><circle cx="2" cy="2" r="1.6"/><circle cx="8" cy="2" r="1.6"/><circle cx="2" cy="8" r="1.6"/><circle cx="8" cy="8" r="1.6"/><circle cx="2" cy="14" r="1.6"/><circle cx="8" cy="14" r="1.6"/></svg></span>'
      . $vpost($v, 'vid_vis', '<button class="rdot' . ($off ? '' : ' on') . '" name="show" value="' . ($off ? '1' : '') . '" title="' . ($off ? 'Hidden. Tap to show on the website' : 'On the website. Tap to hide') . '" aria-label="' . ($off ? 'Hidden, tap to show' : 'On the website, tap to hide') . '"></button>', ' class="vvis"')
      . (!empty($v['embed']) ? '<a class="vth vemb" href="https://www.instagram.com/reel/' . h($v['embed']) . '/" target="_blank" rel="noopener" title="Open on Instagram">' . ($poster ? '<img src="' . h($poster) . '" alt="">' : '') . '<span>Instagram</span></a>'
        : '<video class="vth' . (empty($v['wide']) ? '' : ' wd') . '" src="/' . h(video_url((string)$v['file'])) . '#t=0.1" preload="' . ($poster ? 'none' : 'metadata') . '" muted playsinline' . ($poster ? ' poster="' . h($poster) . '"' : '') . ' onclick="this.paused?this.play():this.pause()"></video>')
      . '<div class="vinfo">' . $vpost($v, 'vid_prods', '<span class="vsell">Sells</span><div class="vps">'
        . (($v['words'] ?? '') !== '' ? '<button class="vchip none" name="rm" value="-words" title="Remove these words from this video">“' . h($v['words']) . '”' . ($ps ? '' : ' · Shop Now button') . ' <span aria-hidden="true">✕</span></button>' : '')   // words typed in the + Add box: shown on the video, with its products or (none) a Shop Now button
        . ($ps ? (!empty($v['shop']) ? '<button class="vchip" name="rm" value="-shop" title="Remove the Shop Now button">Shop Now button <span aria-hidden="true">✕</span></button>' : '') . implode('', array_map(fn($id) => '<button class="vchip' . (!empty($CAT[$id]['hidden']) ? ' hid' : '') . '" name="rm" value="' . h($id) . '" title="Remove ' . h($CAT[$id]['name']) . ' from this video">' . h($CAT[$id]['name']) . ' <span aria-hidden="true">✕</span></button>', $ps)) : (($v['words'] ?? '') !== '' ? '' : '<span class="vchip none">No product · Shop Now button</span>'))
        . '<span class="fpick vpick vplus"><input type="text" name="product_text" maxlength="60" data-ffind data-free placeholder="+ Add or type anything" aria-label="Add a product to this video, or type any words to show on it" autocomplete="off"><input type="hidden" name="add"><ul class="fplist" hidden>'
        . implode('', array_map(fn($id, $q) => in_array((string)$id, $ps, true) ? '' : '<li data-v="' . h($id) . '">' . h($q['name']) . (!empty($q['hidden']) ? ' (hidden)' : '') . '</li>', array_keys($CAT), $CAT))
        . ($ps ? (empty($v['shop']) ? '<li data-v="-">+ Shop Now button</li>' : '') : '') . '</ul></span></div>')
      . '<small class="muted">' . (!empty($v['embed']) ? '' : ($gone ? 'Product deleted: not shown' : ($hid ? 'Product hidden: not shown' : ($off ? 'Hidden' : (($v['where'] ?? '') === 'product' ? 'On product pages only' : 'On the home page, number <span class="vnum">' . ($i + 1) . '</span>' . (($v['where'] ?? '') === 'home' ? ' only' : ' + product pages')))))) . (!empty($v['auto']) ? ' · Instagram, automatic' : (!empty($v['ig']) ? ' · Instagram' : (!empty($v['embed']) ? '<b class="vbad">Not on the website: it opened Instagram. Paste its link again or upload the video</b>' : (!empty($v['link']) ? ' · From a link' : '')))) . $fnote . (!empty($v['from']) ? ' · From ' . h($v['from']) : (!empty($v['sent_com']) ? ' · On fomaxo.com ✓' : ($comBox && $sendOk($v) ? ' · ' . $vpost($v, 'vid_to_com', '<button class="lnk">Send to fomaxo.com</button>', ' style="display:inline"') : ''))) . '</small>'
      . (empty($v['embed']) ? $vpost($v, 'vid_shape', '<button class="vsr' . (empty($v['wide']) ? ' on' : '') . '" name="shape" value="916"' . (empty($v['wide']) ? ' disabled' : '') . '><i></i>9:16</button><button class="vsr' . (empty($v['wide']) ? '' : ' on') . '" name="shape" value="169"' . (empty($v['wide']) ? '' : ' disabled') . '><i></i>16:9</button>', ' class="vshr" aria-label="Video shape"') . $vpost($v, 'vid_where', '<select name="where" onchange="this.form.submit()" aria-label="Where the video shows">' . implode('', array_map(fn($k, $t) => '<option value="' . $k . '"' . (($v['where'] ?? '') === $k ? ' selected' : '') . '>' . $t . '</option>', ['', 'home', 'product'], ['Home + product page', 'Home page only', 'Product page only'])) . '</select>', ' class="vwh"') . $vpost($v, 'vid_person', '<input name="person" maxlength="30" value="' . h($v['person'] ?? '') . '" placeholder="Name of the person in the video" aria-label="Name of the person in the video (shows on the product page)" onchange="this.form.submit()">', ' class="vperson"') : '') . '</div>'
      . '<div class="vmv">' . $vpost($v, 'vid_first', '<button class="btn line sm"' . ($i ? '' : ' disabled') . ' aria-label="Move to first" title="Move to first">⇈</button>') . $vpost($v, 'vid_up', '<button class="btn line sm"' . ($i ? '' : ' disabled') . ' aria-label="Move up">↑</button>') . $vpost($v, 'vid_down', '<button class="btn line sm"' . ($i < $n - 1 ? '' : ' disabled') . ' aria-label="Move down">↓</button>') . $vpost($v, 'vid_last', '<button class="btn line sm"' . ($i < $n - 1 ? '' : ' disabled') . ' aria-label="Move to last" title="Move to last">⇊</button>')
      . $vpost($v, 'vid_del', '<button class="btn line sm danger" aria-label="Delete video">✕</button>', ' onsubmit="return confirm(\'Delete this video?\')"') . '</div></div>';
  }
  /* From Instagram: connect once with a token, then tap a reel and its product (or let Automatic add them) */
  $ig = ig_fresh(); $auto = shop_setting('ig_auto') !== '0';
  if (!$ig) $igBox = '<form class="box vig" method="post">' . $csrfField . '<input type="hidden" name="action" value="vid_ig_token"><div class="bh"><h3>From Instagram</h3></div><div class="bb">'
    . '<p class="muted small">Connect once, then add any reel in two taps (Business or Creator account).</p>'
    . '<div class="igadd"><input name="ig_token" type="password" autocomplete="new-password" spellcheck="false" required placeholder="Instagram access token" aria-label="Instagram access token"><button class="btn">Connect</button></div>'
    . '<details class="igtok"><summary>Where is the token?</summary><ol><li>Open <b>developers.facebook.com</b> and sign in</li><li>My apps → your app</li><li>Instagram → API setup with Instagram login</li><li>Press <b>Generate token</b> and copy it</li><li>Paste it in the box above</li></ol><p>It is kept on your Hostinger server only, never shown again.</p></details></div></form>';
  else {
    $reels = ig_reels($ig); $have = array_filter(array_column($vids, 'ig'));
    $grid = is_array($reels) ? implode('', array_map(fn($r) => '<label class="igr' . (in_array($r['id'], $have, true) ? ' had' : '') . '"><input type="radio" name="ig" value="' . h($r['id']) . '" required>'
        . ($r['thumb'] ? '<img src="' . h($r['thumb']) . '" alt="" loading="lazy" referrerpolicy="no-referrer">' : '<span class="igph"></span>')
        . '<span class="igd">' . ($r['at'] ? date('d/m', $r['at']) : '') . (in_array($r['id'], $have, true) ? ' · Added' : '') . '</span></label>', $reels)) : '';
    $igBox = '<form class="box vig" method="post">' . $csrfField . '<input type="hidden" name="action" value="vid_ig_add">'
      . '<div class="bh"><h3>From Instagram' . ($ig['user'] !== '' ? ' <small class="muted">@' . h($ig['user']) . '</small>' : '') . '</h3><button class="btn line sm" name="action" value="vid_ig_off" formnovalidate onclick="return confirm(\'Disconnect Instagram?\')">Disconnect</button></div><div class="bb">'
      . '<label class="pg igauto"><span><b>Automatic</b><small>New reels show by themselves when the caption names a product, e.g. Gold or Old Money</small></span><input type="checkbox" class="tgl" name="on" value="1"' . ($auto ? ' checked' : '') . ' aria-label="Automatic" onchange="var f=this.form;f.querySelector(\'[name=action]\').value=\'vid_ig_auto\';f.noValidate=true;f.submit()"></label>'
      . ($auto ? '<p class="muted small">Checked every hour. <button class="lnk" name="action" value="vid_ig_sync" formnovalidate>Check now</button></p>' : '')
      . (!is_array($reels) ? '<p class="err small">Instagram: ' . h($reels) . '</p>' : ($grid === '' ? '<p class="muted small">No reels found on this account yet.</p>' : '<p class="muted small">' . ($auto ? 'Or add any reel yourself: tap it, pick its product, then Add.' : 'Tap a reel, pick its product, then Add.') . '</p><div class="igg">' . $grid . '</div>'
      . '<div class="igadd">' . $vpick('Product in the reel') . '<button class="btn" onclick="if(this.form.checkValidity())this.textContent=\'Adding…\'">Add</button></div>'))
      . '</div></form>';
  }
  /* Cloudflare R2: keys once, then the videos are copied there (a few each time this page opens) and a switch plays them from there */
  $r2 = r2_conf(); $r2On = r2_on(); $r2Set = shop_setting('r2_on') === '1' && $r2;   // switched on, but the address may not answer
  if ($r2) r2_sync_later();   // new uploads go to Cloudflare after this page has loaded
  $r2want = $r2 ? r2_wanted() : []; $r2have = $r2 ? r2_have() : []; $r2done = count(array_filter($r2want, fn($sz, $f) => ($r2have[$f] ?? -1) === $sz, ARRAY_FILTER_USE_BOTH));
  $r2Box = !$r2 ? '<form class="box vig" method="post">' . $csrfField . '<input type="hidden" name="action" value="vid_r2_keys"><div class="bh"><h3>Cloudflare <small class="muted">faster videos</small></h3></div><div class="bb">'
      . '<p class="muted small">Keep the videos on Cloudflare too (free up to 10 GB). They play from there, and from Hostinger if Cloudflare ever fails.</p>'
      . '<input name="r2_acct" required spellcheck="false" autocomplete="off" placeholder="Account ID" aria-label="Cloudflare Account ID">'
      . '<input name="r2_key" required spellcheck="false" autocomplete="off" placeholder="Access Key ID" aria-label="Access Key ID">'
      . '<input name="r2_secret" type="password" required autocomplete="new-password" spellcheck="false" placeholder="Secret Access Key" aria-label="Secret Access Key">'
      . '<input name="r2_bucket" required spellcheck="false" autocomplete="off" value="fomaxo-videos-asia" placeholder="Bucket name" aria-label="Bucket name">'
      . '<div class="igadd"><input name="r2_url" type="url" required spellcheck="false" value="https://v.fomaxo.com" placeholder="https://v.fomaxo.com" aria-label="Public address"><button class="btn" onclick="if(this.form.checkValidity())this.textContent=\'Checking…\'">Connect</button></div>'
      . '<details class="igtok"><summary>Where are these?</summary><ol><li>Open <b>dash.cloudflare.com</b> → R2 Object Storage</li><li>Account ID is on the right of the R2 page</li><li>Manage API tokens → Create API token → Object Read &amp; Write, bucket fomaxo-videos-asia</li><li>Copy the Access Key ID and Secret Access Key</li><li>Bucket → Settings → Custom Domains → v.fomaxo.com</li></ol><p>Kept on your Hostinger server only, never shown again.</p></details></div></form>'
    : '<form class="box vig" method="post">' . $csrfField . '<input type="hidden" name="action" value="vid_r2_on"><div class="bh"><h3>Cloudflare <small class="muted">' . h(preg_replace('~^https://~', '', $r2['url'])) . '</small></h3>' . (!empty($r2['shared']) ? '' : '<button class="btn line sm" name="action" value="vid_r2_off" onclick="return confirm(\'Disconnect Cloudflare? Videos play from Hostinger again.\')">Disconnect</button>') . '</div><div class="bb">'
      . (!empty($r2['shared']) ? '<p class="muted small" style="margin:0 0 6px">Connected with the keys pasted on fomaxo.com/admin. Change them there.</p>' : '')
      . '<label class="pg igauto"><span><b>Play videos from Cloudflare</b><small class="r2st' . ($r2On ? ' on' : ($r2Set ? ' wait' : '')) . '">' . ($r2On ? 'ON – videos play from Cloudflare (faster)' : ($r2Set ? 'ON, but ' . h(preg_replace('~^https://~', '', (string)$r2['url'])) . ' does not answer, so videos play from Hostinger for now' : 'OFF – videos play from Hostinger')) . '</small></span><input type="checkbox" class="tgl" name="on" value="1"' . ($r2Set ? ' checked' : '') . ' aria-label="Play videos from Cloudflare" onchange="this.form.submit()"></label>'
      . (function () { $u = r2_total(); $pc = min(100, $u / R2_FREE * 100); $lv = $u >= R2_FREE ? 'full' : ($u >= 0.8 * R2_FREE ? 'near' : '');   // the 10 GB bar: amber from 8 GB, red at 10 GB
          return '<div class="r2bar' . ($lv ? " $lv" : '') . '"><div class="r2t"><b>' . ($u >= 1e8 ? round($u / 1e9, 1) : round($u / 1e6)) . ($u >= 1e8 ? ' GB' : ' MB') . '</b> used of 10 GB free <small class="muted">(fomaxo.in + fomaxo.com)</small><span>' . round($pc) . '%</span></div><i><s style="width:' . max($u ? 1 : 0, round($pc, 1)) . '%"></s></i>'
            . ($lv === 'full' ? '<p>Over the free 10 GB. Cloudflare now charges your card about ₹1.3 per GB each month. Check the payment card in Cloudflare → Billing, or delete old videos.</p>'
              : ($lv === 'near' ? '<p>Nearly full. Above 10 GB Cloudflare charges your card (about ₹1.3 per GB a month). Add a payment card in Cloudflare → Billing, or delete old videos.</p>' : '')) . '</div>'; })()
      . '<p class="muted small r2cp">Copied ' . $r2done . ' of ' . count($r2want) . ' videos to Cloudflare' . ($r2done < count($r2want) ? ' · <button class="lnk" name="action" value="vid_r2_sync" onclick="this.textContent=\'Copying…\'">Copy the rest</button>' : '') . '</p>'
      . '</div></form>';
  $body .= '<div class="row"><a class="btn line sm" href="' . h(self_url(['tab' => 'products'])) . '">← Products</a><h2 class="sp">Shop videos</h2></div>'
    . $sw('#vidPanes', ['list' => 'Your videos', 'add' => 'Add a video']) . '<div class="vids panes" id="vidPanes"><div class="vleft" data-pane="add">' . $igBox . $r2Box
    . '<form class="box vlink" method="post" onsubmit="this.querySelector(\'.btn\').textContent=\'Adding…\'">' . $csrfField . '<input type="hidden" name="action" value="vid_link"><div class="bh"><h3>Paste a link</h3></div><div class="bb">'
    . '<input type="url" name="url" required inputmode="url" placeholder="https://www.instagram.com/reel/…" spellcheck="false" aria-label="Video link">'
    . '<div class="igadd">' . $vpick('Product in the video') . '<button class="btn">Add</button></div>'
    . '<p class="muted small">Instagram reel link, or a link that opens the video itself. Not YouTube or TikTok.</p></div></form>'
    . '<form class="box vadd" method="post" enctype="multipart/form-data" data-vshrink data-max="' . $max . '"' . (r2_conf() ? ' data-hq="1"' : '') . '>' . $csrfField . '<input type="hidden" name="action" value="vid_add"><input type="hidden" name="replace" value="">'
    . '<div class="bh"><h3>Upload from your phone</h3></div><div class="bb">'
    . '<p class="vrepnote" hidden><span></span> <button type="button" class="lnk">Cancel</button></p>'
    . '<label>Video<input type="file" name="video" accept="video/*,.mp4,.mov,.m4v,.webm,.mkv,.avi" required></label>'
    . '<label>Cover photo (optional)<input type="file" name="cover" accept="image/*"></label>'
    . '<div class="vshape" role="radiogroup" aria-label="Video shape"><span>Shape</span><label><input type="radio" name="shape" value="916" checked><i></i>9:16 upright</label><label><input type="radio" name="shape" value="169"><i></i>16:9 wide</label></div>'
    . '<div class="igadd">' . $vpick('Product in the video') . '<button class="btn">Upload</button></div>'
    . (video_com_inbox() !== '' ? '<label class="vtoin" style="display:flex;flex-direction:row;justify-content:flex-start;text-align:left;align-items:center;gap:8px;margin:10px 0 0;font-size:13.5px;cursor:pointer"><input type="checkbox" name="to_com" value="1" checked style="width:18px;height:18px;accent-color:var(--gold,#c9a35b)">Also add to fomaxo.com</label>' : '')
    . '<p class="muted small">Any video, any size. 9:16 or 16:9, picked from the video by itself.' . (r2_conf() ? ' It makes three: 1080p for Cloudflare, a small copy for Hostinger and the preview.' : '') . ' No cover = product photo.</p>'
    . '<div class="vsh muted small" hidden><span></span></div></div></form><script src="/admin/vshrink.js?v=' . asset_v('vshrink.js') . '"></script>'
    . '<script>(function(){var f=document.querySelector("form[data-vshrink]");if(!f)return;var r=f.elements.replace,n=f.querySelector(".vrepnote"),pt=f.querySelector(".vpick input[type=text]"),tc=f.querySelector(".vtoin"),h3=f.querySelector(".bh h3");'   // Replace with sharper video: the upload box takes the original for that row
    . 'var set=function(b){r.value=b?b.dataset.rep:"";n.hidden=!b;if(b&&!f.dataset.batch)f.dataset.one="1";else delete f.dataset.one;if(pt)pt.closest(".vpick").hidden=!!b;if(tc)tc.hidden=!!b;h3.textContent=b?"Replace video number "+b.dataset.n:"Upload from your phone";'
    . 'if(b)n.querySelector("span").textContent="Pick a video. It goes in place of this one.";};'
    . 'n.querySelector("button").onclick=function(){set(null);};'
    . 'document.addEventListener("click",function(e){var b=e.target.closest(".vrep,.vagain");if(!b)return;set(b);var sw=document.querySelector(".sw button[data-show=add]");if(sw&&!f.dataset.batch&&getComputedStyle(sw).display!=="none")sw.click();if(!f.dataset.batch)f.scrollIntoView({behavior:"smooth",block:"start"});var i=f.elements.video;if(i&&!f.dataset.batch)i.click();});})();</script></div>'
    . '<div class="box on vlist' . ($allOn ? '' : ' alloff') . '" data-pane="list"><div class="bh"><h3>Your videos <small class="muted">' . $n . '</small> <b class="tgok vok" aria-live="polite"></b></h3>'
    . '<form method="post" class="vall">' . $csrfField . '<input type="hidden" name="action" value="vid_all"><label class="pg"><span><b>' . ($allOn ? 'On website' : 'Off: row hidden') . '</b></span><input type="checkbox" class="tgl" name="on" value="1"' . ($allOn ? ' checked' : '') . ' aria-label="Show videos on the website" onchange="this.form.submit()"></label></form></div>'
    . ($toComN > 1 ? '<form method="post" class="vtoall muted small" style="margin:0 0 6px;padding:0 14px">' . $csrfField . '<input type="hidden" name="action" value="vid_to_com_all"><button class="lnk" onclick="this.textContent=\'Sending…\'">Send all ' . $toComN . ' to fomaxo.com</button></form>' : '')
    . ($allOn ? '' : '<p class="voff">The video row is hidden on the website. You can still upload, edit, move and delete videos here.</p>') . '<div class="bb">'
    . ($rows ?: '<p class="empty">No videos yet. The home page shows the video row once you add one.</p>')
    . '</div><div class="bf"><span class="muted small">Ring dot filled = on the website. Drag the dots on the left to change the order. Tap a product to remove it. No product = Shop Now button. Hidden products’ videos are left out.</span></div></div></div>';
} elseif ($tab === 'products') {
  $qq = trim((string)($_GET['q'] ?? ''));
  $TG = shop_together();
  /* Sold: bottles bought per product and size in the dates picked, from real orders only (new, paid, delivered, and such orders in Trash: no test,
     awaiting payment, cancelled or refunded orders); free coupon gifts are not counted */
  $D = pick_dates('products'); $sold = []; $soldAll = 0;
  $sq = shop_db()->prepare("SELECT items FROM orders WHERE test = 0 AND status IN ('new', 'paid', 'delivered')" . ($D['r'] === 'all' ? '' : ' AND created >= ? AND created <= ?'));
  $sq->execute($D['r'] === 'all' ? [] : [$D['from'] . ' 00:00:00', $D['to'] . ' 23:59:59']);
  $trashed = trash_sales($D['r'] === 'all' ? '' : $D['from'] . ' 00:00:00', $D['r'] === 'all' ? '' : $D['to'] . ' 23:59:59');   // orders in Trash: their bottles still count here
  foreach ([...$sq->fetchAll(), ...$trashed] as $o) foreach ((array)json_decode((string)$o['items'], true) as $l) { if (!is_array($l) || !empty($l['free']) || ($n = max(0, (int)($l['qty'] ?? 0))) < 1) continue; $id = (string)($l['id'] ?? ''); $op = (string)($l['opt'] ?? ''); $sold[$id]['n'] = ($sold[$id]['n'] ?? 0) + $n; $sold[$id]['s'][$op] = ($sold[$id]['s'][$op] ?? 0) + $n; $soldAll += $n; }
  foreach ($sold as &$x) ksort($x['s'], SORT_NUMERIC); unset($x); uasort($sold, fn($a, $b) => $b['n'] <=> $a['n']); $top = array_key_first($sold);
  $body .= '<div class="row"><form class="row sp pfind" method="get"><input type="hidden" name="tab" value="products"><input type="search" name="q" value="' . h($qq) . '" placeholder="Find a product" style="max-width:280px"></form>'
    /* Customers bought together on the product page: % or ₹ off (one of the two) one of each of two different products in the bag (shop_together) */
    . '<form method="post" class="tgo' . ($TG['on'] ? ' on' : '') . '">' . $csrfField . '<input type="hidden" name="action" value="together"><label>Customers bought together</label>'
    . '<label class="tgs" title="On: the box shows on every product page. Off: the box is hidden"><input type="checkbox" class="tgl" name="together_on" value="1"' . ($TG['on'] ? ' checked' : '') . ' aria-label="Customers bought together box on or off" onchange="this.form.classList.toggle(\'on\', this.checked);tgSave(this.form)"><b class="y">On</b><b class="n">Off</b></label><span class="tgbr"></span>'
    . '<label for="tgPct">Extra off</label><span class="tgn"><input id="tgPct" name="together_pct" inputmode="numeric" pattern="[0-9]*" maxlength="2" value="' . ($TG['pct'] ?: '') . '" placeholder="0" aria-label="Extra off when both are in the bag, %">%</span><span class="tgn">or</span><span class="tgn"><input id="tgRs" name="together_rs" inputmode="numeric" pattern="[0-9]*" maxlength="4" value="' . ($TG['rs'] ?: '') . '" placeholder="0" aria-label="Extra off when both are in the bag, rupees">₹</span>'
    . '<button class="btn line sm">Save</button><b class="tgok" aria-live="polite"></b></form><script>function tgSave(f){var k=f.querySelector(".tgok"),b=f.querySelector("button"),d=new FormData(f);k.className="tgok";k.textContent="Saving…";b.disabled=true;d.append("quick","1");fetch(location.href,{method:"POST",body:d,credentials:"same-origin"}).then(function(r){return r.json()}).then(function(j){k.className="tgok "+(j.ok?"ok":"bad");k.textContent=j.ok?"Saved ✓":j.msg;k.title=j.msg}).catch(function(){k.className="tgok bad";k.textContent="Not saved, try again"}).finally(function(){b.disabled=false})}document.querySelector(".tgo").addEventListener("submit",function(e){e.preventDefault();tgSave(e.target)});[["tgPct","tgRs"],["tgRs","tgPct"]].forEach(function(x){document.getElementById(x[0]).addEventListener("input",function(e){if(e.target.value.trim()!==""&&e.target.value!=="0")document.getElementById(x[1]).value=""})})</script><a class="btn line" href="' . h(self_url(['tab' => 'products', 'videos' => 1])) . '">Videos</a><a class="btn" href="' . h(self_url(['tab' => 'products', 'add' => 1])) . '">+ Add a product</a></div>'
    . '<div class="psrange"><span class="muted small">Bottles sold</span>' . date_bar($D, ['q' => $qq]) . '</div>'
    . '<div class="box fill"><div class="bh">' . (function () use ($CAT, $csrfField) { $any = false; $all = true;   // Discount for all products: On while every product with an Original price has its discount On
        foreach ($CAT as $p) { $sv = $p['sale'] ?? ['prices' => $p['prices'], 'was' => $p['was']]; foreach ($sv['prices'] as $k => $pr) if ((float)($sv['was'][$k] ?? 0) > $pr) { $any = true; if (!empty($p['saleOff'])) $all = false; break; } }
        return $any ? '<form method="post" class="tgo dall' . ($all ? ' on' : '') . '">' . $csrfField . '<input type="hidden" name="action" value="saleoff"><input type="hidden" name="id" value="all"><label class="tgs"><span>Discount for all products</span><input type="checkbox" class="tgl" name="on" value="1"' . ($all ? ' checked' : '') . ' aria-label="Discount on or off for all products" onchange="this.form.submit()"><b class="y">On</b><b class="n">Off</b></label></form>' : ''; })() . '<span class="muted small">Tap the ring dot to show or hide a product on the website: filled green = on the website, empty = hidden. Press Edit to change a product’s name, descriptions, notes, sizes, prices, your cost or photos.</span></div><div class="bb"><div class="prod phd" aria-hidden="true"><span class="pvis">Show</span><span></span><span>Product</span><span>Prices</span><span>Sold · ' . h(period_label($D)) . ' (' . number_format($soldAll) . ')</span><span></span></div>';
  foreach ($CAT as $id => $p) {
    if ($qq !== '' && stripos($p['name'] . ' ' . kind_label($p['kind']), $qq) === false) continue;
    $off = !empty($p['hidden']);   // the ring dot: one tap shows or hides the product on the website, without opening it
    $sv = $p['sale'] ?? ['prices' => $p['prices'], 'was' => $p['was']]; $dOn = empty($p['saleOff']);   // the saved prices (the catalog shows the Original price while the discount is Off)
    $hasWas = (bool)array_filter($sv['prices'], fn($pr, $k) => (float)($sv['was'][$k] ?? 0) > $pr, ARRAY_FILTER_USE_BOTH);
    $body .= '<div class="prod' . ($off ? ' off' : '') . '"><form method="post" class="pvis">' . $csrfField . '<input type="hidden" name="action" value="show"><input type="hidden" name="id" value="' . h($id) . '">'
      . '<button class="rdot' . ($off ? '' : ' on') . '" name="show" value="' . ($off ? '1' : '') . '" title="' . ($off ? 'Hidden. Tap to show on the website' : 'On the website. Tap to hide') . '" aria-label="' . h($p['name'] . ($off ? ': hidden, tap to show on the website' : ': on the website, tap to hide')) . '"></button></form>'
      . $thumbOf($id) . '<div class="pinfo"><b>' . h($p['name']) . '</b><small>' . h(kind_label($p['kind'])) . (!empty($p['added']) ? ' · added here' : '') . '</small>'
      . '<span class="ptag ' . ($off ? 'hid">Hidden' : 'on">On website') . '</span></div>'
      . '<div class="pprices">' . implode('', array_map(function ($opt, $pr) use ($p, $sv, $dOn) { $w = (float)($sv['was'][$opt] ?? 0); $has = $w > $pr;   // Original, then Exclusive; discount Off: only the Original, which customers pay
          return '<div><span>' . h(opt_label($p, (string)$opt)) . '</span>' . ($has ? ($dOn ? '<s class="muted">' . rupees((int)round($w * 100)) . '</s><b>' . rupees((int)round($pr * 100)) . '</b>' : '<b>' . rupees((int)round($w * 100)) . '</b><span class="muted">discount off</span>') : '<span></span><b>' . rupees((int)round($pr * 100)) . '</b>') . '</div>'; }, array_keys($sv['prices']), $sv['prices'])) . '</div>'
      . '<div class="psold">' . (!empty($sold[$id]) ? '<b>' . number_format($sold[$id]['n']) . '<span class="pslbl"> sold</span></b>' . ($id === $top ? ' <span class="pbest">Top</span>' : '') . '<small>' . h(implode(' · ', array_map(fn($o, $n) => opt_label($p, (string)$o) . " $n", array_keys($sold[$id]['s']), $sold[$id]['s']))) . '</small>' : '<span class="muted">0<span class="pslbl"> sold</span></span>') . '</div>'
      . '<div class="pact">' . ($hasWas ? '<form method="post" class="tgo dsw' . ($dOn ? ' on' : '') . '" title="Discount On: customers pay the Exclusive price, the Original crossed out. Off: they pay the Original price.">' . $csrfField . '<input type="hidden" name="action" value="saleoff"><input type="hidden" name="id" value="' . h($id) . '"><label class="tgs"><span class="muted small">Discount</span><input type="checkbox" class="tgl" name="on" value="1"' . ($dOn ? ' checked' : '') . ' aria-label="' . h($p['name']) . ' discount on or off" onchange="this.form.submit()"><b class="y">On</b><b class="n">Off</b></label></form>' : '') . '<a class="btn sm line" href="' . h(self_url(['tab' => 'products', 'edit' => $id])) . '">Edit</a>';
    if (!empty($p['added'])) $body .= '<form method="post" onsubmit="return confirm(\'Delete ' . h(addslashes($p['name'])) . ' for good?\')">' . $csrfField . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . h($id) . '"><button class="btn sm danger">Delete</button></form>';
    $body .= '</div></div>';
  }
  $body .= '</div></div>';
}

/* ============ Expenses ============ */
if ($tab === 'expenses') {
  /* the date bar picks the expenses shown and their total */
  $D = pick_dates('expenses'); $PL = period_label($D);
  $s = shop_db()->prepare('SELECT * FROM expenses WHERE day >= ? AND day <= ? ORDER BY day DESC, id DESC'); $s->execute([$D['from'], $D['to']]);
  $list = $s->fetchAll(); $total = array_sum(array_column($list, 'amount'));
  $byCat = []; foreach ($list as $x) $byCat[$x['category']] = ($byCat[$x['category']] ?? 0) + (int)$x['amount']; arsort($byCat);
  $body .= date_bar($D) . $sw('#expPanes', ['list' => 'Expenses', 'add' => 'Add an expense']) . '<div class="exp panes" id="expPanes">'
    . '<form method="post" class="box" data-pane="add">' . $csrfField . '<input type="hidden" name="action" value="expense"><div class="bh"><h3>Add an expense</h3></div><div class="bb" style="padding-top:12px">'
    . '<label>Date' . date_box('day', date('Y-m-d'), 'Date of the expense', true) . '</label>'
    . '<label>Category' . $sel('category', array_combine(EXPENSE_CATEGORIES, EXPENSE_CATEGORIES), '') . '</label>'
    . '<label>Details<input name="note" maxlength="200" placeholder="optional, e.g. Instagram ads"></label>'
    . '<label>Amount ₹<input type="number" name="amount" min="0.01" step="0.01" required inputmode="decimal"></label><button class="btn" style="width:100%">Add expense</button></div></form>'
    . '<div class="box on" data-pane="list"><div class="bh" style="flex-wrap:wrap"><span class="etot"><span class="muted">' . h($PL) . '</span> <b>' . rupees($total) . '</b> <span class="muted">· ' . count($list) . ' expense' . (count($list) === 1 ? '' : 's') . '</span></span>'
    . '<a class="btn line sm" href="' . h(self_url(['do' => 'expenses_excel'])) . '">Excel</a></div>'
    . ($byCat ? '<div class="bh"><span class="cats">' . implode('', array_map(fn($c, $v) => '<span>' . h($c) . ' <b>' . rupees($v) . '</b></span>', array_keys($byCat), $byCat)) . '</span></div>' : '') . '<div class="bb np">';
  if (!$list) $body .= '<p class="empty">No expenses ' . ($D['r'] === 'today' ? 'today' : 'in these dates') . '.</p>';
  else {
    $body .= '<table class="grid"><thead><tr><th>Date</th><th>Category</th><th class="hide-m">Details</th><th class="r">Amount</th><th></th></tr></thead><tbody>';
    foreach ($list as $x) $body .= '<tr><td class="nw">' . h(date(substr($x['day'], 0, 4) === date('Y') ? 'd M' : 'd M Y', strtotime($x['day']))) . '</td><td>' . h($x['category']) . '<small class="show-m">' . h($x['note']) . '</small></td><td class="hide-m">' . h($x['note']) . '</td><td class="r">' . rupees((int)$x['amount']) . '</td>'
      . '<td class="r"><form method="post" onsubmit="return confirm(\'Delete this expense?\')">' . $csrfField . '<input type="hidden" name="action" value="expense_delete"><input type="hidden" name="id" value="' . (int)$x['id'] . '"><button class="linkbtn">Delete</button></form></td></tr>';
    $body .= '</tbody></table>';
  }
  $body .= '</div></div></div>';
}

/* ============ Analytics ============ */
if ($tab === 'analytics') {
  $D = pick_dates('analytics'); $from = $D['from']; $to = $D['to'];
  $cv = isset($_GET['cv']); $vj = isset($_GET['vj']) && !$cv;   // Overview | Conversion | Visits, all on the same date bar
  $body .= '<div class="atop"><span class="seg aview"><a href="' . h(self_url(['tab' => 'analytics'])) . '"' . ($cv || $vj ? '' : ' class="on"') . '>Overview</a><a href="' . h(self_url(['tab' => 'analytics', 'cv' => 1])) . '"' . ($cv ? ' class="on"' : '') . '>Conversion</a><a href="' . h(self_url(['tab' => 'analytics', 'vj' => 1])) . '"' . ($vj ? ' class="on"' : '') . '>Visits</a></span>'
    . date_bar($D, $cv ? ['cv' => '1'] : ($vj ? ['vj' => '1'] : []), '. Your own visits and bots are not counted.') . '</div>';
}
if ($tab === 'analytics' && $cv) {
  $C = conversion_stats($from, $to, $CAT);
  $pc = fn($a, $b) => $b ? round($a / $b * 100, 1) . '%' : '–';
  $since = '<p class="muted small cvs">Counting since ' . h(dmy($C['since'])) . '.</p>';
  $body .= $sw('#cvPanes', ['products' => 'Products', 'checkout' => 'Checkout', 'campaigns' => 'Campaigns', 'bagc' => 'Bag by country', 'scroll' => 'Homepage scroll']) . '<div class="cv panes" id="cvPanes">';
  /* 1. products */
  $body .= '<div class="box c-prod on" data-pane="products"><div class="bh"><h3>Products: viewed → bag → bought</h3></div><div class="bb np"><table class="grid ctab"><thead><tr><th>Product</th><th class="r">Viewed</th><th class="r">To bag</th><th class="r">Orders</th><th class="r">Units</th><th class="r">Buy rate</th></tr></thead><tbody>';
  if (!$C['products']) $body .= '<tr><td colspan="6" class="empty">No product views in these dates.</td></tr>';
  foreach ($C['products'] as $id => $x) $body .= '<tr><td><div class="pc">' . $thumbOf($id, 'th sm') . '<b>' . h($x['name']) . '</b></div></td><td class="r">' . number_format($x['viewed']) . '</td><td class="r">' . number_format($x['bag']) . '</td><td class="r">' . number_format($x['orders']) . '</td><td class="r">' . number_format($x['units']) . '</td><td class="r"><b>' . ($x['viewed'] ? $pc($x['orders'], $x['viewed']) : '–') . '</b></td></tr>';
  $body .= '</tbody></table><p class="muted small cvn">Viewed and To bag count visits. Buy rate = orders ÷ viewed. Free items are not counted.</p></div></div>';
  /* 2. checkout drop-off */
  $st = $C['steps']; $top = max(1, ...array_values($st));
  $rows = [['Opened checkout', $st['checkout'], null], ['Typed name or mobile', $st['typed'], $st['checkout']], ['Reached payment choice', $st['pay'], $st['typed']], ['Opened Razorpay page', $st['card'], null], ['Closed Razorpay without paying', $st['cardx'] ?? 0, null], ['A payment try failed', $st['cardfail'] ?? 0, null], ['Bought', $st['buy'], $st['pay']]];
  $body .= '<div class="box c-drop" data-pane="checkout"><div class="bh"><h3>Checkout drop-off</h3></div><div class="bb"><div class="fun cfun">';
  foreach ($rows as [$label, $n, $before]) $body .= '<div class="st"><span>' . $label . ($before !== null && $before > $n ? ' <span class="stop">' . number_format($before - $n) . ' stopped</span>' : '') . '</span><b>' . number_format($n) . '</b><span class="bar"><i style="width:' . round($n / $top * 100, 1) . '%"></i></span></div>';
  $E = $C['empty'];
  $body .= '</div><p class="muted small cvn">Counted per visit. Cash on delivery orders skip the Razorpay page, so Bought is compared with Reached payment choice.</p>'
    . '<table class="grid etab"><thead><tr><th>Box left empty</th><th class="r">People</th><th class="r">Share</th></tr></thead><tbody>';
  foreach (['name' => 'Name', 'phone' => 'Mobile', 'state' => 'State', 'address' => 'Address', 'email' => 'Email (optional)'] as $k => $l) $body .= '<tr><td>' . $l . '</td><td class="r">' . number_format($E[$k]) . '</td><td class="r muted">' . $pc($E[$k], $E['people']) . '</td></tr>';
  $body .= '</tbody></table><p class="muted small cvn">Of ' . number_format($E['people']) . ($E['people'] === 1 ? ' person' : ' people') . ' who typed details and did not order.</p>'
    . (!empty($C['fails']) ? '<p class="muted small cvn">Why payments failed (Razorpay): ' . implode(' · ', array_map(fn($r, $k) => h(ucfirst(str_replace('-', ' ', (string)$r))) . ' <b>' . (int)$k . '</b>', array_keys($C['fails']), $C['fails'])) . '</p>' : '')
    . '<p class="cvu">Card payments not finished: <b>' . number_format($C['unpaid']['n']) . '</b>' . ($C['unpaid']['n'] ? ' (' . rupees($C['unpaid']['total']) . ' total)' : '') . '</p>'
    . '<p class="muted small cvs">Opened Razorpay page: counting since ' . h(dmy($C['since'])) . '.</p></div></div>';
  /* 3. campaigns */
  $body .= '<div class="box c-camp" data-pane="campaigns"><div class="bh"><h3>Campaigns</h3></div><div class="bb np"><table class="grid ctab"><thead><tr><th>Source / campaign</th><th class="r">Visits</th><th class="r">Bought</th><th class="r">Conv.</th><th class="r">Revenue</th></tr></thead><tbody>';
  if (!$C['campaigns']) $body .= '<tr><td colspan="5" class="empty">No visits in these dates.</td></tr>';
  foreach ($C['campaigns'] as $x) $body .= '<tr><td><b>' . h(SOURCES[$x['source']] ?? ucfirst($x['source'] ?: 'direct')) . '</b>' . ($x['campaign'] !== '' ? '<small class="cn">' . h($x['campaign']) . '</small>' : '') . '</td><td class="r">' . number_format($x['visits']) . '</td><td class="r">' . number_format($x['bought']) . '</td><td class="r">' . $pc($x['bought'], $x['visits']) . '</td><td class="r">' . ($x['revenue'] ? rupees($x['revenue']) : '–') . '</td></tr>';
  $body .= '</tbody></table><p class="muted small cvn">Tag a post or ad link: fomaxo.in/?utm_source=instagram&amp;utm_campaign=diwali-post</p>' . str_replace('cvs">Counting', 'cvs cvn">Campaign names: counting', $since) . '</div></div>';
  /* 4. people who added to bag, by country */
  $bc = $C['bagc']; $bcT = array_sum($bc);
  $body .= '<div class="box c-bagc" data-pane="bagc"><div class="bh"><h3>Added to bag by country</h3>' . ($bcT ? '<span class="bct">' . number_format($bcT) . ($bcT === 1 ? ' person' : ' people') . '</span>' : '') . '</div><div class="bb np"><table class="grid ctab"><thead><tr><th>Country</th><th class="r">People</th><th class="r">Share</th></tr></thead><tbody>';
  if (!$bc) $body .= '<tr><td colspan="3" class="empty">Nobody added to the bag in these dates.</td></tr>';
  foreach ($bc as $cc => $n) $body .= '<tr><td><b>' . ($cc !== '' ? '<span class="flag">' . fomaxo_flag((string)$cc) . '</span> ' . h(fomaxo_country_name((string)$cc)) : '<span class="muted">Not known</span>') . '</b></td><td class="r"><b>' . number_format($n) . '</b></td><td class="r muted">' . $pc($n, $bcT) . '</td></tr>';
  $body .= '</tbody></table><p class="muted small cvn">People who added a perfume to the bag in these dates, by the country they visited from.</p></div></div>';
  /* 5. homepage scroll */
  $hm = $C['home'];
  $body .= '<div class="box c-scroll" data-pane="scroll"><div class="bh"><h3>Homepage scroll</h3></div><div class="bb"><div class="fun cfun">';
  foreach ([0 => 'Opened the homepage', 25 => 'Scrolled 25%', 50 => 'Scrolled 50%', 75 => 'Scrolled 75%', 100 => 'Reached the bottom'] as $m => $l) {
    $n = $m ? $C['depth'][$m] : $hm;
    $body .= '<div class="st"><span>' . $l . '</span><b>' . number_format($n) . ' <small class="muted">' . ($hm ? round($n / $hm * 100) . '%' : '–') . '</small></b><span class="bar"><i style="width:' . ($hm ? round($n / $hm * 100, 1) : 0) . '%"></i></span></div>';
  }
  $body .= '</div><p class="muted small cvn">Visits that opened the homepage. The site sends one small anonymous note at each depth, once per visit.</p>' . $since . '</div></div></div>';
}
/* Analytics → Visits: our own Clarity. The newest visits step by step, where people leave, what people tap (api/insights-lib.php) */
if ($tab === 'analytics' && $vj) { $body0 = $body; try {   // a failure shows its reason instead of a white page
  require_once dirname(__DIR__) . '/api/insights-lib.php';
  $V = insights_data($from, $to, 60); $names = array_map(fn($p) => $p['name'] ?? '', $CAT);
  $pcT = fn($a, $b) => $b ? round($a / $b * 100) . '%' : '–';
  $body .= $sw('#vjPanes', ['paths' => 'Visits step by step', 'leave' => 'Where people leave', 'taps' => 'What people tap']) . '<div class="vj panes" id="vjPanes">';
  /* 1. visits step by step */
  $endCls = ['bought' => 'ok', 'payment' => 'hot', 'checkout' => 'hot', 'bag' => 'hot', 'bounce' => 'bad', 'left' => ''];
  $body .= '<div class="box v-paths on" data-pane="paths"><div class="bh"><h3>Visits step by step <small class="muted">newest first</small></h3><span class="sp"></span><a class="btn sm" href="' . h(self_url(['do' => 'visits_excel'])) . '">Excel</a></div><div class="bb">';
  if (!$V['paths']) $body .= '<p class="empty">No visits in these dates.</p>';
  else $body .= '<ul class="vlist">';
  foreach ($V['paths'] as $v) {
    $steps = '';
    foreach ($v['steps'] as $st) {
      $taps = array_map(fn($t) => '<i class="' . ($t['rage'] || $t['err'] ? 'rg' : ($t['dead'] ? 'dd' : '')) . '">' . h($t['l']) . '</i>', array_slice($st['taps'], 0, 8));
      $steps .= '<li><b>' . h(insights_pg_name($st['pg'], $names)) . '</b><span class="muted">' . h(implode(' · ', array_filter([$st['secs'] !== null ? insights_secs($st['secs']) : '', $st['pc'] !== null ? 'seen ' . $st['pc'] . '%' : '']))) . '</span>'
              . ($taps ? '<span class="vt">' . implode('', $taps) . (count($st['taps']) > 8 ? '<i class="more">+' . (count($st['taps']) - 8) . '</i>' : '') . '</span>' : '') . '</li>';
    }
    $body .= '<li class="vv"><div class="vh"><span class="vd">' . h(date('d M, H:i', strtotime($v['start']))) . '</span><span class="muted">' . h(['phone' => 'Phone', 'computer' => 'Laptop'][$v['device']] ?? 'Other')
           . ($v['country'] ? ' · ' . fomaxo_flag($v['country']) . ' ' . h($v['country']) : '') . ($v['source'] ? ' · ' . h(SOURCES[$v['source']] ?? ucfirst($v['source'])) : '') . ($v['load'] ? ' · opened in ' . number_format($v['load'] / 1000, 1) . 's' : '') . '</span>'
           . '<span class="vpill ' . ($endCls[$v['end']] ?? '') . '">' . h(insights_end($v['end'])) . '</span></div><ol class="vs">' . $steps . '</ol></li>';
  }
  if ($V['paths']) $body .= '</ul>';
  $body .= '<p class="muted small cvn">Each box is a page: how long they stayed, how far down they saw, and what they tapped. <i class="dd">Grey</i> = tapped something that is not a button. <i class="rg">Red</i> = tapped the same spot 3 times fast, or an error on the page.</p></div></div>';
  /* 2. where people leave */
  $mx = max(1, ...array_column($V['leave'] ?: [['left_n' => 1]], 'left_n'));
  $body .= '<div class="box v-leave" data-pane="leave"><div class="bh"><h3>Where people leave</h3></div><div class="bb np"><table class="grid ctab"><thead><tr><th>Last page</th><th class="r">Saw it</th><th class="r">Left</th><th class="r">Left %</th><th class="r">Time</th><th class="r">Seen</th></tr></thead><tbody>';
  if (!$V['leave']) $body .= '<tr><td colspan="6" class="empty">No visits in these dates.</td></tr>';
  foreach ($V['leave'] as $r) $body .= '<tr><td><b>' . h(insights_pg_name($r['pg'], $names)) . '</b><span class="bar vbar"><i style="width:' . round($r['left_n'] / $mx * 100, 1) . '%"></i></span></td><td class="r">' . number_format($r['seen']) . '</td><td class="r">' . number_format($r['left_n']) . '</td><td class="r"><b>' . $pcT($r['left_n'], $r['seen']) . '</b></td><td class="r">' . insights_secs($r['secs']) . '</td><td class="r">' . ($r['pc'] !== null ? $r['pc'] . '%' : '–') . '</td></tr>';
  $body .= '</tbody></table><p class="muted small cvn">Left: visits that ended on this page. Seen: how far down the page people got, on average.' . ($V['has_trail'] ? '' : ' Taps and scrolling are counted from today.') . '</p></div></div>';
  /* 3. what people tap */
  $body .= taps_box($V, $names, 'box v-taps', ' data-pane="taps"') . '</div>';
} catch (Throwable $e) { $body = $body0 . '<div class="box"><p class="muted empty">Visits could not open: ' . h($e->getMessage()) . ' (line ' . $e->getLine() . '). Send us a picture of this line.</p></div>'; } }
if ($tab === 'analytics' && !$cv && !$vj) {
  $A = analytics($from, $to, $CAT); $f = $A['funnel'];
  $body .= '<div class="kpis n8 strip" style="--n:8">'
    . '<div class="kpi"><span>Visitors</span><b>' . number_format($A['visitors']) . '</b><small>' . number_format($A['visits']) . ' visits</small></div>'
    . '<div class="kpi good"><span>On the site now</span><b>' . $A['now'] . '</b><small>' . h(last_visit_note()) . '</small></div>'
    . '<div class="kpi"><span>Gift page</span><b>' . number_format($A['gift']['visitors']) . '</b><small>' . number_format($A['gift']['views']) . ' views</small></div>'
    . '<div class="kpi"><span>Conversion rate</span><b>' . ($A['conversion'] === null ? '—' : round($A['conversion'], 1) . '%') . '</b><small>visits that bought</small></div>'
    . '<div class="kpi"><span>Cart abandonment</span><b>' . $pct($A['cart_ab']) . '</b><small>added, did not buy</small></div>'
    . '<div class="kpi"><span>Checkout abandonment</span><b>' . $pct($A['checkout_ab']) . '</b><small>at checkout, did not buy</small></div>'
    . '<div class="kpi"><span>Purchases</span><b>' . $A['purchases'] . '</b><small>orders</small></div>'
    . '<div class="kpi"><span>Revenue</span><b>' . rupees($A['revenue']) . '</b><small>' . ($A['paid_orders'] ? rupees(intdiv($A['revenue'], $A['paid_orders'])) . ' per order' : '&nbsp;') . '</small></div></div>' . track_health_note();
  $body .= $sw('#anPanes', ['funnel' => 'Funnel', 'sources' => 'Sources', 'countries' => 'Countries', 'states' => 'States', 'products' => 'Products', 'left' => 'Left at checkout']) . '<div class="an panes" id="anPanes">';
  /* funnel */
  $body .= '<div class="box p-funnel on" data-pane="funnel"><div class="bh"><h3>From visit to purchase</h3></div><div class="bb"><div class="fun">';
  $prev = null; $top = max(1, $f['view']);
  foreach (FUNNEL as $k => $label) {
    $n = $f[$k];
    $body .= '<div class="st"><span>' . $label . '</span><b>' . number_format($n) . ($k !== 'view' && $f['view'] ? ' <small class="muted">' . round($n / $f['view'] * 100) . '%</small>' : '') . '</b><span class="bar"><i style="width:' . round($n / $top * 100, 1) . '%"></i></span>'
      . ($prev !== null && $prev > 0 ? '<span class="drop">' . ($prev > $n ? '<b>' . round(($prev - $n) / $prev * 100) . '% dropped off</b> (' . number_format($prev - $n) . ')' : 'no drop-off') . '</span>' : '') . '</div>';
    $prev = $n;
  }
  $body .= '</div><p class="muted small">Counted per visit. A visit ends after 30 minutes without activity.</p></div></div>';
  /* sources */
  $body .= '<div class="box srcs" data-pane="sources"><div class="bh"><h3>Where visitors come from</h3></div><div class="bb np"><table class="grid"><thead><tr><th>Source</th><th class="r">Visitors</th><th class="r">Visits</th><th class="r">Bought</th><th class="r">Conv.</th></tr></thead><tbody>';
  if (!$A['sources']) $body .= '<tr><td colspan="5" class="empty">No visits yet in these dates.</td></tr>';
  foreach ($A['sources'] as $k => $x) $body .= '<tr><td><b>' . h(SOURCES[$k] ?? ucfirst($k)) . '</b></td><td class="r">' . number_format($x['visitors']) . '</td><td class="r">' . number_format($x['visits']) . '</td><td class="r">' . number_format($x['bought']) . '</td><td class="r">' . ($x['visits'] ? round($x['bought'] / $x['visits'] * 100, 1) . '%' : '—') . '</td></tr>';
  $body .= '</tbody></table><p class="muted small" style="padding:0 12px">Add ?utm_source=instagram (or whatsapp) to links you share, so every visit from them is counted under that name.</p></div></div>';
  /* top countries and Indian states, for the dates picked at the top */
  $G = geo_stats($from, $to);
  foreach (['countries' => 'Top countries', 'states' => 'Visitors by Indian state'] as $gk => $gt) {
    $rows = $G[$gk]; $mx = max(1, ...array_values($rows ?: [1]));
    $body .= '<div class="box geo" data-pane="' . $gk . '"><div class="bh"><h3>' . $gt . '</h3><span class="muted small nw">' . h(period_label($D)) . '</span></div><div class="bb">';
    if (!$rows) $body .= '<p class="empty">No visits ' . (['today' => 'today', 'yest' => 'yesterday'][$D['r']] ?? 'in these dates') . '.</p>';
    foreach ($rows as $name => $n) $body .= '<div class="li"><span class="grow">' . ($gk === 'countries' ? '<span class="flag">' . fomaxo_flag((string)$name) . '</span> ' . h(fomaxo_country_name((string)$name)) : h((string)$name)) . '</span><span class="bar"><i style="width:' . round($n / $mx * 100) . '%"></i></span><b class="num">' . number_format($n) . '</b></div>';
    $body .= '<p class="muted small">' . ($gk === 'states' ? 'Approximate: phone networks often show the state of their nearest hub. ' : '') . 'Looked up from the visitor’s IP address on this server; only the country' . ($gk === 'states' ? ' and state are' : ' is') . ' kept. IP geolocation by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>.</p></div></div>';
  }
  /* products */
  $body .= '<div class="box p-prod" data-pane="products"><div class="bh"><h3>Products</h3></div><div class="bb np"><table class="grid"><thead><tr><th>Product</th><th class="r">Views</th><th class="r">Added</th><th class="r">Sold</th><th class="r">Revenue</th></tr></thead><tbody>';
  if (!$A['products']) $body .= '<tr><td colspan="5" class="empty">No product views yet in these dates.</td></tr>';
  foreach ($A['products'] as $id => $p) $body .= '<tr><td><div class="pc">' . $thumbOf($id, 'th sm') . '<b>' . h($CAT[$id]['name']) . '</b></div></td><td class="r">' . $p['views'] . '</td><td class="r">' . $p['adds'] . '</td><td class="r">' . $p['units'] . '</td><td class="r">' . rupees($p['rev']) . '</td></tr>';
  $body .= '</tbody></table></div></div>';
  /* left at checkout: everyone who typed their details in these dates (all of them are kept for good) */
  $L = checkout_leads($from, $to);
  $body .= '<div class="box p-left" data-pane="left"><div class="bh"><h3>Left at checkout</h3><span class="lt-n muted nw">' . count($L) . (count($L) === 1 ? ' person' : ' people') . '</span><span class="muted small hide-m">Everyone who typed their details at checkout, opened a link from our WhatsApp, came back after buying or invited a friend who ordered, ' . h(date_span($from, $to)) . '</span><span class="sp"></span><a class="btn sm" href="' . h(self_url(['do' => 'leads_excel', 'from' => $from, 'to' => $to])) . '">Excel</a></div><div class="bb np"><div class="lts" data-csrf="' . h($CSRF) . '"><div class="lt-hd"><span></span><span>Date</span><span>Name</span><span>State</span><span class="r">Bag</span><span>Left at</span><span>Ordered later</span><span></span><span></span></div>';
  if (!$L) $body .= '<p class="empty">Nobody typed their details at checkout in these dates.</p>';
  /* one line per customer per day, their latest visit that day, with ×N tries when they came back the same day (date, name, state, bag, where they stopped, ordered later); WhatsApp at the end only for an Indian mobile number (6–9 and 10 digits, what WhatsApp works on); tap the line to open mobile, email, address and products */
  foreach ($L as $l) {
    $names = lead_items($l);
    $later = $l['later'] === '' ? '<span class="muted">Not ordered</span>' : ($l['later'] === 'yes' ? '<span class="badge st-paid">Yes</span>' : '<span class="badge st-paid">' . h($l['later']) . '</span>');
    $body .= '<details class="lt' . ($l['later'] !== '' ? ' dim' : '') . '" data-sid="' . h($l['sid']) . '"><summary>'
      . '<span class="lt-x"><button type="button" class="xbtn" data-lead="' . h(implode(',', $l['sids'])) . '" data-who="' . h($l['name'] ?: ($l['phone'] ? phone_fmt($l['phone']) : 'this person')) . '" title="Remove from this list" aria-label="Remove ' . h($l['name'] ?: 'this person') . ' from Left at checkout">✕</button></span>'
      . '<span class="lt-date nw">' . h(date('d M, H:i', strtotime($l['updated']))) . '</span><b class="lt-name"><span>' . h($l['name'] ?: '—') . '</span>' . ($l['tries'] > 1 ? '<small class="lt-tries" title="Came to checkout ' . $l['tries'] . ' times">×' . $l['tries'] . '<span class="lt-tw"> tries</span></small>' : '') . '</b><span class="lt-state">' . h($l['state'] ?: '—') . '</span>'
      . '<span class="lt-bag r nw">' . (in_array($l['step'], ['visit', 'buyer', 'invite'], true) ? '<span class="muted">—</span>' : rupees((int)$l['total'])) . '</span><span class="lt-step">' . ($l['step'] === 'payment' ? '<span class="badge st-cancelled">At payment</span>' : ($l['step'] === 'visit' ? '<span class="badge st-paid">Opened link</span>' : ($l['step'] === 'buyer' ? '<span class="badge st-pending">Past buyer</span>' : ($l['step'] === 'invite' ? '<span class="badge st-paid">Invited a friend</span>' : ($l['step'] === 'coupon' ? '<span class="badge st-new lt-coupon">Wants coupon</span>' : '<span class="badge st-new">At details</span>'))))) . '</span>'
      . '<span class="lt-later">' . $later . '</span>'
      . '<span class="lt-wa">' . (wa_key_ok((string)$l['phone']) ? '<button type="button" class="btn sm' . (($ltd = $l['sent'] !== '' ? date('d/m', strtotime($l['sent'])) : '') !== '' ? ' line' : '') . '" data-ltwa data-sid="' . h($l['sid']) . '" data-phone="' . h($l['phone']) . '"' . wt_attr($l['phone']) . ($l['step'] === 'invite' ? ' data-k0="pct"' : '') . ' data-who="' . h($l['name'] ?: phone_fmt($l['phone'])) . '" data-head="' . h(lead_wa_parts($l)[0]) . '" data-tail="' . h(lead_wa_parts($l)[1]) . '" title="WhatsApp ' . h($l['name'] ?: phone_fmt($l['phone'])) . ' with a link back to their bag">' . WA_SVG . '<span>' . ($ltd !== '' ? 'Sent ' . $ltd : 'WhatsApp') . '</span></button>' : '') . '</span>'
      . '<span class="lt-chev" aria-hidden="true"></span></summary>'
      . '<div class="lt-more"><dl>'
      . '<dt>Date</dt><dd>' . h(date('d M Y, H:i', strtotime($l['updated']))) . '</dd>'
      . ($l['tries'] > 1 ? '<dt>Tries</dt><dd>' . $l['tries'] . ' times: ' . h(implode(', ', array_map(fn($t) => date('d M H:i', strtotime($t)), $l['times']))) . '</dd>' : '')
      . '<dt>Mobile</dt><dd>' . h($l['phone'] ? phone_fmt($l['phone']) : 'No mobile') . '</dd>'
      . ($l['email'] ? '<dt>Email</dt><dd>' . h($l['email']) . '</dd>' : '')
      . '<dt>Address</dt><dd>' . h(trim($l['address'] . ($l['state'] ? ', ' . $l['state'] : ''), ', ') ?: '—') . '</dd>'
      . '<dt>Products</dt><dd>' . h($names ?: '—') . '</dd>'
      . ($l['step'] === 'visit' ? '<dt>Left at</dt><dd>Opened our WhatsApp link, not at checkout yet</dd>' : '') . ($l['step'] === 'buyer' ? '<dt>Left at</dt><dd>Past buyer visiting, not at checkout yet</dd>' : '') . ($l['step'] === 'invite' ? '<dt>Left at</dt><dd>Invited a friend who ordered: send their coupon</dd>' : '') . ($l['step'] === 'coupon' ? '<dt>Left at</dt><dd>Gave WhatsApp for the coupon: send it</dd>' : '')
      . '<dt>Ordered later</dt><dd>' . ($l['later'] !== '' && $l['later'] !== 'yes' ? '<a href="' . h(self_url(['tab' => 'orders', 'q' => $l['later']])) . '">Order ' . h($l['later']) . '</a>' : ($l['later'] === 'yes' ? 'Yes' : 'Not ordered')) . '</dd></dl>'
      . '</div></details>';
  }
  $body .= '</div></div></div></div>' . wa_box('ltWa', 'ltfree', 'pct', 10, 0, 'A new COMEBACK- code just for this customer: one use, only with their mobile number. It is made when you tap Open WhatsApp.', 2);
}

/* ============ Improve (AJAY 26/10/2026): how to get more people to buy, in plain words. Each tip comes from the visit data of the dates picked
   (Analytics and Analytics → Visits), shows the numbers behind it and the steps to take. A tip only shows when the data says so. ============ */
if ($tab === 'improve') { $body0 = $body; try {   // a failure shows its reason instead of a white page
  require_once dirname(__DIR__) . '/api/insights-lib.php';
  $D = pick_dates('improve'); $from = $D['from']; $to = $D['to'];
  $A = analytics($from, $to, $CAT); $C = conversion_stats($from, $to, $CAT); $V = insights_data($from, $to, 0); $names = array_map(fn($p) => $p['name'] ?? '', $CAT);
  $F = ['visits' => $A['funnel']['view'], 'product' => $A['funnel']['product'], 'cart' => $A['funnel']['add'], 'checkout' => $A['funnel']['checkout'], 'pay' => $A['funnel']['pay'], 'buy' => $A['funnel']['buy']];
  $F['typed'] = min($F['checkout'] ?: PHP_INT_MAX, (int)$C['steps']['typed']);
  $pct = fn($a, $b) => $b > 0 ? round(100 * $a / $b, 1) : 0; $pctT = fn($v) => rtrim(rtrim(number_format($v, 1), '0'), '.') . '%'; $nf = fn($n) => number_format((int)$n);
  $topProd = null; foreach ($C['products'] as $id => $x) { $topProd = ['id' => $id] + $x; break; }
  $tips = [];
  /* the fix a reply word builds: different tips (and funnel steps) that need the same fix share one key, so each fix shows once */
  $pNames = array_map('mb_strtolower', $names);
  $fixOf = fn($w) => ['fix product page' => 'page', 'fix landing page' => 'page', 'fix phone' => 'page', 'fix home' => 'home', 'fix home page' => 'home'][$w]
    ?? (str_starts_with($w, 'fix ') && in_array(substr($w, 4), $pNames, true) ? 'page' : $w);
  $pgFix = fn($pg) => $pg === '/' ? 'home' : (str_starts_with($pg, '/product/') ? 'page' : null);
  $tip = function ($people, $title, $data, $why, $steps, $word, $fix = null) use (&$tips, $fixOf) { $fix ??= $fixOf($word); $tips[] = compact('people', 'title', 'data', 'why', 'steps', 'word', 'fix'); };
  $leaks = [
    ['product', 'cart', 'People look at a product but do not add it to the bag', 'fix product page',
      array_values(array_filter([$V['product_pc'] !== null ? 'On product pages people saw ' . $V['product_pc'] . '% of the page on average.' : '', $topProd && $topProd['viewed'] ? h($topProd['name']) . ': ' . $nf($topProd['viewed']) . ' visits saw it, ' . $nf($topProd['bag']) . ' added it to the bag.' : ''])),
      'Nobody can buy without adding to the bag first, so this is where most sales are lost.',
      ['Open <b>Analytics → Visits</b> and read 5 visits that saw a product page: what they tapped and where they stopped.', 'The first screen of the product page should show price, size, smell and delivery, with one gold Add to bag button.', 'Show the cheaper first try (10ml) and real reviews close to the button.']],
    ['cart', 'checkout', 'People add to the bag but do not open checkout', 'fix bag', [], 'They already chose a perfume. Something in the bag stops them: the price, delivery or the next button.',
      ['Open your own bag on your phone: is the total and free delivery clear, and is Checkout easy to see?', 'Look at the bag steps in <b>Analytics → Visits</b>: what did they tap last?']],
    ['checkout', 'typed', 'People open checkout but do not type anything', 'fix checkout', [], 'The checkout page looks like too much work, or they are not sure yet (payment, delivery time, returns).',
      ['Look at <b>Analytics → Visits</b> for visits that ended on Checkout: how long they stayed and what they tapped.', 'Keep only the boxes we really need and keep Cash on delivery easy to see.']],
    ['typed', 'pay', 'People type their details but stop before paying', 'fix checkout', [], 'They really wanted to buy. Usually one box or one doubt stops them.',
      ['See which box was left empty in <b>Analytics → Conversion → Checkout</b>.', 'WhatsApp them from <b>Analytics → Left at checkout</b>: they gave their mobile.']],
    ['pay', 'buy', 'People reach payment but do not finish', 'check payment', [], 'The last step failed or felt unsafe.',
      ['See in <b>Analytics → Conversion → Checkout</b> how many closed the Razorpay page and why payments failed.', 'WhatsApp the ones marked <b>At payment</b> in <b>Analytics → Left at checkout</b> with a Razorpay payment link.']],
  ];
  $stepName = ['visits' => 'visits', 'product' => 'saw a product', 'cart' => 'added to bag', 'checkout' => 'opened checkout', 'typed' => 'typed details', 'pay' => 'reached payment', 'buy' => 'bought'];
  foreach ($leaks as [$a, $b, $title, $word, $extra, $why, $steps]) {
    $lost = $F[$a] - $F[$b]; if ($F[$a] < 3 || $lost <= 0) continue;
    $tip($lost, $title, array_merge(['<b>' . $nf($F[$a]) . '</b> visits ' . $stepName[$a] . ', only <b>' . $nf($F[$b]) . '</b> ' . $stepName[$b] . ' (' . $pctT($pct($F[$b], $F[$a])) . '). <b>' . $nf($lost) . '</b> stopped here.'], $extra), $why, $steps, $word);
  }
  $n = 0;
  foreach ($V['leave'] as $r) {   // pages where many visits end (not the thank-you pages)
    if ($n >= 2 || preg_match('~^/(thank-you|order|review)~', $r['pg']) || $r['left_n'] < 5 || $pct($r['left_n'], $r['seen']) < 60) continue; $n++;
    $fast = $r['secs'] !== null && $r['secs'] < 15; $pn = insights_pg_name($r['pg'], $names);
    $tip($r['left_n'], 'Many people leave on ' . h($pn), ['<b>' . $nf($r['left_n']) . '</b> of ' . $nf($r['seen']) . ' visits ended here (' . $pctT($pct($r['left_n'], $r['seen'])) . '), after <b>' . insights_secs($r['secs']) . '</b> on average' . ($r['pc'] !== null ? ', seeing ' . $r['pc'] . '% of the page' : '') . '.'],
      $fast ? 'They leave within seconds, so the first screen is not what they expected from the ad or link.' : 'They stay a while and still leave, so the page does not give them a clear next step.',
      $fast ? ['Make the top of this page match the ad: the same photo, price and offer they tapped on.', 'Check it opens fast on your phone with mobile data.'] : ['Look where they stop in <b>Analytics → Visits</b> and put a clear button (Add to bag / Try 10ml) there.'], 'fix ' . mb_strtolower($pn), $pgFix($r['pg']));
  }
  $n = $m = 0;
  foreach ($V['taps'] as $r) {
    if ($r['dead'] && $n < 1 && $r['people'] >= 3 && $r['label'] !== '') { $n++;
      $tip($r['people'], 'People tap “' . h($r['name']) . '” on ' . h(insights_pg_name($r['pg'], $names)) . ', but nothing happens', ['<b>' . $nf($r['people']) . '</b> people tapped it ' . $nf($r['taps']) . ' times.'],
        'They expect it to open or do something. Making it work removes a small annoyance.', [str_starts_with($r['label'], 'Photo') ? 'Make the photo open bigger when tapped.' : 'Make it a link to the right place (size, reviews or Add to bag).'], 'make it tappable', 'tap ' . $r['label']); }
    if ($r['rage_people'] >= 2 && $m < 1) { $m++;
      $tip($r['rage_people'], 'People tap “' . h($r['name']) . '” again and again', ['<b>' . $nf($r['rage_people']) . '</b> people tapped the same spot 3 times in a second on ' . h(insights_pg_name($r['pg'], $names)) . '.'],
        'This usually means it is slow, too small, or does not work on their phone.', ['Try it on your own phone. If it is slow or does nothing, tell us.'], 'check ' . mb_strtolower($r['name']), 'tap ' . $r['label']); }
  }
  /* a source with many visits and almost no adds to bag: its own "check ads" tip below shows where it lands, so the one-page tip leaves that line out */
  $adSrc = null; foreach ($V['by_source'] as $r) if ($r['visits'] >= 30 && $pct($r['adds'], $r['visits']) < 1) { $adSrc = $r; break; }
  if ($V['visits'] >= 20 && $pct($V['bounce'], $V['visits']) >= 50) {
    $w = $V['by_source'][0] ?? null; $ws = $w ? (SOURCES[$w['k']] ?? ucfirst($w['k'])) : '';
    /* where the biggest source lands and how many of them leave from that page at once */
    $lands = []; if ($w && (!$adSrc || $adSrc['k'] !== $w['k'])) foreach ($V['land'] as $r) if (($x = $r['src'][$w['k']] ?? null) && count($lands) < 3) $lands[] = '<b>' . h(insights_pg_name($r['pg'], $names)) . '</b> ' . $nf($x['visits']) . ' (' . $nf($x['bounce']) . ' left at once)';
    $tip($V['bounce'], $pctT($pct($V['bounce'], $V['visits'])) . ' of visits leave after one page without adding anything', array_values(array_filter(['<b>' . $nf($V['bounce']) . '</b> of ' . $nf($V['visits']) . ' visits saw one page and left' . ($V['bounce_fast'] ? ', <b>' . $nf($V['bounce_fast']) . '</b> of them within 10 seconds' : '') . '.' . ($w ? ' Most visits come from <b>' . h($ws) . '</b> (' . $nf($w['visits']) . ').' : ''),
      $lands ? h($ws) . ' visits land on: ' . implode(', ', $lands) . '.' : ''])),
      'The first page they land on decides everything. If it does not match the ad or loads slowly, they go back.', ['Send ads to the exact product and size shown in the ad.', 'Keep the first screen simple: photo, name, price, one button.'], 'fix landing page');
  }
  if ($r = $adSrc) { $sn = SOURCES[$r['k']] ?? ucfirst($r['k']);
    /* where these visits land (first page) and which ad names they carry, so the ad link and the ad can be checked against the page */
    $lands = []; foreach ($V['land'] as $l) if (($x = $l['src'][$r['k']] ?? null) && count($lands) < 4) $lands[] = '<b>' . h(insights_pg_name($l['pg'], $names)) . '</b> ' . $nf($x['visits']) . ' (' . $nf($x['adds']) . ' added)';
    $cm = $V['by_cmp'][$r['k']] ?? []; uasort($cm, fn($a, $b) => $b['visits'] <=> $a['visits']); $named = array_filter($cm, fn($k) => $k !== '', ARRAY_FILTER_USE_KEY);
    $cmL = $named ? 'By ad name: ' . implode(', ', array_map(fn($k, $x) => ($k === '' ? 'no name' : '<b>' . h((string)$k) . '</b>') . ' ' . $nf($x['visits']) . ' (' . $nf($x['adds']) . ' added)', array_slice(array_keys($cm), 0, 4), array_slice($cm, 0, 4))) . '.'
      : 'None of these visits carry an ad name, so the site cannot tell paid ads from bio, story and post links (step 3).';
    $tip($r['visits'] - $r['adds'], 'Visitors from ' . h($sn) . ' almost never add to the bag', array_values(array_filter(['<b>' . $nf($r['visits']) . '</b> visits from ' . h($sn) . ', <b>' . $nf($r['adds']) . '</b> added to the bag.',
      $lands ? 'They land on: ' . implode(', ', $lands) . '.' : '', $cmL])),
      'The ad or post brings the wrong people, or sends them to the wrong page.', [
        'In <b>Meta Ads Manager</b> open the ad set → <b>Placements</b> → <b>Manual placements</b>: untick <b>Audience Network</b> and Messenger, keep Instagram Feed, Stories and Reels. Audience Network shows ads inside other apps and games, where most taps are by accident.',
        'Open each ad → <b>Destination</b> → <b>Website URL</b>: put the address of the product in the ad, e.g. <b>fomaxo.in/fomaxo-gold</b>, not fomaxo.in. Check it on your phone: it must open that perfume.',
        'In the same ad, under <b>URL parameters</b>, paste <b style="overflow-wrap:anywhere">utm_source=instagram&amp;utm_medium=paid&amp;utm_campaign={{campaign.name}}&amp;utm_content={{ad.name}}</b>. Then <b>Analytics → Conversion → Campaigns</b> and this tip show every ad by name.',
        'Check <b>Audience</b>: India only, ages that buy perfume (about 20–45), and interests like perfume or fragrance.'], 'check ads'); }
  if (($p = $V['by_device']['phone'] ?? null) && ($c = $V['by_device']['computer'] ?? null) && $p['visits'] >= 15 && $c['visits'] >= 15 && $c['adds'] > 0 && $pct($p['adds'], $p['visits']) < $pct($c['adds'], $c['visits']) / 2)
    $tip($p['visits'], 'Phones add to the bag much less than laptops', ['Phone: ' . $pctT($pct($p['adds'], $p['visits'])) . ' of ' . $nf($p['visits']) . ' visits added to bag. Laptop: ' . $pctT($pct($c['adds'], $c['visits'])) . ' of ' . $nf($c['visits']) . '.'],
      'Something on the phone page is harder: a button is low, a popup covers it, or it is slow.', ['Open the product page on your phone and add to bag yourself. Note anything slow or hidden.'], 'fix phone');
  $sp = $V['speed']['phone'] ?? null;
  if ($sp && $sp['n'] >= 10 && $pct($sp['slow'], $sp['n']) >= 30)
    $tip($sp['slow'], 'The site opens slowly on many phones', ['On phones the site took <b>' . number_format($sp['avg_ms'] / 1000, 1) . ' s</b> on average to open; <b>' . $nf($sp['slow']) . '</b> of ' . $nf($sp['n']) . ' (' . $pctT($pct($sp['slow'], $sp['n'])) . ') took more than 3 seconds.'],
      'People from ads leave if the page is not ready within about 3 seconds.', ['Open the site on your phone with mobile data (Wi-Fi off) and count the seconds.'], 'make it faster');
  if (($er = $V['errors'][0] ?? null) && $er['people'] >= 2)
    $tip($er['people'], 'An error happens on ' . h(insights_pg_name($er['pg'], $names)), ['<b>' . $nf($er['people']) . '</b> visits had this error ' . $nf($er['n']) . ' times: <span class="muted">' . h($er['label']) . '</span>'],
      'An error can stop a button from working, so people cannot add to bag or pay.', ['Nothing for you to do: we read the error and fix it.'], 'fix the error');
  /* fixes already built and live on fomaxo.in (reply word => day it went live, e.g. 'fix product page' => '2026-11-01'): the tip leaves the list (nothing shown).
     It comes back only when a week has passed and the chosen dates start after the fix, so it is judged on new visits only */
  $done = ['fix phone' => '2026-10-26',   // #512: phone Add To Cart closer, bag note above buy bar
    'fix landing page' => '2026-10-26',   // phone home 01–07 one row (price and buttons in the first screen), no popup over the page Instagram and ad visitors land on
    'fix product page' => '2026-10-26',   // phone photo dots instead of the small photo row, smaller name, personal care "Good for" / "Made with" / How to use, a real review by the price
    'fix home' => '2026-10-26',   // phone home: bar at the bottom after the banner, "Not sure which one? Try any scent in 10ml" → Mini Collection
    'fix checkout' => '2026-10-26',
    'check payment' => '2026-10-26'];   // payment: closed Razorpay → "Payment not finished, no money was taken" box with Try again and a WhatsApp payment link; closes and failure reasons counted in Conversion   // checkout: free delivery / COD / easy returns line on top, one Address box, City and State from the PIN, email optional, landmark and note folded away
  /* tips we built the site side of, where the steps left are done by you outside the site (e.g. in Meta Ads Manager): the tip stays, without "Reply …" */
  $yours = ['check ads' => '2026-10-26'];   // landing pages and ad names shown in the tip, Ads Manager steps
  $replyLi = fn($w) => isset($yours[$w]) ? '<li>Numbers added on ' . date('d/m/Y', strtotime($yours[$w])) . '. These steps are yours: check here again a week after you change the ads.</li>' : '<li>Reply <b class="tword">' . h($w) . '</b> in our chat and we build it, with pictures first.</li>';
  /* tips that need the same fix join into one: the one with the most people leads, the others add their numbers */
  usort($tips, fn($a, $b) => $b['people'] <=> $a['people']);
  $one = [];
  foreach ($tips as $t) {
    if (!isset($one[$t['fix']])) { $one[$t['fix']] = $t; continue; }
    $o = &$one[$t['fix']];
    $o['data'] = array_slice(array_values(array_unique(array_merge($o['data'], $t['data']))), 0, 3);
    $o['steps'] = array_slice(array_values(array_unique(array_merge($o['steps'], $t['steps']))), 0, 3); unset($o);
  }
  $tips = array_values($one);
  $doneFix = []; foreach ($done as $w => $d) $doneFix[$fixOf($w)] = max($doneFix[$fixOf($w)] ?? '', $d);   // a fix is done when any of its reply words is
  $doneH = []; $back = fn($f) => isset($doneFix[$f]) && time() >= strtotime($doneFix[$f] . ' +7 days') && $from >= $doneFix[$f];
  foreach ($tips as $i => $t) if (isset($doneFix[$t['fix']])) {
    if ($back($t['fix'])) { $tips[$i]['data'][] = '<b>Fixed on ' . date('d/m/Y', strtotime($doneFix[$t['fix']])) . ', and still happening on the new visits.</b>'; continue; }
    if (isset($done[$t['word']])) $doneH[$t['word']] = $t['title']; unset($tips[$i]);
  }
  foreach ($done as $w => $d) if (!isset($doneH[$w]) && !in_array($fixOf($w), array_column($tips, 'fix'), true)) $doneH[$w] = '';
  $closed = json_decode((string)shop_setting('improve_closed'), true) ?: [];   // tips closed with ×
  $tips = array_slice(array_values(array_filter($tips, fn($t) => !isset($closed[$t['fix']]))), 0, 6);
  /* the funnel, the biggest leak marked */
  $steps = [['visits', 'Visited the site'], ['product', 'Saw a product'], ['cart', 'Added to bag'], ['checkout', 'Opened checkout'], ['typed', 'Typed details'], ['pay', 'Reached payment'], ['buy', 'Bought']];
  $worst = null; for ($i = 1; $i < count($steps); $i++) { $lost = $F[$steps[$i - 1][0]] - $F[$steps[$i][0]]; if ($lost > 0 && (!$worst || $lost > $worst[1])) $worst = [$i, $lost]; }
  $top = max(1, $F['visits']); $fun = '';
  /* tap a step: what it means, its numbers, why people stop there and what to do (the same words as the tips) */
  $srcN = fn($k) => SOURCES[$k] ?? ucfirst($k); $dv = $V['by_device'];
  $info = [
    'visits' => ['Everyone who opened any page of the site in these dates.', array_values(array_filter([
        $V['by_source'] ? 'Most visits came from ' . implode(', ', array_map(fn($r) => '<b>' . h($srcN($r['k'])) . '</b> (' . $nf($r['visits']) . ' visits, ' . $nf($r['adds']) . ' added to bag)', array_slice($V['by_source'], 0, 3))) . '.' : '',
        isset($dv['phone']) || isset($dv['computer']) ? 'Phone: <b>' . $nf($dv['phone']['visits'] ?? 0) . '</b> visits · Laptop: <b>' . $nf($dv['computer']['visits'] ?? 0) . '</b> visits.' : '',
        $V['visits'] ? '<b>' . $nf($V['bounce']) . '</b> of ' . $nf($V['visits']) . ' (' . $pctT($pct($V['bounce'], $V['visits'])) . ') saw one page and left.' : '',
        $V['land'] ? 'First page of each visit: ' . implode(', ', array_map(fn($r) => '<b>' . h(insights_pg_name($r['pg'], $names)) . '</b> ' . $nf($r['visits']) . ' (' . $nf($r['bounce']) . ' left at once)', array_slice($V['land'], 0, 4))) . '.' : ''])),
      'More visitors only help when they are the right people and land on the right page. A source with many visits and no adds to bag costs money for nothing.',
      ['Compare the sources above: keep the ones whose visitors add to bag, change or stop the rest.', 'Send every ad to the exact product it shows.'], 'check ads'],
    'product' => ['Visits that opened at least one perfume page.', ['<b>' . $nf(max(0, $F['visits'] - $F['product'])) . '</b> visits never opened a perfume page.'],
      'They landed on the home page or another page and did not find a perfume they liked, so they left.',
      ['Make the first screen of the home page show the best sellers with prices.', 'Send ads straight to a product page, not the home page.'], 'fix home page'],
  ];
  foreach ($leaks as [$a, $b, $title, $word, $extra, $why, $steps0]) $info[$b] = [$title . '.', $extra, $why, $steps0, $word];
  $info['cart'][0] = 'Visits that added a perfume to the bag.'; $info['checkout'][0] = 'Visits that opened the checkout page.';
  $info['typed'][0] = 'Visits that typed their name or mobile at checkout.'; $info['pay'][0] = 'Visits that reached the pay step (card, UPI or Cash on delivery).'; $info['buy'][0] = 'Visits that placed a real order.';
  foreach ($steps as $i => [$k, $l]) { $lost = $i ? $F[$steps[$i - 1][0]] - $F[$k] : 0; $w = $worst && $worst[0] === $i; [$what, $data, $why, $todo, $word] = $info[$k];
    $data = array_merge([$i ? '<b>' . $nf($F[$steps[$i - 1][0]]) . '</b> ' . $stepName[$steps[$i - 1][0]] . ', <b>' . $nf($F[$k]) . '</b> ' . $stepName[$k] . ($lost > 0 ? ': <b>' . $nf($lost) . '</b> stopped here (' . $pctT($pct($lost, $F[$steps[$i - 1][0]])) . ').' : '. Nobody stopped here.') : '<b>' . $nf($F['visits']) . '</b> visits.'], $data);
    $fun .= '<details class="fs" name="fs"><summary class="st' . ($w ? ' worst' : '') . '"><span>' . $l . ($lost > 0 ? ' <span class="stop">' . $nf($lost) . ' stopped' . ($w ? ' · biggest loss' : '') . '</span>' : '') . '</span><b>' . $nf($F[$k]) . ' <small class="muted">' . $pctT($pct($F[$k], $F['visits'])) . '</small><i class="fchev" aria-hidden="true"></i></b><span class="bar"><i style="width:' . round($F[$k] / $top * 100, 1) . '%"></i></span></summary>'
          . '<div class="fsi"><p class="muted">' . $what . '</p><p><span class="tl">What the data shows</span>' . implode('<br>', $data) . '</p>'
          . ($i === 0 || $lost > 0 ? '<p><span class="tl">' . ($i ? 'Why people stop here' : 'Why it matters') . '</span>' . $why . '</p>' . (isset($doneFix[$fixOf($word)])
              ? '<p><span class="tl">Done</span>Fixed on ' . date('d/m/Y', strtotime($doneFix[$fixOf($word)])) . '. We check the numbers again on ' . date('d/m/Y', strtotime($doneFix[$fixOf($word)] . ' +7 days')) . '.</p>'
              : (($tn = array_search($fixOf($word), array_column($tips, 'fix'), true)) !== false ? '<p><span class="tl">What to do</span>See tip <b>' . ($tn + 1) . '</b> in What to fix first.</p>'   // the same fix is a tip already: point to it, not the same steps again
              : '<div class="tst"><span class="tl">What to do</span><ol>' . implode('', array_map(fn($x) => '<li>' . $x . '</li>', $todo)) . $replyLi($word) . '</ol></div>')) : '')
          . '</div></details>'; }
  $fixName = ['fix old money' => 'Old Money page: people left without a next step', 'fix product page' => 'Product page: people looked but did not add to bag', 'fix home' => 'Home page: no clear next step',
    'fix landing page' => 'First page from ads', 'check ads' => 'Ads brought visitors who did not add to bag', 'fix bag' => 'Bag: people did not open checkout', 'fix checkout' => 'Checkout: people did not type their details', 'check payment' => 'Payment: people did not finish paying',
    'make it faster' => 'Site opened slowly on phones', 'fix phone' => 'Phone: people did not add to bag'];
  $fixL = ''; foreach ($doneH as $w => $title) $fixL .= '<li>' . ($fixName[$w] ?? ($title ?: h(ucfirst($w)))) . ' <span class="muted">· fixed ' . date('d/m/Y', strtotime($done[$w])) . ', checked again ' . date('d/m/Y', strtotime($done[$w] . ' +7 days')) . '</span></li>';
  foreach ($closed as $ck => $c) $fixL .= '<li>' . h($c['t'] !== '' ? $c['t'] : ucfirst((string)$ck)) . ' <span class="muted">· closed by you ' . date('d/m/Y', strtotime($c['d'])) . '</span>'
    . '<form method="post" class="iundo">' . $csrfField . '<input type="hidden" name="action" value="improve_close"><input type="hidden" name="iclose" value="' . h((string)$ck) . '"><input type="hidden" name="undo" value="1"><button type="submit">Undo</button></form></li>';
  if ($fixL) $fun .= '<details class="fs" name="fs"><summary class="st"><span>Fixed <small class="muted">' . (count($doneH) + count($closed)) . '</small></span><b><i class="fchev" aria-hidden="true"></i></b></summary>'
          . '<div class="fsi"><p class="muted">Problems we already fixed. Each one comes back to What to fix first only if its numbers are still bad a week after the fix.</p><ul class="fxl">' . $fixL . '</ul></div></details>';
  $tipsH = '';
  foreach ($tips as $i => $t)
    $tipsH .= '<li class="itip"><span class="tno">' . ($i + 1) . '</span><div>'
      . '<form method="post" class="iclose" onsubmit="return confirm(\'Close this tip? It moves to Fixed. You can Undo it there.\')">' . $csrfField . '<input type="hidden" name="action" value="improve_close"><input type="hidden" name="iclose" value="' . h($t['fix']) . '"><input type="hidden" name="title" value="' . h(strip_tags($t['title'])) . '"><button type="submit" aria-label="Close this tip" title="Close this tip">×</button></form>'
      . '<h4>' . $t['title'] . '</h4><p><span class="tl">What the data shows</span>' . implode('<br>', $t['data']) . '</p><p><span class="tl">Why it matters</span>' . $t['why'] . '</p>'
            . '<div class="tst"><span class="tl">Steps</span><ol>' . implode('', array_map(fn($s) => '<li>' . $s . '</li>', $t['steps'])) . $replyLi($t['word']) . '</ol></div></div></li>';
  $body .= '<div class="atop">' . date_bar($D, [], '. Your own visits and bots are not counted.') . '</div><div class="imp">'
    . '<div class="box i-fun"><div class="bh"><h3>Where visitors stop</h3></div><div class="bb"><div class="fun cfun">' . $fun . '</div>'
    . '<p class="muted small cvn">' . ($F['visits'] < 20 ? '<b>Only ' . $nf($F['visits']) . ' visits in these dates: pick 7 or 30 days for surer tips.</b> ' : '') . 'Each line counts visits. The red words show how many stopped at that step. <b>Tap a step</b> to see why and what to do.</p>'
    . taps_box($V, $names, 'i-taps', '', true) . '</div></div>'
    . '<div class="box i-tips"><div class="bh"><h3>What to fix first</h3><span class="sp"></span><a class="btn line sm" href="' . h(self_url(['tab' => 'analytics', 'vj' => 1])) . '">See the visits</a></div><div class="bb">'
    . ($tipsH ? '<ol class="tips">' . $tipsH . '</ol>' : ($doneH ? '<p class="empty">Nothing new to fix right now. A fixed tip comes back here only if the numbers are still bad a week after the fix.</p>' : '<p class="empty">No clear problem in these dates yet. Pick 7 or 30 days, or check back when more people have visited.</p>'))
    . '</div><div class="bf">' . '<div class="ihow"><b>How to use this page</b><ol><li>Start with tip 1: it affects the most people.</li><li>Read what the data shows and follow the steps.</li><li>Check again after a week: the numbers should move.</li></ol></div>' . '</div></div></div>';
} catch (Throwable $e) { $body = $body0 . '<div class="box"><p class="muted empty">Improve could not open: ' . h($e->getMessage()) . ' (line ' . $e->getLine() . '). Send us a picture of this line.</p></div>'; } }

/* ============ Progress: a clean page to show someone how the shop is doing, month by month or year by year ============ */
/* Visitors, orders and income only: no names, mobiles or Left at checkout. Income and orders are real orders (New, Paid, Delivered, not test), as on Analytics. */
if ($tab === 'progress') {
  $db = shop_db();
  $yr = ($_GET['v'] ?? '') === 'y';
  $first = min(array_filter([(string)$db->query("SELECT MIN(created) FROM orders WHERE test = 0")->fetchColumn(), (string)$db->query("SELECT MIN(ts) FROM events")->fetchColumn(), date('Y-m-d')]));
  $months = []; for ($t = strtotime(date('Y-m-01')); date('Y-m', $t) >= substr($first, 0, 7); $t = strtotime('-1 month', $t)) $months[] = date('Y-m', $t);
  $years = array_values(array_unique(array_map(fn($m) => substr($m, 0, 4), $months)));
  if ($yr) { $y = (string)($_GET['y'] ?? ''); if (!in_array($y, $years, true)) $y = $years[0]; $c1 = "$y-01-01"; $c2 = "$y-12-31"; $title = "Year $y"; }
  else { $m = (string)($_GET['m'] ?? ''); if (!in_array($m, $months, true)) $m = $months[0]; $c1 = "$m-01"; $c2 = date('Y-m-t', strtotime($c1)); $title = date('F Y', strtotime($c1)); }
  $cEnd = min($c2, date('Y-m-d'));   // this month or year: the graphs stop at today
  $gStart = $yr ? max($c1, substr($first, 0, 7) . '-01') : $c1;   // a year starts at the month the shop started
  $nd = (strtotime($cEnd) - strtotime($gStart)) / 86400 + 1;
  if ($yr ? $nd <= 92 : $nd < 2) $cEnd = $c2;   // series() picks days, months or hours from the length: keep months for a year and days for a month
  $A = analytics($c1, $c2, $CAT);
  $span = ["$c1 00:00:00", "$c2 23:59:59"];
  $pct = fn($a, $b) => $b > 0 ? rtrim(rtrim(number_format($a / $b * 100, 1), '0'), '.') . '%' : '0%';
  /* repeat orders: orders in the period from a mobile that had ordered before */
  $rep = 0; $seen = [];
  foreach ($db->query("SELECT phone, created FROM orders WHERE status IN " . SALE_STATUSES . " AND test = 0 ORDER BY created") as $o) {
    $k = coupon_phone((string)$o['phone']); if ($k === '') continue;
    if ($o['created'] >= $span[0] && $o['created'] <= $span[1] && isset($seen[$k])) $rep++;
    $seen[$k] = 1;
  }
  $units = array_sum(array_column($A['products'], 'units'));
  /* growth: the same days of the month (or year) before, so a month still running is never set against a whole month */
  $b1 = date('Y-m-d', strtotime($yr ? '-1 year' : 'first day of previous month', strtotime($c1)));
  $b2 = min(date('Y-m-d', strtotime($yr ? '-1 year' : '-1 month', strtotime(min($c2, date('Y-m-d'))))), date($yr ? 'Y-12-31' : 'Y-m-t', strtotime($b1)));
  [, $bRev, $bOrd] = sales_between("$b1 00:00:00", "$b2 23:59:59");
  $s = $db->prepare("SELECT COUNT(DISTINCT vid) FROM events WHERE type = 'view' AND ts >= ? AND ts <= ?"); $s->execute(["$b1 00:00:00", "$b2 23:59:59"]); $bVis = (int)$s->fetchColumn();
  $vsL = $yr ? date('Y', strtotime($b1)) : date('M', strtotime($b1));
  $grow = function ($now, $before) use ($vsL) {
    if ($before <= 0) return '';
    $g = (int)round(($now - $before) / $before * 100);
    return '<em class="' . ($g >= 0 ? 'up' : 'dn') . '">' . ($g >= 0 ? '▲ ' : '▼ ') . abs($g) . '%</em> ' . ($g >= 0 ? 'more' : 'less') . ' than ' . $vsL;
  };
  [, $allRev, $allOrd] = sales_between('', '9999-12-31 23:59:59');
  /* month by month (a year): visitors, orders, conversion, income */
  $rows = '';
  if ($yr) {
    $mv = $mo = [];
    $s = $db->prepare("SELECT substr(ts, 1, 7) k, COUNT(DISTINCT vid) v, COUNT(DISTINCT sid) n FROM events WHERE type = 'view' AND ts >= ? AND ts <= ? GROUP BY substr(ts, 1, 7)"); $s->execute($span);
    foreach ($s as $r) $mv[$r['k']] = [(int)$r['v'], (int)$r['n'], 0];
    $s = $db->prepare("SELECT substr(ts, 1, 7) k, COUNT(DISTINCT sid) n FROM events WHERE type = 'buy' AND ts >= ? AND ts <= ? GROUP BY substr(ts, 1, 7)"); $s->execute($span);
    foreach ($s as $r) if (isset($mv[$r['k']])) $mv[$r['k']][2] = (int)$r['n'];
    for ($i = (int)($y === substr($first, 0, 4) ? substr($first, 5, 2) : 1); $i <= 12; $i++) {   // a year starts at the month the shop started
      $k = sprintf('%s-%02d', $y, $i); [, $t, $n] = sales_between("$k-01 00:00:00", date('Y-m-t', strtotime("$k-01")) . ' 23:59:59');
      $v = $mv[$k] ?? [0, 0, 0];
      $rows .= '<tr' . ($k > date('Y-m') ? ' class="dim"' : '') . '><td>' . date('F', strtotime("$k-01")) . '</td><td class="r">' . number_format($v[0]) . '</td><td class="r">' . $n . '</td><td class="r"><b>' . rupees($t) . '</b></td></tr>';
    }
  }
  $box = fn($cls, $h, $inner, $np = false) => '<div class="box ' . $cls . '"><div class="bh"><h3>' . $h . '</h3></div><div class="bb' . ($np ? ' np' : '') . '">' . $inner . '</div></div>';
  $prod = '';
  $P = array_filter($A['products'], fn($p) => $p['units'] > 0); uasort($P, fn($a, $b) => [$b['units'], $b['rev']] <=> [$a['units'], $a['rev']]);
  foreach ($P as $id => $p) $prod .= '<tr><td><div class="pc">' . $thumbOf($id, 'th sm') . '<b>' . h($CAT[$id]['name']) . '</b></div></td><td class="r">' . $p['units'] . '</td><td class="r">' . $pct($p['units'], $units) . '</td></tr>';
  $G = geo_stats($c1, $c2); $geo = '';
  foreach (['countries' => 'Visitors by country', 'states' => 'Visitors by state'] as $gk => $gt) {
    $gr = array_slice($G[$gk], 0, $gk === 'countries' ? 6 : 10, true); $mx = max(1, ...array_values($gr ?: [1])); $in = '';
    foreach ($gr as $name => $n) $in .= '<div class="li"><span class="grow">' . ($gk === 'countries' ? '<span class="flag">' . fomaxo_flag((string)$name) . '</span> ' . h(fomaxo_country_name((string)$name)) : h((string)$name)) . '</span><span class="bar"><i style="width:' . round($n / $mx * 100) . '%"></i></span><b class="num">' . number_format($n) . '</b></div>';
    $geo .= $box('geo', $gt, $in ?: '<p class="empty">No visitors yet.</p>');
  }
  $body .= '<div class="prog">'
    . '<div class="phead"><div><h2>Progress</h2><p class="ptitle">' . h($title) . '</p></div><form class="ppick" method="get"><input type="hidden" name="tab" value="progress">' . ($yr ? '<input type="hidden" name="v" value="y">' : '')
    . '<span class="seg"><a href="' . h(self_url(['tab' => 'progress'])) . '"' . ($yr ? '' : ' class="on"') . '>Monthly</a><a href="' . h(self_url(['tab' => 'progress', 'v' => 'y'])) . '"' . ($yr ? ' class="on"' : '') . '>Yearly</a></span>'
    . '<select name="' . ($yr ? 'y' : 'm') . '" aria-label="' . ($yr ? 'Year' : 'Month') . '" onchange="this.form.submit()">'
    . implode('', array_map(fn($x) => '<option value="' . h($x) . '"' . ($x === ($yr ? $y : $m) ? ' selected' : '') . '>' . h($yr ? $x : date('F Y', strtotime("$x-01"))) . '</option>', $yr ? $years : $months)) . '</select>'
    . '<button type="button" class="btn line sm pprint" onclick="window.print()">Print / PDF</button></form></div>'
    . '<div class="kpis n6" style="--n:6">'
    . '<div class="kpi good"><span>Sales</span><b>' . rupees($A['revenue']) . '</b><small>' . ($grow($A['revenue'], $bRev) ?: ($A['paid_orders'] ? rupees(intdiv($A['revenue'], $A['paid_orders'])) . ' per order' : 'no orders yet')) . '</small></div>'
    . '<div class="kpi"><span>Orders</span><b>' . $A['paid_orders'] . '</b><small>' . ($grow($A['paid_orders'], $bOrd) ?: ($units ? $units . ' items sold' : '&nbsp;')) . '</small></div>'
    . '<div class="kpi"><span>Visitors</span><b>' . number_format($A['visitors']) . '</b><small>' . ($grow($A['visitors'], $bVis) ?: number_format($A['visits']) . ' visits') . '</small></div>'
    . '<div class="kpi"><span>Visitors who buy</span><b>' . ($A['conversion'] === null ? '—' : ($A['conversion'] >= 1 ? round($A['conversion']) . ' in 100' : round($A['conversion'], 1) . '%')) . '</b><small>out of every 100 visits</small></div>'
    . '<div class="kpi"><span>Buy again</span><b>' . $pct($rep, $A['paid_orders']) . '</b><small>&nbsp;</small></div>'
    . '<div class="kpi"><span>Total sales so far</span><b>' . rupees($allRev) . '</b><small>' . $allOrd . ' orders since ' . date('M Y', strtotime($first)) . '</small></div></div>'
    . '<div class="pg2">';
  $SR = series($gStart, $cEnd); $S = &$SR['sales'];
  /* Sales: a line of the total so far, rising with each sale, so one big day never makes the rest look empty. It starts at the first sale; tapping a point shows that day and the total */
  while (count($S['values']) > 1 && $S['values'][0] <= 0) { array_shift($S['values']); array_shift($S['labels']); array_shift($S['full']); }
  $run = 0; foreach ($S['values'] as $i => $v) { $run += $v; $S['full'][$i] .= ' · ' . rupees($v) . ' that ' . ($yr ? 'month' : 'day'); $S['values'][$i] = $run; } unset($S);
  foreach (['sales' => 'Sales', 'visitors' => 'Visitors'] as $k => $label)
    $body .= '<div class="box pc" data-chart="' . $k . '"' . ($k === 'sales' ? ' data-line="1" data-caption=" so far"' : ' data-caption=" in total"') . '><div class="bh"><h3>' . $label . ' <small class="muted">' . ($k === 'sales' ? 'total so far' : 'each ' . ($yr ? 'month' : 'day')) . '</small></h3><span class="ctot"></span></div>'
      . '<div class="chart" role="img" aria-label="' . $label . ' chart"><svg></svg><div class="tip" hidden></div></div></div>';
  $body .= '</div><div class="pg3' . ($yr ? ' yr' : '') . '">'
    . ($yr ? $box('pmonths', 'Month by month', '<table class="grid"><thead><tr><th>Month</th><th class="r">Visitors</th><th class="r">Orders</th><th class="r">Sales</th></tr></thead><tbody>' . $rows
        . '</tbody><tfoot><tr><td><b>Total</b></td><td class="r"><b>' . number_format($A['visitors']) . '</b></td><td class="r"><b>' . $A['paid_orders'] . '</b></td><td class="r"><b>' . rupees($A['revenue']) . '</b></td></tr></tfoot></table>', true) : '')
    . $box('pprod', 'Most sold', $prod ? '<table class="grid"><thead><tr><th>Perfume</th><th class="r">Sold</th><th class="r">Share</th></tr></thead><tbody>' . $prod . '</tbody></table>' : '<p class="empty">No orders in this ' . ($yr ? 'year' : 'month') . ' yet.</p>', (bool)$prod)
    . $geo . '</div>'
    . '<p class="muted small">Sales and orders count real orders only: cancelled and returned orders are left out. Visitor locations are approximate.</p></div>';
  $body .= '<script type="application/json" id="chartData">' . json_encode($SR, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . '</script>';
}

/* ============ Sales (profit & loss; was Reports) ============ */
if ($tab === 'sales') {
  /* the date bar picks the table: day by day up to 3 months, month by month for longer, by year for All */
  $D = pick_dates('sales'); $V = report_view($D); $rows = $V['rows']; $t = report_sum($rows); $cur = date($V['unit'] === 'D' ? 'Y-m-d' : ($V['unit'] === 'M' ? 'Y-m' : 'Y'));
  $m = fn($p) => '<span class="' . ($p < 0 ? 'neg' : '') . '">' . money($p) . '</span>';
  $g = in_array($_GET['g'] ?? '', ['sales', 'profit', 'orders', 'expenses'], true) ? $_GET['g'] : '';
  $kk = (string)($_GET['k'] ?? '');
  /* the four boxes: tapping one sets the dates to that month or year and opens its graph */
  $cy = report_year((int)date('Y')); $cm = $cy[date('Y-m')]; $ct = report_sum($cy);
  $mon = [date('Y-m-01'), date('Y-m-t')]; $yr = [date('Y-01-01'), date('Y-12-31')];
  $boxes = ['sm' => ['Sales this month', rupees($cm['sales']), $cm['orders'] . ($cm['orders'] === 1 ? ' order' : ' orders'), $mon, 'sales', ''],
    'pm' => ['Profit this month', money($cm['net']), date('F'), $mon, 'profit', $cm['net'] < 0 ? 'bad' : 'good'],
    'sy' => ['Sales of this year', rupees($ct['sales']), $ct['orders'] . ($ct['orders'] === 1 ? ' order' : ' orders'), $yr, 'sales', ''],
    'py' => ['Profit of this year', money($ct['net']), date('Y'), $yr, 'profit', $ct['net'] < 0 ? 'bad' : 'good']];
  $body .= date_bar($D) . '<div class="kpis n4 rk" style="--n:4">';
  foreach ($boxes as $k => [$label, $big, $small, $span, $gg, $cls])
    $body .= '<a class="kpi ' . $cls . ($kk === $k && $D['r'] === 'custom' && [$D['from'], $D['to']] === $span ? ' on' : '') . '" href="' . h(self_url(['tab' => 'sales', 'r' => 'custom', 'from' => $span[0], 'to' => $span[1], 'g' => $gg, 'k' => $k])) . '"><span>' . $label . '</span><b>' . $big . '</b><small>' . h($small) . '</small></a>';
  $body .= '</div>';
  $lab = fn(string $k, bool $long) => match ($V['unit']) { 'D' => date($long ? 'D j M Y' : 'j M', strtotime($k)), 'M' => date($long ? 'F Y' : (substr($V['from'], 0, 4) === substr($V['to'], 0, 4) ? 'M' : "M 'y"), strtotime("$k-01")), default => (string)$k };
  /* the graph: only after a box (or a row, while it is open) is tapped; Sales / Profit / Orders / Expenses switch it */
  if ($g !== '') {
    $close = self_url(['tab' => 'sales']);
    $GD = ['labels' => [], 'full' => [], 'sales' => [], 'profit' => [], 'orders' => [], 'expenses' => []];
    foreach ($rows as $k => $r) { $GD['labels'][] = $lab((string)$k, false); $GD['full'][] = $lab((string)$k, true); $GD['sales'][] = $r['sales']; $GD['profit'][] = $r['net']; $GD['orders'][] = $r['orders']; $GD['expenses'][] = $r['expenses']; }
    $body .= '<div class="box rgraph" data-g="' . $g . '"><div class="bh"><span class="seg gm">' . implode('', array_map(fn($x, $l) => '<button type="button" data-g="' . $x . '"' . ($x === $g ? ' class="on"' : '') . ">$l</button>", ['sales', 'profit', 'orders', 'expenses'], ['Sales', 'Profit', 'Orders', 'Expenses'])) . '</span>'
      . '<span class="muted small gdates">From ' . dmy($V['from']) . ' to ' . dmy($V['to']) . '</span><a class="zx" href="' . h($close) . '" aria-label="Close the graph" title="Close">✕</a></div>'
      . '<div class="chart lchart" role="img" aria-label="Graph"><svg></svg><div class="tip" hidden></div></div><div class="gtot"><span class="muted">Total</span> <b></b></div>'
      . '<script type="application/json" id="repGraph">' . json_encode($GD, JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . '</script></div>';
  }
  $what = ['D' => 'Day by day', 'M' => 'Month by month', 'Y' => 'By year'][$V['unit']];
  $head = '<th>' . ['D' => 'Day', 'M' => 'Month', 'Y' => 'Year'][$V['unit']] . '</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">Discounts</th><th class="r">Coupons</th><th class="r">Fees</th><th class="r">Cost of goods</th><th class="r">Gross profit</th><th class="r">Expenses</th><th class="r">Net profit / loss</th>';
  $body .= '<div class="box fill rtab"><div class="bh"><span><h3 style="display:inline">' . $what . '</h3> <span class="muted small">' . h(date_span($V['from'], $V['to'])) . ($V['unit'] === 'D' ? '' : ' · tap a ' . ($V['unit'] === 'M' ? 'month' : 'year') . ' to open it') . '</span></span>'
    . '<a class="btn line sm" href="' . h(self_url(['do' => 'report_excel'])) . '">Excel</a></div><div class="bb np"><table class="grid"><thead><tr>' . $head . '</tr></thead><tbody>';
  foreach ($rows as $k => $r) {
    $k = (string)$k;
    $open = match ($V['unit']) { 'M' => ["$k-01", date('Y-m-t', strtotime("$k-01"))], 'Y' => ["$k-01-01", "$k-12-31"], default => null };
    $name = h($lab($k, $V['unit'] === 'M' && substr($V['from'], 0, 4) !== substr($V['to'], 0, 4)));
    if ($V['unit'] === 'D') $name = h(date('D', strtotime($k))) . ' <span class="muted">' . h(date('j M', strtotime($k))) . '</span>';
    $body .= '<tr' . ($k > $cur ? ' class="dim"' : '') . ($open ? ' data-href="' . h(self_url(['tab' => 'sales', 'r' => 'custom', 'from' => $open[0], 'to' => $open[1]] + ($g ? ['g' => $g] : []))) . '"' : '') . '>'
      . '<td>' . ($open ? '<a href="' . h(self_url(['tab' => 'sales', 'r' => 'custom', 'from' => $open[0], 'to' => $open[1]] + ($g ? ['g' => $g] : []))) . '">' . $name . '</a>' : $name) . '</td>'
      . '<td class="r">' . $r['orders'] . '</td><td class="r">' . rupees($r['sales']) . '</td><td class="r">' . rupees($r['discounts']) . '</td><td class="r">' . rupees($r['coupons']) . '</td><td class="r">' . rupees($r['fees']) . '</td>'
      . '<td class="r">' . rupees($r['cost']) . ($r['nocost'] ? ' <small class="warn">+' . $r['nocost'] . ' no cost</small>' : '') . '</td><td class="r">' . $m($r['gross']) . '</td><td class="r">' . rupees($r['expenses']) . '</td><td class="r"><b>' . $m($r['net']) . '</b></td></tr>';
  }
  $body .= '</tbody><tfoot><tr><td>Total</td><td class="r">' . $t['orders'] . '</td><td class="r">' . rupees($t['sales']) . '</td><td class="r">' . rupees($t['discounts']) . '</td><td class="r">' . rupees($t['coupons']) . '</td><td class="r">' . rupees($t['fees']) . '</td><td class="r">' . rupees($t['cost']) . ($t['nocost'] ? ' <small class="warn">+' . $t['nocost'] . ' no cost</small>' : '') . '</td><td class="r">' . $m($t['gross']) . '</td><td class="r">' . rupees($t['expenses']) . '</td><td class="r"><b>' . $m($t['net']) . '</b></td></tr></tfoot></table>'
    . '<p class="muted small" style="padding:0 12px">Sales are orders marked New, Paid or Delivered, by order date, including the cash on delivery fee. Cancelled orders, unfinished payments and test payments are left out. Discounts are what customers saved against the “Was” price, and Coupons what coupon codes took off (both already taken off sales). Fees are the card / UPI payment fee set in Settings (' . pay_fee_pct() . '%). Gross profit = sales − fees − cost of goods. Net = gross profit − expenses.</p></div></div>'
    . '<script>document.querySelectorAll(".rtab tr[data-href]").forEach(function(r){r.onclick=function(e){if(!e.target.closest("a"))location.href=r.dataset.href}})</script>';
}

/* ============ Reviews ============ */
if ($tab === 'reviews') {
  $rq = trim((string)($_GET['q'] ?? '')); $rv = (string)($_GET['v'] ?? ''); $rv = in_array($rv, ['1', '0'], true) ? $rv : '';
  $rp = (string)($_GET['p'] ?? ''); if (!preg_match('/^[\w-]{1,60}$/', $rp)) $rp = '';
  $rs = (string)($_GET['s'] ?? ''); $rs = in_array($rs, ['1', '2', '3', '4', '5'], true) ? $rs : '';
  $rw = (string)($_GET['who'] ?? ''); if (!preg_match('/^(m:\+?\d{6,16}|n:.{1,80})$/su', $rw)) $rw = '';   // one reviewer, tapped in Top Reviewers (m:mobile or n:name, as grouped there)
  $ri = (string)($_GET['pr'] ?? ''); $ri = in_array($ri, ['bad', 'faulty', 'late', '1'], true) ? $ri : '';   // Bad product / Faulty product / Late delivery chips (old ?pr=1 links: the last two)
  /* the date bar: picking dates shows only the reviews written in them (the counts, stars and top reviewers too) */
  $D = pick_dates('reviews'); $dd = $D['r'] === 'all' ? [] : ['from' => $D['from'], 'to' => $D['to']];
  $ALL = reviews_list($dd); $list = reviews_list(array_filter(['q' => $rq, 'product' => $rp], 'strlen') + $dd + ($rv !== '' ? ['verified' => (int)$rv] : []) + ($rs !== '' ? ['stars' => (int)$rs] : []) + ($ri !== '' ? ['issue' => $ri] : []), $ALL);
  $rvKey = fn($r) => $r['phone'] !== '' ? 'm:' . $r['phone'] : 'n:' . mb_strtolower($r['name']);
  if ($rw !== '') $list = array_values(array_filter($list, fn($r) => $rvKey($r) === $rw));
  $rwName = ''; foreach ($ALL as $r) if ($rvKey($r) === $rw) { $rwName = $r['customer'] ?: $r['name']; break; }
  /* 50 reviews a page, newest first (each one carries its reply box, so a long list was slow to open on a phone); the counts above still cover every review */
  $rpp = 50; $rpages = max(1, (int)ceil(count($list) / $rpp)); $rpage = max(1, min($rpages, (int)($_GET['page'] ?? 1)));
  $nv = count(array_filter($ALL, fn($r) => (int)$r['verified'] === 1)); $nu = count($ALL) - $nv;
  $keep = array_filter(['tab' => 'reviews', 'q' => $rq, 'v' => $rv, 'p' => $rp, 's' => $rs, 'pr' => $ri, 'who' => $rw], 'strlen'); $back = h(json_encode($keep + ($rpage > 1 ? ['page' => $rpage] : [])));
  $body .= date_bar($D, ['q' => $rq, 'v' => $rv, 'p' => $rp, 's' => $rs, 'pr' => $ri, 'who' => $rw]) . '<form method="get" class="row rtool"><input type="hidden" name="tab" value="reviews">' . ($rw !== '' ? '<input type="hidden" name="who" value="' . h($rw) . '">' : '') . ($rv !== '' ? '<input type="hidden" name="v" value="' . $rv . '">' : '') . ($rp !== '' ? '<input type="hidden" name="p" value="' . h($rp) . '">' : '') . ($rs !== '' ? '<input type="hidden" name="s" value="' . $rs . '">' : '') . ($ri !== '' ? '<input type="hidden" name="pr" value="' . $ri . '">' : '')
    . '<input type="search" name="q" value="' . h($rq) . '" placeholder="Words, name or mobile"><button class="btn line sm">Search</button>'
    . '<a class="chip' . ($rv === '1' ? ' on' : '') . '" href="' . h(self_url(['v' => $rv === '1' ? '' : '1'] + $keep)) . '">Verified purchaser <b>' . $nv . '</b></a>'
    . '<a class="chip' . ($rv === '0' ? ' on' : '') . '" href="' . h(self_url(['v' => $rv === '0' ? '' : '0'] + $keep)) . '">Unverified <b>' . $nu . '</b></a>'
    /* problems, verified and unverified alike: tap one to see only those reviews (a coupon is owed), tap again for all */
    . implode('', array_map(fn($k, $t) => '<a class="chip ck-' . $k . ($ri === $k ? ' on' : '') . '" href="' . h(self_url(['pr' => $ri === $k ? '' : $k] + $keep)) . '" title="Show only ' . strtolower($t) . ' reviews">' . $t . ' <b>' . count(array_filter($ALL, fn($r) => ($r['issue'] ?? '') === $k)) . '</b></a>', ['bad', 'faulty', 'late'], ['Bad product', 'Faulty product', 'Delivery delay']))
    /* stars: tap one to see only the reviews with that many stars, tap again for all */
    . implode('', array_map(fn($n) => '<a class="chip' . ($rs === (string)$n ? ' on' : '') . '" href="' . h(self_url(['s' => $rs === (string)$n ? '' : (string)$n] + $keep)) . '" title="Show only ' . $n . '-star reviews">' . $n . '★ <b>' . count(array_filter($ALL, fn($r) => (int)round((float)$r['rating']) === $n)) . '</b></a>', [5, 4, 3, 2, 1])) . '</form>';
  $body .= $sw('#rvPanes', ['list' => 'Reviews', 'stars' => 'Stars By Product', 'top' => 'Top Reviewers']) . '<div class="revs panes" id="rvPanes">';
  /* every review */
  $body .= '<div class="box on" data-pane="list" data-csrf="' . h($CSRF) . '"><div class="bh">' . ($rp !== '' ? '<a class="chip on" href="' . h(self_url(array_diff_key($keep, ['p' => 1]))) . '" title="Show every product">' . h($CAT[$rp]['name'] ?? $rp) . ' <b>✕</b></a>' : '') . ($rw !== '' ? '<a class="chip on" href="' . h(self_url(array_diff_key($keep, ['who' => 1]))) . '" title="Show every reviewer">Reviews by ' . h($rwName !== '' ? $rwName : substr($rw, 2)) . ' <b>✕</b></a>' . (str_starts_with($rw, 'm:') ? '<a class="small" href="' . h(self_url(['tab' => 'members', 'c' => $rw])) . '">Customer page</a>' : '') : '') . '<span class="muted small" style="flex:1">' . count($list) . ' review' . (count($list) === 1 ? '' : 's') . ($D['r'] === 'all' ? '' : ' · ' . h(period_label($D))) . '. Hidden reviews leave the website and the star rating; Show puts them back. Delete removes a review for good.</span></div><div class="bb">';
  if (!reviews_db()) $body .= '<p class="empty">No reviews yet.</p>';
  elseif (!$list) $body .= '<p class="empty">No reviews' . ($rq !== '' || $rv !== '' || $rs !== '' || $ri !== '' ? ' match this search.' : ' yet.') . '</p>';
  $rvSent = json_decode((string)shop_setting('review_wa'), true) ?: [];   // review id => day its customer got a WhatsApp
  foreach (array_slice($list, ($rpage - 1) * $rpp, $rpp) as $r) {
    $live = $r['status'] === 'live';
    /* a 1–3 star review from a customer whose mobile we have: WhatsApp them an apology, with a coupon or not (the WhatsApp box, admin.js); a green button at the end of the stars line */
    $issue = ['late' => 'Delivery delay', 'faulty' => 'Faulty product', 'bad' => 'Bad product'][$r['issue'] ?? ''] ?? '';   // picked on the review form, or read from the review's words at any star rating (late / faulty: a coupon is owed); any other 1–3 stars: bad product
    $rvWa = ((int)round((float)$r['rating']) <= 3 || $issue !== '') && wa_key_ok((string)$r['phone']) ? '<button type="button" class="btn sm' . (isset($rvSent[$r['id']]) ? ' line' : '') . '" data-rvwa="' . (int)$r['id'] . '" data-phone="' . h($r['phone']) . '"' . wt_attr($r['phone']) . ' data-who="' . h($r['name']) . '" data-first="' . h(first_name((string)$r['name'])) . '" data-product="' . h($CAT[$r['product']]['name'] ?? '') . '" data-issue="' . h($r['issue'] ?? '') . '" data-photo="' . (json_decode((string)$r['photos'], true) ? '1' : '') . '">' . WA_SVG . '<span>' . (isset($rvSent[$r['id']]) ? 'Sent ' . h(date('d/m', strtotime($rvSent[$r['id']]))) : 'WhatsApp') . '</span></button>' : '';
    $photos = json_decode((string)$r['photos'], true) ?: [];
    $body .= '<div class="rv' . ($live ? '' : ' off') . (($r['issue'] ?? '') !== '' ? ' rv-' . h($r['issue']) : '') . '"><div class="rvh">' . $thumbOf($r['product'], 'th xs') . '<b>' . h($CAT[$r['product']]['name'] ?? $r['product']) . '</b>' . stars((float)$r['rating'])
      . ($r['verified'] ? '<span class="badge st-paid">Verified purchaser</span>' : '') . ($issue !== '' ? '<span class="badge st-cancelled ib-' . h($r['issue']) . '">' . $issue . '</span>' : '') . ($r['status'] === 'pending' ? '<span class="badge st-new">Waiting</span>' : (!$live ? '<span class="badge st-cancelled">Hidden</span>' : '')) . ($rvWa !== '' ? '<span class="rvwa">' . $rvWa . '</span>' : '') . '</div>'
      . ($r['body'] !== '' ? '<p>' . nl2br(h($r['body'])) . '</p>' : '')
      . ($photos ? '<div class="rvp">' . implode('', array_map(fn($f) => '<a href="/media/r/' . rawurlencode($f) . '" target="_blank" rel="noopener"><img src="/media/r/' . rawurlencode($f) . '" alt="" loading="lazy"></a>', $photos)) . '</div>' : '')
      . '<small class="muted">' . h($r['anonymous'] ? 'Anonymous (' . $r['name'] . ')' : $r['name']) . ($r['phone'] ? ' · ' . h(phone_fmt($r['phone'])) : '') . ' · ' . h(date('d M Y', (int)$r['created'])) . ($r['helpful'] ? ' · ' . (int)$r['helpful'] . ' found it helpful' : '')
      . ($r['phone'] && $r['verified'] ? ' · <a href="' . h(self_url(['tab' => 'members', 'c' => 'm:' . $r['phone']])) . '">Customer page</a>' : '') . '</small>'
      . (($r['reply'] ?? '') !== '' ? '<div class="rvr"><b>Reply from FOMAXO</b><p>' . nl2br(h($r['reply'])) . '</p></div>' : '')
      . '<input type="checkbox" class="rvr-tg" id="rvr' . (int)$r['id'] . '" hidden>'
      . '<div class="rvr-acts"><label for="rvr' . (int)$r['id'] . '" class="btn line sm">' . (($r['reply'] ?? '') === '' ? 'Reply' : 'Edit reply') . '</label>'
      . (($r['reply'] ?? '') !== '' ? '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="review_reply"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="back" value="' . $back . '"><button class="btn line sm danger" name="delete" value="1" data-confirm="Remove your reply from the website?">Delete reply</button></form>' : '')
      . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="review"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="back" value="' . $back . '">'
      . ($live ? '<input type="hidden" name="status" value="hidden"><button class="btn line sm" data-confirm="Hide this review from the website? It stays here and Show brings it back.">Hide</button>' : '<input type="hidden" name="status" value="live"><button class="btn sm">' . ($r['status'] === 'pending' ? 'Publish' : 'Show') . '</button>') . '</form>'
      . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="review_delete"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="back" value="' . $back . '"><button class="btn line sm danger" data-confirm="Delete this review for good? It leaves the website and this list, with its photos and reply. This cannot be undone.">Delete</button></form>' . '</div>'
      . '<form method="post" class="rvr-form" data-sg-name="' . h($r['anonymous'] ? '' : $r['name']) . '" data-sg-product="' . h($CAT[$r['product']]['name'] ?? '') . '" data-sg-stars="' . (int)round((float)$r['rating']) . '" data-sg-issue="' . h($r['issue'] ?? '') . '" data-sg-body="' . h($r['body']) . '">' . $csrfField . '<input type="hidden" name="action" value="review_reply"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="back" value="' . $back . '">'
      . '<textarea name="reply" rows="3" maxlength="1000" placeholder="Write your reply to this customer…">' . h($r['reply'] ?? '') . '</textarea>'
      . '<div class="emo" role="group" aria-label="Add an emoji">' . implode('', array_map(fn($e) => '<button type="button" data-emo="' . $e . '" aria-label="Add ' . $e . '">' . $e . '</button>', REPLY_EMOJI)) . '</div>'
      . '<div class="row"><button class="btn sm">Save reply</button><button type="button" class="btn line sm" data-sg-next title="Write a different reply that fits this review">↻ Another reply</button><button type="button" class="btn line sm" data-sg-clear title="Empty the box to write your own reply">✕ Clear</button></div></form></div>';
  }
  if ($rpages > 1) {
    $body .= '<div class="pager">';
    for ($i = 1; $i <= $rpages; $i++) $body .= '<a' . ($i === $rpage ? ' class="on"' : '') . ' href="' . h(self_url($keep + ($i > 1 ? ['page' => $i] : []))) . '">' . $i . '</a>';
    $body .= '</div>';
  }
  $body .= '</div></div>' . wa_box('rvWa', 'gfree', '', 10, 0, 'A new GOODWILL- code just for this customer: one use, only with their mobile number. It is made when you tap Open WhatsApp.');
  /* stars by product (live reviews only, as on the website) */
  $by = [];
  foreach ($ALL as $r) if ($r['status'] === 'live') { $by[$r['product']]['n'] = ($by[$r['product']]['n'] ?? 0) + 1; $by[$r['product']]['sum'] = ($by[$r['product']]['sum'] ?? 0) + (int)$r['rating']; }
  uasort($by, fn($a, $b) => $b['n'] <=> $a['n']);
  $body .= '<div class="box" data-pane="stars"><div class="bh"><h3>Stars By Product</h3><span class="muted small">Tap a product to see its reviews</span></div><div class="bb">';
  if (!$by) $body .= '<p class="empty">No live reviews yet.</p>';
  foreach ($by as $pid => $x) { $avg = $x['sum'] / $x['n']; $body .= '<a class="li' . ($pid === $rp ? ' on' : '') . '" href="' . h(self_url(['p' => $pid === $rp ? '' : $pid] + $keep)) . '">' . $thumbOf($pid, 'th sm') . '<span class="grow"><b>' . h($CAT[$pid]['name'] ?? $pid) . '</b><small>' . $x['n'] . ' review' . ($x['n'] === 1 ? '' : 's') . '</small></span>' . stars($avg) . '<b class="avg">' . number_format($avg, 1) . '</b></a>'; }
  $body .= '</div></div>';
  /* top reviewers: grouped by mobile (verified purchasers) or by name */
  $tmin = top_reviewers_min(); $who = [];
  foreach ($ALL as $r) { $k = $r['phone'] !== '' ? 'm:' . $r['phone'] : 'n:' . mb_strtolower($r['name']); $who[$k]['name'] = $r['customer'] ?: $r['name']; $who[$k]['phone'] = $r['phone']; $who[$k]['n'] = ($who[$k]['n'] ?? 0) + 1; $who[$k]['sum'] = ($who[$k]['sum'] ?? 0) + (int)$r['rating']; }
  $who = array_filter($who, fn($w) => $w['n'] >= $tmin); uasort($who, fn($a, $b) => $b['n'] <=> $a['n']);
  $body .= '<div class="box" data-pane="top"><div class="bh"><h3>Top Reviewers</h3><form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="top_reviewers"><input type="hidden" name="back" value="' . $back . '"><label class="mrule">at least<input type="number" name="top_reviewers" min="1" max="99" value="' . $tmin . '"> reviews</label><button class="btn line sm">Save</button></form></div><div class="bb">';
  if (!$who) $body .= '<p class="empty">Nobody has ' . $tmin . ' or more reviews yet.</p>';
  foreach ($who as $k => $w) $body .= '<a class="li' . ($k === $rw ? ' on' : '') . '" href="' . h(self_url(['who' => $k === $rw ? '' : $k] + $keep)) . '" title="Show their reviews"><span class="grow"><b>' . h($w['name']) . '</b><small>' . ($w['phone'] ? h(phone_fmt($w['phone'])) . ' · ' : '') . 'average ' . number_format($w['sum'] / $w['n'], 1) . ' ★</small></span><b>' . $w['n'] . '</b><span class="muted small">reviews</span></a>';
  $body .= '</div></div></div>';
}

/* ============ Stores (shown on the Contact page with a Google Map each) ============ */
if ($tab === 'stores') {
  $loc = stores_all();
  $fields = fn(array $st) => '<label>Store name<input name="name" maxlength="80" value="' . h((string)($st['name'] ?? '')) . '" placeholder="FOMAXO Store" required></label>'
    . '<label>Google Maps link<input type="url" name="link" maxlength="600" value="' . h((string)($st['link'] ?? '')) . '" placeholder="https://maps.app.goo.gl/…"></label>'
    . '<label>Address <small>(optional if there is a link)</small><textarea name="address" rows="2" maxlength="300" placeholder="Shop no, building, street, area, city, PIN">' . h((string)($st['address'] ?? '')) . '</textarea></label>'
    . '<label>Opening hours <small>(optional)</small><input name="hours" maxlength="120" value="' . h((string)($st['hours'] ?? '')) . '" placeholder="Open daily · 10 am – 10 pm"></label>';
  $body .= $sw('#stPanes', ['list' => 'Your stores', 'add' => 'Add a store']) . '<div class="exp panes" id="stPanes">'
    . '<form method="post" class="box" data-pane="add">' . $csrfField . '<input type="hidden" name="action" value="store_add"><div class="bh"><h3>Add a store</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:12px">'
    . $fields([]) . '<p class="muted small" style="margin:0">In Google Maps, open your store, press <b>Share</b> and <b>Copy link</b>, then paste it here.</p><button class="btn" style="width:100%">Add store</button></div></form>'
    . '<div class="box on" data-pane="list"><div class="bh"><h3>Your stores · ' . count($loc['stores']) . '</h3>'
    . '<form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="stores_show"><input type="hidden" name="show" value="' . ($loc['show'] ? '0' : '1') . '"><span class="muted small">' . ($loc['show'] ? 'Shown on the Contact page' : 'Hidden on the Contact page') . '</span><button class="btn line sm">' . ($loc['show'] ? 'Hide' : 'Show') . '</button></form></div><div class="bb stores-l">';
  if (!$loc['stores']) $body .= '<p class="empty">No stores yet. Add one and it shows on the Contact page with a Google Map.</p>';
  foreach ($loc['stores'] as $i => $st) {
    $mapQ = shop_map_query_from_link((string)($st['link'] ?? '')) ?: trim(($st['name'] ?? '') . ', ' . ($st['address'] ?? ''), ', ');
    $body .= '<div class="store-c"><form method="post" class="store-f">' . $csrfField . '<input type="hidden" name="action" value="store_save"><input type="hidden" name="i" value="' . $i . '"><b class="gold">Store ' . ($i + 1) . '</b>' . $fields($st)
      . '<div class="row"><button class="btn">Save</button><button class="btn line danger" formnovalidate name="action" value="store_remove" data-confirm="Remove ' . h((string)($st['name'] ?: 'this store')) . ' from the Contact page?">Remove</button></div></form>'
      . '<div class="map-prev"><iframe src="https://maps.google.com/maps?q=' . h(rawurlencode($mapQ)) . '&amp;z=16&amp;output=embed" title="Map preview, store ' . ($i + 1) . '" loading="lazy" referrerpolicy="no-referrer-when-downgrade"></iframe></div></div>';
  }
  $body .= '</div></div></div>';
}

/* ============ Settings ============ */
if ($tab === 'settings') {
  $email = (string)shop_setting('notify_email');
  $pane = (string)($_GET['pane'] ?? '');   // back on the pane just saved (phones show one pane at a time)
  $adsFirst = $pane === 'ads'; $codFirst = $pane === 'cod'; $pgFirst = $pane === 'pages'; $first = $adsFirst || $codFirst || $pgFirst;
  $COD = fomaxo_catalog()['cod'] ?? null;
  $AD = shop_ads() + ['meta' => '', 'tiktok' => '', 'ga4' => '', 'gads' => '', 'gadsLabel' => '', 'clarity' => ''];
  $adIn = fn(string $k, string $label, string $ph, string $help) => '<label>' . $label . '<input name="' . $k . '" value="' . h($AD[$k]) . '" placeholder="' . $ph . '" autocomplete="off" spellcheck="false"></label><p class="muted small" style="margin:-4px 0 0">' . $help . '</p>';
  $setPanes = ['notify' => 'New orders', 'pages' => 'Site pages', 'cod' => 'Cash on delivery', 'ads' => 'Ads', 'pw' => 'Password', 'db' => 'Database'];
  $OFF = shop_pages_off();
  $pgAddr = ['dubai' => 'fomaxo.com'];
  if ($first) $setPanes = [$pane => $setPanes[$pane]] + $setPanes;
  $rs = fn(int $paise) => (string)intdiv($paise, 100);
  $body .= $sw('#setPanes', $setPanes) . '<div class="set panes" id="setPanes">'
    . '<form method="post" class="box' . ($first ? '' : ' on') . '" data-pane="notify">' . $csrfField . '<input type="hidden" name="action" value="settings"><div class="bh"><h3>New orders</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:12px">'
    . '<label>Send new order emails to<input type="email" name="notify_email" value="' . h($email) . '" placeholder="' . h(fomaxo_catalog()['email'] ?: 'fomaxoasset@gmail.com') . '"></label>'
    . '<p class="muted small" style="margin:0">Every new order is emailed here, and so is a password reset link if you forget your password. Empty uses ' . h(fomaxo_catalog()['email'] ?: 'fomaxoasset@gmail.com') . '.</p>'
    . '<label class="pg"><span><b>Sound on new orders</b><small style="white-space:normal">A chime with each new order pop-up</small></span><input type="checkbox" class="tgl" name="alert_sound" value="1"' . (shop_setting('alert_sound') === '1' ? ' checked' : '') . ' aria-label="Sound on new orders"></label>'
    . '<div class="nalert"><button type="button" class="btn line sm" data-notify-on>Turn on pop-ups on this device</button><small class="muted" data-notify-state></small></div>'
    . '<p class="muted small" style="margin:0">Pop-ups show a new order even when the admin tab is behind other windows. Turn them on once on each phone or laptop you use.</p>'
    . '<label>Card / UPI payment fee %<input type="number" name="pay_fee" min="0" max="10" step="0.01" value="' . h((string)pay_fee_pct()) . '"></label>'
    . '<p class="muted small" style="margin:0">Razorpay’s fee on each online payment, used for Fees in Sales.</p><button class="btn">Save</button></div></form>'
    . '<form method="post" class="box' . ($pgFirst ? ' on' : '') . '" data-pane="pages">' . $csrfField . '<input type="hidden" name="action" value="pages"><div class="bh"><h3>Site pages</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:9px">'
    . '<p class="muted small" style="margin:0">Turn a page off to hide it. It leaves the menu and the footer, and its link opens the home page. Home, products, checkout and policies always show.</p>'
    . '<div class="pgs">' . implode('', array_map(fn($k, $n) => '<label class="pg"><span><b>' . h($n) . '</b><small>' . h($pgAddr[$k] ?? 'fomaxo.in/#/' . $k) . '</small></span><input type="checkbox" class="tgl" name="on[]" value="' . h($k) . '"' . (in_array($k, $OFF, true) ? '' : ' checked') . ' aria-label="Show ' . h($n) . '"></label>', array_keys(SHOP_PAGES), SHOP_PAGES)) . '</div>'
    . '<button class="btn">Save</button></div></form>'
    . '<form method="post" class="box' . ($codFirst ? ' on' : '') . '" data-pane="cod" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="cod"><div class="bh"><h3>Cash on delivery</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:9px">'
    . ($COD ? '' : '<p class="small" style="margin:0;color:var(--red,#e5484d)">Cash on delivery is turned off in index.html, so these numbers are not used yet.</p>')
    . '<label>Minimum order (₹)<input type="number" name="cod_min" min="0" step="1" required value="' . h($rs((int)($COD['min'] ?? 100000))) . '"></label><p class="muted small" style="margin:-4px 0 0">Cash on delivery only from this amount.</p>'
    . '<label>Maximum order (₹)<input type="number" name="cod_max" min="0" step="1" value="' . (($COD['max'] ?? 0) ? h($rs((int)$COD['max'])) : '') . '" placeholder="No limit"></label><p class="muted small" style="margin:-4px 0 0">Cash on delivery only for orders under this amount, after any coupon. Customers don’t see it until their order reaches it. Empty or 0 = no limit.</p>'
    . '<label>Cash on delivery fee (₹)<input type="number" name="cod_fee" min="0" step="1" required value="' . h($rs((int)($COD['fee'] ?? 5000))) . '"></label><p class="muted small" style="margin:-4px 0 0">Added to every cash on delivery order. 0 = no fee.</p>'
    . '<button class="btn">Save</button></div></form>'
    . '<form method="post" class="box' . ($adsFirst ? ' on' : '') . '" data-pane="ads" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="ads"><div class="bh"><h3>Ad tracking</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:9px">'
    . '<p class="muted small" style="margin:0">Paste an ID to turn it on; an empty box stays off. The website then tells Meta, TikTok and Google about product views, adds to bag, checkouts and purchases (with the ₹ value), so ads learn who buys.</p>'
    . $adIn('meta', 'Meta Pixel ID', '123456789012345', 'Facebook and Instagram. Events Manager → your pixel. 1050618291345073 is built into the website and runs even when this is empty; an ID typed here is used instead.')
    . $adIn('tiktok', 'TikTok Pixel ID', 'C1ABCDEFGH2IJKLMNOP3', 'Ads Manager → Tools → Events → Web events.')
    . $adIn('ga4', 'Google Analytics ID', 'G-XXXXXXXXXX', 'Analytics → Admin → Data streams. Starts with G-. G-C1TFB0MT5N is built into the website and runs even when this is empty; an ID typed here is used instead.')
    . $adIn('gads', 'Google Ads ID', 'AW-123456789', 'Goals → Conversions → Tag setup. Starts with AW-.')
    . $adIn('gadsLabel', 'Google Ads purchase label', 'AbCdEfGhIjKlMnOp', 'What comes after AW-…/ in your Purchase conversion.')
    . $adIn('clarity', 'Microsoft Clarity project ID', 'abcde12345', 'clarity.microsoft.com → your project → Settings → Overview. yvikvvr7hf is built into the website and runs even when this is empty; an ID typed here is used instead. Records visits and heatmaps; names, phones and addresses at checkout stay hidden, and customer review links and thank-you pages are not recorded.')
    . '<button class="btn">Save</button></div></form>'
    . '<form method="post" class="box' . ($adsFirst ? ' on' : '') . '" data-pane="ads" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="gsv"><div class="bh"><h3>Google site check</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:9px">'
    . '<p class="muted small" style="margin:0">For Google Merchant Center (or Search Console) to confirm fomaxo.in is yours. In Google choose <b>Verify website → HTML file</b>, then paste the file’s name here. Nothing to upload.</p>'
    . '<label>Google file name<input name="gsv" value="' . h(shop_gsv()) . '" placeholder="google1234abcd.html" spellcheck="false" autocapitalize="off"></label>'
    . (shop_gsv() !== '' ? '<p class="muted small" style="margin:-4px 0 0">Ready at <a href="/' . h(shop_gsv()) . '" target="_blank" rel="noopener">fomaxo.in/' . h(shop_gsv()) . '</a>. Keep it saved after Google verifies: Google checks again now and then.</p>' : '')
    . '<button class="btn">Save</button></div></form>'
    . '<form method="post" class="box" data-pane="pw" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="password"><div class="bh"><h3>Change your password</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:12px">'
    . '<label>Current password<input type="password" name="current" required autocomplete="current-password"></label>'
    . '<label>New password <small>(at least 8 characters)</small><input type="password" name="new" required minlength="8" autocomplete="new-password"></label>'
    . '<label>New password again<input type="password" name="again" required minlength="8" autocomplete="new-password"></label><button class="btn">Change password</button>'
    . '<p class="muted small" style="margin:0">Forgot it? Use “Forgot password?” on the sign-in page and a reset link is emailed to ' . h(mask_email(fomaxo_store_email())) . '.</p></div></form>';
  if (shop_is_mysql()) $body .= '<div class="box" data-pane="db"><div class="bh"><h3>Database</h3></div><div class="bb" style="padding-top:12px"><p class="muted">Orders, stock, products, expenses and visits are saved in your Hostinger MySQL database.</p></div></div>';
  else $body .= '<form method="post" class="box" data-pane="db" autocomplete="off">' . $csrfField . '<input type="hidden" name="action" value="mysql"><div class="bh"><h3>Database</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:10px">'
    . '<p class="muted small" style="margin:0">Everything is saved on Hostinger in a private file (shop.sqlite). To use your Hostinger MySQL database instead (optional):</p>'
    . '<ol class="steps small"><li>In Hostinger, open <b>Websites → fomaxo.in → Databases</b>.</li><li>Next to <b>u934663824_fomaxoin</b>, choose <b>Change password</b>.</li><li>Type that password below and press Connect.</li></ol>'
    . '<label>Database name<input name="db_name" value="u934663824_fomaxoin" required></label><label>Database user<input name="db_user" value="u934663824_fomaxoin" required></label>'
    . '<label>Database password<input type="password" name="db_pass" required autocomplete="new-password"></label><button class="btn">Connect</button></div></form>';
  $body .= '</div>';
}
