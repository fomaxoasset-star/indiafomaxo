<?php
declare(strict_types=1);
/* FOMAXO India — "our own Clarity": what visits did on the website, read from events (pages) and trail (taps, how far down each page, how fast
   the site opened, page errors). Admin → Analytics → Visits and Admin → Improve show it; api/data/visits.txt is the same summary as plain text
   (rewritten at most once an hour by api/track.php, not reachable from the web) so it can be read from the hosting file manager.
   Anonymous only: no names, mobiles or anything typed. Works on SQLite and MySQL (shop_db), so the adding up is done here in PHP. */

function insights_table(PDO $db): void {
  static $done = false; if ($done) return; $done = true;
  $my = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
  $db->exec('CREATE TABLE IF NOT EXISTS trail(id ' . ($my ? 'INT NOT NULL AUTO_INCREMENT PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT') . ", ts VARCHAR(19) NOT NULL, sid VARCHAR(16) NOT NULL, vid VARCHAR(16) NOT NULL,
    path VARCHAR(120) NOT NULL DEFAULT '', k VARCHAR(1) NOT NULL, label VARCHAR(60) NOT NULL DEFAULT '', pc INT NOT NULL DEFAULT 0, dead INT NOT NULL DEFAULT 0, rage INT NOT NULL DEFAULT 0,
    device VARCHAR(8) NOT NULL DEFAULT '', ms INT NOT NULL DEFAULT 0)" . ($my ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4' : ''));
  try { $db->exec('CREATE INDEX trail_ts ON trail(ts)'); $db->exec('CREATE INDEX trail_sid ON trail(sid)'); } catch (Throwable $e) {}   // already there
}

/* a page address as one name: home is "/", a product page is the product's name */
function insights_pg(string $p): string { $p = '/' . trim($p, '/'); return in_array($p, ['/', '/home'], true) ? '/' : $p; }
function insights_pg_name(string $pg, array $names = []): string {
  if ($pg === '/') return 'Home';
  if (preg_match('~^/product/([a-z0-9-]+)~', $pg, $m)) return $names[$m[1]] ?? ucfirst($m[1]);
  return ucfirst(str_replace(['-', '/'], [' ', ' / '], trim($pg, '/')));
}
function insights_secs(?int $t): string { return $t === null ? '–' : ($t >= 60 ? intdiv($t, 60) . 'm ' . str_pad((string)($t % 60), 2, '0', STR_PAD_LEFT) . 's' : $t . 's'); }
function insights_end(string $k): string { return ['bought' => 'Bought', 'payment' => 'Left at payment', 'checkout' => 'Left at checkout', 'bag' => 'Added to bag, left', 'bounce' => 'Left at once', 'left' => 'Looked, left'][$k] ?? $k; }

