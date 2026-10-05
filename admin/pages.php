<?php
declare(strict_types=1);
/* FOMAXO India admin: the pages. Included by admin/index.php, which has signed the owner in and set $tab, $CAT, $LIVE, $body … */
if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) { http_response_code(404); exit; }

$sel = fn($name, $opts, $cur) => "<select name=\"$name\">" . implode('', array_map(fn($k, $v) => '<option value="' . h($k) . '"' . ((string)$k === (string)$cur ? ' selected' : '') . '>' . h($v) . '</option>', array_keys($opts), $opts)) . '</select>';
$pct = fn($v) => $v === null ? '—' : round($v) . '%';

/* ============ Dashboard ============ */
if ($tab === 'home') {
  $todo = []; foreach (shop_db()->query("SELECT method, COUNT(*) n, SUM(total) t FROM orders WHERE status IN ('new', 'paid') AND test = 0 GROUP BY method") as $r) $todo[$r['method']] = [(int)$r['n'], (int)$r['t']];
  [$tn, $tt] = sales_between(date('Y-m-d') . ' 00:00:00', date('Y-m-d') . ' 23:59:59');
  $rep = report_year((int)date('Y')); $m = $rep[date('Y-m')];
  $body .= '<div class="dash">'
    . '<div class="kpis n5" style="--n:5">'
    . '<a class="kpi k-new" href="' . h(self_url(['tab' => 'orders', 'status' => 'todo', 'method' => 'cod'])) . '"><span>COD orders to deliver</span><b>' . ($todo['cod'][0] ?? 0) . '</b><small>' . rupees($todo['cod'][1] ?? 0) . ' to collect</small></a>'
    . '<a class="kpi k-paid" href="' . h(self_url(['tab' => 'orders', 'status' => 'todo', 'method' => 'online'])) . '"><span>Online orders to deliver</span><b>' . ($todo['online'][0] ?? 0) . '</b><small>Paid online</small></a>'
    . '<div class="kpi"><span>Sales today</span><b>' . rupees($tt) . '</b><small>' . $tn . ' order' . ($tn === 1 ? '' : 's') . '</small></div>'
    . '<a class="kpi" href="' . h(self_url(['tab' => 'reports'])) . '"><span>Sales this month</span><b>' . rupees($m['sales']) . '</b><small>' . $m['orders'] . ' order' . ($m['orders'] === 1 ? '' : 's') . '</small></a>'
    . '<a class="kpi ' . ($m['net'] < 0 ? 'bad' : 'good') . '" href="' . h(self_url(['tab' => 'reports'])) . '"><span>' . ($m['net'] < 0 ? 'Loss' : 'Profit') . ' this month</span><b>' . money($m['net']) . '</b><small>' . ($m['nocost'] ? $m['nocost'] . ' items with no cost set' : 'after costs and expenses') . '</small></a></div>';
  $body .= $sw('#dashCharts', ['sales' => 'Sales', 'visitors' => 'Visitors']) . '<div id="dashCharts" class="panes" style="display:contents">';
  foreach (['sales' => 'Sales', 'visitors' => 'Visitors'] as $k => $label)
    $body .= '<div class="box c-' . ($k === 'sales' ? 'sales on' : 'vis') . '" data-pane="' . $k . '" data-chart="' . $k . '" data-range="d7"><div class="bh"><span class="ctot"></span><span class="seg">'
      . implode('', array_map(fn($r, $l) => '<button type="button" data-r="' . $r . '"' . ($r === 'd7' ? ' class="on"' : '') . ">$l</button>", ['today', 'd7', 'd30', 'year'], ['Today', '7 days', '30 days', 'Year'])) . '</span></div>'
      . '<div class="chart" role="img" aria-label="' . $label . ' chart"><svg></svg><div class="tip" hidden></div></div></div>';
  $body .= '</div>';
  /* stock alerts and latest orders */
  $STOCK = shop_stock(); $low = shop_low_stock(); $alerts = [];
  foreach ($CAT as $id => $p) { if (!empty($p['hidden'])) continue; foreach ($p['prices'] as $opt => $_) { $v = $STOCK[$id][$opt] ?? null; if ($v !== null && $v <= max($low, 0)) $alerts[] = [$id, $p, (string)$opt, $v]; } }
  usort($alerts, fn($a, $b) => $a[3] <=> $b[3]);
  $latest = shop_db()->query("SELECT * FROM orders WHERE status <> 'awaiting' ORDER BY id DESC LIMIT 30")->fetchAll();
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
  $body .= '<script type="application/json" id="chartData">' . json_encode(series(), JSON_HEX_TAG | JSON_UNESCAPED_UNICODE) . '</script>';
}

