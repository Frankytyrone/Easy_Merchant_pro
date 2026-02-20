// Invoice management functions

/**
 * Function to create a new invoice
 * @param {Number} amount - The amount of the invoice
 * @param {String} customer - The name of the customer
 * @param {Date} dueDate - The due date of the invoice
 * @returns {Object} - The created invoice object
 */
function createInvoice(amount, customer, dueDate) {
    return {
        id: generateInvoiceId(),
        amount: amount,
        customer: customer,
        dueDate: dueDate,
        status: 'Pending'
    };
}

/**
 * Function to generate a unique invoice ID
 * @returns {Number} - A unique invoice ID
 */
function generateInvoiceId() {
    return Math.floor(Math.random() * 1000000);
}

/**
 * Function to mark an invoice as paid
 * @param {Object} invoice - The invoice object to update
 * @returns {Object} - The updated invoice object
 */
function markInvoiceAsPaid(invoice) {
    invoice.status = 'Paid';
    return invoice;
}

/**
 * Function to calculate total due for a list of invoices
 * @param {Array} invoices - An array of invoice objects
 * @returns {Number} - The total amount due
 */
function calculateTotalDue(invoices) {
    return invoices.reduce((total, invoice) => total + invoice.amount, 0);
}