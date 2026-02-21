/* ============================================================
   Easy Builders Merchant Pro — app.js
   Main application controller
   ============================================================ */

const App = (() => {
  let _settings = {};

  /* ── Toast notifications ──────────────────────────────────── */
  function showToast(msg, type = 'info', durationMs = 3500) {
    const container = document.getElementById('toastContainer');
    if (!container) return;
    const toast = document.createElement('div');
    toast.className = `toast ${type}`;
    toast.textContent = msg;
    container.appendChild(toast);
    setTimeout(() => toast.remove(), durationMs);
  }

  /* ── Screen navigation ────────────────────────────────────── */
  function showScreen(id) {
    document.querySelectorAll('.screen').forEach(s => s.classList.remove('active'));
    const target = document.getElementById(id);
    if (target) target.classList.add('active');

    // Update nav button active state
    document.querySelectorAll('.nav-btn, .sidebar-nav button, .sidebar-nav a').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.screen === id);
    });

    // Lazy-load data for each screen
    switch (id) {
      case 'listScreen':     loadInvoiceList();     break;
      case 'customersScreen': Customer.showCustomerList(); break;
      case 'reportsScreen':  showReports();          break;
      case 'settingsScreen': showSettings();         break;
    }
  }

  /* ── Store management ─────────────────────────────────────── */
  function getCurrentStore() {
    return localStorage.getItem('ebmpro_store') || 'falcarragh';
  }

  function switchStore(storeCode) {
    localStorage.setItem('ebmpro_store', storeCode);
    const label = document.getElementById('storeLabel');
    if (label) label.textContent = capitalise(storeCode);
    showToast(`Switched to ${capitalise(storeCode)} store.`);
  }

  function capitalise(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
  }

  /* ── Header user display ──────────────────────────────────── */
  function updateHeaderUser(user) {
    const el = document.getElementById('headerUserName');
    if (el) el.textContent = user ? `${user.full_name || user.name || user.username} ▾` : 'Guest ▾';

    // Show/hide admin-only nav items
    const isAdmin = user && (user.role === 'admin' || user.role === 'manager');
    document.querySelectorAll('.admin-only').forEach(el => {
      el.classList.toggle('hidden', !isAdmin);
    });
  }

  /* ── Toggle user dropdown ─────────────────────────────────── */
  function toggleMenu() {
    const dd = document.getElementById('userDropdown');
    if (!dd) return;
    dd.classList.toggle('hidden');
    // Close on outside click
    const close = e => { if (!dd.contains(e.target)) { dd.classList.add('hidden'); document.removeEventListener('click', close); }};
    if (!dd.classList.contains('hidden')) setTimeout(() => document.addEventListener('click', close), 0);
  }

  /* ── Login ────────────────────────────────────────────────── */
  async function login() {
    const username = document.getElementById('loginUsername').value.trim();
    const password = document.getElementById('loginPassword').value;
    const errEl    = document.getElementById('loginError');
    const btn      = document.getElementById('loginBtn');

    if (!username || !password) {
      if (errEl) errEl.textContent = 'Please enter username and password.';
      return;
    }

    btn.disabled   = true;
    btn.innerHTML  = '<span class="spinner"></span> Signing in…';
    if (errEl) errEl.textContent = '';

    try {
      const user = await Auth.login(username, password);
      hideLoginModal();
      updateHeaderUser(user);
      showToast(`Welcome, ${user.full_name || user.name || user.username}!`, 'success');
      showScreen('invoiceScreen');
    } catch (err) {
      if (errEl) errEl.textContent = err.message || 'Login failed. Please try again.';
    } finally {
      btn.disabled  = false;
      btn.innerHTML = 'Sign In';
    }
  }

  /* ── Logout ───────────────────────────────────────────────── */
  async function logout() {
    await Auth.logout();
    updateHeaderUser(null);
    showLoginModal();
    showToast('Logged out.', 'info');
  }

  /* ── Login modal ──────────────────────────────────────────── */
  function showLoginModal() {
    document.getElementById('loginModal').classList.remove('hidden');
  }
  function hideLoginModal() {
    document.getElementById('loginModal').classList.add('hidden');
  }

  /* ── Load invoice list ────────────────────────────────────── */
  async function loadInvoiceList(filters = {}) {
    const tbody  = document.getElementById('invoiceListTbody');
    const storeFilter = document.getElementById('invoiceStoreFilter');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="7" class="text-center"><span class="spinner"></span> Loading…</td></tr>';

    const params = new URLSearchParams();
    if (filters.status && filters.status !== 'all') params.set('status', filters.status);
    if (filters.q)    params.set('q', filters.q);
    if (storeFilter && storeFilter.value) params.set('store_id', storeFilter.value);
    else params.set('store_id', getCurrentStore());

    try {
      const resp = await fetch(`/ebmpro_api/invoices.php?${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const list = Array.isArray(data) ? data : (data.invoices || []);

      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No invoices found.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map(inv => `
        <tr onclick="App.openInvoice('${inv.id}')">
          <td><strong>${escHtml(inv.invoice_number || '—')}</strong></td>
          <td>${escHtml(inv.customer_name || '—')}</td>
          <td>${fmtDate(inv.invoice_date)}</td>
          <td>${fmtDate(inv.due_date)}</td>
          <td class="text-right">${fmtCur(inv.total)}</td>
          <td class="text-right">${fmtCur(inv.balance)}</td>
          <td><span class="badge badge-${escHtml(inv.status || 'draft')}">${escHtml((inv.status || 'draft').replace('_',' ').toUpperCase())}</span></td>
        </tr>
      `).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Failed to load invoices.</td></tr>';
    }
  }

  /* ── Open invoice from list ───────────────────────────────── */
  async function openInvoice(id) {
    showScreen('invoiceScreen');
    try {
      await Invoice.loadInvoice(id);
    } catch (err) {
      showToast(err.message || 'Failed to load invoice.', 'danger');
    }
  }

  /* ── Load customer list ───────────────────────────────────── */
  function loadCustomerList() {
    Customer.showCustomerList();
  }

  /* ── Reports ──────────────────────────────────────────────── */
  async function showReports() {
    const dateFrom = document.getElementById('reportDateFrom');
    const dateTo   = document.getElementById('reportDateTo');

    const params = new URLSearchParams({ store_id: getCurrentStore() });
    if (dateFrom && dateFrom.value) params.set('date_from', dateFrom.value);
    if (dateTo   && dateTo.value)   params.set('date_to',   dateTo.value);

    const cards = {
      totalInvoiced:   document.getElementById('statTotalInvoiced'),
      totalPaid:       document.getElementById('statTotalPaid'),
      totalOutstanding:document.getElementById('statTotalOutstanding'),
      overdueCount:    document.getElementById('statOverdueCount')
    };

    try {
      const resp = await fetch(`/ebmpro_api/reports.php?${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      if (cards.totalInvoiced)    cards.totalInvoiced.textContent    = fmtCur(data.total_invoiced    || 0);
      if (cards.totalPaid)        cards.totalPaid.textContent        = fmtCur(data.total_paid        || 0);
      if (cards.totalOutstanding) cards.totalOutstanding.textContent = fmtCur(data.total_outstanding || 0);
      if (cards.overdueCount)     cards.overdueCount.textContent     = data.overdue_count || 0;

      // Render report invoice list
      const tbody = document.getElementById('reportInvoicesTbody');
      if (tbody && Array.isArray(data.invoices)) {
        tbody.innerHTML = data.invoices.map(inv => `
          <tr onclick="App.openInvoice('${inv.id}')">
            <td>${escHtml(inv.invoice_number || '—')}</td>
            <td>${escHtml(inv.customer_name  || '—')}</td>
            <td>${fmtDate(inv.invoice_date)}</td>
            <td class="text-right">${fmtCur(inv.total)}</td>
            <td class="text-right">${fmtCur(inv.balance)}</td>
            <td><span class="badge badge-${escHtml(inv.status || 'draft')}">${escHtml((inv.status || 'draft').replace('_',' ').toUpperCase())}</span></td>
          </tr>
        `).join('');
      }
    } catch {
      showToast('Failed to load report data.', 'danger');
    }
  }

  /* ── Settings ─────────────────────────────────────────────── */
  async function showSettings() {
    try {
      const resp = await fetch('/ebmpro_api/settings.php', { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      _settings  = data.settings || data || {};

      const fields = ['shop_name','shop_address','shop_phone','shop_email','vat_number','reg_number',
                      'smtp_host','smtp_port','smtp_user','smtp_from_name','smtp_from_email'];
      fields.forEach(f => {
        const el = document.getElementById(`setting_${f}`);
        if (el) el.value = _settings[f] || '';
      });
    } catch {
      showToast('Failed to load settings.', 'danger');
    }
  }

  async function saveSettings() {
    const fields = ['shop_name','shop_address','shop_phone','shop_email','vat_number','reg_number',
                    'smtp_host','smtp_port','smtp_user','smtp_from_name','smtp_from_email'];
    const data = {};
    fields.forEach(f => {
      const el = document.getElementById(`setting_${f}`);
      if (el) data[f] = el.value;
    });

    // SMTP password (only send if changed)
    const pwEl = document.getElementById('setting_smtp_password');
    if (pwEl && pwEl.value) data.smtp_password = pwEl.value;

    try {
      await Sync.syncData('/ebmpro_api/settings.php', data, 'PUT');
      _settings = Object.assign(_settings, data);
      showToast('Settings saved.', 'success');
    } catch (err) {
      showToast(err.message || 'Save failed.', 'danger');
    }
  }

  function getSettings() { return _settings; }

  /* ── Email invoice ────────────────────────────────────────── */
  function emailInvoice(invoiceId) {
    const modal   = document.getElementById('emailModal');
    if (!modal) return;
    const inv     = Invoice.getCurrent();
    const toEl    = modal.querySelector('[name="email_to"]');
    const subjEl  = modal.querySelector('[name="email_subject"]');
    const bodyEl  = modal.querySelector('[name="email_body"]');

    if (toEl)   toEl.value   = inv.customer_email || '';
    if (subjEl) subjEl.value = `${(inv.invoice_type === 'quote' ? 'Quote' : 'Invoice')} ${inv.invoice_number || ''} from Easy Builders Merchant`;
    if (bodyEl) bodyEl.value = `Dear ${inv.customer_name || 'Customer'},\n\nPlease find attached your ${inv.invoice_type || 'invoice'} for ${fmtCur(inv.total)}.\n\nThank you for your business.\n\nEasy Builders Merchant`;

    const sendBtn = modal.querySelector('#btnSendEmail');
    if (sendBtn) {
      sendBtn.onclick = async () => {
        const payload = {
          invoice_id: invoiceId || inv.id,
          to:         toEl   ? toEl.value   : '',
          subject:    subjEl ? subjEl.value : '',
          body:       bodyEl ? bodyEl.value : ''
        };
        sendBtn.disabled = true;
        try {
          await Sync.syncData('/ebmpro_api/email.php', payload, 'POST');
          closeModal('emailModal');
          showToast('Email sent!', 'success');
        } catch (err) {
          showToast(err.message || 'Failed to send email.', 'danger');
        } finally {
          sendBtn.disabled = false;
        }
      };
    }
    modal.classList.remove('hidden');
  }

  /* ── Payment modal ────────────────────────────────────────── */
  function showAddPaymentModal(invoiceId) {
    const modal  = document.getElementById('paymentModal');
    if (!modal) return;
    const inv    = Invoice.getCurrent();
    const amtEl  = modal.querySelector('[name="payment_amount"]');
    if (amtEl) amtEl.value = inv.balance || '';

    const saveBtn = modal.querySelector('#btnSavePayment');
    if (saveBtn) {
      saveBtn.onclick = async () => {
        const amount    = modal.querySelector('[name="payment_amount"]').value;
        const methodEl  = modal.querySelector('input[name="payment_method"]:checked');
        const method    = methodEl ? methodEl.value : 'cash';
        const date      = modal.querySelector('[name="payment_date"]').value   || new Date().toISOString().slice(0,10);
        const reference = modal.querySelector('[name="payment_reference"]').value || '';

        saveBtn.disabled = true;
        try {
          await Invoice.addPayment(invoiceId || inv.id, amount, method, date, reference);
          closeModal('paymentModal');
          showToast('Payment recorded.', 'success');
        } catch (err) {
          showToast(err.message || 'Failed to save payment.', 'danger');
        } finally {
          saveBtn.disabled = false;
        }
      };
    }
    modal.classList.remove('hidden');
  }

  /* ── Audit log ────────────────────────────────────────────── */
  async function showAuditLog() {
    const tbody = document.getElementById('auditTbody');
    if (!tbody) return;

    tbody.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner"></span></td></tr>';

    try {
      const resp = await fetch('/ebmpro_api/audit.php', { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const logs = Array.isArray(data) ? data : (data.logs || []);

      tbody.innerHTML = logs.map(l => `
        <tr>
          <td>${escHtml(l.created_at || '')}</td>
          <td>${escHtml(l.user_name  || '')}</td>
          <td>${escHtml(l.action     || '')}</td>
          <td>${escHtml(l.entity_type|| '')}</td>
          <td>${escHtml(l.entity_id  || '')}</td>
        </tr>
      `).join('') || '<tr><td colspan="5" class="text-center text-muted">No log entries.</td></tr>';
    } catch {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Failed to load audit log.</td></tr>';
    }
  }

  /* ── Generic modal close ──────────────────────────────────── */
  function closeModal(id) {
    const m = document.getElementById(id);
    if (m) m.classList.add('hidden');
  }

  /* ── Print PDF ────────────────────────────────────────────── */
  function printCurrentInvoice() {
    PDF.printInvoicePDF(Invoice.getCurrent(), _settings);
  }

  /* ── Save/download PDF ────────────────────────────────────── */
  function downloadCurrentPDF() {
    PDF.saveInvoicePDF(Invoice.getCurrent(), _settings);
  }

  /* ── Save current invoice ─────────────────────────────────── */
  async function saveCurrentInvoice() {
    const btn = document.getElementById('btnSaveInvoice');
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner"></span> Saving…'; }
    try {
      const result = await Invoice.saveInvoice();
      if (result && result.offline) {
        showToast('Saved locally (offline — will sync when online).', 'warning');
      } else {
        showToast('Invoice saved!', 'success');
      }
    } catch (err) {
      showToast(err.message || 'Save failed.', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '💾 Save Invoice'; }
    }
  }

  /* ── Helpers ──────────────────────────────────────────────── */
  function fmtDate(str) {
    if (!str) return '';
    const d = new Date(str);
    if (isNaN(d)) return str;
    return [String(d.getDate()).padStart(2,'0'), String(d.getMonth()+1).padStart(2,'0'), d.getFullYear()].join('/');
  }
  function fmtCur(n) {
    return '€' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
  function escHtml(str) {
    return String(str || '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
  }

  /* ── DOMContentLoaded bootstrap ──────────────────────────── */
  async function init() {
    await DB.init();
    Sync.initSync();

    // Store selector
    const storeSel = document.getElementById('storeSelect');
    if (storeSel) {
      storeSel.value = getCurrentStore();
      storeSel.addEventListener('change', () => switchStore(storeSel.value));
    }

    // Wire collapsible sections
    document.querySelectorAll('.collapsible-header').forEach(header => {
      const body = header.nextElementSibling;
      if (!body) return;
      header.addEventListener('click', () => {
        const open = header.classList.toggle('open');
        body.style.maxHeight = open ? body.scrollHeight + 'px' : '0';
      });
      body.style.maxHeight = '0';
      body.style.overflow  = 'hidden';
    });

    // Check auth
    let user = null;
    try {
      user = await Auth.checkAuth();
    } catch { /* network error — allow offline use if token exists */ }

    if (!user && Auth.isLoggedIn()) {
      // Token exists but network unreachable — use cached user
      user = Auth.getUser();
    }

    if (user) {
      hideLoginModal();
      updateHeaderUser(user);
      showScreen('invoiceScreen');
      Invoice.newInvoice();
    } else {
      showLoginModal();
    }

    // Wire product search
    const prodSearch = document.getElementById('productSearch');
    if (prodSearch) {
      prodSearch.addEventListener('input', async () => {
        const q       = prodSearch.value;
        const results = await Product.debouncedSearch(q) || [];
        // Await if it returned a Promise
        const products = results instanceof Promise ? await results : results;
        Product.renderProductDropdown(products || [], prodSearch, product => {
          Invoice.addItem(product);
          prodSearch.value = '';
          prodSearch.focus();
        });
      });
      prodSearch.addEventListener('blur', () => {
        setTimeout(() => Product.closeProductDropdown(prodSearch.closest('.product-search-wrap') || prodSearch.parentElement), 150);
      });
    }

    // Wire customer search
    const custSearch = document.getElementById('customerSearch');
    if (custSearch) {
      let lastResults = [];
      custSearch.addEventListener('input', async () => {
        const q = custSearch.value;
        if (q.length < 1) return;
        const results = await Customer.searchCustomers(q);
        lastResults   = results;
        Customer.renderCustomerDropdown(results, custSearch, custOrIdx => {
          const cust = typeof custOrIdx === 'number' ? lastResults[custOrIdx] : custOrIdx;
          if (cust) Invoice.setCustomer(cust);
        });
      });
      custSearch.addEventListener('blur', () => {
        const wrap = custSearch.parentElement;
        setTimeout(() => { const l = wrap.querySelector('.customer-list'); if (l) l.remove(); }, 150);
      });
    }

    // Wire invoice date/due date
    const invDate = document.getElementById('invoiceDateInput');
    const dueDate = document.getElementById('dueDateInput');
    if (invDate) invDate.addEventListener('change', () => { Invoice.getCurrent().invoice_date = invDate.value; });
    if (dueDate) dueDate.addEventListener('change', () => { Invoice.getCurrent().due_date     = dueDate.value; });

    // Wire notes
    const notes = document.getElementById('invoiceNotes');
    const iNotes= document.getElementById('internalNotes');
    if (notes)  notes.addEventListener('input',  () => { Invoice.getCurrent().notes          = notes.value;  });
    if (iNotes) iNotes.addEventListener('input', () => { Invoice.getCurrent().internal_notes = iNotes.value; });

    // Wire delivery address
    ['deliveryAddress1','deliveryAddress2','deliveryTown','deliveryCounty','deliveryEircode']
      .forEach(id => {
        const el = document.getElementById(id);
        const key = id.replace('delivery','').replace(/^./, c => c.toLowerCase());
        const fieldMap = {
          'address1': 'address1','address2': 'address2','town': 'town','county': 'county','eircode': 'eircode'
        };
        if (el) el.addEventListener('input', () => {
          Invoice.getCurrent().delivery_address[fieldMap[key] || key] = el.value;
        });
      });

    // Wire invoice filter pills
    document.querySelectorAll('.filter-pill[data-status]').forEach(pill => {
      pill.addEventListener('click', () => {
        document.querySelectorAll('.filter-pill[data-status]').forEach(p => p.classList.remove('active'));
        pill.classList.add('active');
        loadInvoiceList({ status: pill.dataset.status });
      });
    });

    // Wire invoice search
    const invSearch = document.getElementById('invoiceSearchInput');
    if (invSearch) {
      let searchTimer;
      invSearch.addEventListener('input', () => {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(() => loadInvoiceList({ q: invSearch.value }), 350);
      });
    }

    // Wire customer list search
    const custListSearch = document.getElementById('customerSearchInput');
    if (custListSearch) {
      let t;
      custListSearch.addEventListener('input', () => {
        clearTimeout(t);
        t = setTimeout(() => Customer.showCustomerList(custListSearch.value), 350);
      });
    }
  }

  document.addEventListener('DOMContentLoaded', init);

  return {
    showScreen,
    getCurrentStore,
    switchStore,
    toggleMenu,
    login,
    logout,
    showLoginModal,
    hideLoginModal,
    loadInvoiceList,
    loadCustomerList,
    openInvoice,
    showReports,
    showSettings,
    saveSettings,
    getSettings,
    emailInvoice,
    showAddPaymentModal,
    showAuditLog,
    closeModal,
    printCurrentInvoice,
    downloadCurrentPDF,
    saveCurrentInvoice,
    showToast,
    updateHeaderUser
  };
})();