/* ============ Orders ============ */
if ($tab === 'orders') {
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
  if ($SUM['states']) {
    $body .= '<div class="chips" aria-label="Orders by state">';
    foreach ($SUM['states'] as $st => $n) $body .= '<a class="chip' . ($F['state'] === $st ? ' on' : '') . '" href="' . h(self_url(array_filter(['state' => $F['state'] === $st ? '' : $st] + $q))) . '">' . h($st) . ' <b>' . $n . '</b></a>';
    $body .= '</div>';
  }
  $nf = count(array_filter([$F['status'], $F['method'], $F['from'], $F['to']], 'strlen'));
  $body .= '<form class="filters' . ($nf ? ' open' : '') . '" id="ordFilters" method="get"><input type="hidden" name="tab" value="orders">' . ($F['state'] !== '' ? '<input type="hidden" name="state" value="' . h($F['state']) . '">' : '')
    . '<label class="fx">Status' . $sel('status', ['' => 'All orders'] + TRACK_CHIPS + ['awaiting' => 'Unfinished online payments'] + (in_array($F['status'], ['todo', 'new', 'paid'], true) ? [$F['status'] => ['todo' => 'Pending', 'new' => 'New', 'paid' => 'Paid'][$F['status']]] : []), $F['status']) . '</label>'
    . '<label class="fx">Payment' . $sel('method', ['' => 'COD and online', 'cod' => 'Cash on delivery (COD)', 'online' => 'Online (card / UPI)'], $F['method']) . '</label>'
    . '<label class="hide-m">From<input type="date" name="from" value="' . h($F['from']) . '"></label><label class="hide-m">To<input type="date" name="to" value="' . h($F['to']) . '"></label>'
    . '<label class="grow"><span class="hide-m">Search</span><input type="search" name="q" value="' . h($F['q']) . '" placeholder="Order no, name, mobile, email or note" aria-label="Search orders"></label>'
    . '<button type="button" class="btn line show-m-i" data-open="#ordFilters">Filters' . ($nf ? ' (' . $nf . ')' : '') . '</button><button class="btn line">Show</button><a class="btn" href="' . h(self_url($q + ['do' => 'excel'])) . '">Excel</a></form>';
  $back = h(json_encode($q + ['page' => $page]));
  $body .= '<form id="qa" method="post" hidden>' . $csrfField . '<input type="hidden" name="action" value="quick"><input type="hidden" name="back" value="' . $back . '"></form>';
  $body .= '<div class="box fill"><div class="bh"><span class="muted small">' . $total . ' order' . ($total === 1 ? '' : 's') . ($F['status'] === 'awaiting' ? '. These shoppers opened online payment but did not finish. They have no order number and took no stock.' : '. Tap a button to update an order, or tap the order to see it.') . '</span></div><div class="bb np">';
  if (!$orders) $body .= '<p class="empty">No orders' . (array_filter($F) ? ' for this filter' : ' yet') . '.</p>';
  foreach ($orders as $o) {
    $items = json_decode((string)$o['items'], true) ?: [];
    $btns = order_buttons($o);
    $body .= '<details class="order os-' . h($o['status']) . '"' . (count($orders) === 1 ? ' open' : '') . '><summary>' . $orderThumb($o)
      . '<span class="no">' . h($o['no'] ?: 'Not paid') . order_waiting($o) . '</span><span class="dt">' . h(date('d M Y, H:i', strtotime($o['created']))) . '</span>'
      . '<span class="cu"><b>' . h($o['name']) . '</b><small>' . h($o['phone']) . '</small></span>'
      . '<span class="tt">' . rupees((int)$o['total']) . '<small>' . ($o['method'] === 'cod' ? 'Cash on delivery' : 'Online') . ($o['test'] ? ' · TEST' : '') . '</small></span>'
      . '<span class="tags">' . order_tags($o) . '</span><span class="acts">' . $btns . '</span></summary>'
      . '<div class="otop">' . order_tracker($o) . '</div>'
      . '<div class="od"><div class="items"><h4>Items</h4>';
    foreach ($items as $it) $body .= '<div class="li">' . $thumbOf((string)($it['id'] ?? ''), 'th sm') . '<span class="grow"><b>' . h($it['name'] ?? $it['id'] ?? '') . '</b><small>' . (int)($it['qty'] ?? 0) . ' × ' . rupees((int)($it['unit'] ?? 0)) . ($it['desc'] ?? '' ? ' · ' . h($it['desc']) : '') . '</small></span></div>';
    $body .= ($o['cod_fee'] ? '<p class="small muted">Cash on delivery fee ' . rupees((int)$o['cod_fee']) . '</p>' : '') . '<p><b>Total ' . rupees((int)$o['total']) . '</b></p></div>'
      . '<div><h4>Delivery</h4><p>' . h($o['name']) . '<br>' . h($o['address']) . '</p><p><a href="tel:' . h(preg_replace('/[^0-9+]/', '', $o['phone'])) . '">' . h($o['phone']) . '</a> · <a href="https://wa.me/' . h(preg_replace('/\D/', '', $o['phone'])) . '" target="_blank" rel="noopener">WhatsApp</a><br><a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a></p>'
      . ($o['note'] ? '<p class="muted">Customer note: ' . h($o['note']) . '</p>' : '') . '</div>'
      . '<div><h4>Payment</h4><p>' . h(pay_label($o)) . ($o['payment_id'] ? '<br><small class="muted">' . h($o['payment_id']) . '</small>' : '') . ($o['paid_at'] ? '<br><small class="muted">Paid ' . h(date('d M Y, H:i', strtotime($o['paid_at']))) . '</small>' : '') . '</p>'
      . '<form method="post" class="stform">' . $csrfField . '<input type="hidden" name="action" value="status"><input type="hidden" name="id" value="' . (int)$o['id'] . '"><input type="hidden" name="back" value="' . $back . '">'
      . '<label>Status <small class="muted">(saves when you pick)</small>' . str_replace('<select name="status">', '<select name="status" data-autosave data-no="' . h($o['no']) . '">', $sel('status', order_status_options($o), $o['status'])) . '</label>'
      . '<label>Your note<input name="admin_note" value="' . h($o['admin_note']) . '" maxlength="500" placeholder="Courier, tracking number…"></label>'
      . '<div class="qbtns"><button class="btn line">Save</button></div></form>'
      . '<p class="muted small">Cancelling or refunding puts the items back in stock.</p></div></div></details>';
  }
  if ($total > ADMIN_PER_PAGE) {
    $body .= '<div class="pager">';
    for ($i = 1; $i <= (int)ceil($total / ADMIN_PER_PAGE); $i++) $body .= '<a' . ($i === $page ? ' class="on"' : '') . ' href="' . h(self_url($q + ['page' => $i])) . '">' . $i . '</a>';
    $body .= '</div>';
  }
  $body .= '</div></div>';
}

