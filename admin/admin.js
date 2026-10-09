/* FOMAXO India admin: phone switches between boxes, dd/mm/yyyy date boxes, the Sales / Visitors bar charts on the dashboard and the Reports line graph. */
/* posts one admin action without reloading the page (csrf from the nearest [data-csrf] box) and gives back the server's JSON answer */
function postAction(el, fields) {
  var box = el.closest('[data-csrf]') || document.querySelector('[data-csrf]'), fd = new FormData();
  fd.append('csrf', box ? box.dataset.csrf : '');
  for (var n in fields) fd.append(n, fields[n]);
  return fetch(location.pathname, {method: 'POST', body: fd, credentials: 'same-origin'}).then(function (r) { return r.json(); });
}
/* a WhatsApp button once sent: Sent dd/mm inside it (outlined); tapping it again opens the box again, and a new send puts the new date */
function markSent(btn, day) {
  var s = btn.querySelector('span'); if (!s) { s = document.createElement('span'); btn.textContent = ''; btn.appendChild(s); }
  s.textContent = 'Sent ' + day; btn.removeAttribute('title');
  btn.classList.add(btn.classList.contains('rqwa') ? 'done' : 'line');
}
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
    if (e.touches.length !== 1 || !matchMedia('(max-width:820px)').matches || document.body.classList.contains('zooming')) return;
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

  /* date boxes: typed as dd/mm/yyyy (the slashes appear by themselves), whatever date format the computer is set to;
     the calendar icon opens the date picker, which always hands back year-month-day, so day and month never swap */
  var DATE_MSG = 'Type the date as dd/mm/yyyy';
  var ymd = function (v) {   /* "06/10/2026" → "2026-10-06", or '' when it is not a real date */
    var m = /^(\d{2})\/(\d{2})\/(\d{4})$/.exec(v); if (!m) return '';
    var d = +m[1], mo = +m[2], y = +m[3], t = new Date(y, mo - 1, d);
    return y >= 2000 && y <= 2100 && t.getFullYear() === y && t.getMonth() === mo - 1 && t.getDate() === d ? m[3] + '-' + m[2] + '-' + m[1] : '';
  };
  var dateOk = function (inp) { var v = inp.value.trim(); return v === '' ? !inp.required : !!ymd(v); };
  var dateMsg = function (inp, show) {
    var box = inp.closest('.dbox'), msg = box.querySelector('.derr');
    if (show && !msg) { msg = document.createElement('small'); msg.className = 'derr'; msg.textContent = DATE_MSG; box.appendChild(msg); }
    if (!show && msg) msg.remove();
    box.classList.toggle('bad', !!show);
  };
  document.querySelectorAll('input[data-date]').forEach(function (inp) {
    var pick = inp.parentNode.querySelector('.dcal input');
    inp.addEventListener('input', function (e) {
      /* keep digits and slashes; a slash after one digit adds the 0 (6/ → 06/); a slash appears after the day and the month */
      var seg = [''], i = 0, v = inp.value, back = e.inputType && e.inputType.indexOf('delete') === 0;
      for (var c = 0; c < v.length; c++) {
        var ch = v[c];
        if (/\d/.test(ch)) { if (seg[i].length === (i < 2 ? 2 : 4)) { if (i === 2) continue; seg[++i] = ''; } seg[i] += ch; }
        else if (ch === '/' && seg[i].length && i < 2) { if (seg[i].length === 1) seg[i] = '0' + seg[i]; seg[++i] = ''; }
      }
      var out = seg.join('/'); if (!back && i < 2 && seg[i].length === 2) out += '/';
      if (out !== v) { var end = inp.selectionStart === v.length; inp.value = out; if (end) inp.setSelectionRange(out.length, out.length); }
      if (inp.closest('.dbox').classList.contains('bad') && dateOk(inp)) dateMsg(inp, false);
    });
    inp.addEventListener('blur', function () { dateMsg(inp, !dateOk(inp)); });
    if (!pick) return;
    pick.addEventListener('click', function () { pick.value = ymd(inp.value.trim()); try { pick.showPicker(); } catch (x) { /* the browser opens it itself */ } });
    pick.addEventListener('change', function () {
      if (!pick.value) return;
      var p = pick.value.split('-'); inp.value = p[2] + '/' + p[1] + '/' + p[0]; dateMsg(inp, false);
      inp.dispatchEvent(new Event('input', {bubbles: true}));
    });
  });
  /* a wrong date stops the form and says how to type it */
  document.addEventListener('submit', function (e) {
    var bad = Array.prototype.filter.call(e.target.querySelectorAll('input[data-date]'), function (i) { return !dateOk(i); });
    if (!bad.length) return;
    e.preventDefault(); e.stopPropagation();
    bad.forEach(function (i) { dateMsg(i, true); }); bad[0].focus();
  }, true);

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

  /* Order page: the Status list saves as soon as a status is picked (cancelling or refunding asks first) */
  document.querySelectorAll('select[data-autosave]').forEach(function (sel) {
    var was = sel.value;
    sel.addEventListener('change', function () {
      var no = sel.dataset.no || 'this order';
      var ask = { cancelled: 'Cancel order ' + no + '? Its items go back into stock.', refunded: 'Mark ' + no + ' as refunded? Its items go back into stock. The money itself is refunded in your Razorpay dashboard.' }[sel.value];
      if (ask && !confirm(ask)) { sel.value = was; return; }
      sel.form.requestSubmit ? sel.form.requestSubmit() : sel.form.submit();
    });
  });

  /* Orders: a one-tap button on an order's row submits it without opening or closing the order */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('summary button[form]');
    if (!b) return;
    e.preventDefault();
    var f = document.getElementById(b.getAttribute('form'));
    if (f && f.requestSubmit) f.requestSubmit(b); else if (f) { if (b.dataset.confirm && !confirm(b.dataset.confirm)) return; var i = document.createElement('input'); i.type = 'hidden'; i.name = b.name; i.value = b.value; f.appendChild(i); f.submit(); }
  });

  /* Review requests / Refill reminders: ✕ in front of a name asks first, then takes that order off the list without reloading */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-rmx]');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    if (!confirm('Remove ' + b.dataset.who + ' from ' + (b.dataset.rmx === 'ask' ? 'Review requests' : 'Refill reminders') + '? They come back with their next order.')) return;
    b.disabled = true;
    postAction(b, {action: 'list_remove', list: b.dataset.rmx, no: b.dataset.no}).then(function (j) {
      if (!j || !j.ok) throw 0;
      var row = b.closest('tr'); row.classList.add('going');
      setTimeout(function () { row.remove(); }, 250);
    }).catch(function () { b.disabled = false; alert('Could not remove it. Please reload the page and try again.'); });
  }, true);

  /* Left at checkout: ✕ on a line asks first, then removes it from the list without reloading (and from a big copy, if one is open) */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-lead]');
    if (!b) return;
    e.preventDefault(); e.stopPropagation();
    if (!confirm('Remove ' + b.dataset.who + ' from Left at checkout?')) return;
    b.disabled = true;
    postAction(b, {action: 'lead_remove', sid: b.dataset.lead, js: '1'}).then(function (j) {
      if (!j || !j.ok) throw 0;
      document.querySelectorAll('.lt[data-sid="' + b.dataset.lead + '"]').forEach(function (row) {
        var box = row.closest('.box'); row.classList.add('going');
        setTimeout(function () {
          row.remove();
          var n = box.querySelectorAll('.lt').length, c = box.querySelector('.lt-n');
          if (c) c.textContent = n + (n === 1 ? ' person' : ' people');
          if (!n && !box.querySelector('.lts .empty')) box.querySelector('.lts').insertAdjacentHTML('beforeend', '<p class="empty">Nobody left in this list for these dates.</p>');
        }, 250);
      });
    }).catch(function () { b.disabled = false; alert('Could not remove it. Please reload the page and try again.'); });
  }, true);

  /* Reviews: an emoji button under the reply box goes in where the cursor is */
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-emo]');
    if (!b) return;
    var t = b.closest('form').querySelector('textarea'), em = b.dataset.emo;
    var s = t.selectionStart == null ? t.value.length : t.selectionStart, n = t.selectionEnd == null ? s : t.selectionEnd;
    if (t.value.length - (n - s) + em.length > t.maxLength && t.maxLength > 0) return;
    t.value = t.value.slice(0, s) + em + t.value.slice(n);
    t.focus(); t.setSelectionRange(s + em.length, s + em.length);
    t.dispatchEvent(new Event('input', {bubbles: true}));
  });

  /* Reviews: Reply fills the box with a reply that fits the review (name, product, stars, what they wrote
     about); "Another reply" swaps the wording. Only a draft: nothing is saved until Save reply. */
  /* good: one short line on what they praised; bad: a clause on what FOMAXO is doing about it */
  var SG_TOPICS = [
    {k: /\b(damag|broken|leak|crack|missing|wrong (item|product)|fake|empty|fault|defect|not working|stopped working|nozzle)/i, nk: /./, good: [], bad: ['we will replace it for you right away'],
      chk: ['{h} we checked and found your product was indeed faulty.', '{h} we looked into your order and found the product was indeed faulty.'],
      fix: ['We apologize, we will replace it, and we have sent a special coupon to your WhatsApp for your next order 🙏', 'We sincerely apologize, a replacement is on its way, and FOMAXO has sent a special coupon to your WhatsApp for your next order 🙏']},
    {k: /long ?last|lasting|\blasts?\b|stays|all day|whole day|hours|longevity|fades?\b/i, nk: /not|n't|only|fades?\b|gone|less|short|weak/i, good: ['So happy it lasts all day for you', 'Glad it stays with you for hours'], bad: ['we are working to make it last longer']},
    {k: /deliver|shipping|courier|arrived|on time|dispatch|late\b|delay/i, nk: /late\b|delay|slow|took|not (yet )?(arrived|delivered|received)|never/i, good: ['Happy it reached you so quickly', 'Glad the delivery was smooth'], bad: ['we are making our deliveries faster'],
      chk: ['{h} we checked and found your delivery was indeed late.', '{h} we looked into your order and found the delivery was indeed late.'],
      fix: ['We apologize, and we have sent a special coupon to your WhatsApp for your next order 🙏', 'We sincerely apologize, and FOMAXO has sent an exclusive coupon to your WhatsApp for your next order 🙏']},
    {k: /smell|scent|fragrance|aroma|perfume|notes?\b|fresh/i, nk: /(not|n't|no)\s+(like|nice|good|great|pleasant)|bad|harsh|too strong|weird|chemical|headache|alcohol/i, good: ['So glad you love the scent', 'Happy the fragrance won you over'], bad: ['your words on the scent go straight to our perfumers']},
    {k: /pack(ing|aging|aged)?\b|\bbox|bottle|wrap/i, nk: /broken|damag|poor|bad|torn|loose|cheap/i, good: ['Glad the packaging made it feel special', 'So happy you loved the box'], bad: ['we are improving our packing']},
    {k: /skin|lips?\b|moistur|glow|soft|smooth/i, nk: /rash|itch|irritat|burn|dry|sticky|allerg/i, good: ['So glad it feels lovely on your skin'], bad: ['we are looking into this right away']},
    {k: /compliment|praise|everyone (asked|loved|likes)|asked me/i, nk: /^$/, good: ['Enjoy all the compliments', 'The compliments are well deserved'], bad: []},
    {k: /gift|birthday|anniversary|wife|husband|girlfriend|boyfriend|mom\b|mother|dad\b|father|sister|brother/i, nk: /^$/, good: ['So lovely that it made a special gift', 'Glad it made the gift memorable'], bad: []},
    {k: /price|value|worth|afford|money|expensive|costly|overpriced/i, nk: /expensive|costly|overpriced|too much|not worth/i, good: ['Glad it feels worth every rupee'], bad: ['we are taking your feedback on price to heart']}
  ];
  var SG_GOOD_OPEN = ['We are thrilled you love {p}{n}!', 'Thank you{n}, so happy {p} is a hit with you!', '{h} thank you for the lovely words about {p}!', 'Thank you for choosing {p}{n}!'];
  var SG_GOOD_END = ['See you again soon 🙏', 'Your support means the world to FOMAXO ❤️', 'Enjoy it ✨'];
  var SG_EMO = [' 🙏', ' ✨', ' ❤️'];
  var SG_BAD_OPEN = ['We sincerely apologize{n}', 'We apologize{n}', '{h} we apologize'];
  var SG_BAD_NOHIT = ['We sincerely apologize that {p} did not meet your expectations{n}.', '{h} we apologize, and we are working to make {p} better for you.'];
  var SG_BAD_END = ['As our apology, FOMAXO has sent a special coupon to your WhatsApp for your next order 🙏', 'As a small token of our apology, we have sent a coupon to your WhatsApp 🎁', 'To make it up to you, we have sent an exclusive coupon to your WhatsApp for your next order 🙏'];
  var SG_OK_OPEN = ['Thank you for your honest review{n}', '{h} thank you for your feedback'];
  function sgPick(a, i) { return a.length ? a[i % a.length] : ''; }
  /* always one or two sentences */
  function sgReply(f, i) {
    var name = (f.dataset.sgName || '').trim().split(/\s+/)[0] || '', stars = +f.dataset.sgStars || 5, body = f.dataset.sgBody || '';
    if (name) name = name.charAt(0).toUpperCase() + name.slice(1).toLowerCase();
    var p = f.dataset.sgProduct ? 'FOMAXO ' + f.dataset.sgProduct : 'FOMAXO', good = stars >= 4;
    var fill = function (s) { return s.replace('{n}', name ? ', ' + name : '').replace('{h}', name ? 'Hi ' + name + ',' : 'Hi,').replace('{p}', p); };
    /* a good review uses only a topic it praises, a poor one only a topic it complains about */
    var hits = SG_TOPICS.filter(function (t) { return t.k.test(body) && (good ? t.good : t.bad).length && (good ? !t.nk.test(body) : t.nk.test(body)); });
    var hit = (!good && hits.filter(function (t) { return t.chk; })[0]) || hits[0];
    var bit = hit ? sgPick(good ? hit.good : hit.bad, i) : '';
    if (good) return fill(sgPick(SG_GOOD_OPEN, i)) + ' ' + (bit ? bit + sgPick(SG_EMO, i) : sgPick(SG_GOOD_END, i));
    /* late delivery or a faulty product: tell them we checked and they are right, then a coupon */
    if (hit && hit.chk) return fill(sgPick(hit.chk, i)) + ' ' + sgPick(hit.fix, i);
    if (stars === 3) return fill(sgPick(SG_OK_OPEN, i)) + ', and ' + (bit || 'we are always working to make ' + p + ' better') + '. As a thank you, FOMAXO has sent a special coupon to your WhatsApp for your next order 🙏';
    return (bit ? fill(sgPick(SG_BAD_OPEN, i)) + ', and ' + bit + '.' : fill(sgPick(SG_BAD_NOHIT, i))) + ' ' + sgPick(SG_BAD_END, i);
  }
  function sgFill(f, next) {
    var t = f.querySelector('textarea');
    f.sgI = next ? (f.sgI || 0) + 1 : Math.floor(Math.random() * 12);
    t.value = sgReply(f, f.sgI).slice(0, t.maxLength > 0 ? t.maxLength : 1000);
    t.style.height = 'auto'; t.style.height = t.scrollHeight + 2 + 'px'; /* the whole draft shows, no scrolling inside the box */
    t.focus(); t.setSelectionRange(t.value.length, t.value.length);
    t.dispatchEvent(new Event('input', {bubbles: true}));
  }
  document.addEventListener('change', function (e) {
    if (!e.target.matches || !e.target.matches('.rvr-tg') || !e.target.checked) return;
    var f = e.target.parentNode.querySelector('.rvr-form');
    if (f && f.dataset.sgName != null && !f.querySelector('textarea').value.trim()) sgFill(f, false);
  });
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest('[data-sg-next]');
    if (b) sgFill(b.closest('form'), true);
    var c = e.target.closest && e.target.closest('[data-sg-clear]');
    if (c) { var t = c.closest('form').querySelector('textarea'); t.value = ''; t.style.height = ''; t.focus(); t.dispatchEvent(new Event('input', {bubbles: true})); }
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

  /* analytics: tap any number tile or box to open it big (full screen on phones); ✕, Esc, a tap outside or Back closes it */
  var an = document.querySelector('.an');
  if (an) {
    var zoomer = null;
    var zoomables = Array.prototype.slice.call(document.querySelectorAll('.kpis.strip>.kpi, .an>.box'));
    var shutZoom = function (fromHistory) {
      if (!zoomer) return;
      zoomer.remove(); zoomer = null; document.body.classList.remove('zooming');
      if (!fromHistory && history.state && history.state.zoom) history.back();
    };
    var openZoom = function (src) {
      shutZoom(true);
      var copy;
      if (src.classList.contains('kpi')) {
        /* a number tile opens all the tiles big, with the one tapped lit up */
        copy = document.createElement('div'); copy.className = 'box zkpis';
        var dates = document.querySelector('.dbar .dspan');
        copy.innerHTML = '<div class="bh"><h3>At a glance</h3></div><div class="bb"><div class="zgrid"></div>' + (dates ? '<p class="muted small"></p>' : '') + '</div>';
        if (dates) copy.querySelector('p').textContent = dates.textContent.replace(/^\. ?/, '');
        src.parentNode.querySelectorAll('.kpi').forEach(function (k) {
          var c = k.cloneNode(true); c.classList.remove('zoomable'); c.removeAttribute('tabindex'); c.removeAttribute('role'); c.removeAttribute('title');
          if (k === src) c.classList.add('on');
          copy.querySelector('.zgrid').appendChild(c);
        });
      } else copy = src.cloneNode(true);
      copy.classList.add('zbox'); copy.classList.remove('on');
      copy.querySelectorAll('.zopen').forEach(function (b) { b.remove(); });
      var x = document.createElement('button'); x.type = 'button'; x.className = 'zx'; x.setAttribute('aria-label', 'Close'); x.textContent = '✕';
      var head = copy.querySelector('.bh');
      if (head) head.appendChild(x); else copy.appendChild(x);
      zoomer = document.createElement('div'); zoomer.className = 'zoomer'; zoomer.setAttribute('role', 'dialog'); zoomer.setAttribute('aria-modal', 'true');
      zoomer.appendChild(copy); document.body.appendChild(zoomer); document.body.classList.add('zooming');
      zoomer.addEventListener('click', function (e) { if (e.target === zoomer || e.target.closest('.zx')) shutZoom(); });
      history.pushState({zoom: 1}, '');
      x.focus();
    };
    zoomables.forEach(function (el) {
      el.classList.add('zoomable');
      if (el.classList.contains('kpi')) { el.tabIndex = 0; el.setAttribute('role', 'button'); el.title = 'Open bigger'; }
      else {
        var b = document.createElement('button'); b.type = 'button'; b.className = 'zopen'; b.title = 'Open bigger'; b.setAttribute('aria-label', 'Open bigger'); b.textContent = '⤢';
        var bh = el.querySelector('.bh'); if (bh) bh.appendChild(b);
      }
      el.addEventListener('click', function (e) {
        if (e.target.closest('.zopen')) { openZoom(el); return; }
        if (e.target.closest('a,button,input,select,textarea,label,form,.seg,details')) return;   /* a Left at checkout line opens in place */
        if (getSelection && String(getSelection()).length) return;   /* selecting text to copy, not opening */
        openZoom(el);
      });
      el.addEventListener('keydown', function (e) { if ((e.key === 'Enter' || e.key === ' ') && e.target === el) { e.preventDefault(); openZoom(el); } });
    });
    document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && zoomer) shutZoom(); });
    window.addEventListener('popstate', function () { shutZoom(true); });
  }

  /* charts: the dashboard's Sales / Visitors bars for the dates picked, and the Reports line graph */
  var inr = function (p) { var r = p / 100, neg = r < 0; r = Math.abs(r); return (neg ? '−' : '') + '₹' + r.toLocaleString('en-IN', {maximumFractionDigits: r < 100 ? 2 : 0}); };
  var short = function (v, money) {
    var r = Math.abs(money ? v / 100 : v), neg = v < 0;
    var s = r >= 1e7 ? (r / 1e7).toFixed(1).replace(/\.0$/, '') + 'Cr' : r >= 1e5 ? (r / 1e5).toFixed(1).replace(/\.0$/, '') + 'L' : r >= 1e3 ? (r / 1e3).toFixed(1).replace(/\.0$/, '') + 'k' : String(Math.round(r));
    return (neg ? '−' : '') + (money ? '₹' : '') + s;
  };
  var nice = function (max) { if (max <= 0) return 1; var p = Math.pow(10, Math.floor(Math.log10(max))), f = max / p; return (f <= 1 ? 1 : f <= 2 ? 2 : f <= 5 ? 5 : 10) * p; };
  var showTip = function (el, tip, html, x, y) {
    var rc = el.getBoundingClientRect();
    tip.innerHTML = html; tip.style.left = Math.min(rc.width - 60, Math.max(60, x - rc.left)) + 'px'; tip.style.top = (y - rc.top) + 'px'; tip.hidden = false;
  };
  var charts = [];

  var dataEl = document.getElementById('chartData');
  if (dataEl) {
    var DATA = JSON.parse(dataEl.textContent);
    var drawBars = function (box) {
      var kind = box.dataset.chart, d = DATA[kind], money = kind === 'sales';
      var el = box.querySelector('.chart'), svg = el.querySelector('svg'), tip = el.querySelector('.tip');
      var W = el.clientWidth - 24, H = el.clientHeight - 14; if (W < 50 || H < 50) return;
      var padL = 46, padB = 20, padT = 8, iw = W - padL - 4, ih = H - padB - padT;
      var vals = d.values, n = vals.length, max = nice(Math.max.apply(null, vals.concat([money ? 100 : 2]))), gap = n > 40 ? 1 : n > 20 ? 2 : 4;
      if (!money && max % 2) max += 1;
      var bw = Math.max(1, iw / n - gap), out = '';
      for (var g = 0; g <= 2; g++) {
        var y = padT + ih - ih * g / 2;
        out += '<line x1="' + padL + '" x2="' + (W - 4) + '" y1="' + y + '" y2="' + y + '" style="stroke:var(--line)" stroke-width="1"/>'
          + '<text x="' + (padL - 8) + '" y="' + (y + 4) + '" text-anchor="end" style="fill:var(--muted)" font-size="11">' + short(max * g / 2, money) + '</text>';
      }
      var every = Math.ceil(n / (W < 420 ? 6 : 12));
      vals.forEach(function (v, i) {
        var x = padL + i * (iw / n) + gap / 2, h = v > 0 ? Math.max(2, ih * v / max) : 0, y = padT + ih - h, r = Math.min(4, bw / 2, h);
        if (h > 0) out += '<path d="M' + x + ',' + (padT + ih) + 'V' + (y + r) + 'Q' + x + ',' + y + ' ' + (x + r) + ',' + y + 'H' + (x + bw - r) + 'Q' + (x + bw) + ',' + y + ' ' + (x + bw) + ',' + (y + r) + 'V' + (padT + ih) + 'Z" style="fill:var(--gold)"/>';
        out += '<rect class="hit" data-i="' + i + '" x="' + (padL + i * iw / n) + '" y="' + padT + '" width="' + (iw / n) + '" height="' + ih + '" fill="transparent"/>';
        if (i % every === 0) out += '<text x="' + (x + bw / 2) + '" y="' + (H - 4) + '" text-anchor="middle" style="fill:var(--muted)" font-size="11">' + d.labels[i] + '</text>';
      });
      svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.innerHTML = out;
      var tot = vals.reduce(function (a, b) { return a + b; }, 0);
      box.querySelector('.ctot').innerHTML = '<b>' + (money ? inr(tot) : d.total.toLocaleString('en-IN')) + '</b>';
      box.querySelector('.ctot').appendChild(document.createTextNode(box.dataset.caption || ''));
      svg.onmousemove = svg.onclick = function (e) {
        var t = e.target.closest('.hit'); if (!t) { tip.hidden = true; return; }
        var i = +t.dataset.i, rt = t.getBoundingClientRect();
        showTip(el, tip, '<b>' + (money ? inr(vals[i]) : vals[i] + (vals[i] === 1 ? ' visitor' : ' visitors')) + '</b>' + d.full[i], rt.left + rt.width / 2, rt.top + 30);
      };
      svg.onmouseleave = function () { tip.hidden = true; };
    };
    document.querySelectorAll('[data-chart]').forEach(function (box) { charts.push(function () { drawBars(box); }); });
  }

  /* Reports: a gold line with a dot for each day or month; tap a dot for its amount; the total shows at the bottom right */
  var gEl = document.getElementById('repGraph');
  if (gEl) {
    var G = JSON.parse(gEl.textContent), gbox = gEl.closest('.rgraph');
    var drawLine = function () {
      var key = gbox.dataset.g, money = key !== 'orders', vals = G[key], n = vals.length;
      var el = gbox.querySelector('.chart'), svg = el.querySelector('svg'), tip = el.querySelector('.tip'); tip.hidden = true;
      var W = el.clientWidth - 24, H = el.clientHeight - 14; if (W < 50 || H < 50) return;
      var padL = 50, padR = 12, padB = 20, padT = 12, iw = W - padL - padR, ih = H - padB - padT;
      var hi = nice(Math.max.apply(null, vals.concat([money ? 100 : 2]))), lo = Math.min.apply(null, vals.concat([0]));
      lo = lo < 0 ? -nice(-lo) : 0;
      var X = function (i) { return padL + (n === 1 ? iw / 2 : iw * i / (n - 1)); }, Y = function (v) { return padT + ih - ih * (v - lo) / (hi - lo); };
      var out = '', steps = lo < 0 ? [lo, 0, hi] : [0, hi / 2, hi];
      steps.forEach(function (v) {
        out += '<line x1="' + padL + '" x2="' + (W - padR) + '" y1="' + Y(v) + '" y2="' + Y(v) + '" style="stroke:var(' + (v === 0 && lo < 0 ? '--muted' : '--line') + ')" stroke-width="1"/>'
          + '<text x="' + (padL - 8) + '" y="' + (Y(v) + 4) + '" text-anchor="end" style="fill:var(--muted)" font-size="11">' + short(v, money) + '</text>';
      });
      var pts = vals.map(function (v, i) { return X(i).toFixed(1) + ',' + Y(v).toFixed(1); });
      out += '<polyline points="' + pts.join(' ') + '" fill="none" style="stroke:var(--gold)" stroke-width="2" stroke-linejoin="round" stroke-linecap="round"/>';
      var every = Math.ceil(n / (W < 420 ? 6 : 12)), r = n > 60 ? 2.5 : 3.5;
      vals.forEach(function (v, i) {
        out += '<circle class="dot" data-i="' + i + '" cx="' + X(i) + '" cy="' + Y(v) + '" r="' + r + '" style="fill:var(' + (v < 0 ? '--red' : '--gold2') + ');stroke:var(--panel)" stroke-width="1.5"/>';
        var w = n === 1 ? iw : iw / (n - 1);
        out += '<rect class="hit" data-i="' + i + '" x="' + (X(i) - w / 2) + '" y="' + padT + '" width="' + w + '" height="' + ih + '" fill="transparent"/>';
        if (i % every === 0) out += '<text x="' + X(i) + '" y="' + (H - 4) + '" text-anchor="' + (i === 0 && n > 1 ? 'start' : 'middle') + '" style="fill:var(--muted)" font-size="11">' + G.labels[i] + '</text>';
      });
      svg.setAttribute('viewBox', '0 0 ' + W + ' ' + H); svg.innerHTML = out;
      var tot = vals.reduce(function (a, b) { return a + b; }, 0);
      gbox.querySelector('.gtot b').textContent = money ? inr(tot) : tot.toLocaleString('en-IN') + (tot === 1 ? ' order' : ' orders');
      gbox.querySelector('.gtot b').className = key === 'profit' && tot < 0 ? 'neg' : '';
      svg.onclick = svg.onmousemove = function (e) {
        var t = e.target.closest('.hit'); if (!t) { tip.hidden = true; return; }
        var i = +t.dataset.i, d = svg.querySelector('.dot[data-i="' + i + '"]'), rd = d.getBoundingClientRect();
        svg.querySelectorAll('.dot.on').forEach(function (x) { x.classList.remove('on'); x.setAttribute('r', r); }); d.classList.add('on'); d.setAttribute('r', r + 2);
        showTip(el, tip, '<b>' + (money ? inr(vals[i]) : vals[i] + (vals[i] === 1 ? ' order' : ' orders')) + '</b>' + G.full[i], rd.left + rd.width / 2, rd.top - 4);
      };
      svg.onmouseleave = function () { tip.hidden = true; };
    };
    gbox.querySelector('.gm').addEventListener('click', function (e) {
      var b = e.target.closest('button[data-g]'); if (!b) return;
      gbox.dataset.g = b.dataset.g; gbox.querySelectorAll('.gm button').forEach(function (x) { x.classList.toggle('on', x === b); }); drawLine();
    });
    charts.push(drawLine);
  }
  var all = function () { charts.forEach(function (f) { f(); }); };
  window.addEventListener('resize', all); all();
})();

