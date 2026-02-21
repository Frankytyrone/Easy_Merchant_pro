/* ============================================================
   Easy Builders Merchant Pro — db.js
   IndexedDB wrapper
   ============================================================ */

const DB = (() => {
  const DB_NAME    = 'ebmpro_offline';
  const DB_VERSION = 1;
  let _db = null;

  function open() {
    return new Promise((resolve, reject) => {
      if (_db) { resolve(_db); return; }

      const req = indexedDB.open(DB_NAME, DB_VERSION);

      req.onupgradeneeded = e => {
        const db = e.target.result;

        /* offline_queue */
        if (!db.objectStoreNames.contains('offline_queue')) {
          const oq = db.createObjectStore('offline_queue', {
            keyPath: 'id',
            autoIncrement: true
          });
          oq.createIndex('entity_type', 'entity_type', { unique: false });
          oq.createIndex('created_at',  'created_at',  { unique: false });
        }

        /* invoices */
        if (!db.objectStoreNames.contains('invoices')) {
          const inv = db.createObjectStore('invoices', { keyPath: 'id' });
          inv.createIndex('store_id',     'store_id',     { unique: false });
          inv.createIndex('status',       'status',       { unique: false });
          inv.createIndex('customer_id',  'customer_id',  { unique: false });
          inv.createIndex('invoice_date', 'invoice_date', { unique: false });
        }

        /* customers */
        if (!db.objectStoreNames.contains('customers')) {
          const cust = db.createObjectStore('customers', { keyPath: 'id' });
          cust.createIndex('name', 'name', { unique: false });
        }

        /* products */
        if (!db.objectStoreNames.contains('products')) {
          const prod = db.createObjectStore('products', { keyPath: 'id' });
          prod.createIndex('code', 'code', { unique: false });
        }

        /* settings */
        if (!db.objectStoreNames.contains('settings')) {
          db.createObjectStore('settings', { keyPath: 'id' });
        }
      };

      req.onsuccess = e => { _db = e.target.result; resolve(_db); };
      req.onerror   = e => reject(e.target.error);
    });
  }

  /* ── init ─────────────────────────────────────────────────── */
  function init() {
    return open();
  }

  /* ── save (add or update) ─────────────────────────────────── */
  function save(storeName, data) {
    return open().then(db => new Promise((resolve, reject) => {
      const tx  = db.transaction(storeName, 'readwrite');
      const req = tx.objectStore(storeName).put(data);
      req.onsuccess = () => resolve(req.result);
      req.onerror   = e => reject(e.target.error);
    }));
  }

  /* ── get single record ────────────────────────────────────── */
  function get(storeName, id) {
    return open().then(db => new Promise((resolve, reject) => {
      const tx  = db.transaction(storeName, 'readonly');
      const req = tx.objectStore(storeName).get(id);
      req.onsuccess = () => resolve(req.result || null);
      req.onerror   = e => reject(e.target.error);
    }));
  }

  /* ── getAll ───────────────────────────────────────────────── */
  function getAll(storeName) {
    return open().then(db => new Promise((resolve, reject) => {
      const tx  = db.transaction(storeName, 'readonly');
      const req = tx.objectStore(storeName).getAll();
      req.onsuccess = () => resolve(req.result);
      req.onerror   = e => reject(e.target.error);
    }));
  }

  /* ── delete ───────────────────────────────────────────────── */
  function del(storeName, id) {
    return open().then(db => new Promise((resolve, reject) => {
      const tx  = db.transaction(storeName, 'readwrite');
      const req = tx.objectStore(storeName).delete(id);
      req.onsuccess = () => resolve(true);
      req.onerror   = e => reject(e.target.error);
    }));
  }

  /* ── query by index ───────────────────────────────────────── */
  function query(storeName, indexName, value) {
    return open().then(db => new Promise((resolve, reject) => {
      const tx      = db.transaction(storeName, 'readonly');
      const index   = tx.objectStore(storeName).index(indexName);
      const results = [];
      const req     = index.openCursor(IDBKeyRange.only(value));
      req.onsuccess = e => {
        const cursor = e.target.result;
        if (cursor) { results.push(cursor.value); cursor.continue(); }
        else { resolve(results); }
      };
      req.onerror = e => reject(e.target.error);
    }));
  }

  /* ── saveOfflineAction ────────────────────────────────────── */
  function saveOfflineAction(action) {
    const record = Object.assign({
      created_at: new Date().toISOString(),
      processed:  false
    }, action);
    return save('offline_queue', record);
  }

  /* ── getOfflineQueue (unprocessed) ───────────────────────── */
  function getOfflineQueue() {
    return getAll('offline_queue').then(items =>
      items.filter(i => !i.processed)
    );
  }

  /* ── markProcessed ────────────────────────────────────────── */
  function markProcessed(id) {
    return get('offline_queue', id).then(item => {
      if (!item) return;
      item.processed = true;
      return save('offline_queue', item);
    });
  }

  /* ── clear all records in a store ────────────────────────── */
  function clear(storeName) {
    return open().then(db => new Promise((resolve, reject) => {
      const tx  = db.transaction(storeName, 'readwrite');
      const req = tx.objectStore(storeName).clear();
      req.onsuccess = () => resolve(true);
      req.onerror   = e => reject(e.target.error);
    }));
  }

  return {
    init,
    save,
    get,
    getAll,
    delete: del,
    query,
    saveOfflineAction,
    getOfflineQueue,
    markProcessed,
    clear
  };
})();