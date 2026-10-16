// Unified root Service Worker for RetailMind
// Implements Cache-First for versioned static assets and Stale-While-Revalidate for HTML shells.

const STATIC_CACHE = 'retailmind-static-v1';
const SHELL_CACHE = 'retailmind-shell-v1';
const STATIC_EXTENSIONS = /\.(?:css|js|woff2?|ttf|eot|svg|png|jpe?g|webp|ico|gif)(?:\?.*)?$/i;

self.addEventListener('install', () => {
  // Wait until user confirms update before skipping waiting
});

self.addEventListener('activate', (event) => {
  const expectedCaches = [STATIC_CACHE, SHELL_CACHE];
  event.waitUntil(
    caches.keys().then((keys) => {
      return Promise.all(
        keys.map((key) => {
          if (!expectedCaches.includes(key)) {
            return caches.delete(key);
          }
        })
      );
    }).then(() => self.clients.claim())
  );
});

self.addEventListener('message', (event) => {
  if (event.data && event.data.type === 'SKIP_WAITING') {
    self.skipWaiting();
  }
});

self.addEventListener('fetch', (event) => {
  if (event.request.method !== 'GET') {
    return;
  }

  const url = new URL(event.request.url);
  if (url.origin !== self.location.origin) {
    return;
  }

  // Exclude service worker script itself
  if (url.pathname.endsWith('/sw.js')) {
    return;
  }

  // Exclude authentication state mutations and dynamic API endpoints
  if (
    url.pathname.endsWith('/logout.php') ||
    url.pathname.includes('/barcodeScanner/apiScanner/') ||
    url.pathname.includes('/api/') ||
    url.searchParams.has('csrf_refresh') ||
    url.searchParams.has('recover_checkout')
  ) {
    return;
  }

  // Navigation requests: HTML shells served Stale-While-Revalidate
  const isNavigation = (
    event.request.mode === 'navigate' ||
    event.request.destination === 'document' ||
    (event.request.headers.get('accept') && event.request.headers.get('accept').includes('text/html'))
  );

  if (isNavigation) {
    event.respondWith(
      caches.open(SHELL_CACHE).then((cache) => {
        return cache.match(event.request).then((cachedResponse) => {
          const fetchPromise = fetch(event.request)
            .then(async (networkResponse) => {
              if (networkResponse && networkResponse.status === 200 && !networkResponse.redirected) {
                await cache.put(event.request, networkResponse.clone());
              }
              return networkResponse;
            })
            .catch(async (err) => {
              if (cachedResponse) {
                return cachedResponse;
              }
              const fallback = (await cache.match('/')) ||
                               (await cache.match(self.registration.scope)) ||
                               (await cache.match('./'));
              if (fallback) {
                return fallback;
              }
              throw err;
            });

          if (cachedResponse) {
            event.waitUntil(fetchPromise);
            return cachedResponse;
          }
          return fetchPromise;
        });
      })
    );
    return;
  }

  // Versioned static assets: Cache-First
  const isStaticAsset = (
    event.request.destination === 'style' ||
    event.request.destination === 'script' ||
    event.request.destination === 'font' ||
    event.request.destination === 'image' ||
    STATIC_EXTENSIONS.test(url.pathname)
  );

  if (isStaticAsset) {
    event.respondWith(
      caches.open(STATIC_CACHE).then((cache) => {
        return cache.match(event.request).then((cachedResponse) => {
          if (cachedResponse) {
            return cachedResponse;
          }
          return fetch(event.request).then(async (networkResponse) => {
            if (networkResponse && networkResponse.status === 200) {
              await cache.put(event.request, networkResponse.clone());
            }
            return networkResponse;
          }).catch(() => new Response('', { status: 408, statusText: 'Offline' }));
        });
      })
    );
  }
});
