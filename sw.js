/* FOMAXO India Store System - offline support. Keeps the app itself on the device so it opens without internet.
   Data requests (api.php) always go to the server; the app handles offline sales itself. */
var VERSION = 'fomaxo-pos-in-v38';
var PHOTOS = 'fomaxo-pos-in-photos';  // kept across app updates
var V = '?v=' + VERSION.replace('fomaxo-pos-in-v', 'in');  // the same version index.html asks for
var SHELL = [
  './', 'index.html', 'assets/app.css' + V, 'assets/app.js' + V, 'assets/qrcode.js' + V,
  'manifest.webmanifest', 'assets/icon-192.png', 'assets/icon-512.png', 'assets/apple-touch-icon.png', 'assets/favicon-32.png'
];

self.addEventListener('install', function (e) {
  e.waitUntil(caches.open(VERSION).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
  e.waitUntil(caches.keys().then(function (keys) {
    return Promise.all(keys.filter(function (k) { return k !== VERSION && k !== PHOTOS; }).map(function (k) { return caches.delete(k); }));
  }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
  var req = e.request;
  if (req.method !== 'GET') return;
  var url = new URL(req.url);
  if (url.origin !== location.origin) return;
  var path = url.pathname;
  // Product photos: kept on the phone and shown instantly; checked again only once a week.
  if (/\/image\.php$/.test(path)) {
    e.respondWith(caches.open(PHOTOS).then(function (c) {
      return c.match(req).then(function (hit) {
        var get = function () { return fetch(req).then(function (res) { if (res.ok) c.put(req, res.clone()); return res; }); };
        if (!hit) return get();
        var age = Date.now() - (Date.parse(hit.headers.get('date')) || 0);
        if (age > 7 * 864e5) e.waitUntil(get().catch(function () {}));
        return hit;
      });
    }));
    return;
  }
  // Live data and server pages: never cached.
  if (/\.php$/.test(path) || path.indexOf('/data/') >= 0) return;

  if (req.mode === 'navigate') {
    // Newest app when online; the saved copy if the network is offline or slower than 3 seconds.
    var saved = caches.match('index.html');
    var net = fetch(req, { cache: 'no-cache' }).then(function (res) {
      var copy = res.clone();
      caches.open(VERSION).then(function (c) { c.put('index.html', copy); });
      return res;
    });
    e.respondWith(new Promise(function (resolve) {
      var done = false;
      function use(r) { if (!done && r) { done = true; resolve(r); } }
      net.then(use, function () { saved.then(function (r) { use(r || Response.error()); }); });
      setTimeout(function () { saved.then(use); }, 3000);
    }));
    return;
  }

  // App files have a version in their address (?v=in..): the saved copy is always right, so use it at once.
  if (url.search.indexOf('v=') >= 0) {
    e.respondWith(caches.match(req).then(function (hit) {
      return hit || fetch(req).then(function (res) {
        if (res.ok) { var copy = res.clone(); caches.open(VERSION).then(function (c) { c.put(req, copy); }); }
        return res;
      });
    }));
    return;
  }

  // Other files (icons, manifest): newest copy first, saved copy when offline.
  e.respondWith(fetch(req, { cache: 'no-cache' }).then(function (res) {
    if (res.ok) { var copy = res.clone(); caches.open(VERSION).then(function (c) { c.put(req, copy); }); }
    return res;
  }).catch(function () {
    return caches.match(req, { ignoreSearch: true });
  }));
});