/* ============ Members ============ */
$ckey = (string)($_GET['c'] ?? '');
if ($tab === 'members' && $ckey !== '' && ($C = customer($ckey))) {
  /* the customer page */
  $W = $C['web']; $wa = $C['phone'] ? 'https://wa.me/91' . $C['phone'] . '?text=' . rawurlencode('Hi ' . $C['name'] . ', this is FOMAXO. ') : '';
  $dt = fn($s) => $s ? h(date('d M Y', is_int($s) ? $s : strtotime($s))) : '—';
  $body .= '<div class="row ctop"><a class="btn line sm" href="' . h(self_url(['tab' => 'members'])) . '">‹ Members</a><h2>' . h($C['name']) . '</h2><span class="muted">' . h(phone_fmt($C['phone']) ?: $C['email']) . '</span><span class="sp"></span>'
    . ($wa ? '<a class="btn sm" href="' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>' : '') . '</div>';
  $body .= '<div class="kpis n6" style="--n:6">'
    . '<div class="kpi"><span>Orders</span><b>' . $C['count'] . '</b></div><div class="kpi"><span>Total spent</span><b>' . rupees($C['spent']) . '</b></div>'
    . '<div class="kpi"><span>Average order</span><b>' . rupees($C['avg']) . '</b></div><div class="kpi"><span>Reviews</span><b>' . count($C['reviews']) . '</b></div>'
    . '<div class="kpi"><span>Visits</span><b>' . count($W['visits']) . '</b></div><div class="kpi"><span>Time on site</span><b>' . ($W['visits'] ? duration($W['seconds']) : '—') . '</b></div></div>';
  $body .= $sw('#cPanes', ['details' => 'Details', 'web' => 'On the website', 'orders' => 'Orders', 'reviews' => 'Reviews']) . '<div class="cust panes" id="cPanes">';
  /* details */
  $body .= '<div class="box on" data-pane="details"><div class="bh"><h3>Details</h3></div><div class="bb"><dl class="dl">'
    . '<dt>Mobile</dt><dd>' . ($C['phone'] ? '<a href="tel:+91' . h($C['phone']) . '">' . h(phone_fmt($C['phone'])) . '</a> · <a href="' . h($wa) . '" target="_blank" rel="noopener">WhatsApp</a>' : '—') . '</dd>'
    . '<dt>Email</dt><dd>' . ($C['email'] ? '<a href="mailto:' . h($C['email']) . '">' . h($C['email']) . '</a>' : '—') . '</dd>'
    . '<dt>Address</dt><dd>' . h($C['address']) . '</dd><dt>State</dt><dd>' . h($C['state'] ?: '—') . '</dd>'
    . '<dt>First order</dt><dd>' . $dt($C['first']) . '</dd><dt>Last order</dt><dd>' . $dt($C['last']) . '</dd>'
    . '<dt>Cancelled</dt><dd>' . $C['cancelled'] . '</dd><dt>Unpaid</dt><dd>' . $C['unpaid'] . '</dd></dl></div></div>';
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
    $body .= '<a class="corder os-' . h($o['status']) . '" href="' . h(self_url(array_filter(['tab' => 'orders', 'q' => $o['no'] ?: $o['name'], 'status' => $o['status'] === 'awaiting' ? 'awaiting' : '']))) . '"><div class="ch"><b>' . h($o['no'] ?: 'Not paid') . '</b><span class="muted small">' . h(date('d M Y', strtotime($o['created']))) . ' · ' . h(pay_label($o)) . '</span><span class="sp"></span><b>' . rupees((int)$o['total']) . '</b><span class="badge st-' . h($o['status']) . '">' . h(FOMAXO_STATUSES[$o['status']] ?? $o['status']) . '</span></div>';
    foreach ($items as $it) $body .= '<div class="ci">' . $thumbOf((string)($it['id'] ?? ''), 'th xs') . '<span>' . (int)($it['qty'] ?? 0) . ' × ' . h($it['name'] ?? '') . '</span><span class="muted">' . rupees((int)($it['unit'] ?? 0)) . '</span></div>';
    $body .= '</a>';
  }
  $body .= '</div></div>';
  /* reviews */
  $body .= '<div class="box" data-pane="reviews"><div class="bh"><h3>Reviews</h3></div><div class="bb">';
  if (!$C['reviews']) $body .= '<p class="empty">No reviews from this customer yet.</p>';
  foreach ($C['reviews'] as $r) $body .= '<div class="rv"><div class="rvh">' . $thumbOf($r['product'], 'th xs') . '<b>' . h($CAT[$r['product']]['name'] ?? $r['product']) . '</b>' . stars((float)$r['rating']) . '<span class="sp"></span><span class="muted small">' . h(date('d M Y', (int)$r['created'])) . '</span>' . ($r['status'] !== 'live' ? '<span class="badge st-cancelled">Removed</span>' : '') . '</div><p>' . nl2br(h($r['body'])) . '</p></div>';
  $body .= '</div></div></div>';
} elseif ($tab === 'members') {
  $min = member_min(); $spend = member_spend(); $mq = trim((string)($_GET['q'] ?? '')); $list = members($min, $spend, $mq);
  $rule = $min . ' or more orders' . ($spend ? ' or ₹' . number_format($spend) . ' or more spent' : '');
  $body .= '<div class="row mtool">'
    . '<form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="member_min"><label class="mrule">Orders: at least<input type="number" name="member_min" min="1" max="999" value="' . $min . '"></label><button class="btn line sm">Save</button></form>'
    . '<form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="member_spend"><label class="mrule">Spent: at least ₹<input type="number" name="member_spend" min="0" max="10000000" value="' . ($spend ?: '') . '" placeholder="off"></label><button class="btn line sm">Save</button></form>'
    . '<form method="get" class="msearch"><input type="hidden" name="tab" value="members"><input type="search" name="q" value="' . h($mq) . '" placeholder="Name, mobile or email"><button class="btn line sm">Search</button></form>'
    . '<a class="btn sm" href="' . h(self_url(array_filter(['do' => 'members_excel', 'q' => $mq]))) . '">Excel</a></div>';
  $body .= '<div class="box fill"><div class="bh"><span class="muted small">' . count($list) . ' member' . (count($list) === 1 ? '' : 's') . ' with ' . $rule . ', most spent first. Cancelled and test orders are left out. An empty amount box turns that filter off. Tap a member to open their page.</span></div><div class="bb np">';
  if (!$list) $body .= '<p class="empty">' . ($mq !== '' ? 'No member matches “' . h($mq) . '”.' : 'No customer has ' . $rule . ' yet. Lower the numbers above to see more.') . '</p>';
  else {
    $body .= '<table class="grid mlist"><thead><tr><th>Name</th><th>Mobile</th><th class="hide-m">Email</th><th class="hide-m">Address</th><th class="r">Orders</th><th class="r">Spent</th></tr></thead><tbody>';
    foreach ($list as $m) {
      $url = h(self_url(['tab' => 'members', 'c' => $m['key']]));
      $body .= '<tr data-href="' . $url . '"><td><a href="' . $url . '"><b>' . h($m['name']) . '</b></a><small class="show-m">' . h($m['state']) . '</small></td>'
        . '<td>' . ($m['phone'] ? h(phone_fmt($m['phone'])) . '<small><a href="https://wa.me/91' . h($m['phone']) . '?text=' . rawurlencode('Hi ' . $m['name'] . ', thank you for being a FOMAXO regular!') . '" target="_blank" rel="noopener">WhatsApp</a></small>' : '—') . '</td>'
        . '<td class="hide-m">' . h($m['email']) . '</td><td class="hide-m"><span class="clip">' . h($m['address']) . '</span></td><td class="r">' . $m['count'] . '</td><td class="r"><b>' . rupees($m['spent']) . '</b></td></tr>';
    }
    $body .= '</tbody></table><script>document.querySelectorAll("tr[data-href]").forEach(function(r){r.onclick=function(e){if(!e.target.closest("a"))location.href=r.dataset.href}})</script>';
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
    . '<label class="chk" style="font-size:14px">Show “Only X left” from <input type="number" name="low_stock" min="0" max="99" value="' . $low . '" style="width:70px"> left or fewer</label><button class="btn line sm">Save</button><span class="muted small">0 turns it off.</span></form>'
    . '<input type="search" class="grow" id="stockFind" placeholder="Find a product" aria-label="Find a product" style="max-width:320px;margin-left:auto"></div>'
    . '<form method="post" class="box fill" data-watch>' . $csrfField . '<input type="hidden" name="action" value="stock"><input type="hidden" name="low_stock" value="' . $low . '">'
    . '<div class="bb np"><table class="grid stock"><thead><tr><th>Product</th><th>Size</th><th>Stock</th><th>Cost ₹</th><th class="hide-m">On the shop</th></tr></thead><tbody>';
  foreach ($CAT as $id => $p) {
    foreach ($p['prices'] as $opt => $_) {
      $v = $STOCK[$id][$opt] ?? null; $c = $COST[$id][$opt] ?? null;
      $state = !empty($p['hidden']) ? '<span class="badge st-awaiting">Hidden</span>' : ($v === null ? ($p['soldOut'] ? '<span class="badge st-cancelled">Sold out</span>' : '<span class="muted small">Not counted</span>')
        : ($v < 1 ? '<span class="badge st-cancelled">Sold out</span>' : ($low && $v <= $low ? '<span class="badge st-new">Only ' . $v . ' left</span>' : '<span class="badge st-paid">In stock</span>')));
      $body .= '<tr data-s="' . ($v === null ? '' : ($v < 1 ? 'out' : ($low && $v <= $low ? 'low' : ''))) . '" data-name="' . h(mb_strtolower($p['name'] . ' ' . kind_label($p['kind']))) . '"><td><div class="pc">' . $thumbOf($id, 'th sm') . '<div><b>' . h($p['name']) . '</b><small>' . h(kind_label($p['kind'])) . '</small></div></div></td><td>' . h(opt_label($p, (string)$opt)) . '</td>'
        . '<td><input type="number" min="0" max="99999" name="stock[' . h($id) . '][' . h((string)$opt) . ']" value="' . ($v === null ? '' : $v) . '" placeholder="—"></td>'
        . '<td><input type="number" min="0" step="0.01" name="cost[' . h($id) . '][' . h((string)$opt) . ']" value="' . ($c === null ? '' : h((string)round($c / 100, 2))) . '" placeholder="—"></td><td class="hide-m">' . $state . '</td></tr>';
    }
  }
  $body .= '</tbody></table><p class="empty" id="stockNone" hidden>No product matches.</p></div><div class="bf"><button class="btn">Save</button><span class="unsaved" hidden>Not saved yet</span><span class="muted small"><b>Stock:</b> how many bottles you have. <b>Cost:</b> what one bottle costs you; Reports use it to work out your profit.</span></div></form>'
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
  $body .= '<h3>Sizes and prices</h3><div class="wide"><p class="muted small" style="margin:0 0 8px">One row per size' . ($adding ? ' (perfumes; other products use the first row only)' : ($kind === '' ? '' : ' (this product has one)')) . '. “Was” is the crossed-out price. “My cost” is what one costs you, for profit. Empty a row to stop selling that size.</p>';
  foreach (range(0, ($adding || $kind === '') ? 3 : 0) as $i) {
    [$sz, $pr, $wa] = $rows[$i] ?? ['', '', '']; $opt = $care || $kind === 'car' ? 'one' : (string)$sz;
    $cost = $opt !== '' && isset($COSTS[$editId][$opt]) ? rtrim(rtrim(number_format($COSTS[$editId][$opt] / 100, 2, '.', ''), '0'), '.') : '';
    $body .= '<div class="srow' . ($adding && $i ? ' k-frag' : '') . '">' . ($adding || $kind === '' ? '<label' . $cls('k-frag') . '>Size (ml)<input type="number" name="size[]" min="1" max="1000" value="' . h((string)$sz) . '"></label>' : '')
      . '<label>Price ₹<input type="number" step="0.01" min="1" name="price[]" value="' . h((string)$pr) . '"' . ($i ? '' : ' required') . '></label>'
      . '<label>Was ₹<input type="number" step="0.01" min="0" name="was[]" placeholder="optional" value="' . h((string)($wa ?: '')) . '"></label>'
      . '<label>My cost ₹<input type="number" step="0.01" min="0" name="cost[]" placeholder="optional" value="' . h($cost) . '"></label>'
      . ($adding ? '<label>Stock<input type="number" min="0" name="stock[]" placeholder="not tracked"></label>' : '') . '</div>';
  }
  $body .= '</div><h3>Photos</h3><div class="wide"><p class="muted small" style="margin:0 0 8px">' . ($imgs ? 'Untick a photo to remove it. The main photo shows first in the shop. ' : '') . 'Add photos straight from your phone’s camera roll.</p><div class="photos">';
  foreach ($imgs as $k) $body .= '<div class="ph"><img src="' . h(img_url($k)) . '" alt="" loading="lazy"><label class="chk"><input type="checkbox" name="keep[]" value="' . h($k) . '" checked> Keep</label><label class="chk"><input type="radio" name="main" value="' . h($k) . '"' . ($k === $imgs[0] ? ' checked' : '') . '> Main photo</label></div>';
  $body .= '</div><label style="margin-top:10px">Add photos <small>(up to 4 at a time)</small><input type="file" name="photos[]" accept="image/*" multiple' . ($adding ? ' required' : '') . '></label>'
    . ($imgs ? '<label class="chk" style="margin-top:8px"><input type="radio" name="main" value="new"> Make the first new photo the main photo</label>' : '') . '</div></div></div>'
    . '<div class="bf"><button class="btn">' . ($adding ? 'Add product' : 'Save changes') . '</button><a class="btn line" href="' . h(self_url(['tab' => 'products'])) . '">Cancel</a></div></form>';
  if ($adding) $body .= '<script>(function(){var k=document.getElementById("kind");function u(){var v=k.value;document.querySelectorAll(".k-frag").forEach(function(e){e.hidden=v!==""});document.querySelectorAll(".k-care").forEach(function(e){e.hidden=v!=="care"});document.querySelectorAll(".k-one").forEach(function(e){e.hidden=v===""});}k.onchange=u;u();})();</script>';
} elseif ($tab === 'products') {
  $qq = trim((string)($_GET['q'] ?? ''));
  $body .= '<div class="row"><form class="row sp" method="get"><input type="hidden" name="tab" value="products"><input type="search" name="q" value="' . h($qq) . '" placeholder="Find a product" style="max-width:280px"></form><a class="btn" href="' . h(self_url(['tab' => 'products', 'add' => 1])) . '">+ Add a product</a></div>'
    . '<div class="box fill"><div class="bh"><span class="muted small">Press Edit to change a product’s name, descriptions, notes, sizes, prices, your cost or photos. Untick “On website” to hide it from the shop.</span></div><div class="bb">';
  foreach ($CAT as $id => $p) {
    if ($qq !== '' && stripos($p['name'] . ' ' . kind_label($p['kind']), $qq) === false) continue;
    $body .= '<div class="prod' . (!empty($p['hidden']) ? ' off' : '') . '">' . $thumbOf($id) . '<div class="pinfo"><b>' . h($p['name']) . '</b><small>' . h(kind_label($p['kind'])) . (!empty($p['added']) ? ' · added here' : '') . '</small>'
      . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="show"><input type="hidden" name="id" value="' . h($id) . '"><label class="chk"><input type="checkbox" name="show" value="1"' . (empty($p['hidden']) ? ' checked' : '') . ' onchange="this.form.submit()"> On website</label></form></div>'
      . '<div class="pprices">' . implode('', array_map(fn($opt, $pr) => '<div><span>' . h(opt_label($p, (string)$opt)) . '</span><b>' . rupees((int)round($pr * 100)) . '</b>' . (!empty($p['was'][$opt]) && $p['was'][$opt] > $pr ? '<s class="muted">' . rupees((int)round($p['was'][$opt] * 100)) . '</s>' : '<span></span>') . '</div>', array_keys($p['prices']), $p['prices'])) . '</div>'
      . '<div class="pact"><a class="btn sm line" href="' . h(self_url(['tab' => 'products', 'edit' => $id])) . '">Edit</a>';
    if (!empty($p['added'])) $body .= '<form method="post" onsubmit="return confirm(\'Delete ' . h(addslashes($p['name'])) . ' for good?\')">' . $csrfField . '<input type="hidden" name="action" value="delete"><input type="hidden" name="id" value="' . h($id) . '"><button class="btn sm danger">Delete</button></form>';
    $body .= '</div></div>';
  }
  $body .= '</div></div>';
}

/* ============ Expenses ============ */
if ($tab === 'expenses') {
  $month = preg_match('/^\d{4}-\d{2}$/', (string)($_GET['month'] ?? '')) ? $_GET['month'] : date('Y-m');
  $prev = date('Y-m', strtotime("$month-01 -1 month")); $next = date('Y-m', strtotime("$month-01 +1 month"));
  $s = shop_db()->prepare('SELECT * FROM expenses WHERE day >= ? AND day < ? ORDER BY day DESC, id DESC'); $s->execute(["$month-01", "$next-01"]);
  $list = $s->fetchAll(); $total = array_sum(array_column($list, 'amount'));
  $byCat = []; foreach ($list as $x) $byCat[$x['category']] = ($byCat[$x['category']] ?? 0) + (int)$x['amount']; arsort($byCat);
  $today = date('Y-m-d'); $defDay = substr($today, 0, 7) === $month ? $today : "$month-01";
  $body .= $sw('#expPanes', ['list' => 'This month', 'add' => 'Add an expense']) . '<div class="exp panes" id="expPanes">'
    . '<form method="post" class="box" data-pane="add">' . $csrfField . '<input type="hidden" name="action" value="expense"><div class="bh"><h3>Add an expense</h3></div><div class="bb" style="padding-top:12px">'
    . '<label>Date<input type="date" name="day" value="' . h($defDay) . '" required></label>'
    . '<label>Category' . $sel('category', array_combine(EXPENSE_CATEGORIES, EXPENSE_CATEGORIES), '') . '</label>'
    . '<label>Details<input name="note" maxlength="200" placeholder="optional, e.g. Instagram ads"></label>'
    . '<label>Amount ₹<input type="number" name="amount" min="0.01" step="0.01" required inputmode="decimal"></label><button class="btn" style="width:100%">Add expense</button></div></form>'
    . '<div class="box on" data-pane="list"><div class="bh"><div class="row"><a class="btn line sm" href="' . h(self_url(['tab' => 'expenses', 'month' => $prev])) . '">‹</a><h2>' . h(date('F Y', strtotime("$month-01"))) . '</h2><a class="btn line sm" href="' . h(self_url(['tab' => 'expenses', 'month' => $next])) . '">›</a></div>'
    . '<a class="btn line sm" href="' . h(self_url(['do' => 'expenses_excel', 'year' => substr($month, 0, 4)])) . '">' . h(substr($month, 0, 4)) . ' Excel</a></div>'
    . '<div class="bh" style="flex-wrap:wrap"><span><b style="font-size:18px">' . rupees($total) . '</b> <span class="muted small">spent · ' . count($list) . ' expense' . (count($list) === 1 ? '' : 's') . '</span></span><span class="cats">'
    . implode('', array_map(fn($c, $v) => '<span>' . h($c) . ' <b>' . rupees($v) . '</b></span>', array_keys($byCat), $byCat)) . '</span></div><div class="bb np">';
  if (!$list) $body .= '<p class="empty">No expenses this month.</p>';
  else {
    $body .= '<table class="grid"><thead><tr><th>Date</th><th>Category</th><th class="hide-m">Details</th><th class="r">Amount</th><th></th></tr></thead><tbody>';
    foreach ($list as $x) $body .= '<tr><td>' . h(date('d M', strtotime($x['day']))) . '</td><td>' . h($x['category']) . '<small class="show-m">' . h($x['note']) . '</small></td><td class="hide-m">' . h($x['note']) . '</td><td class="r">' . rupees((int)$x['amount']) . '</td>'
      . '<td class="r"><form method="post" onsubmit="return confirm(\'Delete this expense?\')">' . $csrfField . '<input type="hidden" name="action" value="expense_delete"><input type="hidden" name="id" value="' . (int)$x['id'] . '"><input type="hidden" name="month" value="' . h($month) . '"><button class="linkbtn">Delete</button></form></td></tr>';
    $body .= '</tbody></table>';
  }
  $body .= '</div></div></div>';
}

/* ============ Analytics ============ */
if ($tab === 'analytics') {
  $r = (string)($_GET['r'] ?? '7'); $today = date('Y-m-d');
  [$from, $to] = match ($r) { 'today' => [$today, $today], '30' => [date('Y-m-d', strtotime('-29 day')), $today], 'custom' => [
    preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['from'] ?? '')) ? $_GET['from'] : date('Y-m-d', strtotime('-6 day')),
    preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($_GET['to'] ?? '')) ? $_GET['to'] : $today], default => [date('Y-m-d', strtotime('-6 day')), $today] };
  if ($from > $to) [$from, $to] = [$to, $from];
  $A = analytics($from, $to, $CAT); $f = $A['funnel'];
  $body .= '<form class="range' . ($r === 'custom' ? ' open' : '') . '" id="anRange" method="get"><input type="hidden" name="tab" value="analytics"><span class="seg">'
    . implode('', array_map(fn($k, $l) => '<a href="' . h(self_url(['tab' => 'analytics', 'r' => $k])) . '"' . ($r === $k ? ' class="on"' : '') . ">$l</a>", ['today', '7', '30'], ['Today', '7 days', '30 days'])) . '<button type="button" class="show-m-i' . ($r === 'custom' ? ' on' : '') . '" data-open="#anRange">Dates</button></span>'
    . '<input type="hidden" name="r" value="custom"><input class="fx" type="date" name="from" value="' . h($from) . '" aria-label="From"><input class="fx" type="date" name="to" value="' . h($to) . '" aria-label="To"><button class="btn line sm fx">Show</button>'
    . '<span class="muted small hide-m">' . h(date('d M Y', strtotime($from))) . ($from !== $to ? ' – ' . h(date('d M Y', strtotime($to))) : '') . '. Your own visits and bots are not counted.</span></form>';
  $body .= '<div class="kpis n7 strip" style="--n:7">'
    . '<div class="kpi"><span>Visitors</span><b>' . number_format($A['visitors']) . '</b><small>' . number_format($A['visits']) . ' visits</small></div>'
    . '<div class="kpi good"><span>On the site now</span><b>' . $A['now'] . '</b><small>last 5 minutes</small></div>'
    . '<div class="kpi"><span>Conversion rate</span><b>' . ($A['conversion'] === null ? '—' : round($A['conversion'], 1) . '%') . '</b><small>visits that bought</small></div>'
    . '<div class="kpi"><span>Cart abandonment</span><b>' . $pct($A['cart_ab']) . '</b><small>added, did not buy</small></div>'
    . '<div class="kpi"><span>Checkout abandonment</span><b>' . $pct($A['checkout_ab']) . '</b><small>at checkout, did not buy</small></div>'
    . '<div class="kpi"><span>Purchases</span><b>' . $A['purchases'] . '</b><small>orders</small></div>'
    . '<div class="kpi"><span>Revenue</span><b>' . rupees($A['revenue']) . '</b><small>' . ($A['purchases'] ? rupees(intdiv($A['revenue'], $A['purchases'])) . ' per order' : '&nbsp;') . '</small></div></div>';
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
  /* top countries and Indian states, each with its own Today / 7 days / 30 days / Year */
  $G = geo_stats(); $RS = ['today' => 'Today', 'd7' => '7 days', 'd30' => '30 days', 'year' => 'Year'];
  foreach (['countries' => 'Top countries', 'states' => 'Visitors by Indian state'] as $gk => $gt) {
    $body .= '<div class="box geo" data-pane="' . $gk . '"><div class="bh"><h3>' . $gt . '</h3><span class="seg rs">' . implode('', array_map(fn($k, $l) => '<button type="button" data-r="' . $k . '"' . ($k === 'd7' ? ' class="on"' : '') . ">$l</button>", array_keys($RS), $RS)) . '</span></div><div class="bb">';
    foreach ($RS as $rk => $_) {
      $rows = $G[$gk][$rk]; $mx = max(1, ...array_values($rows ?: [1]));
      $body .= '<div class="rl" data-r="' . $rk . '"' . ($rk === 'd7' ? '' : ' hidden') . '>';
      if (!$rows) $body .= '<p class="empty">No visits ' . ($rk === 'today' ? 'today' : 'in this time') . '.</p>';
      foreach ($rows as $name => $n) $body .= '<div class="li"><span class="grow">' . ($gk === 'countries' ? '<span class="flag">' . fomaxo_flag((string)$name) . '</span> ' . h(fomaxo_country_name((string)$name)) : h((string)$name)) . '</span><span class="bar"><i style="width:' . round($n / $mx * 100) . '%"></i></span><b class="num">' . number_format($n) . '</b></div>';
      $body .= '</div>';
    }
    $body .= '<p class="muted small">' . ($gk === 'states' ? 'Approximate: phone networks often show the state of their nearest hub. ' : '') . 'Looked up from the visitor’s IP address on this server; only the country' . ($gk === 'states' ? ' and state are' : ' is') . ' kept. IP geolocation by <a href="https://db-ip.com" target="_blank" rel="noopener">DB-IP</a>.</p></div></div>';
  }
  /* products */
  $body .= '<div class="box p-prod" data-pane="products"><div class="bh"><h3>Products</h3></div><div class="bb np"><table class="grid"><thead><tr><th>Product</th><th class="r">Views</th><th class="r">Added</th><th class="r">Sold</th><th class="r">Revenue</th></tr></thead><tbody>';
  if (!$A['products']) $body .= '<tr><td colspan="5" class="empty">No product views yet in these dates.</td></tr>';
  foreach ($A['products'] as $id => $p) $body .= '<tr><td><div class="pc">' . $thumbOf($id, 'th sm') . '<b>' . h($CAT[$id]['name']) . '</b></div></td><td class="r">' . $p['views'] . '</td><td class="r">' . $p['adds'] . '</td><td class="r">' . $p['units'] . '</td><td class="r">' . rupees($p['rev']) . '</td></tr>';
  $body .= '</tbody></table></div></div>';
  /* left at checkout: everyone, kept for good */
  $L = checkout_leads();
  $body .= '<div class="box p-left" data-pane="left"><div class="bh"><h3>Left at checkout</h3><span class="muted small hide-m">Everyone who typed their details at checkout (all dates)</span><span class="sp"></span><a class="btn sm" href="' . h(self_url(['do' => 'leads_excel'])) . '">Excel</a></div><div class="bb np"><table class="grid ltab"><thead><tr><th>Date</th><th>Name</th><th>State · address</th><th>Products</th><th class="r">Bag</th><th>Left at</th><th>Ordered later</th><th></th></tr></thead><tbody>';
  if (!$L) $body .= '<tr><td colspan="8" class="empty">Nobody has typed their details at checkout yet.</td></tr>';
  foreach ($L as $l) {
    $names = lead_items($l);
    $msg = 'Hi ' . ($l['name'] ?: 'there') . ', this is FOMAXO. We saw you were about to order ' . ($names ?: 'from our shop') . '. Can we help you finish your order?';
    $body .= '<tr' . ($l['later'] !== '' ? ' class="dim"' : '') . '><td class="nw lt-date">' . h(date('d M Y, H:i', strtotime($l['updated']))) . '</td><td class="lt-name"><b>' . h($l['name'] ?: '—') . '</b><small>' . h($l['phone'] ? phone_fmt($l['phone']) : 'no mobile') . '</small>' . ($l['email'] ? '<small>' . h($l['email']) . '</small>' : '') . '</td>'
      . '<td class="lt-addr"><b>' . h($l['state'] ?: '—') . '</b><small class="clip">' . h($l['address']) . '</small></td>'
      . '<td class="lt-items"><small class="clip2">' . h($names) . '</small></td><td class="r nw lt-bag">' . rupees((int)$l['total']) . '</td>'
      . '<td class="lt-step">' . ($l['step'] === 'payment' ? '<span class="badge st-cancelled">At payment</span>' : '<span class="badge st-new">At details</span>') . '</td>'
      . '<td class="lt-later">' . ($l['later'] === '' ? '<span class="muted">Not ordered</span>' : ($l['later'] === 'yes' ? '<span class="badge st-paid">Yes</span>' : '<a href="' . h(self_url(['tab' => 'orders', 'q' => $l['later']])) . '"><span class="badge st-paid">' . h($l['later']) . '</span></a>')) . '</td>'
      . '<td class="r lt-wa">' . ($l['phone'] ? '<a class="btn sm" href="https://wa.me/91' . h($l['phone']) . '?text=' . rawurlencode($msg) . '" target="_blank" rel="noopener">WhatsApp</a>' : '') . '</td></tr>';
  }
  $body .= '</tbody></table></div></div></div>';
}

