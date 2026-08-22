/* Auth Center 液态玻璃（可选开启）
 * 开关：Cookie glass_enabled=1 开启
 * 依赖：js/liquid-glass.js（渲染器，仅开启时加载）
 * 基于 martin65536/liquid-glass-webgl (Apache-2.0)，见 js/LIQUID-GLASS-LICENSE.txt
 */
(function () {
  'use strict';

  var KEY = 'glass_enabled';
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

  window.glassEnabled = function () { return getCookie(KEY) === '1'; };

  // ===== 开关按钮 =====
  function ensureToggle() {
    if (document.getElementById('glass-toggle')) return;
    var btn = document.createElement('button');
    btn.id = 'glass-toggle';
    btn.className = 'theme-toggle glass-toggle';
    btn.title = '液态玻璃（' + (window.glassEnabled() ? '已开启' : '已关闭') + '）';
    btn.setAttribute('aria-label', '切换液态玻璃');
    btn.innerHTML = '<mdui-icon id="glass-icon" name="' +
      (window.glassEnabled() ? 'water_drop' : 'water_drop--outlined') +
      '"></mdui-icon>';
    btn.addEventListener('click', function () {
      if (window.glassEnabled()) {
        setCookie(KEY, '', -1);
      } else {
        setCookie(KEY, '1', DAYS);
      }
      location.reload();
    });
    // 放在主题切换按钮左侧（若存在），否则固定右上角
    var theme = document.querySelector('.theme-toggle.fixed');
    if (theme && theme.id !== 'glass-toggle') {
      theme.parentNode.insertBefore(btn, theme);
    } else {
      btn.classList.add('fixed');
      document.body.appendChild(btn);
    }
  }

  // ===== 渲染器初始化（仅开启时）=====
  function initGlass() {
    if (!window.glassEnabled()) return;
    if (!window.LiquidGlass || !window.LiquidGlass.LiquidGlassRenderer) return;
    var canvas = document.getElementById('glassBg');
    if (canvas) return; // 防止重复初始化

    canvas = document.createElement('canvas');
    canvas.id = 'glassBg';
    canvas.setAttribute('aria-hidden', 'true');
    canvas.style.cssText = 'position:fixed;top:0;left:0;right:0;bottom:0;width:100%;height:100%;z-index:0;pointer-events:none;';
    document.body.insertBefore(canvas, document.body.firstChild);
    document.body.classList.add('glass-on');

    var renderer;
    try {
      renderer = new LiquidGlass.LiquidGlassRenderer(canvas);
    } catch (e) { return; }
    renderer.cornerStyle = 1;

    // DPR：优先读取性能测试或保守默认（骁龙 695 级别默认 1.5 流畅）
    var m = document.cookie.match(/(?:^|;\s*)glass_dpr=([0-9.]+)/);
    var dpr = m ? parseFloat(m[1]) : 0;
    if (!(dpr > 0)) dpr = Math.min(1.5, window.devicePixelRatio || 1);
    renderer.dpr = dpr;

    var W = 0, H = 0;
    function resize() {
      W = window.innerWidth;
      H = window.innerHeight;
      canvas.style.width = W + 'px';
      canvas.style.height = H + 'px';
      renderer.resize(W, H);
      updateDomTargets();
    }
    window.addEventListener('resize', resize);

    function isDark() {
      return document.documentElement.classList.contains('mdui-theme-dark') ||
        (document.documentElement.classList.contains('mdui-theme-auto') &&
         window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches);
    }

    function applyBg() {
      if (isDark()) {
        renderer.setBackgroundColor([0, 0, 0]); // AuthCenter OLED 纯黑底
      } else {
        renderer.setBackgroundColor([0.96, 0.955, 0.95]); // 浅色底
      }
      renderer.requestRender();
    }

    // 缓存 DOM 收集到的目标矩形，避免在 rAF 里每帧 getBoundingClientRect 造成重排卡顿
    var cachedDomElements = [];
    function updateDomTargets() {
      var nodes = document.querySelectorAll('[data-glass="container"]');
      var list = [];
      var darkNow = isDark();
      for (var i = 0; i < nodes.length; i++) {
        var el = nodes[i];
        var r = el.getBoundingClientRect();
        if (r.width < 2 || r.height < 2) continue;
        // 视口外超出过多则不参与渲染提升性能
        if (r.bottom < -100 || r.top > H + 100 || r.right < -100 || r.left > W + 100) continue;

        list.push({
          id: 'glass-card-' + i,
          kind: 'glass-shape',
          rect: { x: r.left, y: r.top, w: r.width, h: r.height },
          cornerRadius: parseFloat(el.getAttribute('data-glass-radius') || '16'),
          refractionHeight: 8,
          refractionAmount: -16,
          depthEffect: true,
          chromaticAberration: false,
          blurRadius: 12,
          saturation: 1.5,
          brightness: 0,
          contrast: 1,
          tintColor: [0, 0, 0, 0],
          surfaceColor: darkNow ? [1, 1, 1, 0.08] : [1, 1, 1, 0.35],
          highlight: { mode: 0, color: [1, 1, 1], angle: Math.PI / 4, falloff: 1.0, alpha: 0.5, widthDp: 0.5 },
          outerShadow: { radius: 24, alpha: 0.1, offsetX: 0, offsetY: 4, color: [0, 0, 0] },
          label: '',
          labelColor: [0, 0, 0, 1],
          showChevron: false,
          isInteractive: false,
          scroll: false,
          innerShadow: null,
          independentBackdrop: false,
        });
      }
      cachedDomElements = list;
    }

    var angle = 0;
    function syncScene() {
      if (!window.LiquidGlass || !window.LiquidGlass.buildAmbientElements) return;
      var ambient = window.LiquidGlass.buildAmbientElements({ W: W, H: H, angle: angle, deformMul: 1 });
      renderer.setElements(ambient.concat(cachedDomElements));
      renderer.requestRender();
    }

    // 滚动、缩放时快速更新 DOM 位置
    window.addEventListener('scroll', function () {
      updateDomTargets();
      syncScene();
    }, { passive: true });

    // 氛围背景动画循环
    var lastTs = performance.now();
    function animLoop(ts) {
      var dt = Math.min(0.05, (ts - lastTs) / 1000);
      lastTs = ts;
      angle += dt * 0.6;
      syncScene();
      requestAnimationFrame(animLoop);
    }

    // 页面完全加载 / 字体加载后矫正一次位置
    if (document.fonts && document.fonts.ready) {
      document.fonts.ready.then(function () {
        updateDomTargets();
        syncScene();
      });
    }
    window.addEventListener('load', function () {
      updateDomTargets();
      syncScene();
    });

    // 800ms 漂移检测（例如动态展开折叠面板时）
    var lastSig = '';
    setInterval(function () {
      var nodes = document.querySelectorAll('[data-glass="container"]');
      var sig = Array.prototype.map.call(nodes, function (n) {
        var r = n.getBoundingClientRect();
        return Math.round(r.left) + ',' + Math.round(r.top) + ',' + Math.round(r.width) + ',' + Math.round(r.height);
      }).join(';');
      if (sig !== lastSig) {
        lastSig = sig;
        updateDomTargets();
        syncScene();
      }
    }, 800);

    // 主题切换监听
    if (window.MutationObserver) {
      new MutationObserver(function () {
        applyBg();
        updateDomTargets();
        syncScene();
      }).observe(
        document.documentElement, { attributes: true, attributeFilter: ['class'] }
      );
    }

    resize();
    applyBg();
    renderer.startAnimation();
    requestAnimationFrame(animLoop);
  }

  function boot() {
    ensureToggle();
    if (window.glassEnabled()) {
      if (!window.LiquidGlass) {
        var s = document.createElement('script');
        s.src = '/js/liquid-glass.js?v=' + (window.__glassVer || '20260822');
        s.onload = initGlass;
        s.onerror = function () {
          document.body.classList.remove('glass-on');
        };
        document.head.appendChild(s);
      } else {
        initGlass();
      }
    }
  }

  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', boot);
  } else {
    boot();
  }
})();
