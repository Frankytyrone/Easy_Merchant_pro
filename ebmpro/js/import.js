/* ============================================================
   Easy Builders Merchant Pro — import.js
   CSV import module (customers, products, invoices)
   ============================================================ */

const Import = (() => {
  /**
   * Run an import for the given type ('customers', 'products', 'invoices').
   * Reads the corresponding file input, uploads to import_csv.php and
   * shows progress / result in the settings screen.
   */
  async function run(type) {
    const fileInputId = `import${capitalise(type)}File`;
    const fileInput = document.getElementById(fileInputId);
    if (!fileInput || !fileInput.files || fileInput.files.length === 0) {
      App.showToast(`Please select a ${type} CSV file first.`, 'warning');
      return;
    }

    const file = fileInput.files[0];
    const progressWrap  = document.getElementById('importProgressWrap');
    const progressBar   = document.getElementById('importProgressBar');
    const progressLabel = document.getElementById('importProgressLabel');
    const resultDiv     = document.getElementById('importResult');

    // Reset UI
    if (progressWrap)  progressWrap.classList.remove('hidden');
    if (progressBar)   progressBar.style.width = '0%';
    if (progressLabel) progressLabel.textContent = `Uploading ${type} CSV…`;
    if (resultDiv)     resultDiv.classList.add('hidden');

    const formData = new FormData();
    formData.append('type', type);
    formData.append('file', file);

    try {
      // Simulate progress during upload (indeterminate)
      let pct = 0;
      const ticker = setInterval(() => {
        pct = Math.min(pct + 5, 90);
        if (progressBar) progressBar.style.width = pct + '%';
      }, 200);

      const resp = await fetch('/ebmpro_api/import_csv.php', {
        method: 'POST',
        headers: Auth.getAuthHeaders(),
        body: formData,
      });

      clearInterval(ticker);
      if (progressBar) progressBar.style.width = '100%';

      const data = await resp.json();

      if (!data.success) {
        if (progressLabel) progressLabel.textContent = 'Import failed.';
        App.showToast(data.error || 'Import failed.', 'danger');
        return;
      }

      if (progressLabel) progressLabel.textContent = 'Import complete.';

      const errCount = Array.isArray(data.errors) ? data.errors.length : 0;
      const errHtml  = errCount > 0
        ? `<details class="mt-1"><summary style="cursor:pointer;color:#c00">${errCount} error(s) — click to expand</summary><ul style="font-size:.8rem;margin:.5rem 0 0 1rem">${data.errors.slice(0, 20).map(e => `<li>${escHtml(e)}</li>`).join('')}</ul></details>`
        : '';

      if (resultDiv) {
        resultDiv.innerHTML =
          `<div class="alert alert-success" style="background:#d4edda;border:1px solid #c3e6cb;border-radius:4px;padding:.75rem 1rem">
            ✅ <strong>${capitalise(type)}</strong> import complete —
            <strong>${data.imported}</strong> imported,
            <strong>${data.skipped}</strong> skipped${errCount > 0 ? `, <strong>${errCount}</strong> errors` : ''}.
            ${errHtml}
          </div>`;
        resultDiv.classList.remove('hidden');
      }

      App.showToast(
        `${capitalise(type)}: ${data.imported} imported, ${data.skipped} skipped.`,
        errCount > 0 ? 'warning' : 'success'
      );

      // Clear the file input
      fileInput.value = '';

    } catch (err) {
      if (progressLabel) progressLabel.textContent = 'Upload failed.';
      App.showToast(err.message || 'Upload failed.', 'danger');
    }
  }

  function escHtml(str) {
    const d = document.createElement('div');
    d.textContent = str;
    return d.innerHTML;
  }

  function capitalise(s) {
    return s ? s.charAt(0).toUpperCase() + s.slice(1) : s;
  }

  return { run };
})();
