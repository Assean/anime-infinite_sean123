/**
 * ANIME INFINITE — main.js
 * Global utilities: Auth state, Toast, Modal, i18n, Theme, API helpers
 */

// ── i18n ───────────────────────────────────────────────────────
const i18n = {
  'zh-TW': {
    'nav.home':     '首頁',
    'nav.news':     '最新資訊',
    'nav.patch':    '更新爆料',
    'nav.guide':    '攻略分享',
    'nav.store':    '儲值商城',
    'nav.login':    '登入',
    'nav.register': '免費加入',
  },
  'en-US': {
    'nav.home':     'Home',
    'nav.news':     'News',
    'nav.patch':    'Updates',
    'nav.guide':    'Guides',
    'nav.store':    'Store',
    'nav.login':    'Login',
    'nav.register': 'Join Free',
  }
};

let currentLang = localStorage.getItem('ai_lang') || 'zh-TW';

function applyLang() {
  document.documentElement.lang = currentLang === 'zh-TW' ? 'zh-TW' : 'en-US';
  const btn = document.getElementById('langBtn');
  if (btn) btn.textContent = currentLang === 'zh-TW' ? 'EN' : '中';
  document.querySelectorAll('[data-i18n]').forEach(el => {
    const key = el.getAttribute('data-i18n');
    if (i18n[currentLang]?.[key]) el.textContent = i18n[currentLang][key];
  });
}

function toggleLang() {
  currentLang = currentLang === 'zh-TW' ? 'en-US' : 'zh-TW';
  localStorage.setItem('ai_lang', currentLang);
  applyLang();
}

// ── Theme ──────────────────────────────────────────────────────
let currentTheme = localStorage.getItem('ai_theme') || 'dark';

function applyTheme() {
  document.documentElement.setAttribute('data-theme', currentTheme);
  const icon = document.getElementById('themeIcon');
  if (icon) icon.className = currentTheme === 'dark' ? 'fa-solid fa-sun' : 'fa-solid fa-moon';
}

function toggleTheme() {
  currentTheme = currentTheme === 'dark' ? 'light' : 'dark';
  localStorage.setItem('ai_theme', currentTheme);
  applyTheme();
}

// ── Auth State ─────────────────────────────────────────────────
const AI = window.AI = {
  TOKEN_KEY: 'ai_token',
  USER_KEY:  'ai_user',

  getToken() { return localStorage.getItem(this.TOKEN_KEY); },
  getUser()  {
    try { return JSON.parse(localStorage.getItem(this.USER_KEY)); }
    catch { return null; }
  },

  setSession(token, user) {
    localStorage.setItem(this.TOKEN_KEY, token);
    localStorage.setItem(this.USER_KEY, JSON.stringify(user));
  },

  clearSession() {
    localStorage.removeItem(this.TOKEN_KEY);
    localStorage.removeItem(this.USER_KEY);
  },

  isLoggedIn() { return !!this.getToken(); },

  isAdmin() {
    const u = this.getUser();
    return u && u.role_level >= 8;
  },

  isSuperAdmin() {
    const u = this.getUser();
    return u && u.role_level >= 12;
  }
};

// ── Toast ──────────────────────────────────────────────────────
const TOAST_ICONS = {
  success: '✅',
  error:   '❌',
  info:    'ℹ️',
  warning: '⚠️',
};

function showToast(type = 'info', title = '', msg = '', duration = 4000) {
  const container = document.getElementById('toastContainer');
  if (!container) return;

  const toast = document.createElement('div');
  toast.className = `toast ${type}`;
  toast.innerHTML = `
    <div class="toast-icon">${TOAST_ICONS[type] || 'ℹ️'}</div>
    <div>
      ${title ? `<div class="toast-title">${escHtml(title)}</div>` : ''}
      ${msg    ? `<div class="toast-msg">${escHtml(msg)}</div>` : ''}
    </div>
  `;
  container.appendChild(toast);

  setTimeout(() => {
    toast.classList.add('out');
    toast.addEventListener('animationend', () => toast.remove(), { once: true });
  }, duration);
}

// ── Modal ──────────────────────────────────────────────────────
function openModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.add('open');
  document.body.style.overflow = 'hidden';
}

function closeModal(id) {
  const el = document.getElementById(id);
  if (el) el.classList.remove('open');
  document.body.style.overflow = '';
}

function closeOnOverlay(e, id) {
  if (e.target === document.getElementById(id)) closeModal(id);
}

// ── API Helper ─────────────────────────────────────────────────
async function apiFetch(endpoint, options = {}) {
  const token = AI.getToken();
  const headers = {
    'Content-Type': 'application/json',
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(options.headers || {}),
  };

  try {
    const res = await fetch(`api/${endpoint}`, { ...options, headers });
    const data = await res.json();
    if (data.error === 'UNAUTHORIZED') {
      AI.clearSession();
      window.location.href = 'auth.html?expired=1';
    }
    return data;
  } catch (err) {
    console.error('[API]', endpoint, err);
    return { success: false, message: '網路連線錯誤，請稍後再試' };
  }
}

// ── Mobile Nav ─────────────────────────────────────────────────
function toggleMobileNav() {
  const nav  = document.getElementById('navMenu');
  const side = document.getElementById('dashboardSidebar') || document.getElementById('adminSidebar');
  if (nav) nav.style.display = nav.style.display === 'flex' ? '' : 'flex';
  if (side) side.classList.toggle('open');
}

// ── Logout ────────────────────────────────────────────────────
function logout() {
  apiFetch('auth.php?action=logout', { method: 'POST' }).finally(() => {
    AI.clearSession();
    showToast('info', '已登出', '期待下次見到你！');
    setTimeout(() => window.location.href = 'index.html', 1000);
  });
}

