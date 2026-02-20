// ebmpro/js/pdf.js — invoice PDF generation using jsPDF.

/**
 * Generate and download a PDF for the given invoice.
 * @param {Object} invoice - Invoice data object.
 */
function generateInvoicePDF(invoice) {
    const { jsPDF } = window.jspdf;
    const doc = new jsPDF({ unit: 'mm', format: 'a4' });

    // Header
    doc.setFontSize(18);
    doc.text('Easy Builders Merchant Pro', 14, 20);

    doc.setFontSize(12);
    doc.text('Invoice #: ' + invoice.id, 14, 32);
    doc.text('Customer: ' + invoice.customer, 14, 40);
    doc.text('Date: ' + (invoice.date || new Date().toLocaleDateString('en-IE')), 14, 48);

    // Items table (simple implementation)
    let y = 60;
    doc.setFontSize(10);
    doc.text('Description', 14, y);
    doc.text('Qty', 100, y);
    doc.text('Price', 130, y);
    doc.text('Total', 165, y);
    y += 6;
    doc.line(14, y, 196, y);
    y += 4;

    (invoice.items || []).forEach(item => {
        doc.text(String(item.description || ''), 14, y);
        doc.text(String(item.qty || ''), 100, y);
        doc.text(String(item.price || ''), 130, y);
        doc.text(String(item.total || ''), 165, y);
        y += 6;
    });

    // Footer
    doc.setFontSize(8);
    doc.text('shanemcgee.biz  |  Easy Builders Merchant Pro  |  E&OE', 14, 285);

    doc.save('invoice-' + invoice.id + '.pdf');
}
