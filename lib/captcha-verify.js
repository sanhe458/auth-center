/* AJ-Captcha 滑动拼图组件 v2 — 极验风格弹出式（pop）
 * Auth Center 登录/注册
 * 依赖：CryptoJS（lib/crypto-js.js）
 * 用法：
 *   initCaptchaSlider({
 *     wrap: '#captchaSlider',   // 挂载容器
 *     form: '#login-form',      // 表单（组件会写 hidden: captcha_token / captcha_pointJson）
 *     onPass: function(){},     // 验证通过回调
 *     triggerText: '完成验证',  // 触发条文字
 *   });
 * 交互：触发条 → 点击弹出模态框（打开即预加载出题）→ 滑过后自动收起、触发条变绿打勾。
 * 安全：提交时后端必须从 hidden 读 captcha_token/captcha_pointJson 并做服务端强校验。
 */
(function (global) {
  if (typeof CryptoJS === 'undefined') { console.error('captcha-verify: 缺少 CryptoJS'); return; }

  var API = '/api/captcha/get';
  var CHECK = '/api/captcha/check';

  function aesEncrypt(word, keyWord) {
    var key = CryptoJS.enc.Utf8.parse(keyWord);
    var srcs = CryptoJS.enc.Utf8.parse(word);
    return CryptoJS.AES.encrypt(srcs, key, { mode: CryptoJS.mode.ECB, padding: CryptoJS.pad.Pkcs7 }).toString();
  }
  function esc(s) { return String(s == null ? '' : s).replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/>/g, '&gt;').replace(/"/g, '&quot;'); }

  function initCaptchaSlider(opts) {
    opts = opts || {};
    var wrap = typeof opts.wrap === 'string' ? document.querySelector(opts.wrap) : opts.wrap;
    if (!wrap) return;
    var form = opts.form ? (typeof opts.form === 'string' ? document.querySelector(opts.form) : opts.form) : null;
    var triggerText = esc(opts.triggerText || '完成验证');

    wrap.innerHTML =
      '<div class="cap-geetest">' +
      '  <div class="cap-trigger" role="button" tabindex="0">' +
      '    <span class="cap-trigger-icon">&#x21BB;</span>' +
      '    <span class="cap-trigger-text">' + triggerText + '</span>' +
      '    <span class="cap-trigger-arrow">&#x203A;</span>' +
      '  </div>' +
      '  <div class="cap-modal">' +
      '    <div class="cap-modal-mask"></div>' +
      '    <div class="cap-modal-panel">' +
      '      <div class="cap-modal-head"><span class="cap-modal-title">请完成安全验证</span><span class="cap-modal-close">&#x2715;</span></div>' +
      '      <div class="cap-slider">' +
      '        <div class="captcha-imgbox">' +
      '          <img class="back" alt="">' +
      '          <div class="captcha-mask"></div>' +
      '          <div class="captcha-loading"></div>' +
      '          <img class="captcha-jigsaw" alt="">' +
      '          <div class="captcha-msg"></div>' +
      '        </div>' +
      '        <div class="captcha-bar">' +
      '          <div class="captcha-bar-track"></div>' +
      '          <div class="captcha-bar-text">按住滑块，拖动完成拼图</div>' +
      '          <div class="captcha-bar-btn">&#x25B6;</div>' +
      '        </div>' +
      '        <div class="cap-slider-foot"><span class="cap-refresh">&#x21BB; 换一张</span></div>' +
      '      </div>' +
      '    </div>' +
      '  </div>' +
      '</div>';

    var root = wrap.querySelector('.cap-geetest');
    var trigger = root.querySelector('.cap-trigger');
    var triggerIcon = root.querySelector('.cap-trigger-icon');
    var triggerTextEl = root.querySelector('.cap-trigger-text');
    var modal = root.querySelector('.cap-modal');
    var mask = root.querySelector('.cap-modal-mask');
    var closeBtn = root.querySelector('.cap-modal-close');
    var refreshBtn = root.querySelector('.cap-refresh');
    var box = root.querySelector('.captcha-imgbox');
    var back = root.querySelector('.back');
    var jigsaw = root.querySelector('.captcha-jigsaw');
    var dim = root.querySelector('.captcha-mask');
    var loadingEl = root.querySelector('.captcha-loading');
    var msg = root.querySelector('.captcha-msg');
    var bar = root.querySelector('.captcha-bar');
    var track = root.querySelector('.captcha-bar-track');
    var barText = root.querySelector('.captcha-bar-text');
    var barBtn = root.querySelector('.captcha-bar-btn');

    var DISP_W = 310, DISP_H = 155;
    var state = { token: '', secretKey: '', pointJson: '', passed: false };
    var startX = 0, curX = 0, dragging = false, blockW = 0, blockH = 0, loading = false;

    function writeField(name, val) {
      if (!form) return;
      var f = form.querySelector('input[name="' + name + '"]');
      if (!f) { f = document.createElement('input'); f.type = 'hidden'; f.name = name; form.appendChild(f); }
      f.value = val;
    }
    function setMsg(txt, ok) {
      msg.textContent = txt;
      msg.className = 'captcha-msg show' + (ok ? ' ok' : ' err');
      setTimeout(function () { msg.className = 'captcha-msg'; }, ok ? 1400 : 800);
    }
    function setState(token, secretKey) {
      state.passed = false; state.token = token; state.secretKey = secretKey; state.pointJson = '';
      writeField('captcha_token', ''); writeField('captcha_pointJson', '');
      barText.textContent = '按住滑块，拖动完成拼图';
      track.style.width = '0px'; barBtn.style.left = '0px'; barBtn.classList.remove('disabled');
    }
    /** 图片加载状态：未加载完全时白色半透明遮罩 + 滑动条/换一张禁用 */
    function setLoading(b) {
      loading = b;
      loadingEl.classList.toggle('off', !b);
      bar.classList.toggle('disabled', b);
      barBtn.classList.toggle('disabled', b);
      refreshBtn.classList.toggle('disabled', b);
    }
    function load() {
      setLoading(true); barText.textContent = '加载中…';
      fetch(API).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || d.repCode !== '0000') { setLoading(false); barText.textContent = '加载失败，点击重试'; return; }
        var rd = d.repData;
        var ts = Date.now();
        // 图片走真实 URL（带时间戳防缓存），规避 data URL 大图在部分内核被截断的问题
        back.src = rd.originalImage + (rd.originalImage.indexOf('?') >= 0 ? '&' : '?') + '_=' + ts;
        jigsaw.src = rd.jigsawImage + (rd.jigsawImage.indexOf('?') >= 0 ? '&' : '?') + '_=' + ts;
        setState(rd.token, rd.secretKey);
        // 保持 loading：等图片真正加载完成（back onload）后再解锁
      }).catch(function () { setLoading(false); barText.textContent = '网络错误，点击重试'; });
    }
    // 主图加载完成才撒遮罩解锁；加载失败解除禁用（允许手动换一张）
    back.addEventListener('load', function () { if (!state.passed) setLoading(false); });
    back.addEventListener('error', function () { if (!state.passed) { setMsg('图片加载失败，请换一张', false); setLoading(false); } });
    jigsaw.addEventListener('error', function () { /* 单片失败不影响主流程 */ });
    jigsaw.addEventListener('load', function () {
      blockW = jigsaw.naturalWidth; blockH = jigsaw.naturalHeight;
      jigsaw.style.width = blockW + 'px'; jigsaw.style.height = blockH + 'px';
      jigsaw.style.display = 'block'; jigsaw.style.left = '0px'; jigsaw.style.top = '0px';
    });

    function submit(pos) {
      if (state.passed) return;
      try {
        state.pointJson = aesEncrypt(JSON.stringify({ x: pos, y: 5.0 }), state.secretKey);
        var body = new URLSearchParams();
        body.set('token', state.token); body.set('pointJson', state.pointJson);
        fetch(CHECK, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.success) {
              state.passed = true;
              writeField('captcha_token', state.token); writeField('captcha_pointJson', state.pointJson);
              track.style.width = '100%'; barBtn.style.left = (DISP_W - 42) + 'px';
              barText.textContent = '&#10003; 验证成功';
              setMsg('验证通过', true);
              barBtn.classList.add('disabled');
              closeModal();
              triggerSuccess();
              if (opts.onPass) opts.onPass();
            } else {
              setMsg('验证失败，请重试', false); setTimeout(load, 800);
            }
          }).catch(function () { setMsg('校验失败，请重试', false); setTimeout(load, 800); });
      } catch (e) { setMsg('加密失败，请重试', false); setTimeout(load, 800); }
    }

    function triggerSuccess() {
      trigger.classList.add('passed');
      triggerIcon.innerHTML = '&#10003;';
      triggerTextEl.textContent = '验证通过';
      trigger.querySelector('.cap-trigger-arrow').style.display = 'none';
    }
    function openModal() {
      modal.style.display = 'flex';
      // 已通过则无需再出题；否则每次打开都预加载新题
      if (!state.passed) load();
    }
    function closeModal() { modal.style.display = 'none'; }

    var startDrag = function (e) {
      if (state.passed || loading || !state.token) return;
      e.preventDefault();
      dragging = true;
      startX = (e.clientX !== undefined ? e.clientX : (e.touches && e.touches[0].clientX));
      dim.classList.add('on');
      jigsaw.style.display = 'block'; jigsaw.style.left = (curX || 0) + 'px';
    };
    var moveLeft = function (e) {
      if (!dragging || state.passed) return;
      var clientX = e.clientX !== undefined ? e.clientX : (e.touches && e.touches[0].clientX);
      var dx = Math.max(0, Math.min(DISP_W - 42, clientX - startX));
      curX = dx;
      jigsaw.style.left = dx + 'px'; track.style.width = dx + 'px'; barBtn.style.left = dx + 'px';
    };
    var endDrag = function () {
      if (!dragging) return;
      dragging = false; dim.classList.remove('on');
      if (!state.passed) submit(curX);
    };

    barBtn.addEventListener('mousedown', startDrag);
    barBtn.addEventListener('touchstart', startDrag, { passive: false });
    document.addEventListener('mousemove', moveLeft);
    document.addEventListener('mouseup', endDrag);
    document.addEventListener('touchmove', moveLeft, { passive: false });
    document.addEventListener('touchend', endDrag);
    trigger.addEventListener('click', function () { if (!state.passed) openModal(); });
    mask.addEventListener('click', closeModal);
    closeBtn.addEventListener('click', closeModal);
    refreshBtn.addEventListener('click', load);
  }

  global.initCaptchaSlider = initCaptchaSlider;
})(window);
