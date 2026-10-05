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
  if ($SUM['states']) {
    $body .= '<div class="chips" aria-label="Orders by state">';
    foreach ($SUM['states'] as $st => $n) $body .= '<a class="chip' . ($F['state'] === $st ? ' on' : '') . '" href="' . h(self_url(array_filter(['state' => $F['state'] === $st ? '' : $st] + $q))) . '">' . h($st) . ' <b>' . $n . '</b></a>';
    $body .= '</div>';
  }
  $body .= '<form class="filters" method="get"><input type="hidden" name="tab" value="orders">' . ($F['state'] !== '' ? '<input type="hidden" name="state" value="' . h($F['state']) . '">' : '')
    . '<label>Status' . $sel('status', ['' => 'All orders', 'todo' => 'To deliver (New + Paid)', 'new' => 'New', 'paid' => 'Paid', 'delivered' => 'Delivered', 'cancelled' => 'Cancelled', 'awaiting' => 'Unfinished online payments'], $F['status']) . '</label>'
    . '<label>Payment' . $sel('method', ['' => 'COD and online', 'cod' => 'Cash on delivery (COD)', 'online' => 'Online (card / UPI)'], $F['method']) . '</label>'
    . '<label class="hide-m">From<input type="date" name="from" value="' . h($F['from']) . '"></label><label class="hide-m">To<input type="date" name="to" value="' . h($F['to']) . '"></label>'
    . '<label class="grow">Search<input type="search" name="q" value="' . h($F['q']) . '" placeholder="Order no, name, mobile, email or note"></label>'
    . '<button class="btn line">Show</button><a class="btn" href="' . h(self_url($q + ['do' => 'excel'])) . '">Excel</a></form>';
  $back = h(json_encode($q + ['page' => $page]));
  $body .= '<div class="box fill"><div class="bh"><span class="muted small">' . $total . ' order' . ($total === 1 ? '' : 's') . ($F['status'] === 'awaiting' ? '. These shoppers opened online payment but did not finish. They have no order number and took no stock.' : '. Tap an order to see it and change its status.') . '</span></div><div class="bb np">';
  if (!$orders) $body .= '<p class="empty">No orders' . (array_filter($F) ? ' for this filter' : ' yet') . '.</p>';
  foreach ($orders as $o) {
    $items = json_decode((string)$o['items'], true) ?: [];
    $body .= '<details class="order os-' . h($o['status']) . '"' . (count($orders) === 1 ? ' open' : '') . '><summary>' . $orderThumb($o)
      . '<span class="no">' . h($o['no'] ?: 'Not paid') . '</span><span class="dt">' . h(date('d M Y, H:i', strtotime($o['created']))) . '</span>'
      . '<span class="cu"><b>' . h($o['name']) . '</b><small>' . h($o['phone']) . '</small></span>'
      . '<span class="tt">' . rupees((int)$o['total']) . '</span><span class="pm">' . h(pay_label($o)) . '</span>'
      . '<span class="badge st-' . h($o['status']) . '">' . h(FOMAXO_STATUSES[$o['status']] ?? $o['status']) . '</span></summary>'
      . '<div class="od"><div class="items"><h4>Items</h4>';
    foreach ($items as $it) $body .= '<div class="li">' . $thumbOf((string)($it['id'] ?? ''), 'th sm') . '<span class="grow"><b>' . h($it['name'] ?? $it['id'] ?? '') . '</b><small>' . (int)($it['qty'] ?? 0) . ' × ' . rupees((int)($it['unit'] ?? 0)) . ($it['desc'] ?? '' ? ' · ' . h($it['desc']) : '') . '</small></span></div>';
    $body .= ($o['cod_fee'] ? '<p class="small muted">Cash on delivery fee ' . rupees((int)$o['cod_fee']) . '</p>' : '') . '<p><b>Total ' . rupees((int)$o['total']) . '</b></p></div>'
      . '<div><h4>Delivery</h4><p>' . h($o['name']) . '<br>' . h($o['address']) . '</p><p><a href="tel:' . h(preg_replace('/[^0-9+]/', '', $o['phone'])) . '">' . h($o['phone']) . '</a> · <a href="https://wa.me/' . h(preg_replace('/\D/', '', $o['phone'])) . '" target="_blank" rel="noopener">WhatsApp</a><br><a href="mailto:' . h($o['email']) . '">' . h($o['email']) . '</a></p>'
      . ($o['note'] ? '<p class="muted">Customer note: ' . h($o['note']) . '</p>' : '') . '</div>'
      . '<div><h4>Payment</h4><p>' . h(pay_label($o)) . ($o['payment_id'] ? '<br><small class="muted">' . h($o['payment_id']) . '</small>' : '') . ($o['paid_at'] ? '<br><small class="muted">Paid ' . h(date('d M Y, H:i', strtotime($o['paid_at']))) . '</small>' : '') . '</p>'
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
  $body .= '</div></div>';
}

