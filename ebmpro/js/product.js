/* ============================================================
   Easy Builders Merchant Pro — product.js
   Product search module with in-session cache
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
      const list = Array.isArray(data) ? data : (data.products || []);
      _cache.set(key, list);
      return list;
    } catch {
      // Fallback: search IndexedDB product cache
      try {
        const all = await DB.getAll('products');
        const q   = key;
        const results = all.filter(p =>
          (p.code        || '').toLowerCase().includes(q) ||
          (p.description || '').toLowerCase().includes(q)
        ).slice(0, 20);
        return results;
      } catch { return []; }
    }
  }

  const debouncedSearch = debounce(searchProducts, 200);

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
        <span class="item-code">${escapeHtml(prod.code || '')}</span>
        ${escapeHtml(prod.description || '')}
        <span class="item-vat">${prod.vat_rate || 0}% VAT</span>
        <span class="item-price">${fmt(prod.price)}</span>
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

  return {
    searchProducts,
    debouncedSearch,
    renderProductDropdown,
    closeProductDropdown,
    clearCache
  };
})();
