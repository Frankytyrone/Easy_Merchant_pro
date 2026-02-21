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

  /* ── Draw text-based logo box (top-left) ──────────────────── */
  function drawLogoBox(doc, x, y, w, h) {
    // Outer border with rounded corners
    doc.setDrawColor(0, 0, 0);
    doc.setLineWidth(0.5);
    doc.roundedRect(x, y, w, h, 2, 2, 'S');

    // Line 1: "SHANE MC GEE" — bold, large, spaced
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(18);
    doc.setTextColor(0, 0, 0);
    doc.text('SHANE MC GEE', x + w / 2, y + 9, { align: 'center', charSpace: 1.5 });

    // Thin divider under line 1
    doc.setLineWidth(0.3);
    doc.line(x + 4, y + 11, x + w - 4, y + 11);

    // Line 2: "Solid Fuel" — medium bold
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(13);
    doc.text('Solid Fuel', x + w / 2, y + 17, { align: 'center' });

    // Line 3: "Paint · Hardware · Animal Feed" — small normal
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.text('Paint \u00B7 Hardware \u00B7 Animal Feed', x + w / 2, y + 23, { align: 'center' });
  }

  /* ── generateInvoicePDF ───────────────────────────────────── */
  function generateInvoicePDF(invoice, settings = {}) {
    const jsPDF = getJsPDF();
    const doc   = new jsPDF({ orientation: 'portrait', unit: 'mm', format: 'a4' });

    const shopName    = settings.shop_name    || 'Easy Builders Merchant';
    const shopAddr1   = settings.shop_address || 'Falcarragh';
    const shopAddr2   = settings.shop_address2 || '';
    const shopTown    = settings.shop_town    || 'Co. Donegal';
    const shopEmail   = settings.shop_email   || '';

    const pageW   = doc.internal.pageSize.getWidth();
    const marginL = 15;
    const marginR = 15;

    /* ── 1. Logo box (top-left) ──────────────────────────────── */
    drawLogoBox(doc, marginL, 10, 85, 24);

    /* ── 2. Shop address (top-right, right-aligned, bold) ─────── */
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(9);
    doc.setTextColor(0, 0, 0);
    const addrLines = [shopName, shopAddr1];
    if (shopAddr2) addrLines.push(shopAddr2);
    addrLines.push(shopTown);
    let addrY = 14;
    addrLines.forEach(line => {
      doc.text(line, pageW - marginR, addrY, { align: 'right' });
      addrY += 5;
    });

    /* ── 3. "Page 1 of 1" (small, right-aligned) ─────────────── */
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(7);
    doc.text('Page 1 of 1', pageW - marginR, 38, { align: 'right' });

    /* ── 4. Three-column bordered grid box ───────────────────── */
    const gridX  = marginL;
    const gridY  = 42;
    const gridW  = pageW - marginL - marginR;
    const gridH  = 45;
    const col1W  = gridW * 0.36;
    const col2W  = gridW * 0.36;
    // col3W is the remainder

    doc.setDrawColor(0, 0, 0);
    doc.setLineWidth(0.3);
    // Outer border
    doc.rect(gridX, gridY, gridW, gridH, 'S');
    // Vertical dividers
    doc.line(gridX + col1W,         gridY, gridX + col1W,         gridY + gridH);
    doc.line(gridX + col1W + col2W, gridY, gridX + col1W + col2W, gridY + gridH);
    // Header row bottom border (at y+7)
    doc.line(gridX, gridY + 7, gridX + gridW, gridY + 7);

    // Column header labels
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(8);
    doc.setTextColor(0, 0, 0);
    doc.text('Invoice address', gridX + 2,                   gridY + 5);
    doc.text('Delivery address', gridX + col1W + 2,          gridY + 5);
    doc.text('Invoice details',  gridX + col1W + col2W + 2,  gridY + 5);

    // Column 1: Invoice address
    const custName = invoice.customer_name || '';
    const custAddr = [
      invoice.customer_address1 || invoice.address_1,
      invoice.customer_address2 || invoice.address_2,
      invoice.customer_town     || invoice.inv_town,
      invoice.customer_county   || invoice.inv_region,
      invoice.customer_eircode  || invoice.inv_postcode
    ].filter(Boolean);

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    let cy = gridY + 12;
    doc.setFont('helvetica', 'bold');
    doc.text(custName, gridX + 2, cy);
    cy += 5;
    doc.setFont('helvetica', 'normal');
    custAddr.forEach(line => {
      doc.text(line, gridX + 2, cy);
      cy += 5;
    });

    // Column 2: Delivery address
    const da = invoice.delivery_address || {};
    const daLines = [da.address1, da.address2, da.town, da.county, da.eircode].filter(Boolean);
    let dy2 = gridY + 12;
    daLines.forEach(line => {
      doc.text(line, gridX + col1W + 2, dy2);
      dy2 += 5;
    });

    // Column 3: Invoice details
    const col3X   = gridX + col1W + col2W + 2;
    const invoiceNumber = invoice.invoice_number || '—';
    const invoiceDate   = fmtDate(invoice.invoice_date);
    const dueDate       = fmtDate(invoice.due_date);
    const accountNo     = invoice.account_no || 'none';

    doc.setFont('helvetica', 'normal');
    doc.setFontSize(8);
    let iy = gridY + 12;
    const detailRightX = gridX + gridW - 2;

    // Number:
    doc.text('Number:', col3X, iy);
    doc.text(invoiceNumber, detailRightX, iy, { align: 'right' });
    iy += 5;
    // Date:
    doc.text('Date:', col3X, iy);
    doc.text(invoiceDate, detailRightX, iy, { align: 'right' });
    iy += 5;
    // Due: (bold value)
    doc.text('Due:', col3X, iy);
    doc.setFont('helvetica', 'bold');
    doc.text(dueDate, detailRightX, iy, { align: 'right' });
    doc.setFont('helvetica', 'normal');
    iy += 5;
    // Account No:
    doc.text('Account No:', col3X, iy);
    doc.text(accountNo, detailRightX, iy, { align: 'right' });

    /* ── 5. "Invoice Details" label row ──────────────────────── */
    const labelRowY = gridY + gridH + 3;
    doc.setFillColor(220, 220, 220);
    doc.rect(gridX, labelRowY, gridW, 7, 'F');
    doc.setFont('helvetica', 'bold');
    doc.setFontSize(8);
    doc.setTextColor(0, 0, 0);
    doc.text('Invoice Details', gridX + 2, labelRowY + 5);

    /* ── 6. Line items table ──────────────────────────────────── */
    const tableTop = labelRowY + 7;

    const tableRows = (invoice.items || []).map(item => {
      const qty     = parseFloat(item.qty)          || 0;
      const price   = parseFloat(item.unit_price)   || 0;
      const discPct = parseFloat(item.discount_pct) || 0;
      const vatRate = parseFloat(item.vat_rate)      || 0;
      const lineNet = parseFloat(item.line_net != null ? item.line_net : (qty * price * (1 - discPct / 100)));
      const lineVat = parseFloat(item.line_vat != null ? item.line_vat : (lineNet * vatRate / 100));
      return {
        code:        item.code        || '',
        description: item.description || '',
        tx:          vatRate > 0 ? '\u2713' : '',
        vat_rate:    vatRate > 0 ? `${vatRate}%` : '0%',
        qty:         qty,
        price:       fmtCur(price),
        discount:    discPct > 0 ? `${discPct}%` : '',
        amount:      fmtCur(lineNet),
        vat:         fmtCur(lineVat)
      };
    });

    doc.autoTable({
      startY:  tableTop,
      columns: [
        { header: 'Code',        dataKey: 'code'        },
        { header: 'Description', dataKey: 'description' },
        { header: 'Tx',          dataKey: 'tx'          },
        { header: 'VAT%',        dataKey: 'vat_rate'    },
        { header: 'Qty',         dataKey: 'qty'         },
        { header: 'Price',       dataKey: 'price'       },
        { header: 'Discount',    dataKey: 'discount'    },
        { header: 'Amount',      dataKey: 'amount'      },
        { header: 'VAT',         dataKey: 'vat'         }
      ],
      body: tableRows,
      theme: 'striped',
      headStyles: {
        fillColor: [26, 58, 92],
        textColor: 255,
        fontStyle: 'bold',
        fontSize:  8
      },
      bodyStyles:         { fontSize: 8 },
      alternateRowStyles: { fillColor: [245, 248, 252] },
      columnStyles: {
        code:        { cellWidth: 18 },
        description: { cellWidth: 'auto' },
        tx:          { halign: 'center', cellWidth: 8 },
        vat_rate:    { halign: 'right',  cellWidth: 12 },
        qty:         { halign: 'right',  cellWidth: 10 },
        price:       { halign: 'right',  cellWidth: 20 },
        discount:    { halign: 'right',  cellWidth: 16 },
        amount:      { halign: 'right',  cellWidth: 22 },
        vat:         { halign: 'right',  cellWidth: 18 }
      },
      margin: { left: marginL, right: marginR }
    });

    /* ── 7. Totals row table ──────────────────────────────────── */
    const subtotal  = parseFloat(invoice.subtotal)     || 0;
    const discTotal = parseFloat(invoice.discount_total) || 0;
    const delivery  = 0;
    const vatTotal  = parseFloat(invoice.vat_total)    || 0;
    const total     = parseFloat(invoice.total)        || 0;
    const paid      = parseFloat(invoice.amount_paid)  || 0;
    const balance   = parseFloat(invoice.balance)      || (total - paid);

    doc.autoTable({
      startY: doc.lastAutoTable.finalY,
      head:   [],
      body: [[
        fmtCur(subtotal),
        discTotal > 0 ? fmtCur(discTotal) : '—',
        fmtCur(delivery),
        fmtCur(vatTotal),
        fmtCur(total),
        paid > 0 ? fmtCur(paid) : '—',
        fmtCur(balance)
      ]],
      columns: [
        { header: 'Sub-total', dataKey: 0 },
        { header: 'Discount',  dataKey: 1 },
        { header: 'Delivery',  dataKey: 2 },
        { header: 'VAT',       dataKey: 3 },
        { header: 'Total',     dataKey: 4 },
        { header: 'Paid',      dataKey: 5 },
        { header: 'BALANCE',   dataKey: 6 }
      ],
      theme: 'plain',
      styles: { fontSize: 8, halign: 'right', lineWidth: 0.2, lineColor: [0, 0, 0] },
      columnStyles: {
        6: { fontStyle: 'bold' }
      },
      margin: { left: marginL, right: marginR }
    });

    /* ── 8. Footer text ───────────────────────────────────────── */
    let footY = doc.lastAutoTable.finalY + 8;
    doc.setFont('helvetica', 'normal');
    doc.setFontSize(9);
    doc.setTextColor(40, 40, 40);
    doc.text(shopName, marginL, footY);
    if (shopEmail) {
      footY += 5;
      doc.text(`email: ${shopEmail}`, marginL, footY);
    }

    /* ── 9. Reply slip (if balance > 0) ──────────────────────── */
    if (balance > 0) {
      const pageH  = doc.internal.pageSize.getHeight();
      const slipY  = pageH - 52;

      // Full-width solid separator
      doc.setDrawColor(0, 0, 0);
      doc.setLineWidth(0.4);
      doc.line(marginL, slipY, pageW - marginR, slipY);

      // Left side: customer name + address
      let slipLY = slipY + 7;
      doc.setFont('helvetica', 'bold');
      doc.setFontSize(9);
      doc.setTextColor(0, 0, 0);
      doc.text(custName, marginL, slipLY);
      slipLY += 5;
      doc.setFont('helvetica', 'normal');
      custAddr.forEach(line => {
        doc.text(line, marginL, slipLY);
        slipLY += 5;
      });

      // Right side: bordered two-column box
      const boxX  = pageW - marginR - 75;
      const boxY  = slipY + 5;
      const boxW  = 75;
      const rowH  = 7;
      const rows  = [
        ['Number',  invoiceNumber,   true  ],
        ['Date',    invoiceDate,     false ],
        ['Due',     dueDate,         false ],
        ['Balance', fmtCur(balance), true ]
      ];
      const boxH  = rows.length * rowH;
      const labelColW = 28;

      doc.setDrawColor(0, 0, 0);
      doc.setLineWidth(0.3);
      doc.rect(boxX, boxY, boxW, boxH, 'S');
      // Vertical divider
      doc.line(boxX + labelColW, boxY, boxX + labelColW, boxY + boxH);

      rows.forEach(([ label, val, bold ], i) => {
        const ry = boxY + i * rowH;
        // Row bottom border (except last)
        if (i < rows.length - 1) {
          doc.line(boxX, ry + rowH, boxX + boxW, ry + rowH);
        }
        doc.setFont('helvetica', bold ? 'bold' : 'normal');
        doc.setFontSize(8);
        doc.text(label, boxX + 2, ry + 5);
        doc.setFont('helvetica', bold ? 'bold' : 'normal');
        doc.text(val, boxX + boxW - 2, ry + 5, { align: 'right' });
      });

      // Centred note below box
      doc.setFont('helvetica', 'italic');
      doc.setFontSize(8);
      doc.text(
        'Please return this reply slip with your payment - Thank You.',
        pageW / 2,
        boxY + boxH + 6,
        { align: 'center' }
      );
    }

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