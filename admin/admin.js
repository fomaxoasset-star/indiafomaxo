/* FOMAXO India admin: phone switches between boxes, and the Sales / Visitors bar charts on the dashboard. */
(function () {
  /* phones: keep the open tab in view in the scrolling tab strip */
  var tabOn = document.querySelector('.mtabs .on'); if (tabOn) tabOn.parentNode.scrollLeft = tabOn.offsetLeft - (tabOn.parentNode.clientWidth - tabOn.clientWidth) / 2;

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
