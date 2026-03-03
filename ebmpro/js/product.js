/* ============================================================
   Easy Builders Merchant Pro — product.js
   Product search module with in-session cache, barcode scan
   ============================================================ */

const Product = (() => {
  /* ── In-session cache ─────────────────────────────────────── */
  const _cache = new Map();

  /* ── Debounce helper ──────────────────────────────────────── */
  function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  }

  /* ── searchProducts ───────────────────────────────────────── */
  async function searchProducts(query) {
    if (!query || query.trim().length < 1) return [];
    const key = query.trim().toLowerCase();

    if (_cache.has(key)) return _cache.get(key);

    try {
      const resp = await fetch(
        `/ebmpro_api/products.php?q=${encodeURIComponent(key)}`,
        { headers: Auth.getAuthHeaders() }
      );
      if (!resp.ok) return [];
      const data = await resp.json();
      const list = data.data || (Array.isArray(data) ? data : (data.products || []));
      _cache.set(key, list);
      return list;
    } catch {
      // Fallback: search IndexedDB product cache
      try {
        const all = await DB.getAll('products');
        const q   = key;
        const results = all.filter(p =>
          (p.product_code || p.code || '').toLowerCase().includes(q) ||
          (p.description  || '').toLowerCase().includes(q) ||
          (p.barcode      || '').includes(q)
        ).slice(0, 20);
        return results;
      } catch { return []; }
    }
  }

  /* 300ms debounce as per spec */
  const debouncedSearch = debounce(searchProducts, 300);

  /* ── formatPrice ──────────────────────────────────────────── */
  function fmt(n) {
    return '€' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  /* ── renderProductDropdown ────────────────────────────────── */
  function renderProductDropdown(products, inputEl, callback) {
    const wrap = inputEl.closest('.product-search-wrap') || inputEl.parentElement;
    closeProductDropdown(wrap);

    if (!products || !products.length) return;

    const list = document.createElement('ul');
    list.className = 'dropdown-list product-list';
    list.setAttribute('role', 'listbox');

    products.forEach((prod, idx) => {
      const li = document.createElement('li');
      li.className = 'dropdown-item';
      li.setAttribute('role', 'option');
      li.setAttribute('data-idx', String(idx));
      li.innerHTML = `
        <span class="item-code">${escapeHtml(prod.product_code || prod.code || '')}</span>
        ${escapeHtml(prod.description || '')}
        <span class="item-vat">${prod.vat_rate || 0}% VAT</span>
        <span class="item-price">${fmt(prod.price ?? prod.unit_price)}</span>
      `;
      li.addEventListener('mousedown', e => {
        e.preventDefault();
        closeProductDropdown(wrap);
        callback(prod);
      });
      list.appendChild(li);
    });

    wrap.style.position = 'relative';
    wrap.appendChild(list);
    enableKeyboardNav(inputEl, list, products, callback, wrap);
  }

  function closeProductDropdown(wrap) {
    const l = wrap.querySelector('.product-list');
    if (l) l.remove();
  }

  function enableKeyboardNav(inputEl, list, products, callback, wrap) {
    let active = -1;

    function onKey(e) {
      const items = list.querySelectorAll('.dropdown-item');
      if (!items.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        active = Math.min(active + 1, items.length - 1);
        highlight(items, active);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active = Math.max(active - 1, 0);
        highlight(items, active);
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault();
        closeProductDropdown(wrap);
        inputEl.removeEventListener('keydown', onKey);
        callback(products[active]);
      } else if (e.key === 'Escape') {
        closeProductDropdown(wrap);
        inputEl.removeEventListener('keydown', onKey);
      }
    }

    inputEl.addEventListener('keydown', onKey);
  }

  function highlight(items, idx) {
    items.forEach((el, i) => el.classList.toggle('active', i === idx));
    if (items[idx]) items[idx].scrollIntoView({ block: 'nearest' });
  }

  /* ── escapeHtml ───────────────────────────────────────────── */
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  /* ── clearCache (call if products are updated) ────────────── */
  function clearCache() { _cache.clear(); }

  /* ══════════════════════════════════════════════════════════
     BARCODE SCANNER
  ═══════════════════════════════════════════════════════════ */

  let _scannerStream = null;
  let _scannerInterval = null;

  /**
   * Open camera barcode scanner.
   * callback(productOrNull) is called when a barcode is decoded.
   */
  async function openBarcodeScanner(callback) {
    closeBarcodeScanner();

    const overlay = document.createElement('div');
    overlay.id = 'barcodeOverlay';
    overlay.style.cssText = `
      position:fixed;inset:0;z-index:9000;background:rgba(0,0,0,.85);
      display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1rem;
    `;
    overlay.innerHTML = `
      <p style="color:#fff;font-size:1rem;margin:0;">📷 Point camera at barcode</p>
      <video id="barcodeVideo" autoplay playsinline
        style="width:min(350px,90vw);border-radius:10px;background:#000;"></video>
      <canvas id="barcodeCanvas" style="display:none;"></canvas>
      <p id="barcodeStatus" style="color:#aaa;font-size:.85rem;margin:0;">Initialising camera…</p>
      <button id="closeScannerBtn"
        style="background:#dc3545;color:#fff;border:none;padding:.5rem 1.5rem;border-radius:8px;cursor:pointer;font-size:1rem;">
        ✕ Cancel
      </button>
    `;
    document.body.appendChild(overlay);

    overlay.querySelector('#closeScannerBtn').addEventListener('click', () => {
      closeBarcodeScanner();
      callback(null);
    });

    const video  = overlay.querySelector('#barcodeVideo');
    const canvas = overlay.querySelector('#barcodeCanvas');
    const status = overlay.querySelector('#barcodeStatus');

    try {
      _scannerStream = await navigator.mediaDevices.getUserMedia({
        video: { facingMode: 'environment' },
        audio: false,
      });
      video.srcObject = _scannerStream;
      await video.play();
      status.textContent = 'Scanning…';
    } catch (err) {
      status.textContent = 'Camera not available: ' + err.message;
      return;
    }

    // Try native BarcodeDetector API first
    if (typeof BarcodeDetector !== 'undefined') {
      const detector = new BarcodeDetector({ formats: ['ean_13', 'ean_8', 'code_39', 'code_128', 'qr_code', 'upc_a', 'upc_e'] });
      _scannerInterval = setInterval(async () => {
        if (video.readyState < 2) return;
        try {
          const barcodes = await detector.detect(video);
          if (barcodes.length > 0) {
            const raw = barcodes[0].rawValue;
            await handleScannedBarcode(raw, callback, status);
          }
        } catch { /* ignore frame errors */ }
      }, 200);
    } else {
      // Fallback: ZXing (loaded lazily)
      await loadZXing();
      if (typeof ZXing === 'undefined') {
        status.textContent = 'Barcode scanning not supported on this browser. Please search manually.';
        return;
      }
      try {
        const codeReader = new ZXing.BrowserMultiFormatReader();
        codeReader.decodeFromVideoElement(video, (result, err) => {
          if (result) {
            handleScannedBarcode(result.getText(), callback, status);
          }
        });
      } catch (e) {
        status.textContent = 'Scanner error: ' + e.message;
      }
    }
  }

  async function handleScannedBarcode(barcode, callback, statusEl) {
    if (!barcode) return;
    if (statusEl) statusEl.textContent = 'Found: ' + barcode + ' — looking up…';

    closeBarcodeScanner();

    // Search by barcode
    const results = await searchProducts(barcode);
    if (results && results.length > 0) {
      callback(results[0]);
    } else {
      if (statusEl) statusEl.textContent = 'Product not found — please search manually';
      // Show toast
      if (typeof App !== 'undefined' && App.toast) {
        App.toast('Product not found for barcode: ' + barcode + ' — please search manually', 'warning');
      }
      callback(null);
    }
  }

  function closeBarcodeScanner() {
    if (_scannerInterval) { clearInterval(_scannerInterval); _scannerInterval = null; }
    if (_scannerStream) {
      _scannerStream.getTracks().forEach(t => t.stop());
      _scannerStream = null;
    }
    const overlay = document.getElementById('barcodeOverlay');
    if (overlay) overlay.remove();
  }

  let _zxingLoaded = false;
  async function loadZXing() {
    if (_zxingLoaded) return;
    return new Promise(resolve => {
      const s = document.createElement('script');
      s.src = 'https://unpkg.com/@zxing/library@latest/umd/index.min.js';
      s.onload  = () => { _zxingLoaded = true; resolve(); };
      s.onerror = () => resolve();
      document.head.appendChild(s);
    });
  }

  /* ── loadProducts ─────────────────────────────────────────── */
  async function loadProducts(page = 1, perPage = 50) {
    try {
      const resp = await fetch(
        `/ebmpro_api/products.php?page=${page}&per_page=${perPage}`,
        { headers: Auth.getAuthHeaders() }
      );
      if (!resp.ok) throw new Error('API error');
      const data = await resp.json();
      return data; // { success, data: [...], meta: { total, page, pages } }
    } catch {
      try {
        const all = await DB.getAll('products');
        return { success: true, data: all, meta: { total: all.length, page: 1, pages: 1 } };
      } catch { return { success: false, data: [], meta: {} }; }
    }
  }

  /* ── saveProduct ──────────────────────────────────────────── */
  async function saveProduct(formData, id = null) {
    // Map form fields to API column names
    const payload = {
      product_code: (formData.product_code || formData.code || '').trim(),
      description:  (formData.description  || '').trim(),
      category:     (formData.category     || '').trim() || null,
      price:        parseFloat(formData.price ?? formData.unit_price ?? 0),
      vat_rate:     parseFloat(formData.vat_rate ?? 23),
      unit:         (formData.unit         || 'each').trim(),
    };

    if (!payload.description) {
      throw new Error('Product description is required');
    }

    const method  = id ? 'PUT' : 'POST';
    const url     = id
      ? `/ebmpro_api/products.php?id=${encodeURIComponent(id)}`
      : '/ebmpro_api/products.php';

    const resp = await fetch(url, {
      method,
      headers: Auth.getAuthHeaders(),
      body:    JSON.stringify(payload),
    });
    const result = await resp.json();
    if (!resp.ok || result.success === false) {
      throw new Error(result.error || result.message || `Server error (${resp.status})`);
    }
    // Bust search cache so updated product appears in searches immediately
    clearCache();
    return result; // { success: true, data: { ...product } }
  }

  /* ── deleteProduct ────────────────────────────────────────── */
  async function deleteProduct(id) {
    const resp = await fetch(
      `/ebmpro_api/products.php?id=${encodeURIComponent(id)}`,
      { method: 'DELETE', headers: Auth.getAuthHeaders() }
    );
    const result = await resp.json();
    if (!resp.ok || result.success === false) {
      throw new Error(result.error || result.message || `Server error (${resp.status})`);
    }
    clearCache();
    return result;
  }

  /* ── renderProductList ────────────────────────────────────── */
  function renderProductList(products, containerEl) {
    if (!containerEl) return;
    if (!products || !products.length) {
      containerEl.innerHTML = '<p class="text-muted" style="padding:1rem">No products found.</p>';
      return;
    }
    containerEl.innerHTML = `
      <table class="data-table" style="width:100%">
        <thead>
          <tr>
            <th>Code</th>
            <th>Description</th>
            <th>Category</th>
            <th>Price</th>
            <th>VAT %</th>
            <th>Unit</th>
            <th style="width:110px">Actions</th>
          </tr>
        </thead>
        <tbody>
          ${products.map(p => `
            <tr data-id="${p.id}">
              <td>${escapeHtml(p.product_code || '')}</td>
              <td>${escapeHtml(p.description  || '')}</td>
              <td>${escapeHtml(p.category     || '')}</td>
              <td class="text-right">${fmt(p.price)}</td>
              <td class="text-right">${parseFloat(p.vat_rate || 0).toFixed(1)}%</td>
              <td>${escapeHtml(p.unit || 'each')}</td>
              <td>
                <button class="btn-sm btn-edit"   data-id="${p.id}" aria-label="Edit product">✏️</button>
                <button class="btn-sm btn-delete" data-id="${p.id}" aria-label="Delete product">🗑️</button>
              </td>
            </tr>
          `).join('')}
        </tbody>
      </table>
    `;
  }

  /* ── getProductById ───────────────────────────────────────── */
  async function getProductById(id) {
    try {
      const resp = await fetch(
        `/ebmpro_api/products.php?id=${encodeURIComponent(id)}`,
        { headers: Auth.getAuthHeaders() }
      );
      if (!resp.ok) throw new Error('API error');
      const data = await resp.json();
      return data.data || data;
    } catch {
      return null;
    }
  }

  return {
    searchProducts,
    debouncedSearch,
    renderProductDropdown,
    closeProductDropdown,
    clearCache,
    openBarcodeScanner,
    closeBarcodeScanner,
    loadProducts,
    saveProduct,
    deleteProduct,
    renderProductList,
    getProductById,
  };
})();