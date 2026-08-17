/* 1:1 图片裁切组件（跨页面复用）
 * 用法：
 *   AvatarCrop.open(file).then(function (croppedFile) {
 *     // croppedFile: 裁切后的 1:1 File（png，默认 512x512）
 *   }).catch(function (err) {
 *     // 用户取消或出错
 *   });
 * 依赖：无（原生 JS + DOM），自带弹窗，不依赖 mdui-dialog。
 * 支持：鼠标/触屏拖拽移动、滚轮缩放、圆形遮罩 1:1 正方形。
 */
window.AvatarCrop = (function () {
  'use strict';

  const DEFAULT_SIZE = 512; // 输出边长（1:1）

  let container = null;      // modal 根元素
  let imgEl = null;          // 可拖拽的图片
  let frameEl = null;        // 裁切框（1:1）
  let sliderEl = null;       // 缩放滑块
  let wrapEl = null;         // 容器
  let img = null;            // Image
  let scale = 1;             // 当前缩放
  let baseW = 0, baseH = 0;  // 图片在画布中的基础尺寸
  let ox = 0, oy = 0;        // 图片偏移
  let resolveFn = null;
  let rejectFn = null;

  function buildModal() {
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:9999;display:flex;align-items:center;justify-content:center;';
    const box = document.createElement('div');
    box.style.cssText = 'background:#fff;border-radius:16px;padding:20px;max-width:520px;width:90%;box-shadow:0 10px 40px rgba(0,0,0,.4);';

    const title = document.createElement('div');
    title.textContent = '裁切图片（1:1）';
    title.style.cssText = 'font-weight:700;font-size:16px;margin-bottom:6px;color:#1c1b20;';
    const hint = document.createElement('div');
    hint.textContent = '拖动图片调整位置，滚轮缩放，裁切区会保留为正方形';
    hint.style.cssText = 'font-size:12px;color:#777;margin-bottom:12px;';

    // 裁切区容器
    wrapEl = document.createElement('div');
    wrapEl.style.cssText = 'position:relative;width:100%;aspect-ratio:1/1;overflow:hidden;background:#000;border-radius:12px;touch-action:none;cursor:grab;';

    imgEl = document.createElement('img');
    imgEl.style.cssText = 'position:absolute;user-select:none;-webkit-user-drag:none;max-width:none;touch-action:none;';
    imgEl.addEventListener('pointerdown', onPointerDown);
    imgEl.addEventListener('pointermove', onPointerMove);
    imgEl.addEventListener('pointerup', onPointerUp);
    imgEl.addEventListener('pointercancel', onPointerUp);
    imgEl.addEventListener('wheel', onWheel, { passive: false });
    wrapEl.appendChild(imgEl);

    frameEl = document.createElement('div');
    frameEl.style.cssText = 'position:absolute;inset:0;box-shadow:0 0 0 9999px rgba(0,0,0,.5);border:2px solid #ffa726;border-radius:8px;pointer-events:none;';
    wrapEl.appendChild(frameEl);

    // 缩放滑块（原生 range，适配触控滑动）
    const sliderRow = document.createElement('div');
    sliderRow.style.cssText = 'display:flex;align-items:center;gap:10px;margin-top:14px;';
    const minusLabel = document.createElement('span');
    minusLabel.textContent = '−';
    minusLabel.style.cssText = 'font-size:18px;color:#999;user-select:none;';
    sliderEl = document.createElement('input');
    sliderEl.type = 'range';
    sliderEl.min = '0';
    sliderEl.max = '100';
    sliderEl.value = '50';    // 初始映射到 scale 基线（见 syncSlider）
    sliderEl.style.cssText = 'flex:1;-webkit-appearance:none;appearance:none;height:40px;background:transparent;cursor:pointer;touch-action:none;';
    sliderEl.addEventListener('input', onSliderInput);
    const plusLabel = document.createElement('span');
    plusLabel.textContent = '+';
    plusLabel.style.cssText = 'font-size:18px;color:#999;user-select:none;';
    sliderRow.appendChild(minusLabel);
    sliderRow.appendChild(sliderEl);
    sliderRow.appendChild(plusLabel);

    // 按钮行
    const btnRow = document.createElement('div');
    btnRow.style.cssText = 'display:flex;gap:10px;margin-top:14px;justify-content:flex-end;';
    const cancelBtn = document.createElement('button');
    cancelBtn.textContent = '取消';
    cancelBtn.style.cssText = 'padding:9px 20px;border:1px solid #ddd;border-radius:10px;background:#fff;cursor:pointer;font-size:14px;color:#555;';
    cancelBtn.onclick = function () { closeWithCancel(); };
    const okBtn = document.createElement('button');
    okBtn.textContent = '确认裁切';
    okBtn.style.cssText = 'padding:9px 20px;border:none;border-radius:10px;background:linear-gradient(135deg,#ffb74d,#ff7043);color:#3a1d00;font-weight:700;cursor:pointer;font-size:14px;';
    okBtn.onclick = function () { confirmCrop(); };
    btnRow.appendChild(cancelBtn);
    btnRow.appendChild(okBtn);

    box.appendChild(title);
    box.appendChild(hint);
    box.appendChild(wrapEl);
    box.appendChild(sliderRow);
    box.appendChild(btnRow);
    overlay.appendChild(box);

    overlay.addEventListener('click', function (e) { if (e.target === overlay) closeWithCancel(); });

    container = overlay;
    document.body.appendChild(container);
  }

  // 多点触控：活动指针集合
  let pointers = {};
  let dragStart = null;   // 单指拖拽基准 {sx,sy,px,py}
  let pinchStart = null;  // 双指缩放基准 {dist, scale}

  function onPointerDown(e) {
    e.preventDefault();
    pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
    imgEl.setPointerCapture && imgEl.setPointerCapture(e.pointerId);
    imgEl.style.cursor = 'grabbing';
  }

  function onPointerMove(e) {
    e.preventDefault();
    if (!(e.pointerId in pointers)) return;
    pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
    const ids = Object.keys(pointers);
    if (ids.length >= 2) {
      // 双指捏合：用前两个指针的距离
      const [a, b] = ids;
      const p1 = pointers[a], p2 = pointers[b];
      const dist = Math.hypot(p2.x - p1.x, p2.y - p1.y);
      if (!pinchStart) {
        pinchStart = { dist, scale };
        dragStart = null;
      } else {
        if (pinchStart.dist > 0) {
          scale = Math.min(5, Math.max(1, pinchStart.scale * (dist / pinchStart.dist)));
          applyTransform();
        }
      }
    } else if (ids.length === 1) {
      // 单指拖拽
      const id = ids[0];
      const p = pointers[id];
      if (!dragStart) {
        dragStart = { sx: ox, sy: oy, px: p.x, py: p.y };
        pinchStart = null;
      } else {
        ox = dragStart.sx + (p.x - dragStart.px);
        oy = dragStart.sy + (p.y - dragStart.py);
        applyTransform();
      }
    }
  }

  function onPointerUp(e) {
    e.preventDefault();
    delete pointers[e.pointerId];
    const ids = Object.keys(pointers);
    if (ids.length < 2) pinchStart = null;
    if (ids.length < 1) dragStart = null;
    if (ids.length === 0) imgEl.style.cursor = 'grab';
  }

  function onWheel(e) {
    e.preventDefault();
    const delta = e.deltaY > 0 ? 0.9 : 1.1;
    scale = Math.min(5, Math.max(1, scale * delta));
    applyTransform();
  }

  // 设置缩放值（滑块/手势共用），范围 1~5
  function setScale(v) {
    scale = Math.min(5, Math.max(1, v));
    applyTransform();
  }

  // 滑块 input：滑块值 0~100 → scale 1~5
  function onSliderInput() {
    const v = parseFloat(sliderEl.value) / 100; // 0~1
    setScale(1 + v * 4);                        // 1~5
  }

  // 把当前 scale 反映到滑块（缩放后保持滑块位置一致）
  function syncSlider() {
    if (!sliderEl) return;
    const v = ((scale - 1) / 4) * 100; // scale 1~5 → 0~100
    sliderEl.value = String(Math.round(Math.min(100, Math.max(0, v))));
  }

  function applyTransform() {
    const w = baseW * scale;
    const h = baseH * scale;
    imgEl.style.width = w + 'px';
    imgEl.style.height = h + 'px';
    imgEl.style.left = ox + 'px';
    imgEl.style.top = oy + 'px';
    syncSlider();
  }

  // 初始化：以裁切框(容器)为中心铺满
  function initLayout() {
    const cw = wrapEl.clientWidth;
    const iw = img.naturalWidth, ih = img.naturalHeight;
    // 铺满容器（覆盖模式）
    scale = Math.max(cw / iw, cw / ih);
    baseW = iw; baseH = ih;
    // 居中
    ox = (cw - iw * scale) / 2;
    oy = (cw - ih * scale) / 2;
    applyTransform();
  }

  function closeWithCancel() {
    if (container) { container.remove(); container = null; }
    if (rejectFn) rejectFn(new Error('cancelled'));
  }

  function confirmCrop() {
    const size = DEFAULT_SIZE;
    const cw = wrapEl.clientWidth;
    // 计算裁切框中心对应的图片像素
    const centerImgX = (cw / 2 - ox) / scale;
    const centerImgY = (cw / 2 - oy) / scale;
    const half = cw / 2 / scale; // 裁切框半宽（图片像素）

    const sx = Math.max(0, centerImgX - half);
    const sy = Math.max(0, centerImgY - half);
    const sw = Math.min(img.naturalWidth - sx, half * 2);
    const sh = Math.min(img.naturalHeight - sy, half * 2);

    const canvas = document.createElement('canvas');
    canvas.width = size; canvas.height = size;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#fff'; ctx.fillRect(0, 0, size, size);
    ctx.drawImage(img, sx, sy, sw, sh, 0, 0, size, size);

    canvas.toBlob(function (blob) {
      const croppedFile = new File([blob], 'avatar_cropped.png', { type: 'image/png' });
      if (container) { container.remove(); container = null; }
      if (resolveFn) resolveFn(croppedFile);
    }, 'image/png');
  }

  return {
    /** 打开裁切弹窗，返回 Promise<File>；取消则 reject */
    open: function (file) {
      return new Promise(function (resolve, reject) {
        resolveFn = resolve;
        rejectFn = reject;
        const url = URL.createObjectURL(file);
        img = new Image();
        img.onload = function () {
          buildModal();
          imgEl.src = url;
          initLayout();
        };
        img.onerror = function () { URL.revokeObjectURL(url); reject(new Error('图片解析失败')); };
        img.src = url;
      });
    }
  };
})();