// ── Escape HTML ───────────────────────────────────────────────
function escHtml(str) {
  return String(str)
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');
}

// ── Clipboard ─────────────────────────────────────────────────
function copyToClipboard(text, label = '已複製') {
  navigator.clipboard?.writeText(text).then(() => {
    showToast('success', label);
  }).catch(() => {
    showToast('error', '複製失敗', '請手動複製');
  });
}

// ── Format date ───────────────────────────────────────────────
function formatDate(dateStr) {
  const d = new Date(dateStr);
  return d.toLocaleString('zh-TW', {
    year: 'numeric', month: '2-digit', day: '2-digit',
    hour: '2-digit', minute: '2-digit'
  });
}

// ── Time ago ─────────────────────────────────────────────────
function timeAgo(dateStr) {
  const diff = Date.now() - new Date(dateStr).getTime();
  const mins = Math.floor(diff / 60000);
  if (mins < 1)    return '剛剛';
  if (mins < 60)   return `${mins} 分鐘前`;
  const hrs = Math.floor(mins / 60);
  if (hrs  < 24)   return `${hrs} 小時前`;
  const days = Math.floor(hrs / 24);
  if (days < 7)    return `${days} 天前`;
  return formatDate(dateStr);
}

// ── Number format ────────────────────────────────────────────
function fmtNum(n) {
  if (n >= 1e6) return (n / 1e6).toFixed(1) + 'M';
  if (n >= 1e3) return (n / 1e3).toFixed(1) + 'K';
  return String(n);
}

// ── Countdown ────────────────────────────────────────────────
function startCountdown(endDate, elId) {
  const el = document.getElementById(elId);
  if (!el) return;
  const end = new Date(endDate).getTime();

  const tick = () => {
    const diff = end - Date.now();
    if (diff <= 0) { el.textContent = '已結束'; return; }
    const d = Math.floor(diff / 86400000);
    const h = Math.floor((diff % 86400000) / 3600000);
    const m = Math.floor((diff % 3600000)  / 60000);
    const s = Math.floor((diff % 60000)    / 1000);
    el.textContent = `${d}天 ${String(h).padStart(2,'0')}:${String(m).padStart(2,'0')}:${String(s).padStart(2,'0')}`;
    setTimeout(tick, 1000);
  };
  tick();
}

// ── Progress ring helper ──────────────────────────────────────
function setProgressRing(svgEl, pct, color) {
  const r    = 22;
  const circ = 2 * Math.PI * r;
  const fill = svgEl.querySelector('.progress-ring-fill');
  if (!fill) return;
  fill.style.setProperty('--circumference', circ);
  fill.style.setProperty('--offset', circ - (pct / 100) * circ);
  fill.setAttribute('stroke', color || 'var(--accent-cyan)');
  fill.setAttribute('stroke-dasharray', circ);
  fill.setAttribute('stroke-dashoffset', circ - (pct / 100) * circ);
}

function buildProgressRing(pct, color, icon, label) {
  const r = 22, circ = 2 * Math.PI * r;
  const offset = circ - (pct / 100) * circ;
  return `
    <div class="verify-item" title="${label}">
      <div class="verify-ring-wrap">
        <svg width="52" height="52" viewBox="0 0 52 52" class="progress-ring">
          <circle class="progress-ring-track" cx="26" cy="26" r="${r}" stroke-width="3"/>
          <circle class="progress-ring-fill" cx="26" cy="26" r="${r}" stroke-width="3"
            stroke="${color}" stroke-dasharray="${circ}" stroke-dashoffset="${offset}"
            fill="none" stroke-linecap="round"
            style="transform-origin:center;transform:rotate(-90deg)"/>
        </svg>
        <div class="verify-ring-icon">${icon}</div>
      </div>
      <div class="verify-label ${pct === 100 ? 'done' : ''}">${label}</div>
    </div>
  `;
}

// ── Skeleton to real ──────────────────────────────────────────
function replaceSkeleton(containerId, htmlContent, delay = 600) {
  setTimeout(() => {
    const el = document.getElementById(containerId);
    if (el) {
      el.style.opacity = '0';
      el.innerHTML = htmlContent;
      el.style.transition = 'opacity 0.4s';
      requestAnimationFrame(() => { el.style.opacity = '1'; });
    }
  }, delay);
}

// ── Tab system ───────────────────────────────────────────────
function initTabs(tabListId) {
  const list = document.getElementById(tabListId);
  if (!list) return;

  list.querySelectorAll('.tab-btn').forEach(btn => {
    btn.addEventListener('click', () => {
      const target = btn.getAttribute('data-tab');
      list.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
      btn.classList.add('active');
      const section = btn.closest('.tab-section') || document;
      section.querySelectorAll('.tab-pane').forEach(p => p.classList.remove('active'));
      const pane = document.getElementById(target);
      if (pane) pane.classList.add('active');
    });
  });
}

// ── Init ─────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', () => {
  applyLang();
  applyTheme();
  initTabs('mainTabList');

  // Auto-init progress rings
  document.querySelectorAll('[data-progress]').forEach(el => {
    const pct   = parseInt(el.getAttribute('data-progress')) || 0;
    const color = el.getAttribute('data-color') || 'var(--accent-cyan)';
    setProgressRing(el, pct, color);
  });

  // Check expired session
  const params = new URLSearchParams(window.location.search);
  if (params.get('expired') === '1') {
    showToast('warning', '登入已逾期', '請重新登入');
  }
});