/* ============ Members ============ */
if ($tab === 'members') {
  $min = member_min(); $spend = member_spend(); $mq = trim((string)($_GET['q'] ?? '')); $list = members($min, $spend, $mq);
  $rule = $min . ' or more orders' . ($spend ? ' or ₹' . number_format($spend) . ' or more spent' : '');
  $body .= '<div class="row mtool"><form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="member_min">'
    . '<label class="mrule">Orders at least<input type="number" name="member_min" min="1" max="999" value="' . $min . '"></label>'
    . '<label class="mrule">or spent at least ₹<input type="number" name="member_spend" min="0" max="10000000" value="' . $spend . '"></label><button class="btn line sm">Save</button></form>'
    . '<form method="get" class="msearch"><input type="hidden" name="tab" value="members"><input type="search" name="q" value="' . h($mq) . '" placeholder="Name, mobile or email"><button class="btn line sm">Search</button></form>'
    . '<a class="btn sm" href="' . h(self_url(array_filter(['do' => 'members_excel', 'q' => $mq]))) . '">Excel</a></div>';
  $body .= '<div class="box fill"><div class="bh"><span class="muted small">' . count($list) . ' member' . (count($list) === 1 ? '' : 's') . ' with ' . $rule . ', grouped by mobile (or email), most spent first. Cancelled and test orders are left out. ₹0 turns the amount off. Tap a member to see their orders.</span></div><div class="bb np">';
  if (!$list) $body .= '<p class="empty">' . ($mq !== '' ? 'No member matches “' . h($mq) . '”.' : 'No customer has ' . $rule . ' yet. Lower the numbers above to see more.') . '</p>';
  foreach ($list as $m) {
    $msg = 'Hi ' . $m['name'] . ', thank you for being a FOMAXO regular!';
    $body .= '<details class="order mem"><summary><span class="no">' . h($m['name']) . '</span>'
      . '<span class="cu"><b>' . h(phone_fmt($m['phone']) ?: $m['email']) . '</b><small>' . h($m['state']) . '</small></span>'
      . '<span class="mn"><b>' . $m['count'] . '</b><small>orders</small></span><span class="tt">' . rupees($m['spent']) . '</span><span class="pm">avg ' . rupees($m['avg']) . '</span>'
      . '<span class="dt">' . h(date('d M Y', strtotime($m['first']))) . ' – ' . h(date('d M Y', strtotime($m['last']))) . '</span></summary>'
      . '<div class="od"><div><h4>Contact</h4><p>' . ($m['phone'] ? '<a href="tel:+91' . h($m['phone']) . '">' . h(phone_fmt($m['phone'])) . '</a> · <a href="https://wa.me/91' . h($m['phone']) . '?text=' . rawurlencode($msg) . '" target="_blank" rel="noopener">WhatsApp</a><br>' : '')
      . ($m['email'] ? '<a href="mailto:' . h($m['email']) . '">' . h($m['email']) . '</a>' : '') . '</p><h4>Latest address</h4><p>' . h($m['address']) . '</p></div>'
      . '<div><h4>Summary</h4><p>' . $m['count'] . ' orders · ' . rupees($m['spent']) . ' spent<br>Average order ' . rupees($m['avg']) . '<br>First order ' . h(date('d M Y', strtotime($m['first']))) . '<br>Last order ' . h(date('d M Y', strtotime($m['last']))) . '</p></div>'
      . '<div class="items"><h4>Order history</h4>';
    foreach (array_reverse($m['orders']) as $o) $body .= '<a class="li" href="' . h(self_url(['tab' => 'orders', 'q' => $o['no']])) . '"><span class="grow"><b>' . h($o['no']) . '</b><small>' . h(date('d M Y', strtotime($o['created']))) . ' · ' . ($o['method'] === 'cod' ? 'COD' : 'Online') . '</small></span><span>' . rupees((int)$o['total']) . '</span><span class="badge st-' . h($o['status']) . '">' . h(FOMAXO_STATUSES[$o['status']]) . '</span></a>';
    $body .= '</div></div></details>';
  }
  $body .= '</div></div>';
}

