/* ============================================================
   Easy Builders Merchant Pro — customer.js
   Customer management module
   ============================================================ */

const Customer = (() => {
  /* ── Debounce helper ──────────────────────────────────────── */
  function debounce(fn, ms) {
    let t;
    return (...args) => { clearTimeout(t); t = setTimeout(() => fn(...args), ms); };
  }

  /* ── searchCustomers ──────────────────────────────────────── */
  async function searchCustomers(query) {
    if (!query || query.trim().length < 1) return [];
    try {
      const resp = await fetch(
        `/ebmpro_api/customers.php?q=${encodeURIComponent(query.trim())}`,
        { headers: Auth.getAuthHeaders() }
      );
      if (!resp.ok) return [];
      const data = await resp.json();
      return Array.isArray(data) ? data : (data.customers || []);
    } catch {
      // Fallback to local IndexedDB cache
      try {
        const all = await DB.getAll('customers');
        const q   = query.toLowerCase();
        return all.filter(c =>
          (c.name  || '').toLowerCase().includes(q) ||
          (c.phone || '').toLowerCase().includes(q) ||
          (c.email || '').toLowerCase().includes(q)
        ).slice(0, 10);
      } catch { return []; }
    }
  }

  const debouncedSearch = debounce(searchCustomers, 300);

  /* ── renderCustomerDropdown ───────────────────────────────── */
  function renderCustomerDropdown(customers, inputEl, callback) {
    const wrap = inputEl.parentElement;
    let list   = wrap.querySelector('.dropdown-list.customer-list');

    // Remove existing dropdown
    if (list) list.remove();
    if (!customers || !customers.length) return;

    list = document.createElement('ul');
    list.className = 'dropdown-list customer-list';
    list.setAttribute('role', 'listbox');

    customers.forEach((cust, idx) => {
      const li = document.createElement('li');
      li.className = 'dropdown-item';
      li.setAttribute('role', 'option');
      li.setAttribute('data-idx', idx);
      li.innerHTML = `
        <strong>${escapeHtml(cust.name || '')}</strong>
        ${cust.phone ? `<span class="text-muted small"> · ${escapeHtml(cust.phone)}</span>` : ''}
        ${cust.email ? `<span class="text-muted small"> · ${escapeHtml(cust.email)}</span>` : ''}
        ${cust.town  ? `<div class="text-muted small">${escapeHtml(cust.town)}</div>` : ''}
      `;
      li.addEventListener('mousedown', e => {
        e.preventDefault();
        closeDropdown(wrap);
        callback(cust);
      });
      list.appendChild(li);
    });

    wrap.appendChild(list);
    enableKeyboardNav(inputEl, list, callback, wrap);
  }

  function closeDropdown(wrap) {
    const l = wrap.querySelector('.dropdown-list');
    if (l) l.remove();
  }

  function enableKeyboardNav(inputEl, list, callback, wrap) {
    let active = -1;
    const items = () => list.querySelectorAll('.dropdown-item');

    inputEl.addEventListener('keydown', function onKey(e) {
      const its = items();
      if (!its.length) return;

      if (e.key === 'ArrowDown') {
        e.preventDefault();
        active = Math.min(active + 1, its.length - 1);
        highlight(its, active);
      } else if (e.key === 'ArrowUp') {
        e.preventDefault();
        active = Math.max(active - 1, 0);
        highlight(its, active);
      } else if (e.key === 'Enter' && active >= 0) {
        e.preventDefault();
        const custIdx = parseInt(its[active].dataset.idx, 10);
        inputEl.removeEventListener('keydown', onKey);
        closeDropdown(wrap);
        callback(custIdx); // pass index — caller resolves from last results
      } else if (e.key === 'Escape') {
        closeDropdown(wrap);
        inputEl.removeEventListener('keydown', onKey);
      }
    });
  }

  function highlight(items, idx) {
    items.forEach((el, i) => {
      el.classList.toggle('active', i === idx);
    });
    if (items[idx]) items[idx].scrollIntoView({ block: 'nearest' });
  }

  /* ── getCustomer ──────────────────────────────────────────── */
  async function getCustomer(id) {
    try {
      const resp = await fetch(
        `/ebmpro_api/customers.php?id=${encodeURIComponent(id)}`,
        { headers: Auth.getAuthHeaders() }
      );
      if (!resp.ok) return null;
      const data = await resp.json();
      return data.customer || data || null;
    } catch {
      return DB.get('customers', id);
    }
  }

  /* ── saveCustomer ─────────────────────────────────────────── */
  async function saveCustomer(data) {
    const method   = data.id ? 'PUT' : 'POST';
    const result   = await Sync.syncData('/ebmpro_api/customers.php', data, method);
    // Cache locally
    if (result && (result.id || data.id)) {
      await DB.save('customers', Object.assign({}, data, result));
    }
    return result;
  }

  /* ── showAddCustomerModal ─────────────────────────────────── */
  function showAddCustomerModal(prefill = {}, onSave) {
    const modal = document.getElementById('addCustomerModal');
    if (!modal) return;

    // Prefill name if provided
    const nameEl = modal.querySelector('[name="customer_name"]');
    if (nameEl) nameEl.value = prefill.name || '';

    modal.classList.remove('hidden');
    if (nameEl) nameEl.focus();

    // Wire save button
    const form = modal.querySelector('#addCustomerForm');
    if (form) {
      form.onsubmit = async e => {
        e.preventDefault();
        const fd = new FormData(form);
        const obj = Object.fromEntries(fd.entries());
        try {
          const result = await saveCustomer(obj);
          const saved  = Object.assign({}, obj, result || {});
          hideAddCustomerModal();
          App.showToast('Customer saved.', 'success');
          if (typeof onSave === 'function') onSave(saved);
        } catch (err) {
          App.showToast(err.message || 'Save failed.', 'danger');
        }
      };
    }
  }

  function hideAddCustomerModal() {
    const modal = document.getElementById('addCustomerModal');
    if (modal) modal.classList.add('hidden');
  }

  /* ── showCustomerList ─────────────────────────────────────── */
  async function showCustomerList(query = '') {
    const tbody = document.getElementById('customersTbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner"></span> Loading…</td></tr>';

    try {
      const url   = query
        ? `/ebmpro_api/customers.php?q=${encodeURIComponent(query)}`
        : '/ebmpro_api/customers.php';
      const resp  = await fetch(url, { headers: Auth.getAuthHeaders() });
      const data  = await resp.json();
      const custs = Array.isArray(data) ? data : (data.customers || []);

      if (!custs.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No customers found.</td></tr>';
        return;
      }

      tbody.innerHTML = custs.map(c => `
        <tr onclick="Customer.showEditCustomerModal(${c.id})">
          <td><strong>${escapeHtml(c.name || '')}</strong></td>
          <td>${escapeHtml(c.phone || '')}</td>
          <td>${escapeHtml(c.email || '')}</td>
          <td>${escapeHtml(c.town || '')}</td>
          <td>${escapeHtml(c.county || '')}</td>
        </tr>
      `).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Failed to load customers.</td></tr>';
    }
  }

  /* ── showEditCustomerModal ────────────────────────────────── */
  async function showEditCustomerModal(id) {
    const cust = await getCustomer(id);
    if (!cust) return;

    const modal = document.getElementById('addCustomerModal');
    if (!modal) return;

    const title = modal.querySelector('.modal-header h3');
    if (title) title.textContent = 'Edit Customer';

    const fields = ['customer_name','phone','email','address1','address2','town','county','eircode','vat_number','account_number'];
    fields.forEach(f => {
      const el = modal.querySelector(`[name="${f}"]`);
      if (el) el.value = cust[f] || '';
    });

    // Set hidden id
    let hiddenId = modal.querySelector('[name="id"]');
    if (!hiddenId) {
      hiddenId = document.createElement('input');
      hiddenId.type = 'hidden';
      hiddenId.name = 'id';
      modal.querySelector('#addCustomerForm').appendChild(hiddenId);
    }
    hiddenId.value = id;

    modal.classList.remove('hidden');
  }

  /* ── escapeHtml helper ────────────────────────────────────── */
  function escapeHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  return {
    searchCustomers,
    debouncedSearch,
    renderCustomerDropdown,
    showAddCustomerModal,
    hideAddCustomerModal,
    saveCustomer,
    getCustomer,
    showCustomerList,
    showEditCustomerModal
  };
})();
