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
      return Array.isArray(data) ? data : (data.data || data.customers || []);
    } catch {
      // Fallback to local IndexedDB cache
      try {
        const all = await DB.getAll('customers');
        const q   = query.toLowerCase();
        return all.filter(c =>
          (c.company_name  || c.name  || '').toLowerCase().includes(q) ||
          (c.inv_telephone || c.phone || '').toLowerCase().includes(q) ||
          (c.email_address || c.email || '').toLowerCase().includes(q)
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
      const custName  = cust.company_name  || cust.name  || '';
      const custPhone = cust.inv_telephone || cust.phone || '';
      const custEmail = cust.email_address || cust.email || '';
      const custTown  = cust.inv_town      || cust.town  || '';
      li.innerHTML = `
        <strong>${escapeHtml(custName)}</strong>
        ${custPhone ? `<span class="text-muted small"> · ${escapeHtml(custPhone)}</span>` : ''}
        ${custEmail ? `<span class="text-muted small"> · ${escapeHtml(custEmail)}</span>` : ''}
        ${custTown  ? `<div class="text-muted small">${escapeHtml(custTown)}</div>` : ''}
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
      return data.data || data.customer || data || null;
    } catch {
      return DB.get('customers', id);
    }
  }

  /* ── saveCustomer ─────────────────────────────────────────── */
  async function saveCustomer(data) {
    const method = data.id ? 'PUT' : 'POST';
    // Map form field names to API/DB column names
    const mapped = {
      company_name:  data.customer_name  || data.company_name  || data.name,
      inv_telephone: data.phone          || data.inv_telephone,
      email_address: data.email          || data.email_address,
      address_1:     data.address1       || data.address_1,
      address_2:     data.address2       || data.address_2,
      inv_town:      data.town           || data.inv_town,
      inv_region:    data.county         || data.inv_region,
      inv_postcode:  data.eircode        || data.inv_postcode,
      account_no:    data.account_number || data.account_no,
      contact_name:  data.contact_name,
      vat_registered: (data.vat_registered === true || data.vat_registered === 1 || (data.vat_number && String(data.vat_number).trim())) ? 1 : 0,
      notes:         data.notes,
      payment_terms: data.payment_terms,
      id:            data.id,
    };
    const result   = await Sync.syncData('/ebmpro_api/customers.php', mapped, method);
    // Cache locally
    if (result && (result.id || mapped.id)) {
      await DB.save('customers', Object.assign({}, mapped, result));
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
          showCustomerList();
          if (typeof onSave === 'function') onSave(saved);
        } catch (err) {
          App.showToast(err.message || 'Save failed.', 'danger');
        }
      };
    }
  }

  function hideAddCustomerModal() {
    const modal = document.getElementById('addCustomerModal');
    if (!modal) return;
    modal.classList.add('hidden');
    const form = modal.querySelector('#addCustomerForm');
    if (form) {
      form.reset();
      const hiddenId = form.querySelector('[name="id"]');
      if (hiddenId) hiddenId.remove();
    }
    const title = modal.querySelector('.modal-header h3');
    if (title) title.textContent = 'Add Customer';
  }

  /* ── showCustomerList ─────────────────────────────────────── */
  async function showCustomerList(query = '') {
    const tbody = document.getElementById('customersTbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><span class="spinner"></span> Loading…</td></tr>';

    try {
      const url   = query
        ? `/ebmpro_api/customers.php?q=${encodeURIComponent(query)}`
        : '/ebmpro_api/customers.php';
      const resp  = await fetch(url, { headers: Auth.getAuthHeaders() });
      const data  = await resp.json();
      const custs = Array.isArray(data) ? data : (data.data || data.customers || []);

      if (!custs.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No customers found.</td></tr>';
        return;
      }

      tbody.innerHTML = custs.map(c => `
        <tr>
          <td onclick="Customer.showEditCustomerModal(${c.id})"><strong>${escapeHtml(c.company_name || c.name || '')}</strong></td>
          <td onclick="Customer.showEditCustomerModal(${c.id})">${escapeHtml(c.inv_telephone || c.phone || '')}</td>
          <td onclick="Customer.showEditCustomerModal(${c.id})">${escapeHtml(c.email_address || c.email || '')}</td>
          <td onclick="Customer.showEditCustomerModal(${c.id})">${escapeHtml(c.inv_town || c.town || '')}</td>
          <td onclick="Customer.showEditCustomerModal(${c.id})">${escapeHtml(c.inv_region || c.county || '')}</td>
          <td><button class="btn btn-sm btn-light" onclick="App.openStatementModal(${c.id})" title="Send account statement">📄 Statement</button></td>
        </tr>
      `).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Failed to load customers.</td></tr>';
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

    const fields = {
      customer_name:  cust.company_name  || cust.customer_name  || '',
      phone:          cust.inv_telephone || cust.phone          || '',
      email:          cust.email_address || cust.email          || '',
      address1:       cust.address_1     || cust.address1       || '',
      address2:       cust.address_2     || cust.address2       || '',
      town:           cust.inv_town      || cust.town           || '',
      county:         cust.inv_region    || cust.county         || '',
      eircode:        cust.inv_postcode  || cust.eircode        || '',
      vat_number:     cust.vat_number    || '',
      account_number: cust.account_no    || cust.account_number || '',
    };
    Object.entries(fields).forEach(([f, val]) => {
      const el = modal.querySelector(`[name="${f}"]`);
      if (el) el.value = val;
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

    const form = modal.querySelector('#addCustomerForm');
    if (form) {
      form.onsubmit = async e => {
        e.preventDefault();
        const fd  = new FormData(form);
        const obj = Object.fromEntries(fd.entries());
        try {
          await saveCustomer(obj);
          hideAddCustomerModal();
          App.showToast('Customer updated.', 'success');
          showCustomerList();
        } catch (err) {
          App.showToast(err.message || 'Save failed.', 'danger');
        }
      };
    }
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