/* product edit page: ‹ › move a photo, Make main puts it first; the hidden photo_seq[] inputs follow, so Save keeps the order */
(function () {
  var box = document.getElementById('phs'); if (!box) return;
  var mark = function () {
    box.querySelectorAll('.ph').forEach(function (ph, i, all) {
      ph.classList.toggle('main', i === 0); ph.querySelector('.phl').textContent = i ? 'Photo ' + (i + 1) : 'Main photo';
      ph.querySelector('[data-mv="-1"]').disabled = i === 0; ph.querySelector('.mk').disabled = i === 0; ph.querySelector('[data-mv="1"]').disabled = i === all.length - 1;
    });
  };
  box.addEventListener('click', function (e) {
    var b = e.target.closest('[data-mv]'); if (!b) return;
    var ph = b.closest('.ph'), mv = b.dataset.mv;
    if (mv === '0') box.insertBefore(ph, box.firstChild);
    else if (mv === '-1' && ph.previousElementSibling) box.insertBefore(ph, ph.previousElementSibling);
    else if (mv === '1' && ph.nextElementSibling) box.insertBefore(ph.nextElementSibling, ph);
    mark(); ph.querySelector('input').dispatchEvent(new Event('input', {bubbles: true}));
  });
  mark();
})();

(function () {
  /* Offer: the end date shows only for "Countdown" */
  var ofr = document.getElementById('offerForm');
  if (ofr) {
    var ofShow = function () { var m = (ofr.querySelector('input[name=mode]:checked') || {}).value;
      ofr.querySelector('.ofend').hidden = m !== 'end'; };
    ofr.addEventListener('change', function (e) { if (e.target.name === 'mode') ofShow(); });
    /* Products (popup and line): type or pick a name to add it; untick a picked one to take it out */
    ofr.querySelectorAll('.ofpick').forEach(function (pk) {
      var add = pk.querySelector('.ofadd');
      add.addEventListener('input', function () {
        var v = add.value.trim().toLowerCase(), hit = [].slice.call(pk.querySelectorAll('.ofit input')).filter(function (i) { return i.dataset.name.toLowerCase() === v; })[0];
        if (hit) { hit.checked = true; add.value = ''; hit.dispatchEvent(new Event('change', {bubbles: true})); }
      });
    });
    /* Line by prices: "Same as popup" picks the popup's products */
    var same = ofr.querySelector('[data-ofsame]');
    if (same) same.addEventListener('click', function () {
      ofr.querySelectorAll('input[name="lines[]"]').forEach(function (i) { i.checked = !!ofr.querySelector('input[name="items[]"][value="' + i.value + '"]:checked'); });
      ofr.dispatchEvent(new Event('change', {bubbles: true}));
    });
  }
})();