/* everything Visits and Improve show, for the days $from to $to (Y-m-d) */
function insights_data(string $from, string $to, int $paths = 60): array {
  $db = shop_db(); insights_table($db); $a = ["$from 00:00:00", "$to 23:59:59"];
  $q = function (string $sql, array $args) use ($db) { $s = $db->prepare($sql); $s->execute($args); return $s->fetchAll(); };
  $out = ['leave' => [], 'taps' => [], 'paths' => [], 'visits' => 0, 'has_trail' => false, 'speed' => [], 'errors' => [], 'bounce' => 0, 'by_source' => [], 'by_device' => [], 'product_pc' => null, 'land' => [], 'bounce_fast' => 0, 'by_cmp' => []];
  /* pages of each visit, in order; time on a page = until the next page, or the visit's last moment (at most 30 minutes) */
  $V = []; $did = [];
  foreach ($q("SELECT sid, ts, type, path, product FROM events WHERE type IN ('view', 'add', 'checkout', 'pay', 'card', 'buy') AND ts >= ? AND ts <= ? ORDER BY ts, id", $a) as $r) {
    if ($r['type'] === 'view') $V[$r['sid']][] = ['pg' => insights_pg((string)$r['path']), 'at' => (string)$r['ts']]; else $did[$r['sid']][$r['type']] = 1;
  }
  $meta = []; if ($V) foreach ($q('SELECT sid, last, source, device, country, campaign FROM visits WHERE last >= ?', [strtotime($a[0])]) as $r) if (isset($V[$r['sid']])) $meta[$r['sid']] = $r;
  $T = []; try { foreach ($q('SELECT sid, ts, path, k, label, pc, dead, rage, ms, device FROM trail WHERE ts >= ? AND ts <= ? ORDER BY ts, id', $a) as $r) $T[$r['sid']][] = $r; } catch (Throwable $e) {}
  $out['has_trail'] = (bool)$T || (bool)$q('SELECT 1 FROM trail LIMIT 1', []);
  $out['visits'] = count($V);
  $L = []; $taps = []; $speed = []; $errs = []; $scroll = [];
  foreach ($V as $sid => &$steps) {
    $last = isset($meta[$sid]) ? date('Y-m-d H:i:s', (int)$meta[$sid]['last']) : null;
    foreach ($steps as $i => &$st) { $end = $steps[$i + 1]['at'] ?? $last; $st['secs'] = $end ? min(1800, max(0, strtotime($end) - strtotime($st['at']))) : null; $st['pc'] = null; $st['taps'] = []; } unset($st);
    $load = null;
    foreach ($T[$sid] ?? [] as $t) {   // each tap, scroll and error goes to the last step on that page that started before it
      $pg = insights_pg((string)$t['path']);
      if ($t['k'] === 'l') { $load ??= (int)$t['ms']; $speed[$t['device']][] = (int)$t['ms']; continue; }
      if ($t['k'] === 'e') { $ek = $pg . '|' . $t['label']; $errs[$ek] ??= ['pg' => $pg, 'label' => $t['label'], 'people' => [], 'n' => 0]; $errs[$ek]['people'][$sid] = 1; $errs[$ek]['n']++; }
      if ($t['k'] === 's') $scroll[$pg][$sid] = max($scroll[$pg][$sid] ?? 0, (int)$t['pc']);
      if ($t['k'] === 't') { $tk = $pg . '|' . $t['label'] . '|' . $t['dead']; $taps[$tk] ??= ['pg' => $pg, 'label' => $t['label'], 'dead' => (int)$t['dead'], 'taps' => 0, 'people' => [], 'rage' => 0, 'rage_people' => []];
        $taps[$tk]['taps']++; $taps[$tk]['people'][$sid] = 1; if ($t['rage']) { $taps[$tk]['rage']++; $taps[$tk]['rage_people'][$sid] = 1; } }
      for ($i = count($steps) - 1; $i >= 0; $i--) if ($steps[$i]['pg'] === $pg && $steps[$i]['at'] <= $t['ts']) break;
      if ($i < 0) for ($i = 0; $i < count($steps); $i++) if ($steps[$i]['pg'] === $pg) break;
      if ($i < 0 || $i >= count($steps)) continue;
      if ($t['k'] === 's') $steps[$i]['pc'] = max((int)$steps[$i]['pc'], (int)$t['pc']);
      else $steps[$i]['taps'][] = ['l' => ($t['k'] === 'e' ? 'Error: ' : '') . $t['label'], 'dead' => (int)$t['dead'], 'rage' => (int)$t['rage'], 'err' => (int)($t['k'] === 'e')];
    }
    $d = $did[$sid] ?? [];
    $end = isset($d['buy']) ? 'bought' : (isset($d['pay']) || isset($d['card']) ? 'payment' : (isset($d['checkout']) ? 'checkout' : (isset($d['add']) ? 'bag'
         : (count($steps) === 1 && !array_filter($steps[0]['taps'], fn($x) => !$x['dead'] && !$x['err']) ? 'bounce' : 'left'))));
    if ($end === 'bounce') { $out['bounce']++; if ($steps[0]['secs'] !== null && $steps[0]['secs'] < 10) $out['bounce_fast']++; }
    $src = (string)($meta[$sid]['source'] ?? ''); $dev = (string)($meta[$sid]['device'] ?? '');
    /* the page each visit landed on (first page), by source: where Instagram and ad visitors arrive and how many of them left from it at once */
    $lp = $steps[0]['pg']; $out['land'][$lp] ??= ['pg' => $lp, 'visits' => 0, 'bounce' => 0, 'src' => []]; $out['land'][$lp]['visits']++; if ($end === 'bounce') $out['land'][$lp]['bounce']++;
    if ($src !== '') { $out['land'][$lp]['src'][$src] ??= ['visits' => 0, 'bounce' => 0, 'adds' => 0]; $out['land'][$lp]['src'][$src]['visits']++; if ($end === 'bounce') $out['land'][$lp]['src'][$src]['bounce']++; if (isset($d['add'])) $out['land'][$lp]['src'][$src]['adds']++;
      /* by ad name (utm_campaign in the link; '' = no name: a bio, story or ad link without URL parameters) */
      $cm = (string)($meta[$sid]['campaign'] ?? ''); $out['by_cmp'][$src][$cm] ??= ['visits' => 0, 'adds' => 0]; $out['by_cmp'][$src][$cm]['visits']++; if (isset($d['add'])) $out['by_cmp'][$src][$cm]['adds']++; }
    foreach (['by_source' => $src, 'by_device' => $dev] as $bk => $bv) if ($bv !== '') { $out[$bk][$bv] ??= ['k' => $bv, 'visits' => 0, 'adds' => 0]; $out[$bk][$bv]['visits']++; if (isset($d['add'])) $out[$bk][$bv]['adds']++; }
    $seen = [];
    foreach ($steps as $i => $st) { $L[$st['pg']] ??= ['pg' => $st['pg'], 'seen' => 0, 'left_n' => 0, 'secs' => []]; if (!isset($seen[$st['pg']])) { $seen[$st['pg']] = 1; $L[$st['pg']]['seen']++; } if ($st['secs'] !== null) $L[$st['pg']]['secs'][] = $st['secs']; }
    $L[end($steps)['pg']]['left_n']++;
    $steps = ['sid' => $sid, 'start' => $steps[0]['at'], 'last' => end($steps)['at'], 'device' => $dev, 'country' => (string)($meta[$sid]['country'] ?? ''), 'source' => $src, 'steps' => $steps, 'end' => $end, 'load' => $load];
  }
  unset($steps);
  foreach ($L as &$r) { $r['secs'] = $r['secs'] ? (int)round(array_sum($r['secs']) / count($r['secs'])) : null;
    $sc = $scroll[$r['pg']] ?? []; $r['pc'] = $sc ? (int)round(array_sum($sc) / count($sc)) : null; $r['bottom'] = $sc ? (int)round(100 * count(array_filter($sc, fn($v) => $v >= 90)) / count($sc)) : null; } unset($r);
  usort($L, fn($x, $y) => [$y['left_n'], $y['seen']] <=> [$x['left_n'], $x['seen']]);
  $out['leave'] = array_slice($L, 0, 40);
  foreach ($taps as &$r) { $r['people'] = count($r['people']); $r['rage_people'] = count($r['rage_people']); } unset($r);
  usort($taps, fn($x, $y) => $y['taps'] <=> $x['taps']); $out['taps'] = array_slice(array_values($taps), 0, 300);
  foreach ($speed as $dv => $ms) $out['speed'][$dv] = ['n' => count($ms), 'avg_ms' => (int)round(array_sum($ms) / count($ms)), 'slow' => count(array_filter($ms, fn($v) => $v > 3000))];
  foreach ($errs as &$r) $r['people'] = count($r['people']); unset($r);
  usort($errs, fn($x, $y) => [$y['people'], $y['n']] <=> [$x['people'], $x['n']]); $out['errors'] = array_slice(array_values($errs), 0, 20);
  $pp = []; foreach ($scroll as $pg => $sc) if (str_starts_with($pg, '/product/')) foreach ($sc as $v) $pp[] = $v;
  $out['product_pc'] = $pp ? (int)round(array_sum($pp) / count($pp)) : null;
  usort($out['by_source'], fn($x, $y) => $y['visits'] <=> $x['visits']);
  usort($out['land'], fn($x, $y) => [$y['visits'], $y['bounce']] <=> [$x['visits'], $x['bounce']]); $out['land'] = array_slice($out['land'], 0, 20);
  $all = array_values($V); usort($all, fn($x, $y) => strcmp($y['last'], $x['last']));
  $out['paths'] = array_slice($all, 0, $paths);
  return $out;
}

