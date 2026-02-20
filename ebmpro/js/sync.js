/* ============================================================
   Easy Builders Merchant Pro — sync.js
   Online/offline sync manager
   ============================================================ */

const Sync = (() => {
  let _online = navigator.onLine;

  /* ── Device ID ────────────────────────────────────────────── */
  function getDeviceId() {
    let id = localStorage.getItem('ebmpro_device_id');
    if (!id) {
      id = (typeof crypto !== 'undefined' && crypto.randomUUID)
        ? crypto.randomUUID()
        : Math.random().toString(36).slice(2) + Date.now().toString(36);
      localStorage.setItem('ebmpro_device_id', id);
    }
    return id;
  }

  /* ── Update UI indicator ──────────────────────────────────── */
  function updateUI(online) {
    const el = document.getElementById('onlineStatus');
    if (!el) return;
    if (online) {
      el.className = 'online';
      el.textContent = '🟢 Online';
    } else {
      el.className = 'offline';
      el.textContent = '🔴 Offline';
    }
  }

  /* ── Flush offline queue ──────────────────────────────────── */
  async function flushOfflineQueue() {
    let items;
    try { items = await DB.getOfflineQueue(); }
    catch { return; }

    if (!items || !items.length) return;

    const deviceId = getDeviceId();
    try {
      const resp = await fetch('/ebmpro_api/sync.php', {
        method:  'POST',
        headers: Object.assign(
          { 'Content-Type': 'application/json' },
          Auth.getAuthHeaders ? Auth.getAuthHeaders() : {}
        ),
        body: JSON.stringify({ device_id: deviceId, queue: items })
      });

      if (resp.ok) {
        const result = await resp.json();
        // processed may be an array of IDs or a count; mark all pending as processed
        const processed = Array.isArray(result.processed)
          ? result.processed
          : items.map(i => i.id);
        for (const id of processed) {
          await DB.markProcessed(id);
        }
        console.info(`[Sync] Flushed ${processed.length} queued item(s).`);
      }
    } catch (err) {
      console.warn('[Sync] Could not flush offline queue:', err);
    }
  }

  /* ── Handle online event ──────────────────────────────────── */
  function handleOnline() {
    _online = true;
    updateUI(true);
    flushOfflineQueue();

    // Request a background sync if supported
    if ('serviceWorker' in navigator && 'SyncManager' in window) {
      navigator.serviceWorker.ready.then(sw =>
        sw.sync.register('background-sync').catch(() => {})
      );
    }
  }

  /* ── Handle offline event ─────────────────────────────────── */
  function handleOffline() {
    _online = false;
    updateUI(false);
  }

  /* ── Derive entity type from endpoint path ────────────────── */
  function deriveEntityType(endpoint) {
    const file = (endpoint.split('/').pop() || '').split('?')[0].replace('.php', '');
    return file || 'unknown';
  }

  /* ── syncData: online→fetch, offline→queue ────────────────── */
  async function syncData(endpoint, data, method = 'POST') {
    if (_online) {
      try {
        const headers = typeof Auth !== 'undefined' && Auth.getAuthHeaders
          ? Auth.getAuthHeaders()
          : { 'Content-Type': 'application/json' };

        const resp = await fetch(endpoint, {
          method,
          headers,
          body: method !== 'GET' && method !== 'DELETE' ? JSON.stringify(data) : undefined
        });
        return await resp.json();
      } catch (err) {
        console.warn('[Sync] Fetch failed, queueing:', err);
        // Fall through to queue
      }
    }

    // Offline or fetch failed — queue
    if (method !== 'GET') {
      await DB.saveOfflineAction({
        action:      method,
        entity_type: deriveEntityType(endpoint),
        url:         endpoint,
        payload:     data
      });
    }
    return { success: true, offline: true, queued: method !== 'GET' };
  }

  /* ── initSync ─────────────────────────────────────────────── */
  function initSync() {
    window.addEventListener('online',  handleOnline);
    window.addEventListener('offline', handleOffline);

    // Set initial state after DOM is ready
    requestAnimationFrame(() => updateUI(_online));

    // Flush any queued items if we're online at startup
    if (_online) flushOfflineQueue();
  }

  /* ── getOnlineStatus ──────────────────────────────────────── */
  function getOnlineStatus() {
    return _online;
  }

  return {
    initSync,
    getOnlineStatus,
    syncData,
    flushOfflineQueue,
    getDeviceId
  };
})();
