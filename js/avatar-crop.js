/* 1:1 图片裁切组件（跨页面复用）
 * 用法：
 *   AvatarCrop.open(file).then(function (croppedFile) {
 *     // croppedFile: 裁切后的 1:1 File（png，默认 512x512）
 *   }).catch(function (err) {
 *     // 用户取消或出错
 *   });
 * 依赖：无（原生 JS + DOM），自带弹窗，不依赖 mdui-dialog。
 * 功能：图片铺满裁切框，拖拽移动调整位置，确认输出 1:1 正方形。
 */
window.AvatarCrop = (function () {
  'use strict';

  const DEFAULT_SIZE = 512; // 输出边长（1:1）

  let container = null;
  let imgEl = null;
  let wrapEl = null;
  let img = null;
  let baseW = 0, baseH = 0;  // 图片在当前容器下的实际显示尺寸（cover 铺满）
  let ox = 0, oy = 0;        // 图片偏移
  const pointers = {};       // 活动指针
  let dragStart = null;      // 拖拽基准 {sx,sy,px,py}
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
    hint.textContent = '拖动图片调整位置，裁切区会保留为正方形';
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
    wrapEl.appendChild(imgEl);

    const frameEl = document.createElement('div');
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

  function onPointerDown(e) {
    e.preventDefault();
    pointers[e.pointerId] = { x: e.clientX, y: e.clientY };
    imgEl.setPointerCapture && imgEl.setPointerCapture(e.pointerId);
    imgEl.style.cursor = 'grabbing';
  }

  function onPointerMove(e) {
    e.preventDefault();
    if (!(e.pointerId in pointers)) return;
    if (Object.keys(pointers).length > 1) return; // 多指忽略，仅单指拖拽
    const p = { x: e.clientX, y: e.clientY };
    if (!dragStart) {
      dragStart = { sx: ox, sy: oy, px: p.x, py: p.y };
      return;
    }
    ox = dragStart.sx + (p.x - dragStart.px);
    oy = dragStart.sy + (p.y - dragStart.py);
    applyTransform();
    pointers[e.pointerId] = p;
  }

  function onPointerUp(e) {
    e.preventDefault();
    delete pointers[e.pointerId];
    dragStart = null;
    if (Object.keys(pointers).length === 0) imgEl.style.cursor = 'grab';
  }

  function applyTransform() {
    imgEl.style.width = baseW + 'px';
    imgEl.style.height = baseH + 'px';
    imgEl.style.left = ox + 'px';
    imgEl.style.top = oy + 'px';
  }

  // 初始化：图片 cover 铺满裁切框并居中
  function initLayout() {
    const cw = wrapEl.clientWidth;
    const iw = img.naturalWidth, ih = img.naturalHeight;
    if (iw === 0 || ih === 0 || cw === 0) return;
    // cover：铺满容器
    const s = Math.max(cw / iw, cw / ih);
    baseW = Math.round(iw * s);
    baseH = Math.round(ih * s);
    ox = (cw - baseW) / 2;
    oy = (cw - baseH) / 2;
    applyTransform();
  }

  function closeWithCancel() {
    if (container) { container.remove(); container = null; }
    if (rejectFn) rejectFn(new Error('cancelled'));
  }

  function confirmCrop() {
    const size = DEFAULT_SIZE;
    const cw = wrapEl.clientWidth;
    // 当前可视中心对应的图片像素
    const scaleX = img.naturalWidth / baseW;
    const scaleY = img.naturalHeight / baseH;
    const half = cw / 2;
    const centerImgX = (cw / 2 - ox) * scaleX;
    const centerImgY = (cw / 2 - oy) * scaleY;
    const halfImgX = half * scaleX;
    const halfImgY = half * scaleY;

    const sx = Math.max(0, centerImgX - halfImgX);
    const sy = Math.max(0, centerImgY - halfImgY);
    const sw = Math.min(img.naturalWidth - sx, halfImgX * 2);
    const sh = Math.min(img.naturalHeight - sy, halfImgY * 2);

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
