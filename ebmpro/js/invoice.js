/* ============================================================
   Easy Builders Merchant Pro — invoice.js
   Invoice logic, item management, and rendering
   ============================================================ */

const Invoice = (() => {
  /* ── currentInvoice state ─────────────────────────────────── */
  let currentInvoice = buildEmpty();

  function buildEmpty() {
    const today = todayStr();
    return {
      id:               null,
      store_code:        App ? App.getCurrentStore() : 'FAL',
      invoice_type:     'invoice',  // 'invoice' | 'quote'
      customer_id:      null,
      customer_name:    '',
      items:            [],
      notes:            '',
      internal_notes:   '',
      invoice_date:     today,
      due_date:         addDays(today, 30),
      delivery_address: { address1: '', address2: '', town: '', county: '', eircode: '' },
      subtotal:         0,
      total_vat:        0,
      total:            0,
      amount_paid:      0,
      balance:          0,
      status:           'draft',
      vat_breakdown:    {}
    };
  }

  /* ── Date helpers ─────────────────────────────────────────── */
  function todayStr() {
    return new Date().toISOString().slice(0, 10);
  }
  function addDays(dateStr, days) {
    const d = new Date(dateStr);
    d.setDate(d.getDate() + days);
    return d.toISOString().slice(0, 10);
  }

  /* ── addItem ──────────────────────────────────────────────── */
  function addItem(product) {
    const item = {
      product_id:   product.id   || null,
      code:         product.code || '',
      description:  product.description || '',
      qty:          1,
      unit_price:   parseFloat(product.price) || 0,
      discount_pct: 0,
      vat_rate:     parseFloat(product.vat_rate) || 0,
      line_net:     0,
      line_vat:     0,
      line_total:   0
    };
    currentInvoice.items.push(item);
    calculateTotals();
    renderItemsTable();
    renderTotals();
  }

  /* ── removeItem ───────────────────────────────────────────── */
  function removeItem(index) {
    currentInvoice.items.splice(index, 1);
    calculateTotals();
    renderItemsTable();
    renderTotals();
  }

  /* ── updateItem ───────────────────────────────────────────── */
  function updateItem(index, field, value) {
    if (!currentInvoice.items[index]) return;
    const numFields = ['qty', 'unit_price', 'discount_pct', 'vat_rate'];
    currentInvoice.items[index][field] = numFields.includes(field)
      ? parseFloat(value) || 0
      : value;
    calculateTotals();
    renderTotals();
  }

  /* ── calculateTotals ──────────────────────────────────────── */
  function calculateTotals() {
    let subtotal = 0;
    let totalVat = 0;
    const vatBreakdown = {};

    currentInvoice.items.forEach(item => {
      const qty      = parseFloat(item.qty)          || 0;
      const price    = parseFloat(item.unit_price)    || 0;
      const disc     = parseFloat(item.discount_pct)  || 0;
      const vatRate  = parseFloat(item.vat_rate)      || 0;

      const lineNet   = qty * price * (1 - disc / 100);
      const lineVat   = lineNet * (vatRate / 100);
      const lineTotal = lineNet + lineVat;

      item.line_net   = round2(lineNet);
      item.line_vat   = round2(lineVat);
      item.line_total = round2(lineTotal);

      subtotal += lineNet;
      totalVat += lineVat;

      if (vatRate > 0) {
        const key = vatRate.toFixed(1);
        vatBreakdown[key] = (vatBreakdown[key] || 0) + lineVat;
      }
    });

    currentInvoice.subtotal      = round2(subtotal);
    currentInvoice.total_vat     = round2(totalVat);
    currentInvoice.total         = round2(subtotal + totalVat);
    currentInvoice.vat_breakdown = vatBreakdown;
    currentInvoice.balance       = round2(currentInvoice.total - (currentInvoice.amount_paid || 0));
  }

  function round2(n) { return Math.round(n * 100) / 100; }

  /* ── renderItemsTable ─────────────────────────────────────── */
  function renderItemsTable() {
    const tbody = document.getElementById('invoiceItemsTbody');
    if (!tbody) return;

    if (!currentInvoice.items.length) {
      tbody.innerHTML = `
        <tr id="emptyItemsRow">
          <td colspan="10" class="text-center text-muted" style="padding:1.5rem">
            No items — search for a product above to add lines.
          </td>
        </tr>`;
      return;
    }

    tbody.innerHTML = currentInvoice.items.map((item, i) => `
      <tr data-idx="${i}">
        <td class="col-code">
          <input type="text" value="${escHtml(item.code)}"
            onchange="Invoice.updateItem(${i},'code',this.value)" aria-label="Code">
        </td>
        <td class="col-desc">
          <input type="text" value="${escHtml(item.description)}"
            onchange="Invoice.updateItem(${i},'description',this.value)" aria-label="Description">
        </td>
        <td class="col-qty">
          <input type="number" value="${item.qty}" min="0" step="0.001"
            oninput="Invoice.updateItem(${i},'qty',this.value)" aria-label="Qty">
        </td>
        <td class="col-price">
          <input type="number" value="${item.unit_price}" min="0" step="0.01"
            oninput="Invoice.updateItem(${i},'unit_price',this.value)" aria-label="Price">
        </td>
        <td class="col-disc">
          <input type="number" value="${item.discount_pct}" min="0" max="100" step="0.1"
            oninput="Invoice.updateItem(${i},'discount_pct',this.value)" aria-label="Disc%">
        </td>
        <td class="col-vat">
          <input type="number" value="${item.vat_rate}" min="0" max="100" step="0.1"
            oninput="Invoice.updateItem(${i},'vat_rate',this.value)" aria-label="VAT%">
        </td>
        <td class="col-net text-right">${fmtCur(item.line_net)}</td>
        <td class="col-vata text-right">${fmtCur(item.line_vat)}</td>
        <td class="col-total text-right fw-bold">${fmtCur(item.line_total)}</td>
        <td class="col-del">
          <button class="btn-del-row" onclick="Invoice.removeItem(${i})" title="Remove line" aria-label="Remove line">×</button>
        </td>
      </tr>
    `).join('');
  }

  /* ── renderTotals ─────────────────────────────────────────── */
  function renderTotals() {
    const el = document.getElementById('invoiceTotals');
    if (!el) return;

    const vatRows = Object.entries(currentInvoice.vat_breakdown)
      .sort(([a], [b]) => parseFloat(a) - parseFloat(b))
      .map(([rate, amt]) =>
        `<tr><td>VAT @ ${rate}%</td><td>${fmtCur(amt)}</td></tr>`
      ).join('');

    el.innerHTML = `
      <table class="totals-table">
        <tr><td>Subtotal (ex VAT)</td><td>${fmtCur(currentInvoice.subtotal)}</td></tr>
        ${vatRows}
        <tr class="total-row"><td>Total</td><td>${fmtCur(currentInvoice.total)}</td></tr>
        ${currentInvoice.amount_paid > 0
          ? `<tr class="paid-row"><td>Paid</td><td>−${fmtCur(currentInvoice.amount_paid)}</td></tr>`
          : ''}
        ${currentInvoice.amount_paid > 0
          ? `<tr class="balance-row"><td>Balance Due</td><td>${fmtCur(currentInvoice.balance)}</td></tr>`
          : ''}
      </table>
    `;
  }

  /* ── saveInvoice ──────────────────────────────────────────── */
  async function saveInvoice() {
    // Sync store_code from app state
    currentInvoice.store_code = App.getCurrentStore();
    const method  = currentInvoice.id ? 'PUT' : 'POST';
    const url     = currentInvoice.id
      ? `/ebmpro_api/invoices.php?id=${encodeURIComponent(currentInvoice.id)}`
      : '/ebmpro_api/invoices.php';
    const result  = await Sync.syncData(url, currentInvoice, method);

    if (result && result.id) {
      currentInvoice.id = result.id;
      // Cache locally
      await DB.save('invoices', currentInvoice);
    }
    return result;
  }

  /* ── loadInvoice ──────────────────────────────────────────── */
  async function loadInvoice(id) {
    try {
      const resp = await fetch(
        `/ebmpro_api/invoices.php?id=${encodeURIComponent(id)}`,
        { headers: Auth.getAuthHeaders() }
      );
      if (!resp.ok) throw new Error('API error');
      const data = await resp.json();
      currentInvoice = Object.assign(buildEmpty(), data.invoice || data);
    } catch {
      const cached = await DB.get('invoices', id);
      if (cached) currentInvoice = Object.assign(buildEmpty(), cached);
      else throw new Error('Invoice not found');
    }

    calculateTotals();
    populateForm();
    renderItemsTable();
    renderTotals();
    return currentInvoice;
  }

  /* ── populateForm ─────────────────────────────────────────── */
  function populateForm() {
    setVal('customerSearch',    currentInvoice.customer_name);
    setVal('invoiceDateInput',  currentInvoice.invoice_date);
    setVal('dueDateInput',      currentInvoice.due_date);
    setVal('invoiceNotes',      currentInvoice.notes);
    setVal('internalNotes',     currentInvoice.internal_notes);
    setVal('deliveryAddress1',  currentInvoice.delivery_address.address1);
    setVal('deliveryAddress2',  currentInvoice.delivery_address.address2);
    setVal('deliveryTown',      currentInvoice.delivery_address.town);
    setVal('deliveryCounty',    currentInvoice.delivery_address.county);
    setVal('deliveryEircode',   currentInvoice.delivery_address.eircode);

    // Invoice type toggle
    document.querySelectorAll('.type-toggle button').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.type === currentInvoice.invoice_type);
    });

    // Invoice number display
    const numEl = document.getElementById('invoiceNumberDisplay');
    if (numEl) numEl.textContent = currentInvoice.invoice_number || '(new)';
  }

  function setVal(id, val) {
    const el = document.getElementById(id);
    if (el) el.value = val || '';
  }

  /* ── newInvoice ───────────────────────────────────────────── */
  function newInvoice() {
    currentInvoice = buildEmpty();
    populateForm();
    renderItemsTable();
    renderTotals();
  }

  /* ── setCustomer ──────────────────────────────────────────── */
  function setCustomer(customer) {
    currentInvoice.customer_id   = customer.id;
    currentInvoice.customer_name = customer.name;
    const el = document.getElementById('customerSearch');
    if (el) el.value = customer.name;
  }

  /* ── setInvoiceType ───────────────────────────────────────── */
  function setInvoiceType(type) {
    currentInvoice.invoice_type = type;
    document.querySelectorAll('.type-toggle button').forEach(btn => {
      btn.classList.toggle('active', btn.dataset.type === type);
    });
    // Update action button label
    const saveBtn = document.getElementById('btnSaveInvoice');
    if (saveBtn) saveBtn.textContent = type === 'quote' ? '💾 Save Quote' : '💾 Save Invoice';
  }

  /* ── convertToInvoice ─────────────────────────────────────── */
  async function convertToInvoice(quoteId) {
    const result = await Sync.syncData(
      '/ebmpro_api/invoices.php?action=convert_quote',
      { quote_id: quoteId },
      'POST'
    );
    if (result && result.invoice_id) {
      await loadInvoice(result.invoice_id);
    }
    return result;
  }

  /* ── addPayment ───────────────────────────────────────────── */
  async function addPayment(invoiceId, amount, method, date, reference) {
    const result = await Sync.syncData('/ebmpro_api/payments.php', {
      invoice_id: invoiceId,
      amount:     parseFloat(amount),
      method,
      date,
      reference
    }, 'POST');

    if (result && !result.offline) {
      // Reload invoice to reflect new balance
      await loadInvoice(invoiceId);
    }
    return result;
  }

  /* ── getCurrent ───────────────────────────────────────────── */
  function getCurrent() { return currentInvoice; }

  /* ── Formatting helpers ───────────────────────────────────── */
  function fmtCur(n) {
    return '€' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }
  function escHtml(str) {
    return String(str || '')
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;');
  }

  return {
    addItem,
    removeItem,
    updateItem,
    calculateTotals,
    renderItemsTable,
    renderTotals,
    saveInvoice,
    loadInvoice,
    newInvoice,
    setCustomer,
    setInvoiceType,
    convertToInvoice,
    addPayment,
    getCurrent
  };
})();