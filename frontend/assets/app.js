// =======================================================
// frontend/assets/app.js — shared across all pages
// =======================================================

const API = '/dms/api';

// ---- Token storage ----
const Auth = {
    save(token, user) {
        localStorage.setItem('dms_token', token);
        localStorage.setItem('dms_user', JSON.stringify(user));
    },
    token()  { return localStorage.getItem('dms_token'); },
    user()   { try { return JSON.parse(localStorage.getItem('dms_user')); } catch { return null; } },
    clear()  { localStorage.removeItem('dms_token'); localStorage.removeItem('dms_user'); },
    isExpired(token) {
        try {
            const p = JSON.parse(atob(token.split('.')[1].replace(/-/g,'+').replace(/_/g,'/')));
            return p.exp < Math.floor(Date.now()/1000);
        } catch { return true; }
    },
    isLoggedIn() {
        const t = this.token();
        return t && !this.isExpired(t);
    }
};

// ---- Guard: call at top of every protected page ----
function requireLogin(adminOnly = false) {
    if (!Auth.isLoggedIn()) {
        window.location.href = '/dms/frontend/login.html';
        return null;
    }
    const user = Auth.user();
    // No forced redirect — user chooses when to change password
    if (adminOnly && user.role !== 'admin') {
        window.location.href = '/dms/frontend/dashboard.html';
        return null;
    }
    return user;
}

// ---- API wrapper (auto-attaches JWT) ----
async function api(endpoint, method = 'GET', body = null) {
    const headers = { 'Content-Type': 'application/json' };
    if (Auth.token()) headers['Authorization'] = `Bearer ${Auth.token()}`;
    const opts = { method, headers };
    if (body) opts.body = JSON.stringify(body);
    const res  = await fetch(API + endpoint, opts);
    const data = await res.json();
    if (res.status === 401) { Auth.clear(); window.location.href = '/dms/frontend/login.html'; }
    return { ok: res.ok, status: res.status, ...data };
}

// ---- Logout ----
async function logout() {
    try { await api('/auth/logout', 'POST'); } catch {}
    Auth.clear();
    window.location.href = '/dms/frontend/login.html';
}

// ---- Toast notification ----
function toast(msg, type = 'success') {
    let container = document.getElementById('toast-container');
    if (!container) {
        container = document.createElement('div');
        container.id = 'toast-container';
        container.style.cssText = 'position:fixed;top:20px;right:20px;z-index:9999;display:flex;flex-direction:column;gap:8px;';
        document.body.appendChild(container);
    }
    const el = document.createElement('div');
    const colors = { success:'#1E8449', error:'#922B21', info:'#1F4E79', warning:'#D35400' };
    el.style.cssText = `background:${colors[type]||colors.info};color:#fff;padding:12px 20px;border-radius:8px;
        font-size:14px;font-family:Inter,sans-serif;box-shadow:0 4px 12px rgba(0,0,0,.2);
        min-width:240px;animation:slideIn .2s ease;`;
    el.textContent = msg;
    container.appendChild(el);
    setTimeout(() => el.remove(), 3500);
}
