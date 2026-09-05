const CACHE_NAME = 'arca-static-v1';

const CORE_ASSETS = [
  './index.html',
  './admin.html',
  './assets/css/style.css',
  './assets/js/app.js',
  './assets/js/admin.js',
  './assets/icons/icon.svg',
  './manifest.json',
];

self.addEventListener('install', (event) => {
  event.waitUntil(
    caches.open(CACHE_NAME).then((cache) => cache.addAll(CORE_ASSETS)).catch(() => {
      // Si algún recurso falla al precachear (ej. sin conexión en el primer
      // arranque), no bloqueamos la instalación del service worker.
    })
  );
  self.skipWaiting();
});

self.addEventListener('activate', (event) => {
  event.waitUntil(
    caches.keys().then((names) =>
      Promise.all(names.filter((n) => n !== CACHE_NAME).map((n) => caches.delete(n)))
    )
  );
  self.clients.claim();
});

self.addEventListener('fetch', (event) => {
  const url = new URL(event.request.url);

  // Las llamadas a la API nunca se sirven desde caché: los datos financieros
  // deben ser siempre frescos. Si no hay red, que falle de forma visible.
  if (url.pathname.includes('/api/')) {
    return;
  }

  if (event.request.method !== 'GET') {
    return;
  }

  event.respondWith(
    caches.match(event.request).then((cached) => {
      const networkFetch = fetch(event.request)
        .then((response) => {
          if (response && response.status === 200) {
            const clone = response.clone();
            caches.open(CACHE_NAME).then((cache) => cache.put(event.request, clone));
          }
          return response;
        })
        .catch(() => cached);
      return cached || networkFetch;
    })
  );
});
