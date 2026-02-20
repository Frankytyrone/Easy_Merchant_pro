/* ============================================================
   Easy Builders Merchant Pro — Service Worker
   Cache: ebmpro-v1
   ============================================================ */

const CACHE_NAME = 'ebmpro-v1';

const APP_ASSETS = [
  '/ebmpro/',
  '/ebmpro/index.html',
  '/ebmpro/css/styles.css',
  '/ebmpro/js/db.js',
  '/ebmpro/js/sync.js',
  '/ebmpro/js/auth.js',
  '/ebmpro/js/app.js',
  '/ebmpro/js/invoice.js',
  '/ebmpro/js/customer.js',
  '/ebmpro/js/product.js',
  '/ebmpro/js/pdf.js',
  '/ebmpro/manifest.json'
];

/* ── Install: pre-cache all app assets ─────────────────────── */
self.addEventListener('install', event => {
  event.waitUntil(
    caches.open(CACHE_NAME)
      .then(cache => cache.addAll(APP_ASSETS))
      .then(() => self.skipWaiting())
  );
});

/* ── Activate: clean up old caches ─────────────────────────── */
self.addEventListener('activate', event => {
  event.waitUntil(
    caches.keys()
      .then(keys => Promise.all(
        keys
          .filter(key => key !== CACHE_NAME)
          .map(key => caches.delete(key))
      ))
      .then(() => self.clients.claim())
  );
});

/* ── Fetch: cache-first for app assets, network-first for API ─ */
self.addEventListener('fetch', event => {
  const url = new URL(event.request.url);

  if (url.pathname.startsWith('/ebmpro_api/')) {
    // Network-first for API calls
    event.respondWith(networkFirstApi(event.request));
  } else {
    // Cache-first for app assets
    event.respondWith(cacheFirst(event.request));
  }
});

async function cacheFirst(request) {
  const cached = await caches.match(request);
  if (cached) return cached;
  try {
    const response = await fetch(request);
    if (response.ok) {
      const cache = await caches.open(CACHE_NAME);
      cache.put(request, response.clone());
    }
    return response;
  } catch {
    return new Response('Offline — asset not cached', { status: 503 });
  }
}

async function networkFirstApi(request) {
  try {
    const response = await fetch(request.clone());
    return response;
  } catch {
    // Offline — queue write requests
    if (request.method !== 'GET') {
      await queueOfflineRequest(request.clone());
      return new Response(
        JSON.stringify({ success: false, offline: true, queued: true }),
        { status: 200, headers: { 'Content-Type': 'application/json' } }
      );
    }
    return new Response(
      JSON.stringify({ success: false, offline: true, queued: false }),
      { status: 503, headers: { 'Content-Type': 'application/json' } }
    );
  }
}

/* ── IndexedDB offline queue helpers ──────────────────────── */
function openOfflineDB() {
  return new Promise((resolve, reject) => {
    const req = indexedDB.open('ebmpro_offline', 1);
    req.onupgradeneeded = e => {
      const db = e.target.result;
      if (!db.objectStoreNames.contains('offline_queue')) {
        const store = db.createObjectStore('offline_queue', {
          keyPath: 'id',
          autoIncrement: true
        });
        store.createIndex('entity_type', 'entity_type', { unique: false });
        store.createIndex('created_at', 'created_at', { unique: false });
      }
    };
    req.onsuccess = e => resolve(e.target.result);
    req.onerror = e => reject(e.target.error);
  });
}

async function queueOfflineRequest(request) {
  let body = null;
  try { body = await request.text(); } catch { /* ignore */ }

  const db = await openOfflineDB();
  const tx = db.transaction('offline_queue', 'readwrite');
  tx.objectStore('offline_queue').add({
    url: request.url,
    method: request.method,
    body,
    headers: [...request.headers.entries()].reduce((acc, [k, v]) => {
      acc[k] = v;
      return acc;
    }, {}),
    entity_type: deriveEntityType(request.url),
    created_at: new Date().toISOString(),
    processed: false
  });
  return new Promise((resolve, reject) => {
    tx.oncomplete = resolve;
    tx.onerror = e => reject(e.target.error);
  });
}

function deriveEntityType(url) {
  const parts = url.split('/');
  const file = parts[parts.length - 1].split('?')[0].replace('.php', '');
  return file || 'unknown';
}

/* ── Background Sync: flush offline queue ─────────────────── */
self.addEventListener('sync', event => {
  if (event.tag === 'background-sync') {
    event.waitUntil(flushOfflineQueue());
  }
});

async function flushOfflineQueue() {
  const db = await openOfflineDB();
  const tx = db.transaction('offline_queue', 'readonly');
  const items = await new Promise((resolve, reject) => {
    const req = tx.objectStore('offline_queue').getAll();
    req.onsuccess = () => resolve(req.result);
    req.onerror = e => reject(e.target.error);
  });

  const pending = items.filter(i => !i.processed);
  if (!pending.length) return;

  for (const item of pending) {
    try {
      await fetch(item.url, {
        method: item.method,
        headers: item.headers,
        body: item.body
      });
      const wtx = db.transaction('offline_queue', 'readwrite');
      const record = await new Promise((resolve, reject) => {
        const r = wtx.objectStore('offline_queue').get(item.id);
        r.onsuccess = () => resolve(r.result);
        r.onerror = e => reject(e.target.error);
      });
      if (record) {
        record.processed = true;
        const wtx2 = db.transaction('offline_queue', 'readwrite');
        wtx2.objectStore('offline_queue').put(record);
      }
    } catch {
      break; // Still offline, stop trying
    }
  }
}