/* Offer: Preview shows the popup as shoppers will see it, from what is typed and ticked now (nothing is saved) */
(function () {
  var sale = document.getElementById('offerForm'), newp = document.getElementById('newpForm');
  if (!sale && !newp) return;
  var esc = function (s) { return String(s).replace(/[&<>"]/g, function (c) { return {'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;'}[c]; }); };
  var val = function (f, n) { var e = f.querySelector('[name="' + n + '"]'); return e ? e.value.trim() : ''; };
  var pad = function (n) { return (n < 10 ? '0' : '') + n; };
  var dark = true, which = 'sale', box = null, tick = null, phone = matchMedia('(max-width:820px)').matches;
  /* the popup's product picture size, laptop and phone: kept in the form's hidden size_l / size_p, so Save puts it on the website */
  /* what the size bar can size in each popup: [key, button, CSS letter] (the sale popup's products are px, the rest % of standard) */
  var PARTS = {sale: [['img', 'Products', ''], ['title', 'Top line', 'k'], ['pct', '% off', 'p'], ['sub', 'Under the %', 's'], ['btn', 'Button', 'b']],
    'new': [['title', 'Top line', 'k'], ['img', 'Picture', 'i'], ['name', 'Name', 'n'], ['line', 'Short line', 's'], ['btn', 'Button', 'b']]};
  var part = which === 'new' ? 'title' : 'img';
  var sizeIn = function (k, ph) { k = k || part; ph = ph === undefined ? phone : ph; var d = ph ? 'p' : 'l';
    if (which === 'new') return newp && newp.querySelector('input[name="nfs[' + d + '][' + k + ']"]');
    return sale && sale.querySelector(k === 'img' ? 'input[name="size_' + d + '"]' : 'input[name="fs[' + d + '][' + k + ']"]'); };
  var sizeWord = function (i) { var lo = +i.dataset.min, hi = +i.dataset.max, f = (+i.value - lo) / (hi - lo);
    if (part !== 'img' || which === 'new') return +i.value === 100 ? 'Standard' : i.value + '%';
    return (+i.value === +i.dataset.def ? 'Standard' : f < .25 ? 'Small' : f < .5 ? 'Medium' : f < .75 ? 'Large' : 'Extra large'); };
  /* the end typed on the page (dd/mm/yyyy and a time, India time) as a moment, or 0 */
  var endAt = function () {
    var m = /^(\d{1,2})\/(\d{1,2})\/(\d{4})$/.exec(val(sale, 'end')); if (!m) return 0;
    var t = /^(\d{2}):(\d{2})$/.exec(val(sale, 'end_time')) || [0, '23', '59'];
    return Date.UTC(+m[3], +m[2] - 1, +m[1], +t[1], +t[2]) - 330 * 60000;
  };
  var saleHTML = function () {
    var mode = (sale.querySelector('input[name=mode]:checked') || {}).value;
    var picked = [].slice.call(sale.querySelectorAll('input[name="items[]"]:checked'));
    var best = picked.length ? Math.max.apply(null, picked.map(function (i) { return +i.dataset.pct; })) : +sale.dataset.best;
    var typed = parseInt(val(sale, 'pct'), 10), pct = typed > 0 ? Math.min(typed, best) : best;
    var note = mode === 'off' ? 'The timer is Off, so this popup will not show.' : !sale.querySelector('input[name=popup]').checked ? 'Popup is not ticked, so this popup will not show.' : typed > best ? 'You typed ' + typed + '%, but the biggest real saving is ' + best + '%, so it shows ' + best + '%.' : '';
    var end = endAt(), left = Math.max(0, Math.floor((end - Date.now()) / 1000));
    var cd = mode === 'end' ? '<div class="pv-cd">' + [Math.floor(left / 86400), pad(Math.floor(left % 86400 / 3600)), pad(Math.floor(left % 3600 / 60)), pad(left % 60)].map(function (n, i) {
      return '<div><b>' + (end ? n : '–') + '</b><span>' + ['Days', 'Hours', 'Min', 'Sec'][i] + '</span></div>'; }).join('') + '</div>' : '';
    var items = picked.length ? '<div class="pv-items">' + picked.map(function (i) { var d = i.dataset;
      return '<div class="pv-item">' + (d.img ? '<img src="' + esc(d.img) + '" alt="">' : '<span class="pv-noimg"></span>') + '<b>' + esc(d.name) + '</b><span>' + (d.was ? '<s>' + esc(d.was) + '</s> ' : '') + esc(d.price) + '</span></div>'; }).join('') + '</div>' : '';
    return [note, '<p class="pv-k">' + esc(val(sale, 'title') || 'Limited time offer') + '</p>'
      + (best ? '<p class="pv-pct">' + pct + '% off</p>' : '<p class="pv-on">No product has an old price yet, so this popup stays hidden.</p>')
      + '<p class="pv-on">' + esc((val(sale, 'sub') || 'on selected fragrances').toUpperCase()) + '</p>' + items + cd
      + '<p class="pv-hurry">HURRY UP!!!</p><span class="pv-go">' + esc(val(sale, 'btn') || 'Shop the offer') + '</span><span class="pv-no">No thanks</span>'];
  };
  /* the line by sale prices: on a shop card for each product picked for the line, and on a product page */
  var lineHTML = function () {
    var mode = (sale.querySelector('input[name=mode]:checked') || {}).value;
    var picked = [].slice.call(sale.querySelectorAll('input[name="lines[]"]:checked'));
    var note = mode === 'off' ? 'The timer is Off, so the line will not show.' : !picked.length ? 'No product is picked for the line, so it will not show.' : '';
    if (!picked.length) return [note, '<p class="pv-on">Pick products under Line by prices.</p>'];
    var end = endAt(), left = Math.max(0, Math.floor((end - Date.now()) / 1000));
    var cd = mode === 'end' ? '<b>Ends in ' + Math.floor(left / 86400) + 'D ' + pad(Math.floor(left % 86400 / 3600)) + 'H ' + pad(Math.floor(left % 3600 / 60)) + 'M ' + pad(left % 60) + 'S</b>' : '';
    var clock = '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="9" fill="none" stroke="currentColor" stroke-width="1.7"/><path d="M12 7v5l3.2 2" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>';
    var ln = function (cls) { return '<div class="pv-ofl ' + cls + (cd ? '' : ' nocd') + '"><span>' + clock + esc(val(sale, 'title') || 'Limited time offer') + '</span>' + cd + '</div>'; };
    var price = function (d) { return (d.was ? '<s>' + esc(d.was) + '</s> ' : '') + '<span>' + esc(d.price) + '</span>'; };
    return [note, '<p class="pv-lab">Shop cards (' + picked.length + ' product' + (picked.length > 1 ? 's' : '') + ')</p><div class="pv-cards">' + picked.map(function (i) { var d = i.dataset;
        return '<div class="pv-card">' + (d.img ? '<img src="' + esc(d.img) + '" alt="">' : '<span class="pv-noimg"></span>') + '<b>' + esc(d.name) + '</b><p class="pv-price">' + price(d) + '</p>' + ln('cardl') + '</div>'; }).join('') + '</div>'
      + '<p class="pv-lab">Product page</p><p class="pv-price big">' + price(picked[0].dataset) + '</p>' + ln('boxl')];
  };
  var newHTML = function () {
    var sel = newp.querySelector('select[name=np_id]'), opt = sel.options[sel.selectedIndex], img = opt ? opt.dataset.img : '';
    var isNew = !!(opt && opt.value && !/ \(hidden\)$/.test(opt.textContent)), name = val(newp, 'np_name') || (opt && opt.value ? opt.textContent.replace(/ \(hidden\)$/, '') : '');
    var note = !document.querySelector('input[name=np_on]').checked ? 'On is not ticked, so this popup will not show.' : !name ? 'Write the product name, or pick the product.' : '';
    return [note, '<p class="pv-k">' + esc(val(newp, 'np_label') || 'Coming soon') + '</p>' + (img ? '<img class="pv-img" src="' + esc(img) + '" alt="">' : '')
      + '<p class="pv-name">' + esc(name || 'Product name') + '</p>' + (val(newp, 'np_line') ? '<p class="pv-on">' + esc(val(newp, 'np_line')) + '</p>' : '')
      + '<span class="pv-go">' + (isNew ? 'Shop now' : 'Explore FOMAXO') + '</span><span class="pv-no">Close</span>'];
  };
  var draw = function () {
    if (!box) return;
    var r = which === 'sale' ? saleHTML() : which === 'line' ? lineHTML() : newHTML();
    box.querySelector('.pv-note').textContent = r[0]; box.querySelector('.pv-note').hidden = !r[0];
    var site = box.querySelector('.pv-site'); site.classList.toggle('light', !dark);
    /* the size bar shows for both popups (not the line); the preview is drawn at laptop or phone width */
    var P = PARTS[which], sized = !!P && (which === 'new' ? !!newp : !!sale), sz = box.querySelector('.pv-size');
    if (sized && !P.some(function (x) { return x[0] === part; })) part = P[0][0];
    var si = sized ? sizeIn() : null;
    if (sz) sz.hidden = !sized;
    if (sized) { var ps = sz.querySelector('.pv-parts');
      if (ps.dataset.for !== which) { ps.dataset.for = which; ps.innerHTML = P.map(function (x) { return '<button type="button" data-pvpart="' + x[0] + '">' + x[1] + '</button>'; }).join(''); }
      var r0 = sz.querySelector('input[type=range]');
      r0.min = si.dataset.min; r0.max = si.dataset.max; r0.value = si.value; sz.querySelector('.pv-sw').textContent = sizeWord(si);
      sz.querySelector('[data-pvreset]').disabled = +si.value === +si.dataset.def; }
    site.classList.toggle('phone', sized && phone); site.classList.toggle('laptop', sized && !phone);
    site.style.cssText = !sized ? '' : (which === 'sale' ? '--ofw:' + sizeIn('img').value + 'px;--ofn:' + Math.max(1, sale.querySelectorAll('input[name="items[]"]:checked').length) : '')
      + P.filter(function (x) { return x[2]; }).map(function (x) { return ';--f' + x[2] + ':' + sizeIn(x[0]).value / 100; }).join('');
    box.querySelectorAll('[data-pvpart]').forEach(function (b) { b.classList.toggle('on', b.dataset.pvpart === part); });
    site.querySelector('.pv-box').classList.toggle('pv-plain', which === 'line');
    site.querySelector('.pv-box').innerHTML = (which === 'line' ? '' : '<span class="pv-x">×</span>') + r[1];
    box.querySelectorAll('[data-pvmode]').forEach(function (b) { b.classList.toggle('on', (b.dataset.pvmode === 'dark') === dark); });
    box.querySelectorAll('[data-pvwhich]').forEach(function (b) { b.classList.toggle('on', b.dataset.pvwhich === which); });
    box.querySelectorAll('[data-pvdev]').forEach(function (b) { b.classList.toggle('on', (b.dataset.pvdev === 'phone') === phone); });
  };
  var close = function () { if (box) { box.remove(); box = null; clearInterval(tick); document.removeEventListener('keydown', key); } };
  var key = function (e) { if (e.key === 'Escape') close(); };
  var open = function (w) {
    which = w; close();
    box = document.createElement('div'); box.className = 'pvw';
    box.innerHTML = '<div class="pv-bar"><span class="seg">' + (sale ? '<button type="button" data-pvwhich="sale">Sale popup</button><button type="button" data-pvwhich="line">Line by prices</button>' : '') + (newp ? '<button type="button" data-pvwhich="new">New product popup</button>' : '')
      + '</span><span class="seg"><button type="button" data-pvmode="dark">Dark</button><button type="button" data-pvmode="light">Light</button></span><button type="button" class="btn sm" data-pvclose>Close preview</button></div>'
      + '<div class="pv-size" hidden><span class="seg"><button type="button" data-pvdev="laptop">Laptop</button><button type="button" data-pvdev="phone">Phone</button></span>'
        + '<span class="seg pv-parts"></span>'
        + '<label>Size<input type="range" step="5" aria-label="Size in the popup"></label><b class="pv-sw"></b>'
        + '<button type="button" class="btn line sm" data-pvreset>Reset</button><button type="button" class="btn sm" data-pvsave>Save</button></div>'
      + '<p class="pv-note"></p><div class="pv-site"><div class="pv-box"></div></div><p class="muted small pv-foot">Preview only. Press Save on the page to put it on the website.</p>';
    box.addEventListener('click', function (e) {
      var t = e.target.closest('button'); if (e.target === box || (t && t.hasAttribute('data-pvclose'))) { close(); return; }
      if (t && t.dataset.pvmode) { dark = t.dataset.pvmode === 'dark'; draw(); }
      if (t && t.dataset.pvwhich) { which = t.dataset.pvwhich; draw(); }
      if (t && t.dataset.pvdev) { phone = t.dataset.pvdev === 'phone'; draw(); }
      if (t && t.dataset.pvpart) { part = t.dataset.pvpart; draw(); }
      if (t && t.hasAttribute('data-pvreset')) { sizeIn().value = sizeIn().dataset.def; draw(); }
      if (t && t.hasAttribute('data-pvsave')) (which === 'new' ? newp : sale).requestSubmit();
    });
    box.addEventListener('input', function (e) { if (e.target.type === 'range') { sizeIn().value = e.target.value; draw(); } });
    document.body.appendChild(box); document.addEventListener('keydown', key); draw();
    tick = setInterval(function () { if (which !== 'new') draw(); }, 1000);
  };
  document.addEventListener('click', function (e) { var b = e.target.closest && e.target.closest('[data-preview]'); if (b) open(b.dataset.preview); });
})();

/* The WhatsApp box (Left at checkout, Refill reminders): a ready message to one customer. No coupon, or % off / ₹ off / a free product with an
   optional minimum order, for which a new code is made (one use, only their mobile) when Open WhatsApp is tapped. The message can be changed first.
   o.text(button data, coupon or null) writes the message; o.action is asked on the server; o.always asks it even without a coupon; o.done(reply, button). */
function waBox(id, sel, o) {
  var dlg = document.getElementById(id); if (!dlg) return;
  var q = function (s) { return dlg.querySelector(s); }, kind = q('.ltwa-kind'), val = q('.ltwa-val input'), free = q('.ltwa-free [data-ffind]'),
    freeV = q('.ltwa-free input[type=hidden]'), min = q('.ltwa-min input'), text = q('.ltwa-text'), err = q('.ltwa-err'), send = q('[data-ltwa-send]'),
    K0 = kind.value, PCT = dlg.dataset.pct || '10', btn = null;
  function build() {
    var k = kind.value, v = parseInt(val.value, 10) || 0, m = parseInt(min.value, 10) || 0;
    dlg.dataset.kind = k;
    text.value = o.text(btn.dataset, k ? {
      gift: k === 'free' ? 'a *free ' + (free.value.split(' · ')[0] || 'gift') + '* with' : '*' + (k === 'pct' ? (v || PCT) + '% off' : '₹' + (v || 200) + ' off') + '*',
      min: m ? ' of ₹' + m.toLocaleString('en-IN') + ' or more' : ''} : null);
  }
  function wa(t) { return 'https://wa.me/' + (btn.dataset.phone ? '91' + btn.dataset.phone : '') + '?text=' + encodeURIComponent(t); }   // no mobile (a coupon for anyone): WhatsApp asks who
  document.addEventListener('click', function (e) {
    var b = e.target.closest && e.target.closest(sel); if (!b) return;
    e.preventDefault(); e.stopPropagation();   // a Left at checkout button sits in the line's summary: don't open the line
    btn = b; q('.ltwa-who').textContent = b.dataset.who;
    var noc = b.hasAttribute('data-nocoupon'); dlg.classList.toggle('noc', noc);   // the message already carries its coupon: no choice
    kind.value = noc ? '' : K0; val.value = ''; min.value = dlg.dataset.min || ''; free.value = ''; freeV.value = ''; err.hidden = true; send.disabled = false; build(); dlg.showModal();
  });
  kind.addEventListener('change', build); val.addEventListener('input', build); min.addEventListener('input', build); free.addEventListener('change', build);
  q('[data-ltwa-close]').addEventListener('click', function () { dlg.close(); });
  send.addEventListener('click', function () {
    var k = kind.value; err.hidden = true;
    if (!k && !o.always) { window.open(wa(text.value), '_blank'); dlg.close(); return; }
    var w = window.open('', '_blank');   // opened now, while the tap counts, so the browser lets it through; WhatsApp loads in it once the server answers
    var f = o.fields(btn.dataset);
    f.action = o.action; f.gkind = k; f.pct = val.value || (k === 'pct' ? PCT : '200'); f.gfree = freeV.value; f.gmin = min.value;
    send.disabled = true;
    postAction(btn, f).then(function (d) {
      send.disabled = false;
      if (d.error || (k && !d.code)) throw new Error(d.error || 'The code could not be made.');
      if (d.code) text.value = text.value.split('[CODE]').join(d.code);
      if (w) w.location = wa(text.value); else location.href = wa(text.value);
      if (o.done) o.done(d, btn);
      dlg.close();
    }).catch(function (x) { send.disabled = false; if (w) w.close(); err.textContent = x.message; err.hidden = false; });
  });
}

/* Refill reminders: laid out like the automatic message; % off (the usual) is picked first. The order then shows Sent (asked even without a coupon). */
waBox('rfWa', '[data-rf]', {
  action: 'refill_sent', always: true,
  fields: function (d) { return {no: d.rf}; },
  text: function (d, c) {
    var n2 = '\n\n';
    return 'Hi ' + d.first + ',' + n2 + 'I hope you are enjoying ' + d.perfumes + '. It has been ' + d.days + ' days since your order, so your bottle may be running low.'
      + (c ? n2 + 'As a thank you, here is your personal code for ' + c.gift + ' your next order' + c.min + ' (single use):' + n2 + '*[CODE]*' : '')
      + n2 + 'You can reorder anytime here:\nhttps://fomaxo.in'
      + (d.review ? n2 + 'If you have a moment, we would love your honest review. It will show as Verified Purchaser:\n' + d.review : '')
      + n2 + 'Just reply here if you would like help choosing your next scent. If you would rather not get these messages, reply STOP.' + n2 + 'Thank you,\nFOMAXO';
  },
  done: function (d, btn) {   // the line shows Sent with today's date (India time, from the server), and the counts move once
    var due = btn.parentNode.querySelector('.rdue');
    if (due) due.remove();
    var todo = document.querySelector('[data-rfn="todo"]'), done = document.querySelector('[data-rfn="sent"]');
    if (todo && !btn.classList.contains('line')) { todo.textContent = Math.max(0, +todo.textContent - 1); done.textContent = +done.textContent + 1; }
    markSent(btn, d.day);
  }
});

/* Left at checkout: the ready message (what they left, the link back to their bag) to change if you like; No coupon is picked first, or % off / ₹ off /
   a free product, for which a new COMEBACK- code is made (one use, only their mobile, ends in 7 days) when Open WhatsApp is tapped */
waBox('ltWa', '[data-ltwa]', {
  action: 'lead_coupon', always: true,
  fields: function (d) { return {phone: d.phone, sid: d.sid}; },
  done: function (d, btn) { markSent(btn, d.day); },
  text: function (d, c) {
    return d.head + (c ? '\n\nAs a thank you, here is your personal code for ' + c.gift + ' your order' + c.min + ' (single use, valid 7 days, with this mobile number):\n\n*[CODE]*' : '') + d.tail;
  }
});

/* Reviews: a 1–3 star review's customer, or one who picked Late delivery / Faulty product, gets an apology; % off is picked first. The review then shows Sent dd/mm by its green button. */
waBox('rvWa', '[data-rvwa]', {
  action: 'review_wa', always: true,
  fields: function (d) { return {id: d.rvwa}; },
  text: function (d, c) {
    var n2 = '\n\n';
    return 'Hi ' + (d.first || 'there') + ',' + n2 + 'Thank you for your review' + (d.product ? ' of ' + d.product : '') + '. ' + ({late: 'We checked and found your delivery was indeed late. We are sorry about that.', faulty: 'We checked ' + (d.photo ? 'the photo' : 'your order') + ' and found your product was indeed faulty. We are sorry about that.'}[d.issue] || 'We are sorry it was not what you hoped for.')
      + (c ? n2 + 'As an apology, here is your personal code for ' + c.gift + ' your next order' + c.min + ' (single use):' + n2 + '*[CODE]*' + n2 + 'Type the code at checkout on our website:\nhttps://fomaxo.in' : '')
      + n2 + 'Just reply here if there is anything we can do to put it right.' + n2 + 'Thank you,\nFOMAXO';
  },
  done: function (d, btn) { markSent(btn, d.day); }
});

/* Every other WhatsApp button (Orders, an order, Review requests, Members, a customer, Coupons): its ready message to change if you like, and
   No coupon (picked first) or % off / ₹ off / a free product, for which a new THANKS- code is made for that mobile only (one use) on Open WhatsApp.
   Review requests and Coupons then show Sent dd/mm. */
waBox('gWa', '[data-wabox]', {
  action: 'wa_coupon', always: true,
  fields: function (d) { return {phone: d.phone || '', mark: d.mark || ''}; },
  text: function (d, c) {
    return d.head + (c ? '\n\nAs a thank you, here is your personal code for ' + c.gift + ' your next order' + c.min + ' (single use, with this mobile number):\n\n*[CODE]*\n\nType the code at checkout on our website:\nhttps://fomaxo.in' : '') + d.tail;
  },
  done: function (d, btn) {
    var first = !btn.classList.contains('line'); markSent(btn, d.day);
    document.querySelectorAll('[data-wabox][data-mark="' + btn.dataset.mark + '"]').forEach(function (b) { if (b !== btn) markSent(b, d.day); });   // the same customer's other buttons on the page
    var tr = btn.closest('.rqlist tr');
    if (tr && first) { tr.classList.add('dim2'); [['todo', -1], ['ask', -1], ['sent', 1]].forEach(function (x) { var n = document.querySelector('[data-rqn="' + x[0] + '"]'); if (n) n.textContent = Math.max(0, +n.textContent + x[1]); }); }
  }
});

/* Coupons: picking Free product on a new coupon ticks One use per customer */
document.addEventListener('change', function (e) {
  var r = e.target;
  if (r.name !== 'kind' || r.value !== 'free' || !r.checked) return;
  var f = r.form, u = f && f.elements.per_cust;
  if (u && !f.elements.editing) u.checked = true;
});

/* Coupons: Make code fills the code box with one like FOMAXO-7K2Q (no 0/O or 1/I, so it is easy to read out) */
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-mkcode]');
  if (!b) return;
  var abc = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ', c = 'FOMAXO-';
  for (var i = 0; i < 4; i++) c += abc[Math.floor(Math.random() * abc.length)];
  var inp = b.parentNode.querySelector('input'); inp.value = c; inp.dispatchEvent(new Event('input', {bubbles: true}));
});

