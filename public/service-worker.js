const CACHE_NAME = 'vertice-static-v1';
const STATIC_FILE = /\.(?:css|js|png|svg|webp|woff2?)$/i;

self.addEventListener('install', (event) => {
  event.waitUntil(Promise.all([self.skipWaiting(), caches.open(CACHE_NAME).then((cache) => cache.add('/offline.html'))]));
});

self.addEventListener('activate', (event) => {
  event.waitUntil((async () => {
    const names = await caches.keys();
    await Promise.all(names.filter((name) => name.startsWith('vertice-static-') && name !== CACHE_NAME).map((name) => caches.delete(name)));
    await self.clients.claim();
  })());
});

self.addEventListener('fetch', (event) => {
  const request = event.request;
  const url = new URL(request.url);
  if (url.origin !== self.location.origin || request.method !== 'GET') return;

  if (request.mode === 'navigate') {
    event.respondWith(fetch(request).catch(async () => {
      const offline = await caches.match('/offline.html');
      return offline || new Response('Você está sem conexão. Reconecte-se e tente novamente.', { status: 503, headers: { 'Content-Type': 'text/plain; charset=utf-8' } });
    }));
    return;
  }

  if (!url.pathname.startsWith('/assets/') || !STATIC_FILE.test(url.pathname)) return;
  event.respondWith((async () => {
    try {
      const response = await fetch(request);
      if (response.ok && response.type === 'basic') {
        const cache = await caches.open(CACHE_NAME);
        await cache.put(request, response.clone());
      }
      return response;
    } catch (error) {
      const cached = await caches.match(request);
      if (cached) return cached;
      throw error;
    }
  })());
});
