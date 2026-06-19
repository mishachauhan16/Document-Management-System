// =============================================
// navbar.js — shared DMS header for all pages
// Inject this on every page: <script src="assets/navbar.js"></script>
// =============================================

function injectNavbar() {
  const user = Auth.user();
  const isAdmin = user && user.role === 'admin';

  const navbar = document.createElement('nav');
  navbar.id = 'dms-navbar';
  navbar.innerHTML = `
    <style>
      #dms-navbar {
        background: #1e293b;
        border-bottom: 1px solid #334155;
        padding: 0 28px;
        height: 58px;
        display: flex;
        align-items: center;
        justify-content: space-between;
        position: sticky;
        top: 0;
        z-index: 1000;
        font-family: 'Inter', sans-serif;
      }
      #dms-navbar .nav-logo {
        display: flex;
        align-items: center;
        gap: 10px;
        text-decoration: none;
        cursor: pointer;
      }
      #dms-navbar .nav-logo .logo-icon {
        width: 34px;
        height: 34px;
        background: linear-gradient(135deg, #2563eb, #1d4ed8);
        border-radius: 8px;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 16px;
      }
      #dms-navbar .nav-logo span {
        font-size: 16px;
        font-weight: 700;
        color: #f1f5f9;
        letter-spacing: -0.3px;
      }
      #dms-navbar .nav-logo small {
        font-size: 11px;
        color: #64748b;
        display: block;
        line-height: 1;
        margin-top: 1px;
      }
      #dms-navbar .nav-right {
        display: flex;
        align-items: center;
        gap: 10px;
      }
      #dms-navbar .nav-link {
        background: none;
        border: none;
        color: #94a3b8;
        font-size: 13px;
        font-family: 'Inter', sans-serif;
        cursor: pointer;
        padding: 6px 12px;
        border-radius: 6px;
        transition: all 0.15s;
        text-decoration: none;
      }
      #dms-navbar .nav-link:hover {
        background: #334155;
        color: #e2e8f0;
      }
      #dms-navbar .nav-link.active {
        color: #60a5fa;
      }
      #dms-navbar .nav-divider {
        width: 1px;
        height: 20px;
        background: #334155;
      }
      #dms-navbar .user-chip {
        display: flex;
        align-items: center;
        gap: 8px;
        background: #334155;
        border-radius: 20px;
        padding: 5px 14px 5px 6px;
      }
      #dms-navbar .avatar {
        width: 26px;
        height: 26px;
        background: linear-gradient(135deg, #7c3aed, #4f46e5);
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
        font-size: 11px;
        font-weight: 700;
        color: #fff;
      }
      #dms-navbar .user-chip span {
        font-size: 13px;
        color: #e2e8f0;
        font-weight: 500;
      }
      #dms-navbar .btn-logout {
        background: none;
        border: 1px solid #334155;
        color: #94a3b8;
        padding: 6px 14px;
        border-radius: 7px;
        font-size: 13px;
        cursor: pointer;
        font-family: 'Inter', sans-serif;
        transition: all 0.2s;
      }
      #dms-navbar .btn-logout:hover {
        background: #7f1d1d;
        border-color: #7f1d1d;
        color: #fca5a5;
      }
    </style>

    <a class="nav-logo" onclick="goHome()">
      <div class="logo-icon">📁</div>
      <div>
        <span>DMS</span>
        <small>Document Management</small>
      </div>
    </a>

    <div class="nav-right">
      <button class="nav-link ${isCurrentPage('dashboard') ? 'active' : ''}"
        onclick="window.location.href='dashboard.html'">
        📂 Documents
      </button>
      ${isAdmin ? `
      <button class="nav-link ${isCurrentPage('admin') ? 'active' : ''}"
        onclick="window.location.href='admin.html'">
        ⚙️ Admin
      </button>` : ''}
      <button class="nav-link ${isCurrentPage('change-password') ? 'active' : ''}"
        onclick="window.location.href='change-password.html'">
        🔐 Password
      </button>
      <div class="nav-divider"></div>
      <div class="user-chip">
        <div class="avatar">${user ? user.name[0].toUpperCase() : 'U'}</div>
        <span>${user ? user.name : 'User'}</span>
      </div>
      <button class="btn-logout" onclick="logout()">Sign Out</button>
    </div>
  `;

  document.body.insertBefore(navbar, document.body.firstChild);
}

function goHome() {
  const user = Auth.user();
  if (!user) { window.location.href = 'login.html'; return; }
  window.location.href = user.role === 'admin' ? 'admin.html' : 'dashboard.html';
}

function isCurrentPage(name) {
  return window.location.pathname.includes(name);
}

// Auto-inject when DOM is ready
document.addEventListener('DOMContentLoaded', function () {
  if (Auth.isLoggedIn()) injectNavbar();
});