/* Coupons: the free product box. Typing narrows its dropdown, a tap (or Enter, or the arrow keys) picks a line,
   and the hidden input next to it takes that line's "id|size" for the form */
(function () {
  function parts(f) { var w = f.parentNode; return {hid: w.querySelector('input[type=hidden]'), ul: w.querySelector('.fplist')}; }
  function shown(ul) { return Array.prototype.filter.call(ul.children, function (li) { return !li.hidden; }); }
  function filter(f, all) {
    var x = parts(f), q = all ? '' : f.value.trim().toLowerCase();   // matched from the start of a word: "old" finds Old Money, not Gold
    Array.prototype.forEach.call(x.ul.children, function (li) { li.hidden = q !== '' && (' ' + li.textContent.toLowerCase()).indexOf(' ' + q) < 0; li.classList.remove('on'); });
    x.ul.hidden = !shown(x.ul).length;
  }
  function pick(f, li) { var x = parts(f); f.value = li.textContent; x.hid.value = li.dataset.v; x.ul.hidden = true; f.dispatchEvent(new Event('change', {bubbles: true})); }
  document.addEventListener('focusin', function (e) { if (e.target.matches && e.target.matches('[data-ffind]')) { e.target.select(); filter(e.target, true); } });
  document.addEventListener('input', function (e) { var f = e.target; if (f.matches && f.matches('[data-ffind]')) { parts(f).hid.value = ''; filter(f); } });
  document.addEventListener('focusout', function (e) {
    var f = e.target; if (!f.matches || !f.matches('[data-ffind]')) return;
    var x = parts(f); x.ul.hidden = true;
    if (!x.hid.value) { var m = shown(x.ul); if (f.value.trim() && m.length) pick(f, m[0]); }   // left with words typed: the first match
  });
  document.addEventListener('mousedown', function (e) {   // mousedown, before the box loses focus
    var li = e.target.closest && e.target.closest('.fplist li');
    if (li) { e.preventDefault(); pick(li.parentNode.parentNode.querySelector('[data-ffind]'), li); }
  });
  document.addEventListener('keydown', function (e) {
    var f = e.target; if (!f.matches || !f.matches('[data-ffind]')) return;
    var ul = parts(f).ul, m = shown(ul), i = m.findIndex(function (li) { return li.classList.contains('on'); });
    if (e.key === 'ArrowDown' || e.key === 'ArrowUp') {
      e.preventDefault(); if (ul.hidden) filter(f); if (!m.length) return;
      if (i > -1) m[i].classList.remove('on');
      i = e.key === 'ArrowDown' ? (i + 1) % m.length : (i < 1 ? m.length - 1 : i - 1);
      m[i].classList.add('on'); m[i].scrollIntoView({block: 'nearest'});
    } else if (e.key === 'Enter' && !ul.hidden && m.length) { e.preventDefault(); pick(f, m[i > -1 ? i : 0]); }
    else if (e.key === 'Escape') ul.hidden = true;
  });
})();


/* Review requests: All · Reviewed · Partly · Not reviewed · To ask filter the list at once */
document.addEventListener('click', function (e) {
  var b = e.target.closest && e.target.closest('[data-rqf]'); if (!b) return;
  document.querySelectorAll('[data-rqf]').forEach(function (x) { x.classList.toggle('on', x === b); });
  document.querySelectorAll('.rqlist tr[data-st]').forEach(function (r) { r.hidden = !!b.dataset.rqf && r.dataset.st !== b.dataset.rqf; });
});
