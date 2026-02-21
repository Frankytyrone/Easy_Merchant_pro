/* ============================================================
   Easy Builders Merchant Pro — auth.js
   Authentication module
   ============================================================ */

const Auth = (() => {
  const TOKEN_KEY = 'ebmpro_token';
  const USER_KEY  = 'ebmpro_user';

  /* ── getToken ─────────────────────────────────────────────── */
  function getToken() {
    return localStorage.getItem(TOKEN_KEY) || null;
  }

  /* ── getUser ──────────────────────────────────────────────── */
  function getUser() {
    try {
      const raw = localStorage.getItem(USER_KEY);
      return raw ? JSON.parse(raw) : null;
    } catch {
      return null;
    }
  }

  /* ── isLoggedIn ───────────────────────────────────────────── */
  function isLoggedIn() {
    return !!getToken();
  }

  /* ── getAuthHeaders ───────────────────────────────────────── */
  function getAuthHeaders() {
    const token = getToken();
    return {
      'Authorization':  token ? `Bearer ${token}` : '',
      'Content-Type':   'application/json'
    };
  }

  /* ── login ────────────────────────────────────────────────── */
  async function login(username, password) {
    const resp = await fetch('/ebmpro_api/auth.php', {
      method:  'POST',
      headers: { 'Content-Type': 'application/json' },
      body:    JSON.stringify({ username, password })
    });

    const data = await resp.json();

    if (!resp.ok || !data.token) {
      throw new Error(data.message || data.error || 'Login failed');
    }

    localStorage.setItem(TOKEN_KEY, data.token);
    localStorage.setItem(USER_KEY,  JSON.stringify(data.user));
    return data.user;
  }

  /* ── logout ───────────────────────────────────────────────── */
  async function logout() {
    const token = getToken();
    if (token) {
      try {
        await fetch('/ebmpro_api/auth.php', {
          method:  'DELETE',
          headers: getAuthHeaders()
        });
      } catch { /* ignore network errors on logout */ }
    }
    localStorage.removeItem(TOKEN_KEY);
    localStorage.removeItem(USER_KEY);
  }

  /* ── checkAuth ─────────────────────────────────────────────── */
  async function checkAuth() {
    const token = getToken();
    if (!token) return null;

    try {
      const resp = await fetch('/ebmpro_api/auth.php', {
        method:  'GET',
        headers: getAuthHeaders()
      });
      if (!resp.ok) {
        localStorage.removeItem(TOKEN_KEY);
        localStorage.removeItem(USER_KEY);
        return null;
      }
      const data = await resp.json();
      // Refresh stored user info
      if (data.user) {
        localStorage.setItem(USER_KEY, JSON.stringify(data.user));
      }
      return data.user || getUser();
    } catch {
      // Network error — if we have a stored user, optimistically return it
      return getUser();
    }
  }

  return {
    login,
    logout,
    getToken,
    getUser,
    isLoggedIn,
    checkAuth,
    getAuthHeaders
  };
})();