/* ============ Reports ============ */
if ($tab === 'reports') {
  $rows = report_year($pyear); $t = report_sum($rows); $cur = date('Y-m');
  $m = fn($p) => '<span class="' . ($p < 0 ? 'neg' : '') . '">' . money($p) . '</span>';
  /* phones: this month and this year at a glance, above the Year picker */
  $cy = report_year((int)date('Y')); $cm = report_sum([$cy[$cur]]); $ct = report_sum($cy);
  $body .= '<div class="kpis mq">'
    . '<div class="kpi"><span>Sales this month</span><b>' . rupees($cm['sales']) . '</b><small>' . $cm['orders'] . ($cm['orders'] === 1 ? ' order' : ' orders') . '</small></div>'
    . '<div class="kpi ' . ($cm['net'] < 0 ? 'bad' : 'good') . '"><span>Profit this month</span><b>' . money($cm['net']) . '</b><small>' . h(date('F')) . '</small></div>'
    . '<div class="kpi"><span>Sales this year</span><b>' . rupees($ct['sales']) . '</b><small>' . $ct['orders'] . ($ct['orders'] === 1 ? ' order' : ' orders') . '</small></div>'
    . '<div class="kpi ' . ($ct['net'] < 0 ? 'bad' : 'good') . '"><span>Profit this year</span><b>' . money($ct['net']) . '</b><small>' . date('Y') . '</small></div></div>';
  $body .= '<div class="row"><h2>Profit &amp; loss</h2><form method="get"><input type="hidden" name="tab" value="reports">' . $sel('year', array_combine(report_years(), report_years()), $pyear) . '</form><span class="sp"></span>'
    . '<a class="btn line" href="' . h(self_url(['do' => 'report_excel', 'year' => $pyear])) . '">Download ' . $pyear . ' (Excel)</a></div>'
    . '<script>document.querySelector("select[name=year]").onchange=function(){this.form.submit()}</script>';
  $body .= '<div class="kpis n5 hide-m" style="--n:5">'
    . '<div class="kpi"><span>Sales ' . $pyear . '</span><b>' . rupees($t['sales']) . '</b><small>' . $t['orders'] . ' orders · ' . rupees($t['discounts']) . ' discounts</small></div>'
    . '<div class="kpi"><span>Cost of goods</span><b>' . rupees($t['cost']) . '</b><small>' . ($t['nocost'] ? '<span class="warn">' . $t['nocost'] . ' items with no cost set</span>' : 'from My cost on each product') . '</small></div>'
    . '<div class="kpi"><span>Payment fees</span><b>' . rupees($t['fees']) . '</b><small>' . pay_fee_pct() . '% of card / UPI sales</small></div>'
    . '<a class="kpi" href="' . h(self_url(['tab' => 'expenses'])) . '"><span>Expenses</span><b>' . rupees($t['expenses']) . '</b><small>Add expenses</small></a>'
    . '<div class="kpi ' . ($t['net'] < 0 ? 'bad' : 'good') . '"><span>Net ' . ($t['net'] < 0 ? 'loss' : 'profit') . '</span><b>' . money($t['net']) . '</b><small>' . ($t['sales'] ? round($t['net'] / $t['sales'] * 100) . '% of sales' : '&nbsp;') . '</small></div></div>';
  $head = '<th>Month</th><th class="r">Orders</th><th class="r">Sales</th><th class="r">Discounts</th><th class="r">Fees</th><th class="r">Cost of goods</th><th class="r">Gross profit</th><th class="r">Expenses</th><th class="r">Net profit / loss</th>';
  $body .= $sw('#repPanes', ['month' => 'Month by month', 'year' => 'By year']) . '<div class="rep panes" id="repPanes"><div class="box on" data-pane="month"><div class="bb np"><table class="grid"><thead><tr>' . $head . '</tr></thead><tbody>';
  foreach ($rows as $k => $r) $body .= '<tr' . ($k > $cur ? ' class="dim"' : '') . '><td>' . h(date('F', strtotime("$k-01"))) . '</td><td class="r">' . $r['orders'] . '</td><td class="r">' . rupees($r['sales']) . '</td><td class="r">' . rupees($r['discounts']) . '</td><td class="r">' . rupees($r['fees']) . '</td>'
    . '<td class="r">' . rupees($r['cost']) . ($r['nocost'] ? ' <small class="warn">+' . $r['nocost'] . ' no cost</small>' : '') . '</td><td class="r">' . $m($r['gross']) . '</td><td class="r">' . rupees($r['expenses']) . '</td><td class="r"><b>' . $m($r['net']) . '</b></td></tr>';
  $body .= '</tbody><tfoot><tr><td>Total ' . $pyear . '</td><td class="r">' . $t['orders'] . '</td><td class="r">' . rupees($t['sales']) . '</td><td class="r">' . rupees($t['discounts']) . '</td><td class="r">' . rupees($t['fees']) . '</td><td class="r">' . rupees($t['cost']) . '</td><td class="r">' . $m($t['gross']) . '</td><td class="r">' . rupees($t['expenses']) . '</td><td class="r"><b>' . $m($t['net']) . '</b></td></tr></tfoot></table></div></div>';
  $body .= '<div class="box yr" data-pane="year"><div class="bb np"><table class="grid"><thead><tr>' . str_replace('Month', 'Year', $head) . '</tr></thead><tbody>';
  foreach (report_years() as $y) { $yt = report_sum(report_year($y)); $body .= '<tr><td><a href="' . h(self_url(['tab' => 'reports', 'year' => $y])) . '">' . $y . '</a></td><td class="r">' . $yt['orders'] . '</td><td class="r">' . rupees($yt['sales']) . '</td><td class="r">' . rupees($yt['discounts']) . '</td><td class="r">' . rupees($yt['fees']) . '</td><td class="r">' . rupees($yt['cost']) . '</td><td class="r">' . $m($yt['gross']) . '</td><td class="r">' . rupees($yt['expenses']) . '</td><td class="r"><b>' . $m($yt['net']) . '</b></td></tr>'; }
  $body .= '</tbody></table><p class="muted small" style="padding:0 12px">Sales are orders marked New, Paid or Delivered, by order date, including the cash on delivery fee. Cancelled orders, unfinished payments and test payments are left out. Discounts are what customers saved against the “Was” price (already taken off sales). Fees are the card / UPI payment fee set in Settings. Gross profit = sales − fees − cost of goods. Net = gross profit − expenses.</p></div></div></div>';
}

