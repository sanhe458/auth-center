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
