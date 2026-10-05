/* FOMAXO India admin: phone switches between boxes, and the Sales / Visitors bar charts on the dashboard. */
(function () {
  /* phones: keep the open tab in view in the scrolling tab strip */
  var tabOn = document.querySelector('.mtabs .on'); if (tabOn) { var bar = tabOn.parentNode, br = bar.getBoundingClientRect(), tr = tabOn.getBoundingClientRect(); bar.scrollLeft += tr.left - br.left - (br.width - tr.width) / 2; }

  /* phones: ‹ and › at the ends of the tab strip, and a swipe left / right on the page, open the next / previous tab */
  var tabLinks = Array.prototype.slice.call(document.querySelectorAll('.mtabs a')), tabAt = tabLinks.indexOf(tabOn);
  var goTab = function (step) { var a = tabLinks[tabAt + step]; if (a) location.href = a.href; };
  var prevB = document.querySelector('.mbar .tprev'), nextB = document.querySelector('.mbar .tnext');
  if (prevB) { prevB.disabled = tabAt <= 0; prevB.onclick = function () { goTab(-1); }; }
  if (nextB) { nextB.disabled = tabAt < 0 || tabAt >= tabLinks.length - 1; nextB.onclick = function () { goTab(1); }; }
  var t0 = null;
  var sideScroller = function (el) {
    for (; el && el !== document.body; el = el.parentElement) {
      var ox = getComputedStyle(el).overflowX;
      if ((ox === 'auto' || ox === 'scroll') && el.scrollWidth > el.clientWidth + 2) return true;
    }
    return false;
  };
  document.addEventListener('touchstart', function (e) {
    t0 = null;
    if (e.touches.length !== 1 || !matchMedia('(max-width:820px)').matches) return;
    if (window.visualViewport && visualViewport.scale > 1.05) return;   /* zoomed in with two fingers: let them pan */
    var el = e.target, act = document.activeElement;
    if (el.closest('input,textarea,select,[contenteditable],.mbar') || (act && /^(INPUT|TEXTAREA|SELECT)$/.test(act.tagName))) return;
    if (sideScroller(el)) return;
    t0 = {x: e.touches[0].clientX, y: e.touches[0].clientY, t: Date.now()};
  }, {passive: true});
  document.addEventListener('touchmove', function (e) { if (e.touches.length > 1) t0 = null; }, {passive: true});
  document.addEventListener('touchend', function (e) {
    if (!t0) return;
    var p = e.changedTouches[0], dx = p.clientX - t0.x, dy = p.clientY - t0.y; var ok = Date.now() - t0.t < 800; t0 = null;
    if (ok && Math.abs(dx) > 60 && Math.abs(dy) < 40 && Math.abs(dx) > Math.abs(dy) * 2) goTab(dx < 0 ? 1 : -1);
  }, {passive: true});

  /* a link button in a .seg lights up as soon as it is tapped, before the next page loads */
  document.querySelectorAll('.seg a').forEach(function (a) {
    a.addEventListener('click', function () { a.parentNode.querySelectorAll('a').forEach(function (x) { x.classList.toggle('on', x === a); }); });
  });

  /* analytics: Today / 7 days / 30 days / Year inside the countries and states boxes */
  document.querySelectorAll('.seg.rs').forEach(function (seg) {
    var box = seg.closest('.box');
    seg.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-r]'); if (!b) return;
      seg.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      box.querySelectorAll('.rl').forEach(function (l) { l.hidden = l.dataset.r !== b.dataset.r; });
    });
  });

  /* a "Saved" message fades away by itself after a few seconds (tap it to close it sooner); problems stay until closed */
  var flash = document.querySelector('.flash');
  if (flash) {
    var shut = function () { flash.classList.add('gone'); setTimeout(function () { flash.remove(); window.dispatchEvent(new Event('resize')); }, 300); };
    flash.addEventListener('click', shut);
    if (!flash.classList.contains('bad')) setTimeout(shut, 4500);
  }

  /* forms: ask first where a [data-confirm] says so, then show "Saving…" so a button is not pressed twice */
  document.addEventListener('submit', function (e) {
    var f = e.target, b = e.submitter || f.querySelector('button:not([type=button])');
    var ask = (b && b.dataset.confirm) || f.dataset.confirm;
    if (ask && !confirm(ask)) { e.preventDefault(); return; }
    if ((f.getAttribute('method') || '').toLowerCase() !== 'post' || !b) return;
    dirty = false;
    setTimeout(function () { b.disabled = true; b.dataset.label = b.textContent; b.textContent = 'Saving…'; }, 0);
  });

  /* Stock and product forms: changed boxes light up, and leaving with unsaved changes asks first */
  var dirty = false;
  document.querySelectorAll('form[data-watch]').forEach(function (f) {
    f.addEventListener('input', function (e) { if (e.target.matches('input,select,textarea')) { e.target.classList.add('changed'); dirty = true; var n = f.querySelector('.unsaved'); if (n) n.hidden = false; } });
  });
  window.addEventListener('beforeunload', function (e) { if (dirty) { e.preventDefault(); e.returnValue = ''; } });

  /* phones: "Filters" and "Dates" buttons open the less-used boxes */
  document.querySelectorAll('[data-open]').forEach(function (b) {
    b.addEventListener('click', function () { var t = document.querySelector(b.dataset.open); t.classList.toggle('open'); b.classList.toggle('on', t.classList.contains('open')); window.dispatchEvent(new Event('resize')); });
  });

  /* a .sw switch shows one [data-pane] of its .panes at a time (phones only; on a computer all panes show) */
  document.querySelectorAll('.sw').forEach(function (sw) {
    var panes = document.querySelector(sw.dataset.for);
    if (!panes) return;
    sw.addEventListener('click', function (e) {
      var b = e.target.closest('button[data-show]'); if (!b) return;
      sw.querySelectorAll('button').forEach(function (x) { x.classList.toggle('on', x === b); });
      panes.querySelectorAll('[data-pane]').forEach(function (p) { p.classList.toggle('on', p.dataset.pane === b.dataset.show); });
      window.dispatchEvent(new Event('resize'));
    });
  });

  /* bar charts: one series each, gold bars, a few grid lines, a tooltip on hover or tap */
  var dataEl = document.getElementById('chartData'); if (!dataEl) return;
  var DATA = JSON.parse(dataEl.textContent);
  var inr = function (p) { var r = p / 100; return '₹' + r.toLocaleString('en-IN', {maximumFractionDigits: r < 100 ? 2 : 0}); };
  var short = function (v, money) {
    var r = money ? v / 100 : v;
    var s = r >= 1e7 ? (r / 1e7).toFixed(1).replace(/\.0$/, '') + 'Cr' : r >= 1e5 ? (r / 1e5).toFixed(1).replace(/\.0$/, '') + 'L' : r >= 1e3 ? (r / 1e3).toFixed(1).replace(/\.0$/, '') + 'k' : String(Math.round(r));
    return (money ? '₹' : '') + s;
  };
  var nice = function (max) { if (max <= 0) return 1; var p = Math.pow(10, Math.floor(Math.log10(max))), f = max / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; };

  function draw(box) {
    var kind = box.dataset.chart, range = box.dataset.range || 'd7', d = DATA[kind][range], money = kind === 'sales';
    var el = box.querySelector('.chart'), svg = el.querySelector('svg'), tip = el.querySelector('.tip');
    var W = el.clientWidth - 24, H = el.clientHeight - 14; if (W < 50 || H < 50) return;
    var padL = 46, padB = 20, padT = 8, iw = W - padL - 4, ih = H - padB - padT;
    var vals = d.values, n = vals.length, max = nice(Math.max.apply(null, vals.concat([money ? 100 : 2]))), gap = n > 20 ? 2 : 4;
    if (!money && max % 2) max += 1;
    var bw = Math.max(2, iw / n - gap), out = '';
    for (var g = 0; g <= 2; g++) {
      var y = padT + ih - ih * g / 2;
      out += '<line x1="' + padL + '" x2="' + (W - 4) + '" y1="' + y + '" y2="' + y + '" stroke="#2e2a21" stroke-width="1"/>'
        + '<text x="' + (padL - 8) + '" y="' + (y + 4) + '" text-anchor="end" fill="#a59c89" font-size="11">' + short(max * g / 2, money) + '</text>';
    }
    var every = Math.ceil(n / (W < 420 ? 6 : 12));
    vals.forEach(function (v, i) {
      var x = padL + i * (iw / n) + gap / 2, h = v > 0 ? Math.max(2, ih * v / max) : 0, y = padT + ih - h, r = Math.min(4, bw / 2, h);
      if (h > 0) out += '<path d="M' + x + ',' + (padT + ih) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y + 'H' + (x + bw - r) + 'Q' + (x + bw) + ',' + y + ' ' + (x + bw) + ',' + (y + r) + 'V' + (padT + ih) + 'Z" fill="#c9a45c"/>';
      out += '<rect class="hit" data-i="' + i + '" x="' + (padL + i * iw / n) + '" y="' + padT + '" width="' + (iw / n) + '" height="' + ih + '" fill="transparent"/>';
      if (i % every === 0) out += '<text x="' + (x + bw / 2) + '" y="' + (H - 4) + '" text-anchor="middle" fill="#a59c89" font-size="11">' + d.labels[i] + '</text>';
    });
    svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.innerHTML = out;
    var tot = vals.reduce(function (a, b) { return a + b; }, 0);
    box.querySelector('.ctot').innerHTML = '<b>' + (money ? inr(tot) : (kind === 'visitors' ? d.total : tot).toLocaleString('en-IN')) + '</b>' + d.caption;
    svg.onmousemove = svg.onclick = function (e) {
      var t = e.target.closest('.hit'); if (!t) { tip.hidden = true; return; }
      var i = +t.dataset.i, rc = el.getBoundingClientRect(), rt = t.getBoundingClientRect();
      tip.innerHTML = '<b>' + (money ? inr(vals[i]) : vals[i] + (vals[i] === 1 ? ' visitor' : ' visitors')) + '</b>' + d.full[i];
      tip.style.left = Math.min(rc.width - 60, Math.max(60, rt.left - rc.left + rt.width / 2)) + 'px'; tip.style.top = (rt.top - rc.top + 30) + 'px'; tip.hidden = false;
    };
    svg.onmouseleave = function () { tip.hidden = true; };
  }
  var boxes = document.querySelectorAll('[data-chart]');
  boxes.forEach(function (box) {
    box.querySelector('.seg').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-r]'); if (!b) return;
      box.dataset.range = b.dataset.r; box.querySelectorAll('.seg button').forEach(function (x) { x.classList.toggle('on', x === b); }); draw(box);
    });
  });
  var all = function () { boxes.forEach(draw); };
  window.addEventListener('resize', all); all();
})();
