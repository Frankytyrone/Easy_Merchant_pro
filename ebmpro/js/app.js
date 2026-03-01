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
      case 'adminScreen':    loadAdminDashboard(); loadBackupList(); break;
      case 'stockScreen':    loadStock();            break;
    }
  }

  /* ── Store management ─────────────────────────────────────── */
  function getCurrentStore() {
    return localStorage.getItem('ebmpro_store') || 'FAL';
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

    // Show/hide admin-only nav items (admin + manager)
    const isAdmin = user && (user.role === 'admin' || user.role === 'manager');
    document.querySelectorAll('.admin-only').forEach(el => {
      el.classList.toggle('hidden', !isAdmin);
    });

    // Show/hide items for admin role only
    const isAdminRole = user && user.role === 'admin';
    document.querySelectorAll('.admin-role-only').forEach(el => {
      el.classList.toggle('hidden', !isAdminRole);
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
    if (storeFilter && storeFilter.value) params.set('store_code', storeFilter.value);
    else params.set('store_code', getCurrentStore());

    try {
      const resp = await fetch(`/ebmpro_api/invoices.php?${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const list = Array.isArray(data) ? data : (data.invoices || data.data || []);

      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No invoices found.</td></tr>';
        hideBanner('overdueBanner');
        return;
      }

      const today = new Date(); today.setHours(0,0,0,0);
      const overdueList = list.filter(inv => {
        if (!inv.due_date) return false;
        if (inv.status !== 'sent' && inv.status !== 'part_paid' && inv.status !== 'partial') return false;
        return new Date(inv.due_date) < today;
      });

      // Show/hide overdue banner
      const banner = document.getElementById('overdueBanner');
      if (banner) {
        if (overdueList.length > 0) {
          banner.classList.remove('hidden');
          banner.style.display = 'flex';
          const bannerText = document.getElementById('overdueBannerText');
          if (bannerText) bannerText.textContent = `⚠️ ${overdueList.length} invoice${overdueList.length > 1 ? 's' : ''} are overdue`;
          // Store for the modal
          banner._overdueList = overdueList;
        } else {
          banner.classList.add('hidden');
          banner.style.display = 'none';
        }
      }

      tbody.innerHTML = list.map(inv => {
        const emailedBadge = inv.email_sent_at ? ' <span title="Emailed on ' + escHtml(inv.email_sent_at) + '">📧</span>' : '';
        return `
        <tr onclick="App.openInvoice('${inv.id}')">
          <td><strong>${escHtml(inv.invoice_number || '—')}</strong>${emailedBadge}</td>
          <td>${escHtml(inv.customer_name || '—')}</td>
          <td>${fmtDate(inv.invoice_date)}</td>
          <td>${fmtDate(inv.due_date)}</td>
          <td class="text-right">${fmtCur(inv.total)}</td>
          <td class="text-right">${fmtCur(inv.balance)}</td>
          <td><span class="badge badge-${escHtml(inv.status || 'draft')}">${escHtml((inv.status || 'draft').replace('_',' ').toUpperCase())}</span></td>
        </tr>
      `;
      }).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Failed to load invoices.</td></tr>';
    }
  }

  function hideBanner(id) {
    const el = document.getElementById(id);
    if (el) { el.classList.add('hidden'); el.style.display = 'none'; }
  }

  /* ── Open invoice from list ───────────────────────────────── */
  async function openInvoice(id) {
    showScreen('invoiceScreen');
    try {
      await Invoice.loadInvoice(id);
      // Load email tracking after invoice is loaded
      loadEmailTrackingStatus(id);
      // Show/hide Send Reminder button
      const inv = Invoice.getCurrent();
      const reminderBtn = document.getElementById('btnSendReminder');
      if (reminderBtn) {
        const today = new Date(); today.setHours(0,0,0,0);
        const isOverdue = inv && inv.due_date && new Date(inv.due_date) < today &&
          (inv.status === 'sent' || inv.status === 'part_paid' || inv.status === 'partial');
        reminderBtn.classList.toggle('hidden', !isOverdue);
      }
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

    const params = new URLSearchParams({ store_code: getCurrentStore() });
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
    const modal = document.getElementById('emailInvoiceModal');
    if (!modal) return;
    const inv    = Invoice.getCurrent();
    const toEl   = document.getElementById('emailInv_to');
    const subjEl = document.getElementById('emailInv_subject');
    const msgEl  = document.getElementById('emailInv_message');

    if (toEl)   toEl.value   = inv.customer_email || '';
    if (subjEl) subjEl.value = `Invoice ${inv.invoice_number || ''} from Easy Builders Merchant`;
    if (msgEl)  msgEl.value  = `Dear ${inv.customer_name || 'Customer'},\n\nPlease find attached your invoice ${inv.invoice_number || ''} for ${fmtCur(inv.total)}.\n\nPayment is due by ${fmtDate(inv.due_date) || 'the due date shown on the invoice'}.\n\nThank you for your business.\n\nEasy Builders Merchant`;

    const sendBtn = document.getElementById('btnSendEmailInvoice');
    if (sendBtn) {
      sendBtn.onclick = async () => {
        const id = invoiceId || inv.id;
        const payload = {
          invoice_id: id,
          to_email:   toEl   ? toEl.value   : '',
          subject:    subjEl ? subjEl.value : '',
          message:    msgEl  ? msgEl.value  : '',
          type:       'invoice',
        };
        sendBtn.disabled = true;
        try {
          await Sync.syncData('/ebmpro_api/email.php', payload, 'POST');
          closeModal('emailInvoiceModal');
          showToast(`✅ Invoice emailed to ${payload.to_email}`, 'success');
          if (id) loadEmailTrackingStatus(id);
        } catch {
          showToast('❌ Failed to send email — check SMTP settings', 'danger');
        } finally {
          sendBtn.disabled = false;
        }
      };
    }
    modal.classList.remove('hidden');
  }

  /* ── Load email tracking status ───────────────────────────── */
  async function loadEmailTrackingStatus(invoiceId) {
    const el = document.getElementById('emailTrackingStatus');
    if (!el || !invoiceId) return;
    try {
      const resp = await fetch(`/ebmpro_api/email.php?invoice_id=${invoiceId}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      if (!data.success || !data.data) {
        el.classList.add('hidden');
        return;
      }
      const d = data.data;
      let html = `📧 Emailed on ${fmtDate(d.emailed_at)} — `;
      if (d.opened_count && parseInt(d.opened_count) > 0) {
        html += `👁️ Opened ${d.opened_count} time${d.opened_count > 1 ? 's' : ''}, last opened ${fmtDate(d.last_opened_at)}`;
      } else {
        html += '⏳ Not opened yet';
      }
      el.innerHTML = html;
      el.classList.remove('hidden');
    } catch {
      el.classList.add('hidden');
    }
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

  /* ── Send single overdue reminder from invoice detail ────────── */
  async function sendSingleReminder() {
    const inv = Invoice.getCurrent();
    if (!inv || !inv.id) { showToast('Please save the invoice first.', 'warning'); return; }
    const btn = document.getElementById('btnSendReminder');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Sending…'; }
    try {
      await Sync.syncData('/ebmpro_api/reminders.php', { invoice_id: inv.id }, 'POST');
      showToast('✅ Reminder sent.', 'success');
    } catch {
      showToast('❌ Failed to send reminder — check SMTP settings', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '⚠️ Send Reminder'; }
    }
  }

  /* ── Open overdue modal ───────────────────────────────────── */
  function openOverdueModal() {
    const modal = document.getElementById('overdueModal');
    if (!modal) return;
    const banner = document.getElementById('overdueBanner');
    const list   = (banner && banner._overdueList) ? banner._overdueList : [];
    const tbody  = document.getElementById('overdueInvoicesTbody');
    if (tbody) {
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No overdue invoices.</td></tr>';
      } else {
        const today = new Date(); today.setHours(0,0,0,0);
        tbody.innerHTML = list.map(inv => {
          const due   = new Date(inv.due_date);
          const days  = Math.floor((today - due) / 86400000);
          return `<tr>
            <td><input type="checkbox" class="overdue-chk" value="${escHtml(String(inv.id))}" checked></td>
            <td>${escHtml(inv.invoice_number || '—')}</td>
            <td>${escHtml(inv.customer_name  || '—')}</td>
            <td class="text-right">${fmtCur(inv.balance)}</td>
            <td>${days} day${days !== 1 ? 's' : ''}</td>
          </tr>`;
        }).join('');
      }
    }
    modal.classList.remove('hidden');
  }

  /* ── Toggle all overdue checkboxes ───────────────────────── */
  function toggleAllOverdue(checked) {
    document.querySelectorAll('.overdue-chk').forEach(cb => { cb.checked = checked; });
  }

  /* ── Send selected reminders ──────────────────────────────── */
  async function sendSelectedReminders() {
    const ids = Array.from(document.querySelectorAll('.overdue-chk:checked')).map(cb => parseInt(cb.value));
    if (!ids.length) { showToast('No invoices selected.', 'warning'); return; }
    const btn = document.getElementById('btnSendSelectedReminders');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Sending…'; }
    try {
      const result = await Sync.syncData('/ebmpro_api/reminders.php', { invoice_ids: ids }, 'POST');
      closeModal('overdueModal');
      showToast(`✅ Sent ${result.sent || ids.length} reminder${ids.length !== 1 ? 's' : ''}.`, 'success');
    } catch {
      showToast('❌ Failed to send reminders — check SMTP settings', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '📧 Send Selected Reminders'; }
    }
  }

  /* ── Admin dashboard ──────────────────────────────────────── */
  async function loadAdminDashboard() {
    try {
      const [dashResp, auditResp] = await Promise.all([
        fetch('/ebmpro_api/admin.php?action=dashboard', { headers: Auth.getAuthHeaders() }),
        fetch('/ebmpro_api/admin.php?action=audit&limit=10', { headers: Auth.getAuthHeaders() }),
      ]);
      const dash  = await dashResp.json();
      const audit = await auditResp.json();

      if (dash.success) {
        // Sales
        const sales = dash.today_sales || [];
        const fal = sales.find(s => s.store_code === 'FAL');
        const gwe = sales.find(s => s.store_code === 'GWE');
        const falEl = document.getElementById('adminFalSales');
        const gweEl = document.getElementById('adminGweSales');
        if (falEl) falEl.textContent = fmtCur(fal ? fal.total_sales : 0);
        if (gweEl) gweEl.textContent = fmtCur(gwe ? gwe.total_sales : 0);

        // Health
        const h = dash.health || {};
        const healthEl = document.getElementById('adminHealthInfo');
        if (healthEl) healthEl.innerHTML = `
          <div>💾 Database: ${h.db_size_mb || 0} MB</div>
          <div>🔄 Sync pending: ${h.sync_pending || 0}</div>
          <div>📦 Last backup: ${h.last_backup ? fmtDate(h.last_backup) : 'None'}</div>
        `;
        const sizeEl = document.getElementById('adminDbSize');
        if (sizeEl) sizeEl.textContent = (h.db_size_mb || 0) + ' MB';
        const syncEl = document.getElementById('adminSyncPending');
        if (syncEl) syncEl.textContent = h.sync_pending || 0;

        // Who's online
        const onlineEl = document.getElementById('adminOnlineList');
        if (onlineEl) {
          const online = dash.online || [];
          if (!online.length) {
            onlineEl.textContent = 'No active users in the last 5 minutes.';
          } else {
            onlineEl.innerHTML = online.map(u =>
              `<div>👤 ${escHtml(u.username)} (${escHtml(u.store_code || '—')}) — ${escHtml(u.last_seen || '')}</div>`
            ).join('');
          }
        }
      }

      // Audit log
      const auditTbody = document.getElementById('adminAuditTbody');
      if (auditTbody) {
        const logs = audit.success ? (audit.data || []) : [];
        auditTbody.innerHTML = logs.map(l => `
          <tr>
            <td>${escHtml(l.created_at || '')}</td>
            <td>${escHtml(l.username   || '')}</td>
            <td>${escHtml(l.action     || '')}</td>
            <td>${escHtml(l.table_name || '')}</td>
            <td>${escHtml(String(l.record_id || ''))}</td>
          </tr>
        `).join('') || '<tr><td colspan="5" class="text-center text-muted">No entries.</td></tr>';
      }

      // Load operators
      await loadOperators();
    } catch {
      showToast('Failed to load admin dashboard.', 'danger');
    }
  }

  /* ── Admin: run backup ────────────────────────────────────── */
  async function runAdminBackup() {
    const btn = document.querySelector('[onclick="App.runAdminBackup()"]');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Backing up…'; }
    try {
      const resp = await fetch('/ebmpro_api/backup.php?action=create', {
        method: 'POST',
        headers: Auth.getAuthHeaders(),
      });
      const data = await resp.json();
      if (data.success) {
        showToast(`✅ Backup created: ${data.filename} (${data.size_mb} MB)`, 'success');
        loadBackupList();
      } else {
        showToast(data.error || '❌ Backup failed.', 'danger');
      }
    } catch {
      showToast('❌ Backup failed.', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '💾 Run Backup Now'; }
    }
  }

  /* ── Admin: load operators ────────────────────────────────── */
  async function loadOperators() {
    const tbody = document.getElementById('adminUsersTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner"></span></td></tr>';
    try {
      const resp = await fetch('/ebmpro_api/admin.php?action=operators', { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const users = data.data || [];
      if (!users.length) {
        tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No users found.</td></tr>';
        return;
      }
      tbody.innerHTML = users.map(u => `
        <tr>
          <td>${escHtml(u.username || '')}</td>
          <td>${escHtml(u.role || '')}</td>
          <td>${escHtml(u.store_id ? 'Store ' + u.store_id : 'All')}</td>
          <td><span class="badge badge-${u.active ? 'paid' : 'cancelled'}">${u.active ? 'Active' : 'Locked'}</span></td>
          <td style="white-space:nowrap">
            <button class="btn btn-sm btn-light" onclick="App.openEditUserModal(${parseInt(u.id)},'${escHtml(u.username)}','${escHtml(u.role)}',${parseInt(u.store_id) || ''})">Edit</button>
            <button class="btn btn-sm btn-light" onclick="App.lockOperator(${parseInt(u.id)},${Number(u.active) ? 0 : 1})">${u.active ? '🔒 Lock' : '🔓 Unlock'}</button>
            <button class="btn btn-sm btn-light" onclick="App.promptResetPassword(${parseInt(u.id)})">🔑 Reset PW</button>
          </td>
        </tr>
      `).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Failed to load users.</td></tr>';
    }
  }

  /* ── Admin: open add user modal ───────────────────────────── */
  function openAddUserModal() {
    document.getElementById('editUserId').value = '';
    document.getElementById('editUserUsername').value = '';
    document.getElementById('editUserPassword').value = '';
    document.getElementById('editUserRole').value = 'counter';
    document.getElementById('editUserStore').value = '';
    document.getElementById('addUserModalTitle').textContent = '👤 Add User';
    const note = document.getElementById('editUserPwNote');
    if (note) note.textContent = '(required for new user)';
    document.getElementById('addUserModal').classList.remove('hidden');
  }

  /* ── Admin: open edit user modal ──────────────────────────── */
  function openEditUserModal(id, username, role, storeId) {
    document.getElementById('editUserId').value = id;
    document.getElementById('editUserUsername').value = username;
    document.getElementById('editUserPassword').value = '';
    document.getElementById('editUserRole').value = role;
    document.getElementById('editUserStore').value = storeId || '';
    document.getElementById('addUserModalTitle').textContent = '✏️ Edit User';
    const note = document.getElementById('editUserPwNote');
    if (note) note.textContent = '(leave blank to keep existing)';
    document.getElementById('addUserModal').classList.remove('hidden');
  }

  /* ── Admin: save operator ─────────────────────────────────── */
  async function saveOperator() {
    const id       = document.getElementById('editUserId').value;
    const username = document.getElementById('editUserUsername').value.trim();
    const password = document.getElementById('editUserPassword').value;
    const role     = document.getElementById('editUserRole').value;
    const storeId  = document.getElementById('editUserStore').value;

    if (!username) { showToast('Username is required.', 'warning'); return; }
    if (!id && !password) { showToast('Password is required for new users.', 'warning'); return; }

    const data = { username, role, store_id: storeId || null };
    if (id) data.id = parseInt(id);
    if (password) data.password = password;

    const btn = document.getElementById('btnSaveUser');
    if (btn) btn.disabled = true;
    try {
      await Sync.syncData('/ebmpro_api/admin.php?action=operator', data, 'POST');
      closeModal('addUserModal');
      showToast('✅ User saved.', 'success');
      await loadOperators();
    } catch (err) {
      showToast(err.message || '❌ Failed to save user.', 'danger');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  /* ── Admin: lock/unlock operator ─────────────────────────── */
  async function lockOperator(userId, active) {
    try {
      await Sync.syncData('/ebmpro_api/admin.php?action=lock', { id: userId, active }, 'POST');
      showToast(active ? '🔓 User unlocked.' : '🔒 User locked.', 'success');
      await loadOperators();
    } catch {
      showToast('❌ Failed to update user.', 'danger');
    }
  }

  /* ── Admin: prompt reset password ────────────────────────── */
  async function promptResetPassword(userId) {
    const newPass = prompt('Enter new password for user (min 6 characters):');
    if (!newPass || newPass.length < 6) { showToast('Password must be at least 6 characters.', 'warning'); return; }
    await resetPassword(userId, newPass);
  }

  /* ── Admin: reset password ────────────────────────────────── */
  async function resetPassword(userId, newPass) {
    try {
      await Sync.syncData('/ebmpro_api/admin.php?action=reset_password', { id: userId, password: newPass }, 'POST');
      showToast('✅ Password reset.', 'success');
    } catch {
      showToast('❌ Failed to reset password.', 'danger');
    }
  }

  /* ── Stock: load ──────────────────────────────────────────── */
  async function loadStock() {
    const tbody   = document.getElementById('stockTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><span class="spinner"></span> Loading…</td></tr>';

    const lowOnly = document.getElementById('stockLowOnly');
    const params  = lowOnly && lowOnly.checked ? '?low=1' : '';

    try {
      const resp = await fetch(`/ebmpro_api/stock.php${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const list = data.data || [];

      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No stock records found.</td></tr>';
        return;
      }

      tbody.innerHTML = list.map(s => {
        const qty    = parseFloat(s.current_qty);
        const minQty = parseFloat(s.min_qty || 0);
        let rowStyle = '';
        if (qty === 0)        rowStyle = 'background:rgba(255,152,0,.12)';
        else if (qty < minQty) rowStyle = 'background:rgba(244,67,54,.12)';
        return `<tr style="${rowStyle}" data-code="${escHtml(s.product_code || '')}" data-desc="${escHtml(s.description || '')}">
          <td><strong>${escHtml(s.product_code || '—')}</strong></td>
          <td>${escHtml(s.description || '—')}</td>
          <td class="text-right" style="${qty === 0 ? 'color:#ff9800;font-weight:600' : qty < minQty ? 'color:#f44336;font-weight:600' : ''}">${qty}</td>
          <td class="text-right">${minQty || '—'}</td>
          <td>${s.last_updated ? fmtDate(s.last_updated) : '—'}</td>
          <td>
            <button class="btn btn-sm btn-light" onclick="App.openAdjustStockModal(${parseInt(s.product_id)},'${escHtml(s.description || '')}')">
              ✏️ Adjust
            </button>
          </td>
        </tr>`;
      }).join('');

      // Restore any active filter
      const searchInput = document.getElementById('stockSearchInput');
      if (searchInput && searchInput.value) filterStock(searchInput.value);
    } catch {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Failed to load stock data.</td></tr>';
    }
  }

  /* ── Stock: filter table ──────────────────────────────────── */
  function filterStock(query) {
    const q = (query || '').toLowerCase();
    document.querySelectorAll('#stockTbody tr').forEach(row => {
      const code = (row.dataset.code || '').toLowerCase();
      const desc = (row.dataset.desc || '').toLowerCase();
      row.style.display = (!q || code.includes(q) || desc.includes(q)) ? '' : 'none';
    });
  }

  /* ── Stock: open adjust modal ─────────────────────────────── */
  function openAdjustStockModal(productId, name) {
    document.getElementById('adjustStockProductId').value = productId;
    document.getElementById('adjustStockProductName').value = name;
    document.getElementById('adjustStockQty').value = '';
    document.getElementById('adjustStockReason').value = 'Delivery received';
    closeModal('adjustStockModal');
    document.getElementById('adjustStockModal').classList.remove('hidden');
  }

  /* ── Stock: save adjustment ───────────────────────────────── */
  async function saveStockAdjust() {
    const productId = parseInt(document.getElementById('adjustStockProductId').value);
    const change    = parseFloat(document.getElementById('adjustStockQty').value);
    const reason    = document.getElementById('adjustStockReason').value;

    if (!productId || isNaN(change) || change === 0) {
      showToast('Please enter a valid quantity change.', 'warning');
      return;
    }

    const btn = document.getElementById('btnSaveStockAdjust');
    if (btn) btn.disabled = true;
    try {
      await Sync.syncData('/ebmpro_api/stock.php', { product_id: productId, quantity_change: change, reason }, 'POST');
      closeModal('adjustStockModal');
      showToast('✅ Stock updated.', 'success');
      await loadStock();
    } catch (err) {
      showToast(err.message || '❌ Failed to update stock.', 'danger');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  /* ── Adjust stock (public API) ────────────────────────────── */
  async function adjustStock(productId, change, reason) {
    try {
      await Sync.syncData('/ebmpro_api/stock.php', { product_id: productId, quantity_change: change, reason }, 'POST');
      showToast('✅ Stock updated.', 'success');
    } catch (err) {
      showToast(err.message || '❌ Failed to update stock.', 'danger');
    }
  }

  /* ── Send Payment Link ────────────────────────────────────── */
  async function sendPaymentLink() {
    const inv = typeof Invoice !== 'undefined' ? Invoice.getCurrent() : null;
    if (!inv || !inv.id) {
      showToast('Please save the invoice first before sending a payment link.', 'warning');
      return;
    }
    if (parseFloat(inv.balance || inv.total || 0) <= 0) {
      showToast('Invoice balance is zero — nothing to pay.', 'warning');
      return;
    }

    const btn = document.getElementById('btnPaymentLink');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Generating…'; }

    try {
      const resp = await fetch('/ebmpro_api/create_payment_link.php', {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/json' }, Auth.getAuthHeaders()),
        body: JSON.stringify({ invoice_id: inv.id }),
      });
      const data = await resp.json();
      if (data.success && data.url) {
        // Copy to clipboard and open
        try { await navigator.clipboard.writeText(data.url); } catch { /* ignore */ }
        showToast('Payment link generated and copied to clipboard!', 'success', 5000);
        window.open(data.url, '_blank', 'noopener,noreferrer');
      } else {
        showToast(data.error || 'Failed to generate payment link. Check payment settings.', 'danger');
      }
    } catch (err) {
      showToast('Network error — could not generate payment link.', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = '🔗 Send Payment Link'; }
    }
  }

  /* ── Reports: run report ──────────────────────────────────── */
  async function runReport() {
    const type  = (document.getElementById('reportType')  || {}).value || 'sales_summary';
    const store = (document.getElementById('reportStore') || {}).value || getCurrentStore();
    const from  = (document.getElementById('reportDateFrom') || {}).value || '';
    const to    = (document.getElementById('reportDateTo')   || {}).value || '';

    const params = new URLSearchParams({ action: type });
    if (store && store.toLowerCase() !== 'both') params.set('store', store.toUpperCase());
    if (from)  params.set('from', from);
    if (to)    params.set('to',   to);

    const tbody  = document.getElementById('reportResultsTbody');
    const thead  = document.getElementById('reportResultsThead');
    const footer = document.getElementById('reportResultsFooter');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center"><span class="spinner"></span> Loading…</td></tr>';

    try {
      const resp = await fetch(`/ebmpro_api/reports.php?${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      if (!data.success) { showToast(data.error || 'Report failed', 'danger'); return; }

      window._reportData = data.data;
      const rows = Array.isArray(data.data) ? data.data : [data.data];

      if (thead && tbody) {
        const cols = rows.length ? Object.keys(rows[0]) : [];
        thead.innerHTML = '<tr>' + cols.map(c => `<th>${escHtml(c.replace(/_/g,' '))}</th>`).join('') + '</tr>';
        tbody.innerHTML = rows.map(r =>
          '<tr>' + cols.map(c => `<td>${escHtml(String(r[c] ?? ''))}</td>`).join('') + '</tr>'
        ).join('') || '<tr><td colspan="6" class="text-center text-muted">No data for this period.</td></tr>';
      }
      if (footer) footer.textContent = rows.length + ' row' + (rows.length !== 1 ? 's' : '');
    } catch {
      showToast('Failed to load report.', 'danger');
    }
  }

  /* ── Reports: export CSV ──────────────────────────────────── */
  function exportReportCSV() {
    const rows = window._reportData;
    if (!rows || !rows.length) { showToast('Run a report first.', 'warning'); return; }
    const dataArr = Array.isArray(rows) ? rows : [rows];
    const cols    = Object.keys(dataArr[0]);
    const lines   = [cols.join(',')];
    dataArr.forEach(r => {
      lines.push(cols.map(c => {
        const v = String(r[c] ?? '');
        return v.includes(',') || v.includes('"') || v.includes('\n') ? '"' + v.replace(/"/g, '""') + '"' : v;
      }).join(','));
    });
    const blob = new Blob([lines.join('\r\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = 'report-' + new Date().toISOString().slice(0, 10) + '.csv';
    a.click();
    URL.revokeObjectURL(url);
  }

  /* ── Statement modal ──────────────────────────────────────── */
  function openStatementModal(customerId) {
    const modal = document.getElementById('statementModal');
    if (!modal) return;
    const today    = new Date();
    const thirtyAgo = new Date(); thirtyAgo.setDate(today.getDate() - 30);
    const fmt = d => d.toISOString().slice(0, 10);

    const fromEl = document.getElementById('stmtDateFrom');
    const toEl   = document.getElementById('stmtDateTo');
    const cidEl  = document.getElementById('stmtCustomerId');
    if (fromEl) fromEl.value = fmt(thirtyAgo);
    if (toEl)   toEl.value   = fmt(today);
    if (cidEl)  cidEl.value  = customerId || '';

    const tbody = document.getElementById('stmtPreviewTbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">Click Preview to load invoices.</td></tr>';

    modal.classList.remove('hidden');
    // Auto-preview
    if (customerId) previewStatement(customerId);
  }

  async function previewStatement(customerId) {
    const cidEl  = document.getElementById('stmtCustomerId');
    const fromEl = document.getElementById('stmtDateFrom');
    const toEl   = document.getElementById('stmtDateTo');
    const id     = customerId || (cidEl && cidEl.value) || '';
    const from   = fromEl ? fromEl.value : '';
    const to     = toEl   ? toEl.value   : '';
    if (!id) { showToast('No customer selected', 'warning'); return; }

    const params = new URLSearchParams({ customer_id: id });
    if (from) params.set('from', from);
    if (to)   params.set('to',   to);

    const tbody = document.getElementById('stmtPreviewTbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="5" class="text-center"><span class="spinner"></span></td></tr>';

    try {
      const resp = await fetch(`/ebmpro_api/statements.php?${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      if (!data.success) { showToast(data.error || 'Failed to load statement', 'danger'); return; }
      const d     = data.data;
      const invs  = d.invoices || [];
      const titleEl = document.getElementById('stmtCustomerName');
      if (titleEl) titleEl.textContent = d.customer.company_name || d.customer.contact_name || '';
      let running = d.opening_balance || 0;
      if (tbody) {
        tbody.innerHTML = invs.map(inv => {
          running += parseFloat(inv.total) - parseFloat(inv.amount_paid);
          return `<tr>
            <td>${escHtml(inv.invoice_date)}</td>
            <td>${escHtml(inv.invoice_number)}</td>
            <td class="text-right">${fmtCur(inv.total)}</td>
            <td class="text-right">${fmtCur(inv.amount_paid)}</td>
            <td class="text-right">${fmtCur(running)}</td>
          </tr>`;
        }).join('') || '<tr><td colspan="5" class="text-center text-muted">No invoices in period.</td></tr>';
      }
      const balEl = document.getElementById('stmtClosingBalance');
      if (balEl) balEl.textContent = fmtCur(d.closing_balance);
    } catch {
      showToast('Failed to load statement.', 'danger');
    }
  }

  async function sendStatement() {
    const cidEl  = document.getElementById('stmtCustomerId');
    const fromEl = document.getElementById('stmtDateFrom');
    const toEl   = document.getElementById('stmtDateTo');
    const id     = cidEl ? parseInt(cidEl.value) : 0;
    if (!id) { showToast('No customer selected', 'warning'); return; }

    const btn = document.getElementById('btnSendStatement');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Sending…'; }
    try {
      const resp = await fetch('/ebmpro_api/statements.php', {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/json' }, Auth.getAuthHeaders()),
        body: JSON.stringify({
          customer_id: id,
          from:        fromEl ? fromEl.value : '',
          to:          toEl   ? toEl.value   : '',
          send_email:  true,
        }),
      });
      const data = await resp.json();
      if (data.success) {
        closeModal('statementModal');
        showToast(data.message || '✅ Statement sent.', 'success');
      } else {
        showToast(data.error || '❌ Failed to send statement.', 'danger');
      }
    } catch {
      showToast('Network error — could not send statement.', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '📧 Send Statement'; }
    }
  }

  /* ── Create Payment Link modal ────────────────────────────── */
  function createPaymentLink(invoiceId) {
    const modal = document.getElementById('paymentLinkModal');
    if (!modal) return;
    const inv   = typeof Invoice !== 'undefined' ? Invoice.getCurrent() : null;
    const bal   = inv ? (parseFloat(inv.balance || 0) || parseFloat(inv.total || 0)) : 0;
    const balEl = document.getElementById('plAmount');
    const idEl  = document.getElementById('plInvoiceId');
    if (balEl) balEl.textContent = fmtCur(bal);
    if (idEl)  idEl.value = invoiceId || (inv ? inv.id : '');
    const resultEl = document.getElementById('plResult');
    if (resultEl) resultEl.classList.add('hidden');
    modal.classList.remove('hidden');
  }

  async function generatePaymentLink() {
    const invoiceId = document.getElementById('plInvoiceId')?.value;
    const gateway   = document.getElementById('plGateway')?.value || 'stripe';
    if (!invoiceId) { showToast('No invoice selected.', 'warning'); return; }

    const btn = document.getElementById('btnGenerateLink');
    if (btn) { btn.disabled = true; btn.innerHTML = '⏳ Generating…'; }

    try {
      const resp = await fetch('/ebmpro_api/create_payment_link.php', {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/json' }, Auth.getAuthHeaders()),
        body: JSON.stringify({ invoice_id: parseInt(invoiceId), gateway }),
      });
      const data = await resp.json();
      if (data.success && data.url) {
        const resultEl = document.getElementById('plResult');
        const urlEl    = document.getElementById('plUrl');
        if (urlEl)    urlEl.value = data.url;
        if (resultEl) resultEl.classList.remove('hidden');
        showToast('Payment link generated!', 'success');
      } else {
        showToast(data.error || 'Failed to generate payment link.', 'danger');
      }
    } catch {
      showToast('Network error — could not generate payment link.', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '🔗 Generate Link'; }
    }
  }

  function copyPaymentLink() {
    const urlEl = document.getElementById('plUrl');
    if (!urlEl) return;
    try {
      navigator.clipboard.writeText(urlEl.value).then(() => showToast('Link copied!', 'success'));
    } catch { showToast('Could not copy link.', 'warning'); }
  }

  async function sendPaymentLinkToCustomer() {
    const urlEl = document.getElementById('plUrl');
    const idEl  = document.getElementById('plInvoiceId');
    if (!urlEl || !idEl) return;
    const inv = typeof Invoice !== 'undefined' ? Invoice.getCurrent() : null;
    const email = inv ? (inv.customer_email || inv.email_address || '') : '';
    if (!email) { showToast('No customer email on this invoice.', 'warning'); return; }

    try {
      await Sync.syncData('/ebmpro_api/email.php', {
        invoice_id: parseInt(idEl.value),
        to_email:   email,
        subject:    'Payment link for invoice ' + (inv ? inv.invoice_number : ''),
        message:    'Please use this link to pay your invoice: ' + urlEl.value,
        type:       'payment_link',
      }, 'POST');
      showToast('Payment link sent to ' + email, 'success');
      closeModal('paymentLinkModal');
    } catch {
      showToast('Failed to send payment link email.', 'danger');
    }
  }

  /* ── Admin: data import ───────────────────────────────────── */
  async function startDataImport(type) {
    const cap       = type.charAt(0).toUpperCase() + type.slice(1);
    const fileInput = document.getElementById('adminImport' + cap + 'File');
    if (!fileInput || !fileInput.files.length) { showToast('Please select a file first.', 'warning'); return; }

    const progressDiv = document.getElementById('adminImport' + cap + 'Progress');
    const bar         = document.getElementById('adminImport' + cap + 'Bar');
    const msg         = document.getElementById('adminImport' + cap + 'Msg');
    if (progressDiv) progressDiv.style.display = '';
    if (bar) { bar.style.background = '#4caf50'; bar.style.width = '0%'; }
    if (msg) { msg.style.color = '#ccc'; msg.textContent = 'Uploading…'; }

    const endpoints = {
      products:  '/ebmpro_api/import_products.php',
      customers: '/ebmpro_api/import_customers.php',
      invoices:  '/ebmpro_api/import_invoices.php',
      payments:  '/ebmpro_api/import_payments.php',
    };
    const endpoint = endpoints[type];
    if (!endpoint) { showToast('Unknown import type.', 'danger'); return; }

    let storeId   = null;
    let storeCode = null;
    if (type === 'products') {
      const el = document.getElementById('adminImportProductsStore');
      if (el) storeId = el.value;
    }
    if (type === 'invoices') {
      const el = document.getElementById('adminImportInvoicesStore');
      if (el) storeCode = el.value;
    }

    const file         = fileInput.files[0];
    let totalInserted  = 0;
    let totalSkipped   = 0;
    let offset         = 0;
    let startTime      = null;

    const sendChunk = async () => {
      if (startTime === null) startTime = Date.now();
      const fd = new FormData();
      fd.append('offset', offset);
      if (offset === 0) fd.append('file', file);
      if (storeId)   fd.append('store_id',   storeId);
      if (storeCode) fd.append('store_code', storeCode);

      const res  = await fetch(endpoint, {
        method: 'POST',
        headers: { 'Authorization': 'Bearer ' + (Auth.getToken ? Auth.getToken() : localStorage.getItem('ebm_token')) },
        body: fd,
      });
      const data = await res.json();

      if (!data.success) {
        if (bar) bar.style.background = '#f44336';
        if (msg) { msg.style.color = '#f44336'; msg.textContent = '❌ Error: ' + (data.error || 'Import failed'); }
        return;
      }

      totalInserted += data.inserted || 0;
      totalSkipped  += data.skipped  || 0;
      const pct      = data.percentage || 0;
      if (bar) bar.style.width = pct + '%';

      if (!data.done) {
        const elapsed   = (Date.now() - startTime) / 1000;
        const processed = data.processed || 0;
        const total     = data.total || 0;
        const rate      = elapsed > 0 ? processed / elapsed : 0;
        const etaSec    = rate > 0 ? Math.ceil((total - processed) / rate) : 0;
        if (msg) msg.textContent = `Importing ${processed.toLocaleString()} of ${total.toLocaleString()}… ${pct}% (${etaSec > 0 ? etaSec + 's remaining' : ''})`;
        offset = data.processed;
        await sendChunk();
      } else {
        if (bar) bar.style.width = '100%';
        if (msg) { msg.style.color = '#4caf50'; msg.textContent = `✅ Complete! ${totalInserted.toLocaleString()} imported, ${totalSkipped.toLocaleString()} skipped`; }
        showToast(`Import complete: ${totalInserted} imported, ${totalSkipped} skipped`, 'success');
      }
    };

    try { await sendChunk(); }
    catch (e) {
      if (bar) bar.style.background = '#f44336';
      if (msg) { msg.style.color = '#f44336'; msg.textContent = '❌ Network error: ' + e.message; }
    }
  }

  /* ── Admin: load backup list ──────────────────────────────── */
  async function loadBackupList() {
    const tbody = document.getElementById('adminBackupsTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="4" class="text-center"><span class="spinner"></span></td></tr>';
    try {
      const resp = await fetch('/ebmpro_api/backup.php?action=list', { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const list = data.data || [];
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">No backups found. Click "Run Backup Now" to create one.</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(b => `
        <tr>
          <td>${escHtml(b.filename)}</td>
          <td>${b.size_mb} MB</td>
          <td>${escHtml(b.created)}</td>
          <td>
            <a href="/ebmpro_api/backup.php?action=download&file=${encodeURIComponent(b.filename)}" class="btn btn-sm btn-light">⬇️ Download</a>
          </td>
        </tr>
      `).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Failed to load backups.</td></tr>';
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
    loadEmailTrackingStatus,
    showAddPaymentModal,
    showAuditLog,
    closeModal,
    printCurrentInvoice,
    downloadCurrentPDF,
    saveCurrentInvoice,
    sendPaymentLink,
    showToast,
    updateHeaderUser,
    toast: showToast,
    sendSingleReminder,
    openOverdueModal,
    toggleAllOverdue,
    sendSelectedReminders,
    loadAdminDashboard,
    runAdminBackup,
    loadOperators,
    openAddUserModal,
    openEditUserModal,
    saveOperator,
    lockOperator,
    promptResetPassword,
    resetPassword,
    loadStock,
    filterStock,
    openAdjustStockModal,
    saveStockAdjust,
    adjustStock,
    runReport,
    exportReportCSV,
    openStatementModal,
    previewStatement,
    sendStatement,
    createPaymentLink,
    generatePaymentLink,
    copyPaymentLink,
    sendPaymentLinkToCustomer,
    loadBackupList,
    startDataImport,
  };
})();