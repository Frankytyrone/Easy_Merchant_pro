/* ============================================================
   Easy Builders Merchant Pro — pdf.js
   PDF generation via jsPDF + autoTable
   ============================================================ */

const PDF = (() => {
  /* ── Currency & date formatters ───────────────────────────── */
  function fmtCur(n) {
    return '€' + parseFloat(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',');
  }

  function fmtDate(str) {
    if (!str) return '';
    const d = new Date(str);
    if (isNaN(d)) return str;
    const dd = String(d.getDate()).padStart(2, '0');
    const mm = String(d.getMonth() + 1).padStart(2, '0');
    const yy = d.getFullYear();
    return `${dd}/${mm}/${yy}`;
  }

  /* ── Resolve jsPDF constructor ────────────────────────────── */
  function getJsPDF() {
    if (typeof window.jspdf !== 'undefined') return window.jspdf.jsPDF;
    if (typeof window.jsPDF !== 'undefined') return window.jsPDF;
    throw new Error('jsPDF not loaded');
  }

  /* ── generateInvoicePDF ───────────────────────────────────── */
  function generateInvoicePDF(invoice, settings = {}) {
    const jsPDF = getJsPDF();
    const doc   = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

    const shopName    = settings.shop_name    || 'Easy Builders Merchant';
    const shopAddr    = settings.shop_address || 'Falcarragh, Co. Donegal';
    const shopPhone   = settings.shop_phone   || '';
    const shopEmail   = settings.shop_email   || '';
    const vatNo       = settings.vat_number   || '';
    const regNo       = settings.reg_number   || '';
    const logoText    = shopName; // Text fallback (no raster logo needed)

    const isQuote     = invoice.invoice_type === 'quote';
    const docLabel    = isQuote ? 'QUOTE' : 'INVOICE';
    const docNumber   = invoice.invoice_number || '—';

    const pageW = doc.internal.pageSize.getWidth();
    const marginL = 15, marginR = 15;
    const colMid  = pageW / 2;

    /* ── Header bar ─────────────────────────────────────────── */
    doc.setFillColor(26, 58, 92);       // --primary
    doc.rect(0, 0, pageW, 28, 'F');

    // Shop name
    doc.setTextColor(255, 255, 255);
    doc.setFontSize(16);
    doc.setFont('helvetica', 'bold');
    doc.text(logoText, marginL, 12);

    // Sub-line: address | phone
    doc.setFontSize(8);
    doc.setFont('helvetica', 'normal');
    const addrLine = [shopAddr, shopPhone, shopEmail].filter(Boolean).join('  |  ');
    doc.text(addrLine, marginL, 19);
    if (vatNo) doc.text(`VAT No: ${vatNo}`, marginL, 24);

    // Document type (right)
    doc.setFontSize(20);
    doc.setFont('helvetica', 'bold');
    doc.setTextColor(232, 134, 26);   // --accent
    doc.text(docLabel, pageW - marginR, 16, { align: 'right' });

    /* ── Invoice meta (right column) ───────────────────────── */
    let y = 34;
    doc.setTextColor(40, 40, 40);
    doc.setFontSize(9);
    doc.setFont('helvetica', 'normal');

    const metaRows = [
      [`${docLabel} No:`, docNumber],
      ['Date:',           fmtDate(invoice.invoice_date)],
      ...(isQuote ? [] : [['Due Date:', fmtDate(invoice.due_date)]]),
      ['Store:',          invoice.store_id || ''],
      ['Status:',         (invoice.status || 'draft').toUpperCase()]
    ];

    metaRows.forEach(([label, val]) => {
      doc.setFont('helvetica', 'bold');
      doc.text(label, pageW - marginR - 40, y, { align: 'left' });
      doc.setFont('helvetica', 'normal');
      doc.text(String(val), pageW - marginR, y, { align: 'right' });
      y += 6;
    });

    /* ── INVOICE TO / DELIVERY TO ───────────────────────────── */
    y = 34;
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(8);
    doc.setTextColor(100, 100, 100);
    doc.text('INVOICE TO:', marginL, y);
    y += 5;

    doc.setTextColor(40, 40, 40);
    doc.setFontSize(10);
    doc.setFont('helvetica', 'bold');
    doc.text(invoice.customer_name || '—', marginL, y);
    y += 5;

    doc.setFontSize(9);
    doc.setFont('helvetica', 'normal');
    const custAddr = [
      invoice.customer_address1,
      invoice.customer_address2,
      invoice.customer_town,
      invoice.customer_county,
      invoice.customer_eircode
    ].filter(Boolean);
    custAddr.forEach(line => { doc.text(line, marginL, y); y += 5; });
    if (invoice.customer_phone) { doc.text(invoice.customer_phone, marginL, y); y += 5; }
    if (invoice.customer_email) { doc.text(invoice.customer_email, marginL, y); y += 5; }

    // Delivery address (right side, same top)
    const da = invoice.delivery_address || {};
    const daLines = [da.address1, da.address2, da.town, da.county, da.eircode].filter(Boolean);
    if (daLines.length) {
      let dy = 39;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(8);
      doc.setTextColor(100, 100, 100);
      doc.text('DELIVERY TO:', colMid + 5, dy);
      dy += 5;
      doc.setFontSize(9);
      doc.setFont('helvetica', 'normal');
      doc.setTextColor(40, 40, 40);
      daLines.forEach(line => { doc.text(line, colMid + 5, dy); dy += 5; });
    }

    /* ── Divider line ───────────────────────────────────────── */
    const tableTop = Math.max(y, 75) + 4;
    doc.setDrawColor(200, 200, 200);
    doc.line(marginL, tableTop - 2, pageW - marginR, tableTop - 2);

    /* ── Items table ────────────────────────────────────────── */
    const tableColumns = [
      { header: 'Code',      dataKey: 'code'      },
      { header: 'Description', dataKey: 'description' },
      { header: 'Qty',       dataKey: 'qty'       },
      { header: 'Unit Price',dataKey: 'unit_price' },
      { header: 'Disc%',     dataKey: 'disc'      },
      { header: 'VAT%',      dataKey: 'vat_rate'  },
      { header: 'Net',       dataKey: 'line_net'  },
      { header: 'VAT',       dataKey: 'line_vat'  },
      { header: 'Total',     dataKey: 'line_total' }
    ];

    const tableRows = (invoice.items || []).map(item => ({
      code:        item.code        || '',
      description: item.description || '',
      qty:         item.qty         || 0,
      unit_price:  fmtCur(item.unit_price),
      disc:        item.discount_pct > 0 ? `${item.discount_pct}%` : '',
      vat_rate:    `${item.vat_rate || 0}%`,
      line_net:    fmtCur(item.line_net),
      line_vat:    fmtCur(item.line_vat),
      line_total:  fmtCur(item.line_total)
    }));

    doc.autoTable({
      startY:  tableTop,
      columns: tableColumns,
      body:    tableRows,
      theme:   'striped',
      headStyles: {
        fillColor:  [26, 58, 92],
        textColor:  255,
        fontStyle:  'bold',
        fontSize:   8
      },
      bodyStyles:      { fontSize: 8 },
      alternateRowStyles: { fillColor: [245, 248, 252] },
      columnStyles: {
        qty:        { halign: 'right', cellWidth: 12 },
        unit_price: { halign: 'right', cellWidth: 22 },
        disc:       { halign: 'right', cellWidth: 12 },
        vat_rate:   { halign: 'right', cellWidth: 12 },
        line_net:   { halign: 'right', cellWidth: 22 },
        line_vat:   { halign: 'right', cellWidth: 18 },
        line_total: { halign: 'right', cellWidth: 24, fontStyle: 'bold' }
      },
      margin: { left: marginL, right: marginR }
    });

    /* ── Totals block ───────────────────────────────────────── */
    let ty = doc.lastAutoTable.finalY + 6;
    const totW  = 80;
    const totX  = pageW - marginR - totW;

    function totRow(label, value, bold = false, color = null) {
      if (color) doc.setTextColor(...color);
      else doc.setTextColor(40, 40, 40);
      doc.setFont('helvetica', bold ? 'bold' : 'normal');
      doc.setFontSize(9);
      doc.text(label, totX + 2, ty);
      doc.text(value, pageW - marginR, ty, { align: 'right' });
      ty += 6;
    }

    // Thin separator
    doc.setDrawColor(200, 200, 200);
    doc.line(totX, ty - 2, pageW - marginR, ty - 2);

    totRow('Subtotal (ex VAT)', fmtCur(invoice.subtotal));

    // VAT breakdown
    const breakdown = invoice.vat_breakdown || {};
    Object.entries(breakdown)
      .sort(([a], [b]) => parseFloat(a) - parseFloat(b))
      .forEach(([rate, amt]) => totRow(`VAT @ ${rate}%`, fmtCur(amt)));

    // Bold separator before total
    doc.setDrawColor(26, 58, 92);
    doc.setLineWidth(0.5);
    doc.line(totX, ty - 1, pageW - marginR, ty - 1);
    doc.setLineWidth(0.2);

    totRow('TOTAL', fmtCur(invoice.total), true);

    if (invoice.amount_paid > 0) {
      totRow('Paid', `−${fmtCur(invoice.amount_paid)}`, false, [39, 174, 96]);
      doc.setDrawColor(200, 200, 200);
      doc.line(totX, ty - 1, pageW - marginR, ty - 1);
      totRow('Balance Due', fmtCur(invoice.balance), true, [231, 76, 60]);
    }

    /* ── Notes ──────────────────────────────────────────────── */
    if (invoice.notes) {
      ty += 4;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(8);
      doc.setTextColor(100, 100, 100);
      doc.text('Notes:', marginL, ty); ty += 5;
      doc.setFont('helvetica', 'normal');
      doc.setTextColor(60, 60, 60);
      const lines = doc.splitTextToSize(invoice.notes, colMid - marginL);
      doc.text(lines, marginL, ty);
      ty += lines.length * 5 + 4;
    }

    /* ── Reply slip (if balance > 0) ────────────────────────── */
    if (invoice.balance > 0) {
      const slipY = doc.internal.pageSize.getHeight() - 40;
      doc.setDrawColor(150, 150, 150);
      doc.setLineDashPattern([2, 2], 0);
      doc.line(marginL, slipY, pageW - marginR, slipY);
      doc.setLineDashPattern([], 0);

      doc.setFont('helvetica', 'bold');
      doc.setFontSize(8);
      doc.setTextColor(100, 100, 100);
      doc.text('PAYMENT SLIP — Please return with payment', marginL, slipY + 5);

      doc.setFont('helvetica', 'normal');
      doc.setFontSize(9);
      doc.setTextColor(40, 40, 40);
      doc.text(`${docLabel} No: ${docNumber}`, marginL, slipY + 12);
      doc.text(`Customer: ${invoice.customer_name || ''}`, marginL, slipY + 18);
      doc.text(`Amount Due: ${fmtCur(invoice.balance)}`, marginL, slipY + 24);
      doc.text(`Due Date: ${fmtDate(invoice.due_date)}`, marginL, slipY + 30);
    }

    /* ── Footer ─────────────────────────────────────────────── */
    const pageH   = doc.internal.pageSize.getHeight();
    doc.setFont('helvetica', 'italic');
    doc.setFontSize(7.5);
    doc.setTextColor(150, 150, 150);
    const footerParts = ['E&OE', 'Registered in Ireland'];
    if (vatNo) footerParts.push(`VAT No: ${vatNo}`);
    if (regNo) footerParts.push(`Reg No: ${regNo}`);
    doc.text(footerParts.join('  ·  '), pageW / 2, pageH - 8, { align: 'center' });

    return doc;
  }

  /* ── Save ─────────────────────────────────────────────────── */
  function saveInvoicePDF(invoice, settings = {}) {
    const doc = generateInvoicePDF(invoice, settings);
    const num = invoice.invoice_number || 'new';
    doc.save(`invoice-${num}.pdf`);
    return doc;
  }

  /* ── Print (opens in new window with autoPrint) ───────────── */
  function printInvoicePDF(invoice, settings = {}) {
    const doc = generateInvoicePDF(invoice, settings);
    doc.autoPrint();
    const blob = doc.output('blob');
    const url  = URL.createObjectURL(blob);
    const win  = window.open(url, '_blank');
    if (!win) {
      // Popup blocked fallback — just save
      saveInvoicePDF(invoice, settings);
    } else {
      setTimeout(() => URL.revokeObjectURL(url), 60000);
    }
    return doc;
  }

  return {
    generateInvoicePDF,
    saveInvoicePDF,
    printInvoicePDF
  };
})();
