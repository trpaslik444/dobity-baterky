/* global self, caches, fetch */

const VERSION = 'v1.0.0';
const STATIC_CACHE = `db-static-${VERSION}`;
const RUNTIME_CACHE = `db-runtime-${VERSION}`;

const APP_SHELL = [
  // Stránka může být shell-only; HTML cache necháme na runtime.
  // Přednačti klíčová aktiva (uprav podle reality):
  '/wp-includes/js/wp-emoji-release.min.js',
  // přidej bundle tvé mapy, CSS, fonty atd.:
  // '/wp-content/plugins/dobity-map/assets/app.js',
  // '/wp-content/plugins/dobity-map/assets/app.css',
];

// Mapové dlaždice (příklad: OSM/Carto/Maptiler apod.)
const TILE_HOST_ALLOWLIST = [
  'tile.openstreetmap.org',
  'tiles.stadiamaps.com',
  'api.maptiler.com',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(STATIC_CACHE).then((cache) => cache.addAll(APP_SHELL))
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((keys) =>
      Promise.all(keys.map((key) => {
        if (![STATIC_CACHE, RUNTIME_CACHE].includes(key)) {
          return caches.delete(key);
        }
      }))
    )
  );
  self.clients.claim();
});

// Helper: je to mapová dlaždice?
function isTileRequest(url) {
  try {
    const u = new URL(url);
    return TILE_HOST_ALLOWLIST.includes(u.hostname);
  } catch {
    return false;
  }
}

self.addEventListener('fetch', (event) => {
  const { request } = event;

  // Pouze GET
  if (request.method !== 'GET') return;

  const url = new URL(request.url);

  const isMapPage = url.pathname.startsWith('/mapa');
  const isWPApi   = url.pathname.startsWith('/wp-json/');
  const isAjax    = url.pathname.startsWith('/wp-admin/admin-ajax.php');

  if (isMapPage || isWPApi || isAjax) {
    event.respondWith((async () => {
      try {
        const reqWithCreds = new Request(request, { credentials: 'include', cache: 'no-store' });
        const netResp = await fetch(reqWithCreds);
        // 401/403 nikdy nekešovat
        if (netResp && netResp.status >= 200 && netResp.status < 300) {
          // záměrně neukládáme do cache (auth-závislý obsah)
        }
        return netResp;
      } catch (err) {
        // Fallback pouze pro mapovou stránku (UI), ne pro API/Ajax
        if (isMapPage) {
          const cache = await caches.open(RUNTIME_CACHE);
          const cached = await cache.match(request, { ignoreSearch: true });
          if (cached) return cached;
        }
        throw err;
      }
    })());
    return;
  }

  // Scope: pouze cesty pod /dobity-map (plus statická aktiva WP)
  // Umožni i načítání CSS/JS/fontů odkudkoli (kvůli funkci UI), ale HTML
  // si drž v rámci scope.
  const isHTML = request.destination === 'document' || request.headers.get('accept')?.includes('text/html');

  // Strategii zvolíme podle typu:
  if (isTileRequest(url.href)) {
    // Map tiles: cache-first s expirací by byla fajn (zde jednoduché cache-first)
    event.respondWith(
      caches.open(RUNTIME_CACHE).then(async (cache) => {
        const cached = await cache.match(request);
        if (cached) return cached;
        const resp = await fetch(request);
        cache.put(request, resp.clone());
        return resp;
      })
    );
    return;
  }

  if (isHTML) {
    // HTML: network-first (aby byla stránka aktuální), fallback na cache
    event.respondWith(
      (async () => {
        try {
          const fresh = await fetch(request);
          const cache = await caches.open(RUNTIME_CACHE);
          cache.put(request, fresh.clone());
          return fresh;
        } catch (e) {
          const cached = await caches.match(request);
          if (cached) return cached;
          // případně návrat jednoduché offline stránky:
          return new Response('<h1>Jste offline</h1>', { headers: { 'Content-Type': 'text/html' } });
        }
      })()
    );
    return;
  }

  // Ostatní (CSS/JS/fonty): stale-while-revalidate
  event.respondWith(
    (async () => {
      const cache = await caches.open(RUNTIME_CACHE);
      const cached = await cache.match(request);
      const network = fetch(request).then((resp) => {
        cache.put(request, resp.clone());
        return resp;
      }).catch(() => null);
      return cached || network || new Response('', { status: 504 });
    })()
  );
});