/* ============ Reviews ============ */
if ($tab === 'reviews') {
  $rq = trim((string)($_GET['q'] ?? '')); $rv = (string)($_GET['v'] ?? ''); $rv = in_array($rv, ['1', '0'], true) ? $rv : '';
  $rp = (string)($_GET['p'] ?? ''); if (!preg_match('/^[\w-]{1,60}$/', $rp)) $rp = '';
  $ALL = reviews_list(); $list = reviews_list(array_filter(['q' => $rq, 'product' => $rp], 'strlen') + ($rv !== '' ? ['verified' => (int)$rv] : []));
  $nv = count(array_filter($ALL, fn($r) => (int)$r['verified'] === 1)); $nu = count($ALL) - $nv;
  $keep = array_filter(['tab' => 'reviews', 'q' => $rq, 'v' => $rv, 'p' => $rp], 'strlen'); $back = h(json_encode($keep));
  $body .= '<form method="get" class="row rtool"><input type="hidden" name="tab" value="reviews">' . ($rv !== '' ? '<input type="hidden" name="v" value="' . $rv . '">' : '') . ($rp !== '' ? '<input type="hidden" name="p" value="' . h($rp) . '">' : '')
    . '<input type="search" name="q" value="' . h($rq) . '" placeholder="Words, name or mobile"><button class="btn line sm">Search</button>'
    . '<a class="chip' . ($rv === '1' ? ' on' : '') . '" href="' . h(self_url(['v' => $rv === '1' ? '' : '1'] + $keep)) . '">Verified purchaser <b>' . $nv . '</b></a>'
    . '<a class="chip' . ($rv === '0' ? ' on' : '') . '" href="' . h(self_url(['v' => $rv === '0' ? '' : '0'] + $keep)) . '">Unverified <b>' . $nu . '</b></a></form>';
  $body .= $sw('#rvPanes', ['list' => 'Reviews', 'stars' => 'Stars By Product', 'top' => 'Top Reviewers']) . '<div class="revs panes" id="rvPanes">';
  /* every review */
  $body .= '<div class="box on" data-pane="list"><div class="bh">' . ($rp !== '' ? '<a class="chip on" href="' . h(self_url(array_diff_key($keep, ['p' => 1]))) . '" title="Show every product">' . h($CAT[$rp]['name'] ?? $rp) . ' <b>✕</b></a>' : '') . '<span class="muted small" style="flex:1">' . count($list) . ' review' . (count($list) === 1 ? '' : 's') . '. Removed reviews leave the website and the star rating; Put back shows them again.</span></div><div class="bb">';
  if (!reviews_db()) $body .= '<p class="empty">No reviews yet.</p>';
  elseif (!$list) $body .= '<p class="empty">No reviews' . ($rq !== '' || $rv !== '' ? ' match this search.' : ' yet.') . '</p>';
  foreach ($list as $r) {
    $live = $r['status'] === 'live';
    $photos = json_decode((string)$r['photos'], true) ?: [];
    $body .= '<div class="rv' . ($live ? '' : ' off') . '"><div class="rvh">' . $thumbOf($r['product'], 'th xs') . '<b>' . h($CAT[$r['product']]['name'] ?? $r['product']) . '</b>' . stars((float)$r['rating'])
      . ($r['verified'] ? '<span class="badge st-paid">Verified purchaser</span>' : '') . ($r['status'] === 'pending' ? '<span class="badge st-new">Waiting</span>' : (!$live ? '<span class="badge st-cancelled">Removed</span>' : '')) . '<span class="sp"></span>'
      . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="review"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="back" value="' . $back . '">'
      . ($live ? '<input type="hidden" name="status" value="hidden"><button class="btn line sm danger" data-confirm="Remove this review from the website? You can put it back later.">Remove</button>' : '<input type="hidden" name="status" value="live"><button class="btn sm">' . ($r['status'] === 'pending' ? 'Publish' : 'Put back') . '</button>') . '</form></div>'
      . '<p>' . nl2br(h($r['body'])) . '</p>'
      . ($photos ? '<div class="rvp">' . implode('', array_map(fn($f) => '<a href="/api/reviews.php?action=photo&amp;f=' . rawurlencode($f) . '" target="_blank" rel="noopener"><img src="/api/reviews.php?action=photo&amp;f=' . rawurlencode($f) . '" alt="" loading="lazy"></a>', $photos)) . '</div>' : '')
      . '<small class="muted">' . h($r['anonymous'] ? 'Anonymous (' . $r['name'] . ')' : $r['name']) . ($r['phone'] ? ' · ' . h(phone_fmt($r['phone'])) : '') . ' · ' . h(date('d M Y', (int)$r['created'])) . ($r['helpful'] ? ' · ' . (int)$r['helpful'] . ' found it helpful' : '')
      . ($r['phone'] ? ' · <a href="' . h(self_url(['tab' => 'members', 'c' => 'm:' . $r['phone']])) . '">Customer page</a>' : '') . '</small>'
      . (($r['reply'] ?? '') !== '' ? '<div class="rvr"><b>Reply from FOMAXO</b><p>' . nl2br(h($r['reply'])) . '</p></div>' : '')
      . '<details class="rvr-edit"><summary class="btn line sm">' . (($r['reply'] ?? '') === '' ? 'Reply' : 'Edit reply') . '</summary>'
      . '<form method="post">' . $csrfField . '<input type="hidden" name="action" value="review_reply"><input type="hidden" name="id" value="' . (int)$r['id'] . '"><input type="hidden" name="back" value="' . $back . '">'
      . '<textarea name="reply" rows="3" maxlength="1000" placeholder="Thank you for your review…" required>' . h($r['reply'] ?? '') . '</textarea>'
      . '<div class="row"><button class="btn sm">' . (($r['reply'] ?? '') === '' ? 'Post reply' : 'Save reply') . '</button>'
      . (($r['reply'] ?? '') !== '' ? '<button class="btn line sm danger" name="delete" value="1" formnovalidate data-confirm="Remove your reply from the website?">Delete reply</button>' : '') . '</div></form></details></div>';
  }
  $body .= '</div></div>';
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
  foreach ($who as $k => $w) $body .= '<div class="li"><span class="grow"><b>' . ($w['phone'] ? '<a href="' . h(self_url(['tab' => 'members', 'c' => $k])) . '">' . h($w['name']) . '</a>' : h($w['name'])) . '</b><small>' . ($w['phone'] ? h(phone_fmt($w['phone'])) . ' · ' : '') . 'average ' . number_format($w['sum'] / $w['n'], 1) . ' ★</small></span><b>' . $w['n'] . '</b><span class="muted small">reviews</span></div>';
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
  $body .= $sw('#setPanes', ['notify' => 'Emails', 'pw' => 'Password', 'db' => 'Database']) . '<div class="set panes" id="setPanes">'
    . '<form method="post" class="box on" data-pane="notify">' . $csrfField . '<input type="hidden" name="action" value="settings"><div class="bh"><h3>Order emails</h3></div><div class="bb" style="padding-top:12px;display:flex;flex-direction:column;gap:12px">'
    . '<label>Send new order emails to<input type="email" name="notify_email" value="' . h($email) . '" placeholder="' . h(fomaxo_catalog()['email'] ?: 'fomaxoasset@gmail.com') . '"></label>'
    . '<p class="muted small" style="margin:0">Every new order is emailed here, and so is a password reset link if you forget your password. Empty uses ' . h(fomaxo_catalog()['email'] ?: 'fomaxoasset@gmail.com') . '.</p>'
    . '<label>Card / UPI payment fee %<input type="number" name="pay_fee" min="0" max="10" step="0.01" value="' . h((string)pay_fee_pct()) . '"></label>'
    . '<p class="muted small" style="margin:0">Razorpay’s fee on each online payment, used for Fees in Reports.</p><button class="btn">Save</button></div></form>'
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
