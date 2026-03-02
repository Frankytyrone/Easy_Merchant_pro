/* ============================================================
   Easy Builders Merchant Pro — system_health.js
   System Health Check & Auto-Fix module
   ============================================================ */

const SystemHealth = (() => {

  /* ── Run Health Check ─────────────────────────────────────── */
  async function runCheck() {
    const btn    = document.getElementById('healthCheckBtn');
    const result = document.getElementById('healthCheckResult');
    if (!btn || !result) return;

    btn.disabled  = true;
    btn.textContent = '⏳ Checking…';
    result.innerHTML = '<p class="text-muted">Running health check…</p>';

    try {
      const res = await fetch('/ebmpro_api/system_health.php', {
        headers: { 'Authorization': 'Bearer ' + Auth.getToken() }
      });
      const data = await res.json();
      if (!data.success) {
        result.innerHTML = `<p class="text-danger">❌ ${data.error || 'Check failed'}</p>`;
        return;
      }
      result.innerHTML = renderHealth(data.health);
    } catch (e) {
      result.innerHTML = `<p class="text-danger">❌ Network error: ${e.message}</p>`;
    } finally {
      btn.disabled    = false;
      btn.textContent = '🔍 Run Health Check';
    }
  }

  /* ── Run Auto-Fix ─────────────────────────────────────────── */
  async function runFix() {
    const btn    = document.getElementById('healthFixBtn');
    const result = document.getElementById('healthFixResult');
    if (!btn || !result) return;

    if (!confirm('This will create missing tables/columns and reset lockouts. Continue?')) return;

    btn.disabled    = true;
    btn.textContent = '⏳ Fixing…';
    result.innerHTML = '<p class="text-muted">Applying fixes…</p>';

    try {
      const res = await fetch('/ebmpro_api/system_fix.php', {
        method:  'POST',
        headers: { 'Authorization': 'Bearer ' + Auth.getToken() }
      });
      const data = await res.json();
      if (!data.success) {
        result.innerHTML = `<p class="text-danger">❌ ${data.error || 'Fix failed'}</p>`;
        return;
      }
      const items = (data.fixed || []).map(f => `<li>✅ ${f}</li>`).join('');
      result.innerHTML = `<ul style="list-style:none;padding:0;margin:0">${items}</ul>`;
    } catch (e) {
      result.innerHTML = `<p class="text-danger">❌ Network error: ${e.message}</p>`;
    } finally {
      btn.disabled    = false;
      btn.textContent = '🔧 Auto-Fix All Issues';
    }
  }

  /* ── Render health results ───────────────────────────────── */
  function renderHealth(h) {
    let html = '';

    // Tables
    html += renderSection('Database Tables', h.tables, (v) => v === 'ok');

    // Columns (grouped by table)
    if (h.columns) {
      let colHtml = '';
      let hasIssue = false;
      for (const [tbl, cols] of Object.entries(h.columns)) {
        const missing = Object.entries(cols).filter(([, v]) => v !== 'ok');
        if (missing.length) {
          hasIssue = true;
          colHtml += `<div style="margin-bottom:.5rem"><strong>${tbl}</strong>: `
            + missing.map(([col]) => `<span style="color:#e74c3c">${col}</span>`).join(', ')
            + '</div>';
        }
      }
      html += `<div style="margin-bottom:1rem;padding:.75rem;background:rgba(255,255,255,.04);border-radius:6px">
        <h4 style="margin:0 0 .5rem">Database Columns ${hasIssue ? '❌' : '✅'}</h4>
        ${hasIssue ? colHtml : '<p style="color:#27ae60;margin:0">All columns present</p>'}
      </div>`;
    }

    // API files
    html += renderSection('API Files', h.api_files, (v) => v === 'ok');

    // JS files
    html += renderSection('JS Files', h.js_files, (v) => v === 'ok');

    // Config
    html += renderSection('PHP Config Constants', h.config, (v) => v === 'ok');

    // Extensions
    html += renderSection('PHP Extensions', h.extensions, (v) => v === 'ok');

    // PHP settings (info only)
    if (h.php_settings) {
      const rows = Object.entries(h.php_settings)
        .map(([k, v]) => `<tr><td style="padding:.2rem .5rem">${k}</td><td style="padding:.2rem .5rem">${v}</td></tr>`)
        .join('');
      html += `<div style="margin-bottom:1rem;padding:.75rem;background:rgba(255,255,255,.04);border-radius:6px">
        <h4 style="margin:0 0 .5rem">PHP Settings ℹ️</h4>
        <table style="font-size:.85rem;border-collapse:collapse">${rows}</table>
      </div>`;
    }

    // Integrity
    html += renderSection('Data Integrity', h.integrity, (v) => v === 'ok');

    // Error log
    if (h.error_log) {
      const lines = h.error_log.map(l => `<div style="font-family:monospace;font-size:.75rem;word-break:break-all">${escHtml(l)}</div>`).join('');
      html += `<div style="margin-bottom:1rem;padding:.75rem;background:rgba(255,255,255,.04);border-radius:6px">
        <h4 style="margin:0 0 .5rem">Error Log (last 20 lines)</h4>
        <div style="max-height:200px;overflow-y:auto;background:#111;color:#eee;padding:.5rem;border-radius:4px">${lines || '(empty)'}</div>
      </div>`;
    }

    return html;
  }

  /* ── renderSection helper ────────────────────────────────── */
  function renderSection(title, obj, isOk) {
    if (!obj || typeof obj !== 'object') return '';
    const entries = Object.entries(obj);
    const issues  = entries.filter(([, v]) => !isOk(v));
    const icon    = issues.length ? '❌' : '✅';
    let inner = '';
    if (issues.length) {
      inner = issues.map(([k, v]) => `<span style="color:#e74c3c;margin-right:.75rem">✗ ${k} (${v})</span>`).join('');
    } else {
      inner = `<span style="color:#27ae60">All ${entries.length} OK</span>`;
    }
    return `<div style="margin-bottom:1rem;padding:.75rem;background:rgba(255,255,255,.04);border-radius:6px">
      <h4 style="margin:0 0 .5rem">${title} ${icon}</h4>
      <div style="font-size:.85rem;line-height:1.8">${inner}</div>
    </div>`;
  }

  /* ── HTML escape ─────────────────────────────────────────── */
  function escHtml(str) {
    return String(str)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;');
  }

  return { runCheck, runFix };
})();

