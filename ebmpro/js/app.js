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
      case 'adminScreen':    loadAdminDashboard();   break;
      case 'stockScreen':    loadStock();            break;
      case 'quotesScreen':   loadQuotes();           break;
      case 'recurringScreen': loadRecurring();       break;
      case 'expensesScreen': loadExpenses();         break;
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
    // Pre-fill default date range (current month) if not already set
    const dateFromEl = document.getElementById('reportDateFrom');
    const dateToEl   = document.getElementById('reportDateTo');
    if (dateFromEl && !dateFromEl.value) {
      dateFromEl.value = new Date().toISOString().slice(0, 8) + '01';
    }
    if (dateToEl && !dateToEl.value) {
      dateToEl.value = new Date().toISOString().slice(0, 10);
    }

    // If new report type control exists, use the new action-based API
    if (document.getElementById('reportType')) {
      await runReport();
      return;
    }

    // Legacy fallback: dashboard stats
    const params = new URLSearchParams({ store_code: getCurrentStore() });
    if (dateFromEl && dateFromEl.value) params.set('date_from', dateFromEl.value);
    if (dateToEl   && dateToEl.value)   params.set('date_to',   dateToEl.value);

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
      // Load backup list
      loadAdminBackupList();
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
        showToast(`✅ Backup created: ${data.filename}`, 'success');
        loadAdminBackupList();
      } else {
        showToast(`❌ Backup failed: ${data.error || 'Unknown error'}`, 'danger');
      }
    } catch {
      showToast('❌ Backup failed.', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.innerHTML = '💾 Run Backup Now'; }
    }
  }

  /* ── Admin: list backup files ──────────────────────────────── */
  async function loadAdminBackupList() {
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
          <td>${escHtml(b.size)}</td>
          <td>${escHtml(b.created)}</td>
          <td style="white-space:nowrap">
            <a href="/ebmpro_api/backup.php?action=download&file=${encodeURIComponent(b.filename)}" class="btn btn-sm btn-light">⬇️ Download</a>
            <button class="btn btn-sm btn-light" onclick="App.deleteBackup('${escHtml(b.filename)}')">🗑️ Delete</button>
          </td>
        </tr>
      `).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="4" class="text-center text-muted">Failed to load backups.</td></tr>';
    }
  }

  /* ── Admin: delete backup file ─────────────────────────────── */
  async function deleteBackup(filename) {
    if (!confirm(`Delete backup: ${filename}?`)) return;
    try {
      const resp = await fetch(`/ebmpro_api/backup.php?action=delete&file=${encodeURIComponent(filename)}`, {
        method: 'POST',
        headers: Auth.getAuthHeaders(),
      });
      const data = await resp.json();
      if (data.success) { showToast('Backup deleted.', 'success'); loadAdminBackupList(); }
      else showToast(data.error || 'Delete failed.', 'danger');
    } catch {
      showToast('Delete failed.', 'danger');
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

  /* ── Send Payment Link (legacy, kept for compat) ──────────── */
  async function sendPaymentLink() {
    const inv = typeof Invoice !== 'undefined' ? Invoice.getCurrent() : null;
    if (!inv || !inv.id) {
      showToast('Please save the invoice first before sending a payment link.', 'warning');
      return;
    }
    createPaymentLink(inv.id);
  }

  /* ── Create Payment Link ──────────────────────────────────── */
  let _paymentLinkUrl = null;

  async function createPaymentLink(invoiceId) {
    const inv = typeof Invoice !== 'undefined' ? Invoice.getCurrent() : null;
    const id  = invoiceId || (inv ? inv.id : null);

    if (!id) {
      showToast('Please save the invoice first.', 'warning');
      return;
    }

    const modal = document.getElementById('paymentLinkModal');
    if (modal) {
      const idEl = document.getElementById('paymentLinkInvoiceId');
      if (idEl) idEl.value = id;

      // Pre-fill amount from current invoice
      if (inv && inv.id === id) {
        const amtEl = document.getElementById('paymentLinkAmount');
        if (amtEl) amtEl.value = parseFloat(inv.balance || inv.total || 0).toFixed(2);
      }

      const resultEl = document.getElementById('paymentLinkResult');
      const copyBtn  = document.getElementById('btnCopyPaymentLink');
      if (resultEl) { resultEl.textContent = ''; resultEl.classList.add('hidden'); }
      if (copyBtn)  copyBtn.classList.add('hidden');
      _paymentLinkUrl = null;

      modal.classList.remove('hidden');
      return;
    }

    // Fallback: direct generation without modal
    await _doGeneratePaymentLink(id, null, 'stripe');
  }

  async function _doGeneratePaymentLink(invoiceId, amount, provider) {
    const btn = document.getElementById('btnGeneratePaymentLink');
    if (btn) { btn.disabled = true; btn.textContent = '⏳ Generating…'; }

    try {
      const payload = { invoice_id: invoiceId, provider };
      if (amount) payload.amount = amount;

      const resp = await fetch('/ebmpro_api/create_payment_link.php', {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/json' }, Auth.getAuthHeaders()),
        body: JSON.stringify(payload),
      });
      const data = await resp.json();
      if (data.success && data.url) {
        _paymentLinkUrl = data.url;
        const resultEl = document.getElementById('paymentLinkResult');
        if (resultEl) {
          resultEl.innerHTML = '🔗 <a href="' + escHtml(data.url) + '" target="_blank" rel="noopener noreferrer">' + escHtml(data.url) + '</a>';
          resultEl.classList.remove('hidden');
        }
        const copyBtn = document.getElementById('btnCopyPaymentLink');
        if (copyBtn) copyBtn.classList.remove('hidden');
        showToast('Payment link generated!', 'success');
      } else {
        showToast(data.error || 'Failed to generate payment link. Check payment settings.', 'danger');
      }
    } catch {
      showToast('Network error — could not generate payment link.', 'danger');
    } finally {
      if (btn) { btn.disabled = false; btn.textContent = '🔗 Generate Link'; }
    }
  }

  async function copyPaymentLink() {
    if (!_paymentLinkUrl) return;
    try {
      await navigator.clipboard.writeText(_paymentLinkUrl);
      showToast('Payment link copied to clipboard!', 'success');
    } catch {
      showToast('Could not copy — please copy manually.', 'warning');
    }
  }

  async function generatePaymentLinkFromModal() {
    const invoiceId = parseInt(document.getElementById('paymentLinkInvoiceId')?.value || 0);
    const amount    = parseFloat(document.getElementById('paymentLinkAmount')?.value  || 0) || null;
    const provider  = document.querySelector('input[name="payment_provider"]:checked')?.value || 'stripe';
    if (!invoiceId) {
      showToast('No invoice selected.', 'warning');
      return;
    }
    await _doGeneratePaymentLink(invoiceId, amount, provider);
  }

  /* ── runReport (action-based) ─────────────────────────────── */
  let _lastReportData  = [];
  let _lastReportType  = '';
  let _lastReportCols  = [];

  async function runReport() {
    const type  = document.getElementById('reportType')?.value  || 'sales_summary';
    const store = document.getElementById('reportStore')?.value || '';
    const from  = document.getElementById('reportDateFrom')?.value || '';
    const to    = document.getElementById('reportDateTo')?.value   || '';

    const params = new URLSearchParams({ action: type });
    if (store) params.set('store', store);
    if (from)  params.set('from', from);
    if (to)    params.set('to', to);

    const tbody = document.getElementById('reportInvoicesTbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center"><span class="spinner"></span> Loading…</td></tr>';

    try {
      const resp = await fetch(`/ebmpro_api/reports.php?${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();

      if (!data.success) {
        showToast(data.error || 'Report failed.', 'danger');
        return;
      }

      _lastReportType = type;
      _renderReportResults(type, data);

    } catch {
      showToast('Failed to load report.', 'danger');
    }
  }

  function _renderReportResults(type, data) {
    const thead = document.querySelector('#reportResults thead tr');
    const tbody = document.getElementById('reportInvoicesTbody');
    if (!tbody) return;

    if (type === 'sales_summary') {
      const d = data.data || {};
      if (thead) thead.innerHTML = '<th>Metric</th><th class="text-right">Value</th>';
      _lastReportData = [
        { metric: 'Invoice Count',     value: d.invoice_count      || 0 },
        { metric: 'Total Sales',       value: fmtCur(d.total_sales  || 0) },
        { metric: 'Total VAT',         value: fmtCur(d.total_vat    || 0) },
        { metric: 'Total Paid',        value: fmtCur(d.total_paid   || 0) },
        { metric: 'Total Outstanding', value: fmtCur(d.total_outstanding || 0) },
        { metric: 'Avg Invoice Value', value: fmtCur(d.avg_invoice_value || 0) },
      ];
      _lastReportCols = ['metric', 'value'];
      tbody.innerHTML = _lastReportData.map(r =>
        `<tr><td>${escHtml(r.metric)}</td><td class="text-right">${escHtml(String(r.value))}</td></tr>`
      ).join('');

      // Update stat cards
      const el = id => document.getElementById(id);
      if (el('statTotalInvoiced'))    el('statTotalInvoiced').textContent    = fmtCur(data.data?.total_sales || 0);
      if (el('statTotalPaid'))        el('statTotalPaid').textContent        = fmtCur(data.data?.total_paid  || 0);
      if (el('statTotalOutstanding')) el('statTotalOutstanding').textContent = fmtCur(data.data?.total_outstanding || 0);
      if (el('statOverdueCount'))     el('statOverdueCount').textContent     = data.data?.invoice_count || 0;

    } else if (type === 'vat_summary') {
      if (thead) thead.innerHTML = '<th>VAT Rate</th><th class="text-right">Net</th><th class="text-right">VAT</th><th class="text-right">Gross</th><th class="text-right">Invoices</th>';
      _lastReportData = data.data || [];
      _lastReportCols = ['vat_rate', 'net_total', 'vat_total', 'gross_total', 'invoice_count'];
      tbody.innerHTML = _lastReportData.map(r =>
        `<tr><td>${r.vat_rate}%</td><td class="text-right">${fmtCur(r.net_total)}</td><td class="text-right">${fmtCur(r.vat_total)}</td><td class="text-right">${fmtCur(r.gross_total)}</td><td class="text-right">${r.invoice_count}</td></tr>`
      ).join('') || '<tr><td colspan="5" class="text-center text-muted">No data.</td></tr>';

    } else if (type === 'sales_by_product') {
      if (thead) thead.innerHTML = '<th>Code</th><th>Description</th><th class="text-right">Qty</th><th class="text-right">Revenue</th><th class="text-right">Invoices</th>';
      _lastReportData = data.data || [];
      _lastReportCols = ['product_code', 'description', 'total_qty', 'total_revenue', 'invoice_count'];
      tbody.innerHTML = _lastReportData.map(r =>
        `<tr><td>${escHtml(r.product_code||'')}</td><td>${escHtml(r.description||'')}</td><td class="text-right">${r.total_qty}</td><td class="text-right">${fmtCur(r.total_revenue)}</td><td class="text-right">${r.invoice_count}</td></tr>`
      ).join('') || '<tr><td colspan="5" class="text-center text-muted">No data.</td></tr>';

    } else if (type === 'sales_by_customer') {
      if (thead) thead.innerHTML = '<th>Customer</th><th>Email</th><th class="text-right">Invoices</th><th class="text-right">Revenue</th><th class="text-right">Outstanding</th>';
      _lastReportData = data.data || [];
      _lastReportCols = ['customer_name', 'email_address', 'invoice_count', 'total_revenue', 'total_outstanding'];
      tbody.innerHTML = _lastReportData.map(r =>
        `<tr><td>${escHtml(r.customer_name||'')}</td><td>${escHtml(r.email_address||'')}</td><td class="text-right">${r.invoice_count}</td><td class="text-right">${fmtCur(r.total_revenue)}</td><td class="text-right">${fmtCur(r.total_outstanding)}</td></tr>`
      ).join('') || '<tr><td colspan="5" class="text-center text-muted">No data.</td></tr>';

    } else if (type === 'overdue') {
      if (thead) thead.innerHTML = '<th>Invoice #</th><th>Customer</th><th>Due Date</th><th class="text-right">Total</th><th class="text-right">Balance</th><th>Days Overdue</th>';
      _lastReportData = data.data || [];
      _lastReportCols = ['invoice_number', 'customer_name', 'due_date', 'total', 'balance', 'days_overdue'];
      tbody.innerHTML = _lastReportData.map(r =>
        `<tr onclick="App.openInvoice('${r.id}')"><td>${escHtml(r.invoice_number||'')}</td><td>${escHtml(r.customer_name||'')}</td><td>${fmtDate(r.due_date)}</td><td class="text-right">${fmtCur(r.total)}</td><td class="text-right">${fmtCur(r.balance)}</td><td>${r.days_overdue} days</td></tr>`
      ).join('') || '<tr><td colspan="6" class="text-center text-muted">No overdue invoices.</td></tr>';
      if (document.getElementById('statOverdueCount')) document.getElementById('statOverdueCount').textContent = data.count || 0;

    } else if (type === 'email_activity') {
      if (thead) thead.innerHTML = '<th>Sent</th><th>To</th><th>Subject</th><th>Status</th>';
      _lastReportData = data.data || [];
      _lastReportCols = ['sent_at', 'to_email', 'subject', 'status'];
      tbody.innerHTML = _lastReportData.map(r =>
        `<tr><td>${fmtDate(r.sent_at)}</td><td>${escHtml(r.to_email||'')}</td><td>${escHtml(r.subject||'')}</td><td>${escHtml(r.status||'')}</td></tr>`
      ).join('') || '<tr><td colspan="4" class="text-center text-muted">No email activity.</td></tr>';
    }
  }

  /* ── exportReportCSV ──────────────────────────────────────── */
  function exportReportCSV() {
    if (!_lastReportData || !_lastReportData.length) {
      showToast('Run a report first before exporting.', 'warning');
      return;
    }

    const cols = _lastReportCols;
    const header = cols.join(',');
    const rows = _lastReportData.map(r =>
      cols.map(c => {
        const val = r[c] != null ? String(r[c]) : '';
        return '"' + val.replace(/"/g, '""') + '"';
      }).join(',')
    );
    const csv  = [header, ...rows].join('\n');
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = `report_${_lastReportType}_${new Date().toISOString().slice(0, 10)}.csv`;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

  /* ── openStatementModal ───────────────────────────────────── */
  function openStatementModal(customerId) {
    const modal = document.getElementById('statementModal');
    if (!modal) {
      showToast('Statement modal not available.', 'warning');
      return;
    }

    const idEl = document.getElementById('statementCustomerId');
    if (idEl) idEl.value = customerId;

    // Pre-fill last 30 days
    const today = new Date();
    const from  = new Date(today);
    from.setDate(from.getDate() - 30);

    const fromEl = document.getElementById('statementFrom');
    const toEl   = document.getElementById('statementTo');
    if (fromEl) fromEl.value = from.toISOString().slice(0, 10);
    if (toEl)   toEl.value   = today.toISOString().slice(0, 10);

    // Clear preview
    const tbody = document.getElementById('statementPreviewTbody');
    if (tbody) tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Click Preview to load invoices.</td></tr>';

    modal.classList.remove('hidden');
  }

  /* ── sendStatement ────────────────────────────────────────── */
  async function sendStatement(doSend = false) {
    const customerId = parseInt(document.getElementById('statementCustomerId')?.value || 0);
    const from       = document.getElementById('statementFrom')?.value || '';
    const to         = document.getElementById('statementTo')?.value   || '';

    if (!customerId) {
      showToast('No customer selected.', 'warning');
      return;
    }

    const btn = document.getElementById('btnSendStatement');
    if (doSend && btn) { btn.disabled = true; btn.textContent = '⏳ Sending…'; }

    try {
      const resp = await fetch('/ebmpro_api/statements.php', {
        method: 'POST',
        headers: Object.assign({ 'Content-Type': 'application/json' }, Auth.getAuthHeaders()),
        body: JSON.stringify({
          customer_id: customerId,
          from,
          to,
          send_email: doSend,
        }),
      });
      const data = await resp.json();

      if (!data.success) {
        showToast(data.error || 'Statement failed.', 'danger');
        return;
      }

      if (doSend) {
        closeModal('statementModal');
        showToast('✅ Statement sent successfully!', 'success');
        return;
      }

      // Preview mode — render invoices in the preview table
      const invoices = data.data?.invoices || [];
      const tbody    = document.getElementById('statementPreviewTbody');
      if (tbody) {
        if (!invoices.length) {
          tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No invoices in this period.</td></tr>';
        } else {
          tbody.innerHTML = invoices.map(inv => `
            <tr>
              <td>${fmtDate(inv.invoice_date)}</td>
              <td>${escHtml(inv.invoice_number || '')}</td>
              <td>${escHtml((inv.status || '').replace('_', ' ').toUpperCase())}</td>
              <td class="text-right">${fmtCur(inv.total)}</td>
              <td class="text-right">${fmtCur(inv.balance)}</td>
              <td class="text-right"><strong>${fmtCur(inv.running_balance)}</strong></td>
            </tr>
          `).join('');
        }
      }
    } catch {
      showToast('Failed to load statement.', 'danger');
    } finally {
      if (doSend && btn) { btn.disabled = false; btn.textContent = '📧 Send Statement'; }
    }
  }

  document.addEventListener('DOMContentLoaded', init);

  /* ══════════════════════════════════════════════════════════
     QUOTES
  ══════════════════════════════════════════════════════════ */

  async function loadQuotes() {
    const tbody   = document.getElementById('quotesTbody');
    const filter  = document.getElementById('quotesStatusFilter');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center"><span class="spinner"></span> Loading…</td></tr>';
    try {
      const params = new URLSearchParams({ store_id: getCurrentStore() === 'FAL' ? 1 : 2 });
      if (filter && filter.value) params.set('status', filter.value);
      const resp = await fetch(`/ebmpro_api/quotes.php?${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const list = data.data || [];
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No quotes found.</td></tr>';
        return;
      }
      const statusColors = { draft:'#9e9e9e', sent:'#2196f3', accepted:'#4caf50', declined:'#f44336', expired:'#ff9800' };
      tbody.innerHTML = list.map(q => {
        const color = statusColors[q.status] || '#9e9e9e';
        return `<tr>
          <td><strong>${escHtml(q.quote_number || '—')}</strong></td>
          <td>${escHtml(q.customer_name || '—')}</td>
          <td>${fmtDate(q.quote_date)}</td>
          <td>${fmtDate(q.expiry_date)}</td>
          <td class="text-right">${fmtCur(q.total)}</td>
          <td><span style="display:inline-block;padding:.2rem .5rem;border-radius:4px;font-size:.8rem;font-weight:600;background:${color}22;color:${color};border:1px solid ${color}44">${escHtml(q.status.toUpperCase())}</span></td>
          <td style="white-space:nowrap">
            ${q.status === 'draft' ? `<button class="btn btn-sm btn-light" onclick="App.openQuoteModal(${q.id})">✏️</button> ` : ''}
            ${q.status === 'draft' || q.status === 'sent' ? `<button class="btn btn-sm btn-light" onclick="App.sendQuote(${q.id})">📧</button> ` : ''}
            <button class="btn btn-sm btn-light" onclick="App.convertQuoteToInvoice(${q.id})" title="Convert to Invoice">🔄</button>
            ${q.status === 'draft' ? `<button class="btn btn-sm btn-light" onclick="App.deleteQuote(${q.id})" style="color:#f44336">🗑️</button>` : ''}
          </td>
        </tr>`;
      }).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Failed to load quotes.</td></tr>';
    }
  }

  function openQuoteModal(id = null) {
    const today = new Date().toISOString().slice(0, 10);
    const expiry = new Date(Date.now() + 30 * 86400000).toISOString().slice(0, 10);
    document.getElementById('quoteId').value          = id || '';
    document.getElementById('quoteCustomerSearch').value = '';
    document.getElementById('quoteCustomerId').value  = '';
    document.getElementById('quoteDate').value         = today;
    document.getElementById('quoteExpiryDate').value   = expiry;
    document.getElementById('quoteNotes').value        = '';
    document.getElementById('quoteItemsWrap').innerHTML = `
      <div class="quote-item-row" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:.4rem;align-items:end;margin-bottom:.4rem">
        <input type="text" class="form-control" placeholder="Description *" name="qi_desc" aria-label="Description">
        <input type="number" class="form-control" placeholder="Qty" name="qi_qty" value="1" min="0.001" step="0.001" aria-label="Quantity">
        <input type="number" class="form-control" placeholder="Unit Price" name="qi_price" min="0" step="0.01" aria-label="Unit price">
        <input type="number" class="form-control" placeholder="VAT%" name="qi_vat" value="23" min="0" step="0.01" aria-label="VAT rate">
        <button class="btn btn-light btn-sm" onclick="this.closest('.quote-item-row').remove()" aria-label="Remove item">✕</button>
      </div>`;
    document.getElementById('quoteModalTitle').textContent = id ? '📋 Edit Quote' : '📋 New Quote';
    document.getElementById('quoteModal').classList.remove('hidden');

    if (id) {
      fetch(`/ebmpro_api/quotes.php?id=${id}`, { headers: Auth.getAuthHeaders() })
        .then(r => r.json()).then(data => {
          const q = data.data;
          if (!q) return;
          document.getElementById('quoteCustomerSearch').value = q.customer_name || '';
          document.getElementById('quoteCustomerId').value  = q.customer_id;
          document.getElementById('quoteDate').value        = q.quote_date || today;
          document.getElementById('quoteExpiryDate').value  = q.expiry_date || expiry;
          document.getElementById('quoteNotes').value       = q.notes || '';
          if (q.items && q.items.length) {
            document.getElementById('quoteItemsWrap').innerHTML = q.items.map(item => `
              <div class="quote-item-row" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:.4rem;align-items:end;margin-bottom:.4rem">
                <input type="text" class="form-control" placeholder="Description *" name="qi_desc" value="${escHtml(item.description)}" aria-label="Description">
                <input type="number" class="form-control" placeholder="Qty" name="qi_qty" value="${item.quantity}" min="0.001" step="0.001" aria-label="Quantity">
                <input type="number" class="form-control" placeholder="Unit Price" name="qi_price" value="${item.unit_price}" min="0" step="0.01" aria-label="Unit price">
                <input type="number" class="form-control" placeholder="VAT%" name="qi_vat" value="${item.vat_rate}" min="0" step="0.01" aria-label="VAT rate">
                <button class="btn btn-light btn-sm" onclick="this.closest('.quote-item-row').remove()" aria-label="Remove item">✕</button>
              </div>`).join('');
          }
        }).catch(() => {});
    }
  }

  function addQuoteItemRow() {
    const wrap = document.getElementById('quoteItemsWrap');
    if (!wrap) return;
    const row = document.createElement('div');
    row.className = 'quote-item-row';
    row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:.4rem;align-items:end;margin-bottom:.4rem';
    row.innerHTML = `
      <input type="text" class="form-control" placeholder="Description *" name="qi_desc" aria-label="Description">
      <input type="number" class="form-control" placeholder="Qty" name="qi_qty" value="1" min="0.001" step="0.001" aria-label="Quantity">
      <input type="number" class="form-control" placeholder="Unit Price" name="qi_price" min="0" step="0.01" aria-label="Unit price">
      <input type="number" class="form-control" placeholder="VAT%" name="qi_vat" value="23" min="0" step="0.01" aria-label="VAT rate">
      <button class="btn btn-light btn-sm" onclick="this.closest('.quote-item-row').remove()" aria-label="Remove item">✕</button>`;
    wrap.appendChild(row);
  }

  async function saveQuote() {
    const id         = document.getElementById('quoteId').value;
    const customerId = document.getElementById('quoteCustomerId').value;
    const storeId    = document.getElementById('quoteStoreId').value;
    const quoteDate  = document.getElementById('quoteDate').value;
    if (!customerId) { showToast('Please select a customer.', 'danger'); return; }
    if (!quoteDate)  { showToast('Quote date is required.', 'danger'); return; }

    const rows  = document.querySelectorAll('#quoteItemsWrap .quote-item-row');
    const items = [];
    for (const row of rows) {
      const desc  = row.querySelector('[name=qi_desc]').value.trim();
      const qty   = parseFloat(row.querySelector('[name=qi_qty]').value) || 1;
      const price = parseFloat(row.querySelector('[name=qi_price]').value) || 0;
      const vat   = parseFloat(row.querySelector('[name=qi_vat]').value) || 23;
      if (!desc) continue;
      items.push({ description: desc, qty, unit_price: price, vat_rate: vat });
    }
    if (!items.length) { showToast('Add at least one line item.', 'danger'); return; }

    const body = {
      customer_id:  parseInt(customerId),
      store_id:     parseInt(storeId),
      quote_date:   quoteDate,
      expiry_date:  document.getElementById('quoteExpiryDate').value || null,
      notes:        document.getElementById('quoteNotes').value || null,
      items,
    };

    const btn = document.getElementById('btnSaveQuote');
    if (btn) btn.disabled = true;
    try {
      const url    = id ? `/ebmpro_api/quotes.php?id=${id}` : '/ebmpro_api/quotes.php';
      const method = id ? 'PUT' : 'POST';
      const resp   = await fetch(url, {
        method,
        headers: { ...Auth.getAuthHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Save failed');
      closeModal('quoteModal');
      showToast('Quote saved!', 'success');
      loadQuotes();
    } catch (err) {
      showToast(err.message || 'Failed to save quote.', 'danger');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function sendQuote(id) {
    if (!confirm('Send this quote by email to the customer?')) return;
    try {
      const resp = await fetch(`/ebmpro_api/quotes.php?action=send&id=${id}`, {
        method: 'POST',
        headers: Auth.getAuthHeaders(),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Send failed');
      showToast('Quote sent!', 'success');
      loadQuotes();
    } catch (err) {
      showToast(err.message || 'Failed to send quote.', 'danger');
    }
  }

  async function convertQuoteToInvoice(id) {
    if (!confirm('Convert this quote to an invoice?')) return;
    try {
      const resp = await fetch(`/ebmpro_api/quotes.php?action=convert&id=${id}`, {
        method: 'POST',
        headers: Auth.getAuthHeaders(),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Convert failed');
      showToast(`Converted! Invoice ${data.invoice_number} created.`, 'success');
      loadQuotes();
    } catch (err) {
      showToast(err.message || 'Failed to convert quote.', 'danger');
    }
  }

  async function deleteQuote(id) {
    if (!confirm('Delete this draft quote?')) return;
    try {
      const resp = await fetch(`/ebmpro_api/quotes.php?id=${id}`, {
        method: 'DELETE',
        headers: Auth.getAuthHeaders(),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Delete failed');
      showToast('Quote deleted.', 'info');
      loadQuotes();
    } catch (err) {
      showToast(err.message || 'Failed to delete quote.', 'danger');
    }
  }

  /* ══════════════════════════════════════════════════════════
     RECURRING INVOICES
  ══════════════════════════════════════════════════════════ */

  async function loadRecurring() {
    const tbody = document.getElementById('recurringTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="6" class="text-center"><span class="spinner"></span> Loading…</td></tr>';
    try {
      const resp = await fetch('/ebmpro_api/recurring.php', { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const list = data.data || [];
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">No recurring invoices found.</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(r => `<tr>
        <td>${escHtml(r.customer_name || '—')}</td>
        <td>${escHtml(r.frequency || '—')}</td>
        <td>${fmtDate(r.next_run_date)}</td>
        <td>${fmtDate(r.last_run_date)}</td>
        <td>
          <button class="btn btn-sm ${r.active ? 'btn-accent' : 'btn-light'}"
            onclick="App.toggleRecurring(${r.id})">${r.active ? '✅ Active' : '⏸ Paused'}</button>
        </td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm btn-light" onclick="App.openRecurringModal(${r.id})">✏️</button>
          <button class="btn btn-sm btn-light" onclick="App.deleteRecurring(${r.id})" style="color:#f44336">🗑️</button>
        </td>
      </tr>`).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="6" class="text-center text-muted">Failed to load.</td></tr>';
    }
  }

  function openRecurringModal(id = null) {
    const today = new Date().toISOString().slice(0, 10);
    document.getElementById('recurringId').value             = id || '';
    document.getElementById('recurringCustomerSearch').value = '';
    document.getElementById('recurringCustomerId').value     = '';
    document.getElementById('recurringFrequency').value      = 'monthly';
    document.getElementById('recurringNextRun').value        = today;
    document.getElementById('recurringNotes').value          = '';
    document.getElementById('recurringItemsWrap').innerHTML  = `
      <div class="recurring-item-row" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:.4rem;align-items:end;margin-bottom:.4rem">
        <input type="text" class="form-control" placeholder="Description *" name="ri_desc" aria-label="Description">
        <input type="number" class="form-control" placeholder="Qty" name="ri_qty" value="1" min="0.001" step="0.001" aria-label="Quantity">
        <input type="number" class="form-control" placeholder="Unit Price" name="ri_price" min="0" step="0.01" aria-label="Unit price">
        <input type="number" class="form-control" placeholder="VAT%" name="ri_vat" value="23" min="0" step="0.01" aria-label="VAT rate">
        <button class="btn btn-light btn-sm" onclick="this.closest('.recurring-item-row').remove()" aria-label="Remove item">✕</button>
      </div>`;
    document.getElementById('recurringModalTitle').textContent = id ? '🔄 Edit Recurring Invoice' : '🔄 New Recurring Invoice';
    document.getElementById('recurringModal').classList.remove('hidden');

    if (id) {
      fetch(`/ebmpro_api/recurring.php?id=${id}`, { headers: Auth.getAuthHeaders() })
        .then(r => r.json()).then(data => {
          const tpl = data.data;
          if (!tpl) return;
          document.getElementById('recurringCustomerSearch').value = tpl.customer_name || '';
          document.getElementById('recurringCustomerId').value     = tpl.customer_id;
          document.getElementById('recurringFrequency').value      = tpl.frequency;
          document.getElementById('recurringNextRun').value        = tpl.next_run_date || today;
          document.getElementById('recurringNotes').value          = tpl.notes || '';
          if (tpl.items && tpl.items.length) {
            document.getElementById('recurringItemsWrap').innerHTML = tpl.items.map(item => `
              <div class="recurring-item-row" style="display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:.4rem;align-items:end;margin-bottom:.4rem">
                <input type="text" class="form-control" placeholder="Description *" name="ri_desc" value="${escHtml(item.description)}" aria-label="Description">
                <input type="number" class="form-control" placeholder="Qty" name="ri_qty" value="${item.quantity}" min="0.001" step="0.001" aria-label="Quantity">
                <input type="number" class="form-control" placeholder="Unit Price" name="ri_price" value="${item.unit_price}" min="0" step="0.01" aria-label="Unit price">
                <input type="number" class="form-control" placeholder="VAT%" name="ri_vat" value="${item.vat_rate}" min="0" step="0.01" aria-label="VAT rate">
                <button class="btn btn-light btn-sm" onclick="this.closest('.recurring-item-row').remove()" aria-label="Remove item">✕</button>
              </div>`).join('');
          }
        }).catch(() => {});
    }
  }

  function addRecurringItemRow() {
    const wrap = document.getElementById('recurringItemsWrap');
    if (!wrap) return;
    const row = document.createElement('div');
    row.className = 'recurring-item-row';
    row.style.cssText = 'display:grid;grid-template-columns:2fr 1fr 1fr 1fr auto;gap:.4rem;align-items:end;margin-bottom:.4rem';
    row.innerHTML = `
      <input type="text" class="form-control" placeholder="Description *" name="ri_desc" aria-label="Description">
      <input type="number" class="form-control" placeholder="Qty" name="ri_qty" value="1" min="0.001" step="0.001" aria-label="Quantity">
      <input type="number" class="form-control" placeholder="Unit Price" name="ri_price" min="0" step="0.01" aria-label="Unit price">
      <input type="number" class="form-control" placeholder="VAT%" name="ri_vat" value="23" min="0" step="0.01" aria-label="VAT rate">
      <button class="btn btn-light btn-sm" onclick="this.closest('.recurring-item-row').remove()" aria-label="Remove item">✕</button>`;
    wrap.appendChild(row);
  }

  async function saveRecurring() {
    const id         = document.getElementById('recurringId').value;
    const customerId = document.getElementById('recurringCustomerId').value;
    const storeId    = document.getElementById('recurringStoreId').value;
    const frequency  = document.getElementById('recurringFrequency').value;
    const nextRun    = document.getElementById('recurringNextRun').value;
    if (!customerId) { showToast('Please select a customer.', 'danger'); return; }
    if (!nextRun)    { showToast('Next run date is required.', 'danger'); return; }

    const rows  = document.querySelectorAll('#recurringItemsWrap .recurring-item-row');
    const items = [];
    for (const row of rows) {
      const desc  = row.querySelector('[name=ri_desc]').value.trim();
      const qty   = parseFloat(row.querySelector('[name=ri_qty]').value) || 1;
      const price = parseFloat(row.querySelector('[name=ri_price]').value) || 0;
      const vat   = parseFloat(row.querySelector('[name=ri_vat]').value) || 23;
      if (!desc) continue;
      items.push({ description: desc, quantity: qty, unit_price: price, vat_rate: vat });
    }
    if (!items.length) { showToast('Add at least one line item.', 'danger'); return; }

    const body = {
      customer_id:    parseInt(customerId),
      store_id:       parseInt(storeId),
      frequency,
      next_run_date:  nextRun,
      notes:          document.getElementById('recurringNotes').value || null,
      items,
    };

    const btn = document.getElementById('btnSaveRecurring');
    if (btn) btn.disabled = true;
    try {
      const url    = id ? `/ebmpro_api/recurring.php?id=${id}` : '/ebmpro_api/recurring.php';
      const method = id ? 'PUT' : 'POST';
      const resp   = await fetch(url, {
        method,
        headers: { ...Auth.getAuthHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Save failed');
      closeModal('recurringModal');
      showToast('Recurring invoice saved!', 'success');
      loadRecurring();
    } catch (err) {
      showToast(err.message || 'Failed to save.', 'danger');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function toggleRecurring(id) {
    try {
      const resp = await fetch(`/ebmpro_api/recurring.php?action=toggle&id=${id}`, {
        method: 'POST',
        headers: Auth.getAuthHeaders(),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Toggle failed');
      loadRecurring();
    } catch (err) {
      showToast(err.message || 'Failed to toggle.', 'danger');
    }
  }

  async function deleteRecurring(id) {
    if (!confirm('Delete this recurring invoice template?')) return;
    try {
      const resp = await fetch(`/ebmpro_api/recurring.php?id=${id}`, {
        method: 'DELETE',
        headers: Auth.getAuthHeaders(),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Delete failed');
      showToast('Template deleted.', 'info');
      loadRecurring();
    } catch (err) {
      showToast(err.message || 'Failed to delete.', 'danger');
    }
  }

  /* ══════════════════════════════════════════════════════════
     EXPENSES
  ══════════════════════════════════════════════════════════ */

  async function loadExpenses() {
    const tbody = document.getElementById('expensesTbody');
    if (!tbody) return;
    tbody.innerHTML = '<tr><td colspan="7" class="text-center"><span class="spinner"></span> Loading…</td></tr>';
    try {
      const params = new URLSearchParams({ store_id: getCurrentStore() === 'FAL' ? 1 : 2 });
      const cat  = document.getElementById('expensesCategoryFilter');
      const from = document.getElementById('expensesFrom');
      const to   = document.getElementById('expensesTo');
      if (cat  && cat.value)  params.set('category', cat.value);
      if (from && from.value) params.set('from', from.value);
      if (to   && to.value)   params.set('to',   to.value);
      const resp = await fetch(`/ebmpro_api/expenses.php?${params}`, { headers: Auth.getAuthHeaders() });
      const data = await resp.json();
      const list = data.data || [];
      const totalEl = document.getElementById('expensesMonthTotalVal');
      if (totalEl) totalEl.textContent = fmtCur(data.month_total || 0);
      if (!list.length) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">No expenses found.</td></tr>';
        return;
      }
      tbody.innerHTML = list.map(e => `<tr>
        <td>${fmtDate(e.expense_date)}</td>
        <td>${escHtml(e.category || '—')}</td>
        <td>${escHtml(e.description || '—')}</td>
        <td>${escHtml(e.supplier || '—')}</td>
        <td class="text-right">${fmtCur(e.amount)}</td>
        <td class="text-right">${fmtCur(e.vat_amount)}</td>
        <td style="white-space:nowrap">
          <button class="btn btn-sm btn-light" onclick="App.openExpenseModal(${e.id})">✏️</button>
          <button class="btn btn-sm btn-light" onclick="App.deleteExpense(${e.id})" style="color:#f44336">🗑️</button>
        </td>
      </tr>`).join('');
    } catch {
      tbody.innerHTML = '<tr><td colspan="7" class="text-center text-muted">Failed to load expenses.</td></tr>';
    }
  }

  function openExpenseModal(id = null) {
    const today = new Date().toISOString().slice(0, 10);
    document.getElementById('expenseId').value          = id || '';
    document.getElementById('expenseDate').value         = today;
    document.getElementById('expenseCategory').value     = 'Materials';
    document.getElementById('expenseAmount').value       = '';
    document.getElementById('expenseDescription').value  = '';
    document.getElementById('expenseSupplier').value     = '';
    document.getElementById('expenseReceiptRef').value   = '';
    document.getElementById('expenseVatRate').value      = '0';
    document.getElementById('expenseVatAmount').value    = '0';
    document.getElementById('expenseModalTitle').textContent = id ? '💸 Edit Expense' : '💸 Add Expense';
    document.getElementById('expenseModal').classList.remove('hidden');

    if (id) {
      fetch(`/ebmpro_api/expenses.php?id=${id}`, { headers: Auth.getAuthHeaders() })
        .then(r => r.json()).then(data => {
          const e = data.data;
          if (!e) return;
          document.getElementById('expenseDate').value        = e.expense_date || today;
          document.getElementById('expenseCategory').value    = e.category || 'Materials';
          document.getElementById('expenseAmount').value      = e.amount || '';
          document.getElementById('expenseDescription').value = e.description || '';
          document.getElementById('expenseSupplier').value    = e.supplier || '';
          document.getElementById('expenseReceiptRef').value  = e.receipt_ref || '';
          document.getElementById('expenseVatRate').value     = e.vat_rate || '0';
          document.getElementById('expenseVatAmount').value   = e.vat_amount || '0';
          const storeEl = document.getElementById('expenseStoreId');
          if (storeEl && e.store_id) storeEl.value = e.store_id;
        }).catch(() => {});
    }
  }

  async function saveExpense() {
    const id     = document.getElementById('expenseId').value;
    const amount = document.getElementById('expenseAmount').value;
    const desc   = document.getElementById('expenseDescription').value.trim();
    if (!amount || parseFloat(amount) <= 0) { showToast('Amount is required.', 'danger'); return; }
    if (!desc) { showToast('Description is required.', 'danger'); return; }

    const body = {
      store_id:     parseInt(document.getElementById('expenseStoreId').value),
      expense_date: document.getElementById('expenseDate').value,
      category:     document.getElementById('expenseCategory').value,
      description:  desc,
      amount:       parseFloat(amount),
      vat_rate:     parseFloat(document.getElementById('expenseVatRate').value) || 0,
      vat_amount:   parseFloat(document.getElementById('expenseVatAmount').value) || 0,
      supplier:     document.getElementById('expenseSupplier').value || null,
      receipt_ref:  document.getElementById('expenseReceiptRef').value || null,
    };

    const btn = document.getElementById('btnSaveExpense');
    if (btn) btn.disabled = true;
    try {
      const url    = id ? `/ebmpro_api/expenses.php?id=${id}` : '/ebmpro_api/expenses.php';
      const method = id ? 'PUT' : 'POST';
      const resp   = await fetch(url, {
        method,
        headers: { ...Auth.getAuthHeaders(), 'Content-Type': 'application/json' },
        body: JSON.stringify(body),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Save failed');
      closeModal('expenseModal');
      showToast('Expense saved!', 'success');
      loadExpenses();
    } catch (err) {
      showToast(err.message || 'Failed to save expense.', 'danger');
    } finally {
      if (btn) btn.disabled = false;
    }
  }

  async function deleteExpense(id) {
    if (!confirm('Delete this expense?')) return;
    try {
      const resp = await fetch(`/ebmpro_api/expenses.php?id=${id}`, {
        method: 'DELETE',
        headers: Auth.getAuthHeaders(),
      });
      const data = await resp.json();
      if (!data.success) throw new Error(data.error || 'Delete failed');
      showToast('Expense deleted.', 'info');
      loadExpenses();
    } catch (err) {
      showToast(err.message || 'Failed to delete.', 'danger');
    }
  }

  /* ══════════════════════════════════════════════════════════
     UNIFIED IMPORT (CSV / XML)
  ══════════════════════════════════════════════════════════ */

  function updateXmlImportAccept() {
    const fmt   = document.getElementById('xmlImportFormat');
    const input = document.getElementById('xmlImportFile');
    if (!fmt || !input) return;
    input.accept = fmt.value === 'xml' ? '.xml' : '.csv';
  }

  async function runUnifiedImport() {
    const typeEl   = document.getElementById('xmlImportType');
    const fmtEl    = document.getElementById('xmlImportFormat');
    const fileEl   = document.getElementById('xmlImportFile');
    const resultEl = document.getElementById('xmlImportResult');
    if (!typeEl || !fmtEl || !fileEl) return;
    if (!fileEl.files || !fileEl.files[0]) {
      showToast('Please select a file to import.', 'danger');
      return;
    }
    const formData = new FormData();
    formData.append('file', fileEl.files[0]);
    formData.append('type', typeEl.value);
    formData.append('format', fmtEl.value);
    try {
      const resp = await fetch(
        `/ebmpro_api/import.php?type=${encodeURIComponent(typeEl.value)}&format=${encodeURIComponent(fmtEl.value)}`,
        { method: 'POST', headers: Auth.getAuthHeaders(), body: formData }
      );
      const data = await resp.json();
      if (resultEl) {
        resultEl.classList.remove('hidden');
        const color = data.success ? '#4caf50' : '#f44336';
        resultEl.style.cssText = `padding:.75rem;border-radius:6px;border-left:3px solid ${color};background:${color}11;font-size:.9rem`;
        let html = `<strong>${escHtml(data.message || (data.success ? 'Done' : data.error || 'Error'))}</strong>`;
        if (data.errors && data.errors.length) {
          html += '<ul style="margin:.5rem 0 0;padding-left:1.2rem;font-size:.82rem">' +
            data.errors.slice(0, 5).map(e => `<li>${escHtml(e)}</li>`).join('') +
            (data.errors.length > 5 ? `<li>… and ${data.errors.length - 5} more</li>` : '') +
            '</ul>';
        }
        resultEl.innerHTML = html;
      }
    } catch (err) {
      showToast(err.message || 'Import failed.', 'danger');
    }
  }

  function downloadXmlSample(type) {
    let xml, filename;
    if (type === 'products') {
      xml = `<?xml version="1.0" encoding="UTF-8"?>\n<products>\n  <product>\n    <name>Widget A</name>\n    <sku>WID-001</sku>\n    <price>9.99</price>\n    <vat_rate>23</vat_rate>\n    <stock_quantity>100</stock_quantity>\n    <description>Optional description</description>\n  </product>\n</products>`;
      filename = 'sample_products.xml';
    } else {
      xml = `<?xml version="1.0" encoding="UTF-8"?>\n<customers>\n  <customer>\n    <name>John Smith</name>\n    <email>john@example.com</email>\n    <phone>0871234567</phone>\n    <address>123 Main St</address>\n    <town>Dublin</town>\n    <county>Dublin</county>\n    <eircode>D01 AB12</eircode>\n  </customer>\n</customers>`;
      filename = 'sample_customers.xml';
    }
    const blob = new Blob([xml], { type: 'application/xml' });
    const url  = URL.createObjectURL(blob);
    const a    = document.createElement('a');
    a.href     = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    URL.revokeObjectURL(url);
  }

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
    createPaymentLink,
    copyPaymentLink,
    generatePaymentLinkFromModal,
    runReport,
    exportReportCSV,
    openStatementModal,
    sendStatement,
    showToast,
    updateHeaderUser,
    toast: showToast,
    sendSingleReminder,
    openOverdueModal,
    toggleAllOverdue,
    sendSelectedReminders,
    loadAdminDashboard,
    runAdminBackup,
    loadAdminBackupList,
    deleteBackup,
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
    // Quotes
    loadQuotes,
    openQuoteModal,
    addQuoteItemRow,
    saveQuote,
    sendQuote,
    convertQuoteToInvoice,
    deleteQuote,
    // Recurring
    loadRecurring,
    openRecurringModal,
    addRecurringItemRow,
    saveRecurring,
    toggleRecurring,
    deleteRecurring,
    // Expenses
    loadExpenses,
    openExpenseModal,
    saveExpense,
    deleteExpense,
    // Unified import
    runUnifiedImport,
    updateXmlImportAccept,
    downloadXmlSample,
  };
})();