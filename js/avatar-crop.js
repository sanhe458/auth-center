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

  function applyTransform() {
    const w = baseW * scale;
    const h = baseH * scale;
    imgEl.style.width = w + 'px';
    imgEl.style.height = h + 'px';
    imgEl.style.left = ox + 'px';
    imgEl.style.top = oy + 'px';
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
