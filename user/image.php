<?php
/**
 * 用户端 · 我的图床
 * 上传图片（复用 imgbb），可选过期时间。
 * 档位：1/7/30 天免费；90/180 天、永久需 10 元解锁，一次付费终身有效。
 * 图床页涉及支付解锁走应用余额；开发者可通过 API 调用。
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/image_host.php';

$user = requireLoginPage();
$permanent = imageIsPermanent((int)$user['id']);

// 我的图片
$db = db();
$st = $db->prepare('SELECT id,name,url,page_url,size,mime,expires_at,is_permanent,created_at FROM images WHERE user_id=? ORDER BY id DESC LIMIT 30');
$st->execute([$user['id']]);
$images = $st->fetchAll();

pageHead('我的图床', '<link rel="stylesheet" href="/css/user.css?v=20260818a">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('images');
contentOpen('我的图床', '上传图片 · 可选过期时间');
?>

<?php if ($permanent): ?>
  <mdui-card style="border-radius:14px;background:rgba(82,196,26,.12);color:#52c41a;padding:14px 18px;margin-bottom:16px;font-size:14px;border:1px solid rgba(82,196,26,.3);">
    ✅ 已解锁 <b>永久图床</b>（¥10 一次，终身有效）——可上传 90天/180天/永久 的图片
  </mdui-card>
<?php endif; ?>

<!-- 上传区 -->
<mdui-card variant="elevated" style="border-radius:16px;padding:20px;margin-bottom:24px;">
  <div class="sec-title" style="margin:0 0 16px;">上传图片</div>
  <div style="display:flex;flex-direction:column;gap:14px;">
    <input type="file" id="fileInput" accept="image/*" style="font-size:14px;">
    <div>
      <div style="font-size:13px;opacity:.65;margin-bottom:8px;">过期时间</div>
      <div id="tierOpts" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
      <div id="tierHint" style="font-size:12px;opacity:.6;margin-top:8px;"></div>
    </div>
    <mdui-button id="btnUp" variant="filled" icon="upload--outlined">上传</mdui-button>
  </div>
  <div id="upMsg" style="margin-top:12px;font-size:13px;opacity:.7;"></div>
</mdui-card>

<!-- 列表 -->
<div class="sec-title" style="margin:0 0 12px;">我的图片（最近 30 张）</div>
<?php if (!$images): ?>
  <mdui-card variant="elevated" style="border-radius:16px;padding:40px;text-align:center;color:#999;">还没有图片，去上方上传一张吧。</mdui-card>
<?php else: ?>
<mdui-card variant="elevated" style="border-radius:16px;overflow:hidden;">
  <mdui-list>
    <?php foreach ($images as $im): ?>
    <mdui-list-item nonclickable>
      <mdui-avatar slot="icon" style="--mdui-avatar-size:44px;border-radius:8px;"><img src="<?= htmlspecialchars($im['url']) ?>" style="width:100%;height:100%;object-fit:cover;border-radius:8px;"></mdui-avatar>
      <div style="min-width:0;">
        <div style="font-size:14px;font-weight:600;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;"><?= htmlspecialchars($im['name'] ?: 'image') ?></div>
        <div style="font-size:12px;opacity:.6;">
          <?= $im['is_permanent'] ? '<b style="color:#52c41a;">永久</b>' : ($im['expires_at'] ? '到期 '.htmlspecialchars($im['expires_at']) : '永久') ?>
          · <?= $im['size'] ? round($im['size']/1024,1).' KB' : '' ?> · <?= htmlspecialchars($im['created_at']) ?>
        </div>
      </div>
      <mdui-button slot="end-icon" variant="text" icon="link--outlined" onclick="copyUrl(this,'<?= htmlspecialchars($im['url']) ?>')">复制</mdui-button>
    </mdui-list-item>
    <?php endforeach; ?>
  </mdui-list>
</mdui-card>
<?php endif; ?>

<?php
contentClose();
echo '</div>';
pageFoot();
?>

<!-- ============ 上传逻辑 + 档位渲染 ============ -->
<?php if ($permanent): ?>
<script>
// 已解锁：全部档位可选
const TIERS = [
  ['1d','1 天',false], ['7d','7 天',false], ['30d','30 天',false],
  ['90d','90 天',false], ['180d','180 天',false], ['forever','永久',false]
];
</script>
<?php else: ?>
<script>
// 未解锁：90/180/永久 锁定
const TIERS = [
  ['1d','1 天',false], ['7d','7 天',false], ['30d','30 天',false],
  ['90d','90 天',true], ['180d','180 天',true], ['forever','永久',true]
];
</script>
<?php endif; ?>
<script>
let selectedTier = '';
const TIER_PERMANENT = <?= $permanent ? 'true' : 'false' ?>;

function renderTiers() {
  const box = document.getElementById('tierOpts');
  box.innerHTML = '';
  TIERS.forEach(([key,label,locked]) => {
    const b = document.createElement('mdui-button');
    b.variant = 'outlined';
    b.style = '--mdui-button-font-size:13px;';
    b.textContent = locked ? label + ' 🔒' : label;
    b.onclick = () => {
      if (locked) {
        // 需解锁
        startUnlock();
        return;
      }
      selectedTier = key;
      box.querySelectorAll('mdui-button').forEach(x => x.style.borderColor = '');
      b.style.borderColor = 'rgb(var(--mdui-color-primary))';
      document.getElementById('tierHint').textContent = '已选：' + label + (selectedTier==='forever'?'（永久）':'');
    };
    box.appendChild(b);
  });
}

async function startUnlock() {
  const msg = document.getElementById('upMsg');
  if (confirm('90天/180天/永久 需 10 元解锁（一次付费终身有效）。去解锁？')) {
    msg.textContent = '正在创建解锁订单...';
    try {
      const r = await fetch('/api/image/unlock_prepare', {method:'POST'});
      const d = await r.json();
      if (r.ok && d.pay_url) {
        msg.innerHTML = '解锁订单已生成：<br><a href="'+d.pay_url+'" target="_blank" rel="noopener"><mdui-button variant="filled" style="margin-top:8px;">前往支付 ¥10 解锁</mdui-button></a><br><span style="font-size:12px;opacity:.6;">支付完成后回到此页刷新即可。</span>';
        // 启动轮询确认
        pollUnlock(d.order_no);
      } else {
        msg.textContent = '创建订单失败：' + (d.error||'未知错误');
      }
    } catch(e) { msg.textContent = '网络错误：'+e.message; }
  }
}

function pollUnlock(orderNo) {
  let n = 0;
  const t = setInterval(async () => {
    n++;
    if (n > 40) { clearInterval(t); return; } // 最多等2分钟
    try {
      const r = await fetch('/api/image/unlock_confirm', {
        method:'POST', headers:{'Content-Type':'application/json'},
        body: JSON.stringify({order_no: orderNo})
      });
      const d = await r.json();
      if (r.ok && d.permanent) {
        clearInterval(t);
        document.getElementById('upMsg').innerHTML = '✅ 解锁成功！现在可以上传永久图片了。';
        setTimeout(()=>location.reload(), 1500);
      }
    } catch(e) {}
  }, 3000);
}

function copyUrl(btn, url) {
  navigator.clipboard.writeText(url).then(()=>{
    const old=btn.textContent; btn.textContent='已复制'; setTimeout(()=>btn.textContent=old,1500);
  });
}

document.getElementById('btnUp').addEventListener('click', async () => {
  const file = document.getElementById('fileInput').files[0];
  const msg = document.getElementById('upMsg');
  if (!file) { msg.textContent='请选择图片'; return; }
  if (!selectedTier) { msg.textContent='请选择过期时间'; return; }
  msg.textContent = '上传中...';
  const fd = new FormData();
  fd.append('image', file);
  fd.append('tier', selectedTier);
  try {
    const r = await fetch('/api/image/upload', {method:'POST', body: fd});
    const d = await r.json();
    if (r.ok && d.image) {
      msg.innerHTML = '✅ 上传成功：<br><a href="'+d.image.url+'" target="_blank" rel="noopener" style="color:var(--mdui-color-primary);word-break:break-all;">'+d.image.url+'</a>';
      setTimeout(()=>location.reload(), 1200);
    } else {
      msg.textContent = '上传失败：' + (d.error||d.message||'未知错误');
    }
  } catch(e) { msg.textContent='网络错误：'+e.message; }
});

renderTiers();
</script>