/* ============ Stock ============ */
if ($tab === 'stock') {
  $STOCK = shop_stock(); $low = shop_low_stock();
  $body .= '<div class="row top"><form method="post" class="row">' . $csrfField . '<input type="hidden" name="action" value="low_stock">'
    . '<label class="chk" style="font-size:14px">Show “Only X left” from <input type="number" name="low_stock" min="0" max="99" value="' . $low . '" style="width:70px"> left or fewer</label><button class="btn line sm">Save</button><span class="muted small">0 turns it off.</span></form></div>'
    . '<form method="post" class="box fill">' . $csrfField . '<input type="hidden" name="action" value="stock"><input type="hidden" name="low_stock" value="' . $low . '">'
    . '<div class="bh"><span class="muted small">Write how many you have of each size. Orders take them off and cancelled orders put them back. At 0 the shop shows Sold out. Leave a box empty to not track that size.</span></div>'
    . '<div class="bb np"><table class="grid"><thead><tr><th>Product</th><th>Size</th><th>In stock</th><th class="hide-m">On the shop</th></tr></thead><tbody>';
  foreach ($CAT as $id => $p) {
    $first = true;
    foreach ($p['prices'] as $opt => $_) {
      $v = $STOCK[$id][$opt] ?? null;
      $state = !empty($p['hidden']) ? '<span class="badge st-awaiting">Hidden</span>' : ($v === null ? ($p['soldOut'] ? '<span class="badge st-cancelled">Sold out</span>' : '<span class="muted small">Not tracked</span>')
        : ($v < 1 ? '<span class="badge st-cancelled">Sold out</span>' : ($low && $v <= $low ? '<span class="badge st-new">Only ' . $v . ' left</span>' : '<span class="badge st-paid">In stock</span>')));
      $body .= '<tr' . ($first ? ' class="first"' : '') . '><td>' . ($first ? '<div class="pc">' . $thumbOf($id) . '<div><b>' . h($p['name']) . '</b><small>' . h(kind_label($p['kind'])) . '</small></div></div>' : '') . '</td><td>' . h(opt_label($p, (string)$opt)) . '</td>'
        . '<td><input type="number" min="0" max="99999" name="stock[' . h($id) . '][' . h((string)$opt) . ']" value="' . ($v === null ? '' : $v) . '" placeholder="—"></td><td class="hide-m">' . $state . '</td></tr>';
      $first = false;
    }
  }
  $body .= '</tbody></table></div><div class="bf"><button class="btn">Save stock</button><span class="muted small">Your cost per item is on each product’s Edit page.</span></div></form>';
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
  $body .= '<form method="post" enctype="multipart/form-data" class="box fill" id="pf">' . $csrfField . '<input type="hidden" name="action" value="' . ($adding ? 'add' : 'edit') . '">' . ($adding ? '' : '<input type="hidden" name="id" value="' . h($editId) . '">')
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
  $body .= '<form class="range" method="get"><input type="hidden" name="tab" value="analytics"><span class="seg">'
    . implode('', array_map(fn($k, $l) => '<a href="' . h(self_url(['tab' => 'analytics', 'r' => $k])) . '"' . ($r === $k ? ' class="on"' : '') . ">$l</a>", ['today', '7', '30'], ['Today', '7 days', '30 days'])) . '</span>'
    . '<input type="hidden" name="r" value="custom"><input type="date" name="from" value="' . h($from) . '"><input type="date" name="to" value="' . h($to) . '"><button class="btn line sm">Show</button>'
    . '<span class="muted small hide-m">' . h(date('d M Y', strtotime($from))) . ($from !== $to ? ' – ' . h(date('d M Y', strtotime($to))) : '') . '. Your own visits and bots are not counted.</span></form>';
  $body .= '<div class="kpis n7" style="--n:7">'
    . '<div class="kpi"><span>Visitors</span><b>' . number_format($A['visitors']) . '</b><small>' . number_format($A['visits']) . ' visits</small></div>'
    . '<div class="kpi good"><span>On the site now</span><b>' . $A['now'] . '</b><small>last 5 minutes</small></div>'
    . '<div class="kpi"><span>Conversion rate</span><b>' . ($A['conversion'] === null ? '—' : round($A['conversion'], 1) . '%') . '</b><small>visits that bought</small></div>'
    . '<div class="kpi"><span>Cart abandonment</span><b>' . $pct($A['cart_ab']) . '</b><small>added, did not buy</small></div>'
    . '<div class="kpi"><span>Checkout abandonment</span><b>' . $pct($A['checkout_ab']) . '</b><small>at checkout, did not buy</small></div>'
    . '<div class="kpi"><span>Purchases</span><b>' . $A['purchases'] . '</b><small>orders</small></div>'
    . '<div class="kpi"><span>Revenue</span><b>' . rupees($A['revenue']) . '</b><small>' . ($A['purchases'] ? rupees(intdiv($A['revenue'], $A['purchases'])) . ' per order' : '&nbsp;') . '</small></div></div>';
  $body .= $sw('#anPanes', ['funnel' => 'Funnel', 'sources' => 'Sources', 'products' => 'Products', 'left' => 'Left at checkout']) . '<div class="an panes" id="anPanes">';
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
  $tot = max(1, array_sum($A['sources']));
  $body .= '<div class="box srcs" data-pane="sources"><div class="bh"><h3>Where visitors came from</h3></div><div class="bb">';
  if (!$A['sources']) $body .= '<p class="empty">No visits yet in these dates.</p>';
  foreach ($A['sources'] as $k => $n) $body .= '<div class="li"><span class="grow"><b>' . h(SOURCES[$k] ?? ucfirst($k)) . '</b></span><span class="bar"><i style="width:' . round($n / $tot * 100) . '%"></i></span><span style="width:70px;text-align:right">' . $n . ' <small>' . round($n / $tot * 100) . '%</small></span></div>';
  $body .= '<p class="muted small">Add ?utm_source=instagram (or whatsapp) to links you share, so every visit from them is counted under that name.</p></div></div>';
  /* products */
  $body .= '<div class="box" data-pane="products"><div class="bh"><h3>Products</h3></div><div class="bb np"><table class="grid"><thead><tr><th>Product</th><th class="r">Views</th><th class="r">Added</th><th class="r">Sold</th><th class="r">Revenue</th></tr></thead><tbody>';
  if (!$A['products']) $body .= '<tr><td colspan="5" class="empty">No product views yet in these dates.</td></tr>';
  foreach ($A['products'] as $id => $p) $body .= '<tr><td><div class="pc">' . $thumbOf($id, 'th sm') . '<b>' . h($CAT[$id]['name']) . '</b></div></td><td class="r">' . $p['views'] . '</td><td class="r">' . $p['adds'] . '</td><td class="r">' . $p['units'] . '</td><td class="r">' . rupees($p['rev']) . '</td></tr>';
  $body .= '</tbody></table></div></div>';
  /* left at checkout */
  $body .= '<div class="box p-left" data-pane="left"><div class="bh"><h3>Left at checkout</h3><span class="muted small">Typed a name or mobile, then did not order</span></div><div class="bb np"><table class="grid"><thead><tr><th>When</th><th>Name</th><th>Left at</th><th>Bag</th><th class="r">Total</th><th></th></tr></thead><tbody>';
  if (!$A['leads']) $body .= '<tr><td colspan="6" class="empty">Nobody left at checkout in these dates.</td></tr>';
  foreach ($A['leads'] as $l) {
    $bag = json_decode((string)$l['bag'], true) ?: [];
    $names = implode(', ', array_map(fn($b) => $b['qty'] . ' × ' . $b['name'], $bag));
    $msg = 'Hi ' . ($l['name'] ?: 'there') . ', this is FOMAXO. We saw you were about to order ' . ($names ?: 'from our shop') . '. Can we help you finish your order?';
    $body .= '<tr><td>' . h(date('d M, H:i', strtotime($l['updated']))) . '</td><td><b>' . h($l['name'] ?: '—') . '</b><small>' . h($l['phone'] ? '+91 ' . substr($l['phone'], 0, 5) . ' ' . substr($l['phone'], 5) : 'no mobile') . '</small></td>'
      . '<td>' . ($l['step'] === 'payment' ? '<span class="badge st-cancelled">Payment</span>' : '<span class="badge st-new">Details</span>') . '</td>'
      . '<td><div class="pc">' . implode('', array_map(fn($b) => $thumbOf((string)$b['id'], 'th sm'), array_slice($bag, 0, 3))) . '<small style="max-width:220px">' . h($names) . '</small></div></td>'
      . '<td class="r">' . rupees((int)$l['total']) . '</td><td class="r">' . ($l['phone'] ? '<a class="btn sm" href="https://wa.me/91' . h($l['phone']) . '?text=' . rawurlencode($msg) . '" target="_blank" rel="noopener">WhatsApp</a>' : '') . '</td></tr>';
  }
  $body .= '</tbody></table></div></div></div>';
}

/* ============ Reports ============ */
if ($tab === 'reports') {
  $rows = report_year($pyear); $t = report_sum($rows); $cur = date('Y-m');
  $m = fn($p) => '<span class="' . ($p < 0 ? 'neg' : '') . '">' . money($p) . '</span>';
  $body .= '<div class="row"><h2>Profit &amp; loss</h2><form method="get"><input type="hidden" name="tab" value="reports">' . $sel('year', array_combine(report_years(), report_years()), $pyear) . '</form><span class="sp"></span>'
    . '<a class="btn line" href="' . h(self_url(['do' => 'report_excel', 'year' => $pyear])) . '">Download ' . $pyear . ' (Excel)</a></div>'
    . '<script>document.querySelector("select[name=year]").onchange=function(){this.form.submit()}</script>';
  $body .= '<div class="kpis n5" style="--n:5">'
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