/* api/data/visits.txt: rewritten at most once an hour, while visitors are on the site */
function insights_file(): string { return __DIR__ . '/data/visits.txt'; }
function insights_due(): bool { $f = insights_file(); return is_dir(dirname($f)) && (!is_file($f) || filemtime($f) < time() - 3600); }
function insights_write(): void {
  $f = insights_file(); @touch($f);   // another visit does not start the same work meanwhile
  try {
    $names = []; try { foreach (shop_db()->query('SELECT DISTINCT product FROM events WHERE product <> \'\' LIMIT 200') as $r) { $p = fomaxo_store_product((string)$r['product']); if ($p) $names[$r['product']] = (string)($p['name'] ?? $r['product']); } } catch (Throwable $e) {}
    $L = ['FOMAXO India visits summary (anonymous). Written ' . date('d/m/Y H:i') . ' India time. Same as fomaxo.in/admin → Analytics → Visits.', ''];
    foreach (['Last 7 days' => 6, 'Today' => 0] as $title => $back) {
      $D = insights_data(date('Y-m-d', strtotime("-$back day")), date('Y-m-d'), $back ? 60 : 30);
      $L[] = "==== $title: {$D['visits']} visits ===="; $L[] = '';
      $L[] = 'WHERE PEOPLE LEAVE (page | visits that saw it | left from it | % left | avg time | avg seen down the page | % reached the bottom)';
      foreach ($D['leave'] as $r) $L[] = sprintf('%s | %d | %d | %d%% | %s | %s | %s', insights_pg_name($r['pg'], $names), $r['seen'], $r['left_n'], $r['seen'] ? round(100 * $r['left_n'] / $r['seen']) : 0, insights_secs($r['secs']), $r['pc'] !== null ? $r['pc'] . '%' : '–', $r['bottom'] !== null ? $r['bottom'] . '%' : '–');
      $L[] = ''; $L[] = 'HOW FAST THE SITE OPENED (device | page loads | average | slower than 3 s)';
      foreach ($D['speed'] as $dv => $r) $L[] = sprintf('%s | %d | %.1fs | %d%%', $dv, $r['n'], $r['avg_ms'] / 1000, round(100 * $r['slow'] / max(1, $r['n'])));
      if (!$D['speed']) $L[] = '(none)';
      $L[] = ''; $L[] = 'ERRORS ON THE PAGE (page | error | people | times)';
      foreach ($D['errors'] as $r) $L[] = sprintf('%s | %s | %d | %d', insights_pg_name($r['pg'], $names), $r['label'], $r['people'], $r['n']);
      if (!$D['errors']) $L[] = '(none)';
      foreach (['TAPPED BUTTONS AND LINKS' => fn($r) => !$r['dead'], 'TAPS ON THINGS THAT ARE NOT BUTTONS' => fn($r) => $r['dead'], 'ANGRY TAPS (same spot 3 times in a second)' => fn($r) => $r['rage'] > 0] as $h => $keep) {
        $L[] = ''; $L[] = "$h (page | thing | taps | people)"; $n = 0;
        foreach ($D['taps'] as $r) if ($keep($r) && $n++ < 40) $L[] = sprintf('%s | %s | %d | %d', insights_pg_name($r['pg'], $names), $r['label'], $h[0] === 'A' ? $r['rage'] : $r['taps'], $h[0] === 'A' ? $r['rage_people'] : $r['people']);
        if (!$n) $L[] = '(none)';
      }
      $L[] = ''; $L[] = 'NEWEST VISITS, STEP BY STEP';
      foreach ($D['paths'] as $v) {
        $L[] = date('d/m H:i', strtotime($v['start'])) . ' · ' . ($v['device'] ?: '?') . ' · ' . ($v['country'] ?: '?') . ' · from ' . ($v['source'] ?: '?') . ($v['load'] ? ' · opened in ' . round($v['load'] / 1000, 1) . 's' : '') . ' · ' . insights_end($v['end']);
        foreach ($v['steps'] as $s) {
          $tp = array_map(fn($t) => ($t['dead'] ? '(not a button) ' : '') . $t['l'] . ($t['rage'] ? ' [angry]' : ''), $s['taps']);
          $L[] = '   ' . insights_pg_name($s['pg'], $names) . ' · ' . insights_secs($s['secs']) . ($s['pc'] !== null ? ' · seen ' . $s['pc'] . '%' : '') . ($tp ? ' · tapped: ' . implode(', ', array_slice($tp, 0, 12)) . (count($tp) > 12 ? ' …' : '') : '');
        }
      }
      $L[] = ''; $L[] = '';
    }
    file_put_contents($f . '.tmp', implode("\n", $L)); rename($f . '.tmp', $f);
  } catch (Throwable $e) { error_log('FOMAXO insights: ' . $e->getMessage()); }
}
