// Auth Center 主题管理：light / dark / auto（跟随系统），状态存 Cookie
(function () {
  'use strict';

  var KEY = 'auth_theme';
  var DAYS = 365;

  function getCookie(name) {
    var m = document.cookie.match(new RegExp('(?:^|; )' + name + '=([^;]*)'));
    return m ? decodeURIComponent(m[1]) : null;
  }

  function setCookie(name, value, days) {
    var d = new Date();
    d.setTime(d.getTime() + days * 864e5);
    document.cookie = name + '=' + encodeURIComponent(value) +
      '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
  }

  function systemPrefersDark() {
    return window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches;
  }

  function getTheme() {
    var t = getCookie(KEY);
    return t === 'light' || t === 'dark' ? t : 'auto';
  }

  function effectiveDark() {
    var t = getTheme();
    return t === 'dark' || (t === 'auto' && systemPrefersDark());
  }

  function applyTheme(theme) {
    var html = document.documentElement;
    html.classList.remove('mdui-theme-light', 'mdui-theme-dark', 'mdui-theme-auto');
    html.classList.add('mdui-theme-' + theme);
    // 同步按钮图标：当前是暗色就显示"切到浅色"的月亮图标反之亦然
    var icon = document.getElementById('theme-icon');
    if (icon) {
      icon.setAttribute('name', effectiveDark() ? 'light_mode--outlined' : 'dark_mode--outlined');
    }
  }

  window.toggleTheme = function () {
    var next = effectiveDark() ? 'light' : 'dark';
    setCookie(KEY, next, DAYS);
    applyTheme(next);
  };

  // 跟随系统变化（仅 auto 模式）
  if (window.matchMedia) {
    window.matchMedia('(prefers-color-scheme: dark)').addEventListener('change', function () {
      if (getTheme() === 'auto') applyTheme('auto');
    });
  }

  // 页面加载时尽早应用，避免闪烁
  applyTheme(getTheme());
})();

// 通用复制：优先 Clipboard API（现代浏览器），失败/不可用时回退 textarea + execCommand（WebView 兼容）
function copyText(btn, text) {
  var done = function () {
    if (btn) {
      var old = btn.textContent;
      btn.textContent = '已复制';
      setTimeout(function () { btn.textContent = old; }, 1500);
    }
  };

  if (navigator.clipboard && window.isSecureContext) {
    navigator.clipboard.writeText(text).then(done).catch(function () { fallbackCopy(text, done); });
  } else {
    fallbackCopy(text, done);
  }
}

function fallbackCopy(text, done) {
  var ta = document.createElement('textarea');
  ta.value = text;
  ta.setAttribute('readonly', '');
  ta.style.position = 'fixed';
  ta.style.top = '-9999px';
  ta.style.opacity = '0';
  document.body.appendChild(ta);
  var ok = false;
  try {
    ta.focus();
    ta.select();
    ta.setSelectionRange(0, text.length);
    ok = document.execCommand('copy');
  } catch (e) { ok = false; }
  document.body.removeChild(ta);
  if (ok) { done(); } else if (window.mdui && mdui.snackbar) {
    mdui.snackbar('复制失败，请长按手动复制');
  }
}
