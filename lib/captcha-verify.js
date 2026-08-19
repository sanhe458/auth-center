/* AJ-Captcha 滑动拼图组件（Auth Center 登录/注册）
 * 依赖：CryptoJS（lib/crypto-js.js）
 * 用法：
 *   initCaptchaSlider({
 *     wrap: '#captchaSlider',   // 组件挂载容器
 *     form: '#login-form',      // 表单选择器（写入 hidden 字段）
 *     onPass: function(){},     // 验证通过回调（可解锁提交按钮）
 *   });
 * 提交时后端必须从表单 hidden 读取：
 *   captcha_token / captcha_pointJson 并通过 /api/captcha/check（服务端强校验，防绕过）
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

  function el(html) {
    var t = document.createElement('template');
    t.innerHTML = html.trim();
    return t.content.firstChild;
  }

  function initCaptchaSlider(opts) {
    opts = opts || {};
    var wrap = typeof opts.wrap === 'string' ? document.querySelector(opts.wrap) : opts.wrap;
    if (!wrap) return;

    var form = opts.form ? (typeof opts.form === 'string' ? document.querySelector(opts.form) : opts.form) : null;

    wrap.innerHTML =
      '<div class="captcha-wrap">' +
      '  <div class="captcha-panel">' +
      '    <div class="captcha-imgbox">' +
      '      <img class="back" alt="">' +
      '      <div class="captcha-mask"></div>' +
      '      <img class="captcha-jigsaw" alt="">' +
      '      <div class="captcha-msg"></div>' +
      '      <div class="captcha-refresh" title="换一张">&#x21BB;</div>' +
      '    </div>' +
      '    <div class="captcha-bar">' +
      '      <div class="captcha-bar-track"></div>' +
      '      <div class="captcha-bar-text">按住滑块，拖动完成拼图</div>' +
      '      <div class="captcha-bar-btn">&#x25B6;</div>' +
      '    </div>' +
      '  </div>' +
      '</div>';

    var box = wrap.querySelector('.captcha-imgbox');
    var back = wrap.querySelector('.back');
    var jigsaw = wrap.querySelector('.captcha-jigsaw');
    var mask = wrap.querySelector('.captcha-mask');
    var msg = wrap.querySelector('.captcha-msg');
    var refresh = wrap.querySelector('.captcha-refresh');
    var bar = wrap.querySelector('.captcha-bar');
    var track = wrap.querySelector('.captcha-bar-track');
    var barText = wrap.querySelector('.captcha-bar-text');
    var barBtn = wrap.querySelector('.captcha-bar-btn');

    // 展示尺寸（1:1，与原图 310x155 对齐）
    var DISP_W = 310, DISP_H = 155;
    var state = { token: '', secretKey: '', pointJson: '', passed: false };
    var startX = 0, curX = 0, dragging = false;
    var blockW = 0, blockH = 0;

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
      state.passed = false;
      state.token = token;
      state.secretKey = secretKey;
      state.pointJson = '';
      writeField('captcha_token', '');
      writeField('captcha_pointJson', '');
      barText.textContent = '按住滑块，拖动完成拼图';
      track.style.width = '0px';
      barBtn.style.left = '0px';
      barBtn.classList.remove('disabled');
      if (opts.onReset) opts.onReset();
    }

    function load() {
      fetch(API).then(function (r) { return r.json(); }).then(function (d) {
        if (!d || d.repCode !== '0000') { setMsg('加载验证码失败', false); return; }
        var rd = d.repData;
        back.src = 'data:image/png;base64,' + rd.originalImageBase64;
        jigsaw.src = 'data:image/png;base64,' + rd.jigsawImageBase64;
        setState(rd.token, rd.secretKey);
        refresh.style.display = '';
      }).catch(function () { setMsg('网络错误，请重试', false); });
    }

    // 拼图块尺寸 1:1
    jigsaw.addEventListener('load', function () {
      blockW = jigsaw.naturalWidth; blockH = jigsaw.naturalHeight;
      jigsaw.style.width = blockW + 'px'; jigsaw.style.height = blockH + 'px';
      jigsaw.style.display = 'block'; jigsaw.style.left = '0px'; jigsaw.style.top = '0px';
    });

    function submit(pos) {
      if (state.passed) return;
      try {
        var pointJson = aesEncrypt(JSON.stringify({ x: pos, y: 5.0 }), state.secretKey);
        state.pointJson = pointJson;
        var body = new URLSearchParams();
        body.set('token', state.token);
        body.set('pointJson', pointJson);
        fetch(CHECK, { method: 'POST', headers: { 'Content-Type': 'application/x-www-form-urlencoded' }, body: body.toString() })
          .then(function (r) { return r.json(); })
          .then(function (d) {
            if (d && d.success) {
              state.passed = true;
              writeField('captcha_token', state.token);
              writeField('captcha_pointJson', state.pointJson);
              track.style.width = '100%'; barBtn.style.left = (DISP_W - 42) + 'px';
              barText.textContent = '&#10003; 验证成功';
              setMsg('验证通过', true);
              barBtn.classList.add('disabled');
              if (opts.onPass) opts.onPass();
            } else {
              setMsg('验证失败，请重试', false);
              setTimeout(load, 800);
            }
          }).catch(function () { setMsg('校验失败，请重试', false); setTimeout(load, 800); });
      } catch (e) { setMsg('加密失败，请重试', false); setTimeout(load, 800); }
    }

    function resetDrag() {
      dragging = false; mask.classList.remove('on'); jigsaw.style.display = 'none';
      // 松开后若未触发，恢复初始
      track.style.width = '0px'; barBtn.style.left = '0px';
      barText.textContent = '按住滑块，拖动完成拼图';
    }

    var moveLeft = function (e) {
      if (!dragging || state.passed) return;
      var clientX = e.clientX !== undefined ? e.clientX : (e.touches && e.touches[0].clientX);
      var dx = clientX - startX;
      dx = Math.max(0, Math.min(DISP_W - 42, dx));
      curX = dx;
      jigsaw.style.left = dx + 'px';
      track.style.width = dx + 'px';
      barBtn.style.left = dx + 'px';
    };
    var startDrag = function (e) {
      if (state.passed || !state.token) return;
      e.preventDefault();
      dragging = true;
      startX = (e.clientX !== undefined ? e.clientX : (e.touches && e.touches[0].clientX));
      mask.classList.add('on');
      // 定位拼图块到拖动位置
      jigsaw.style.display = 'block';
      jigsaw.style.left = (curX || 0) + 'px';
    };
    var endDrag = function (e) {
      if (!dragging) return;
      dragging = false;
      mask.classList.remove('on');
      if (state.passed) return;
      var finalX = curX;
      submit(finalX);
    };

    barBtn.addEventListener('mousedown', startDrag);
    barBtn.addEventListener('touchstart', startDrag, { passive: false });
    document.addEventListener('mousemove', moveLeft);
    document.addEventListener('mouseup', endDrag);
    document.addEventListener('touchmove', moveLeft, { passive: false });
    document.addEventListener('touchend', endDrag);
    refresh.addEventListener('click', load);

    load();
  }

  global.initCaptchaSlider = initCaptchaSlider;
})(window);
