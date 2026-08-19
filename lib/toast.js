/* Auth Center Toast 通知组件
 * 无依赖，兼容 WebView / 现代浏览器
 * 用法：
 *   toast.success('标题', '描述', 3000)
 *   toast.error('标题', '描述')
 *   toast.warning('标题', '描述')
 *   toast.info('标题', '描述')
 *   var t = toast.loading('处理中...'); t.done('完成', '描述');  // 更新为成功
 *   t.fail('失败', '描述');
 */
(function () {
  'use strict';

  var ICONS = {
    success: '✓',
    error: '✕',
    warning: '!',
    info: 'i',
    loading: '◌'
  };

  function getContainer() {
    var c = document.getElementById('toast-container');
    if (!c) {
      c = document.createElement('div');
      c.id = 'toast-container';
      document.body.appendChild(c);
    }
    return c;
  }

  /**
   * @param {string} type success|error|warning|info|loading
   * @param {string} title
   * @param {string} [desc]
   * @param {number} [duration] 毫秒，默认 3200，0=不自动关
   * @returns {{done:Function, fail:Function, close:Function, el:HTMLElement}}
   */
  function show(type, title, desc, duration) {
    var el = document.createElement('div');
    el.className = 'toast-item toast-' + type;

    var icon = document.createElement('div');
    icon.className = 'toast-icon';
    icon.textContent = ICONS[type] || 'i';

    var body = document.createElement('div');
    body.className = 'toast-body';
    var tEl = document.createElement('div');
    tEl.className = 'toast-title';
    tEl.textContent = title;
    body.appendChild(tEl);
    if (desc) {
      var dEl = document.createElement('div');
      dEl.className = 'toast-desc';
      if (desc.indexOf('<') !== -1) { dEl.innerHTML = desc; } else { dEl.textContent = desc; }
      body.appendChild(dEl);
    }

    var close = document.createElement('button');
    close.className = 'toast-close';
    close.textContent = '✕';
    close.setAttribute('aria-label', '关闭');

    el.appendChild(icon);
    el.appendChild(body);
    el.appendChild(close);

    var container = getContainer();
    container.appendChild(el);

    // 入场
    requestAnimationFrame(function () {
      requestAnimationFrame(function () { el.classList.add('toast-in'); });
    });

    // 限宽：标题/描述过长截断
    var maxW = Math.min(360, (window.innerWidth || 375) - 32);
    if (el.scrollWidth > maxW) el.style.maxWidth = maxW + 'px';

    var timer = null;
    var timerBar = null;
    var closed = false;

    function clearTimer() {
      if (timer) { clearTimeout(timer); timer = null; }
      if (timerBar) { timerBar.remove(); timerBar = null; }
    }

    function closeNow() {
      if (closed) return;
      closed = true;
      clearTimer();
      el.classList.remove('toast-in');
      el.classList.add('toast-out');
      setTimeout(function () { el.remove(); }, 300);
    }

    function arm(dur) {
      clearTimer();
      if (!dur) return;
      // 进度条
      timerBar = document.createElement('div');
      timerBar.className = 'toast-timer';
      timerBar.style.animationDuration = dur + 'ms';
      el.appendChild(timerBar);
      timer = setTimeout(closeNow, dur);
    }

    arm(typeof duration === 'number' ? duration : 3200);

    close.addEventListener('click', function (e) { e.stopPropagation(); closeNow(); });
    el.addEventListener('click', closeNow); // 点整条也能关（可自行改造成点击跳转）

    return {
      el: el,
      close: closeNow,
      done: function (t2, d2, dur2) {
        setType(el, 'success');
        icon.textContent = ICONS.success;
        tEl.textContent = t2 || title;
        if (d2 !== undefined && dEl) { dEl.innerHTML = d2; }
        arm(typeof dur2 === 'number' ? dur2 : 2500);
      },
      fail: function (t2, d2, dur2) {
        setType(el, 'error');
        icon.textContent = ICONS.error;
        tEl.textContent = t2 || title;
        if (d2 !== undefined && dEl) { dEl.innerHTML = d2; }
        arm(typeof dur2 === 'number' ? dur2 : 4500);
      }
    };
  }

  // 切换类型时保留 toast-in 等状态类，避免元素隐形
  function setType(el, type) {
    el.classList.remove('toast-success', 'toast-error', 'toast-warning', 'toast-info', 'toast-loading');
    el.classList.add('toast-' + type);
  }

  window.toast = {
    success: function (t, d, dur) { return show('success', t, d, dur); },
    error: function (t, d, dur) { return show('error', t, d, dur); },
    warning: function (t, d, dur) { return show('warning', t, d, dur); },
    info: function (t, d, dur) { return show('info', t, d, dur); },
    loading: function (t, d) { return show('loading', t, d, 0); }
  };
})();
