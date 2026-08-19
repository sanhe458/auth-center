<?php
/**
 * 应用详情/设置（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/scopes.php';
require_once __DIR__ . '/../api/lib/pay.php'; // genPid / genPayKey

$user = requireLoginPage();
$db = db();
$clientId = $_GET['id'] ?? '';

$st = $db->prepare('SELECT * FROM apps WHERE client_id = ? AND owner_id = ? LIMIT 1');
$st->execute([$clientId, $user['id']]);
$app = $st->fetch();
if (!$app) {
    header('Location: apps.php');
    exit;
}

// 权限范围
$st = $db->prepare('SELECT scope FROM app_scopes WHERE app_id = ?');
$st->execute([$app['id']]);
$appScopes = array_column($st->fetchAll(), 'scope');

// 易支付收款商户（一个应用最多关联一个商户）
$merchant = null;
$st = $db->prepare('SELECT * FROM pay_merchants WHERE app_id = ? AND owner_id = ? LIMIT 1');
$st->execute([$app['id'], $app['owner_id']]);
$merchant = $st->fetch() ?: null;

$msg = '';
$err = '';

// 重置 client_secret（POST 后生成并仅展示一次）
if (($_POST['action'] ?? '') === 'reset_secret') {
    $newSecret = genSecret();
    $db->prepare('UPDATE apps SET client_secret_hash = ?, updated_at = NOW() WHERE id = ?')
        ->execute([hashSecret($newSecret), $app['id']]);
    // 存 session 一次性展示，避免刷新重复显示
    session_start();
    $_SESSION['regen_secret_' . $app['client_id']] = $newSecret;
    session_write_close();
    header('Location: app-detail.php?id=' . urlencode($app['client_id']));
    exit;
}

// 展示上一次重置生成的 client_secret（一次性）
$regenedSecret = null;
session_start();
$flashKey = 'regen_secret_' . $app['client_id'];
if (isset($_SESSION[$flashKey])) {
    $regenedSecret = $_SESSION[$flashKey];
    unset($_SESSION[$flashKey]);
}
session_write_close();

// 保存修改
if (($_POST['action'] ?? '') === 'save') {
    $name = trim($_POST['name'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $cb   = trim($_POST['callback'] ?? '');
    $home = trim($_POST['homepage'] ?? '');
    $scopes = $_POST['scopes'] ?? [];

    if (mb_strlen($name) < 2 || mb_strlen($name) > 30) {
        $err = '应用名称需 2-30 个字符';
    } elseif (!preg_match('#^https?://#i', $cb)) {
        $err = '回调地址需以 http:// 或 https:// 开头';
    } else {
        $scopes = sanitizeScopes($scopes);

        $db->prepare('UPDATE apps SET name=?, description=?, callback_url=?, homepage=? WHERE id=?')
            ->execute([$name, $desc, $cb, $home, $app['id']]);
        $db->prepare('DELETE FROM app_scopes WHERE app_id = ?')->execute([$app['id']]);
        $si = $db->prepare('INSERT INTO app_scopes (app_id, scope) VALUES (?,?)');
        foreach ($scopes as $s) $si->execute([$app['id'], $s]);

        $msg = '修改已保存';
        // 刷新
        $st = $db->prepare('SELECT * FROM apps WHERE id = ?');
        $st->execute([$app['id']]);
        $app = $st->fetch();
        $appScopes = $scopes;
    }
}

// 开通易支付收款商户（POST）
if (($_POST['action'] ?? '') === 'open_merchant') {
    if (!$merchant) {
        try {
            $pid = genPid();
            $key = genPayKey();
            $db->prepare('INSERT INTO pay_merchants (pid, name, key_plain, owner_id, app_id) VALUES (?,?,?,?,?)')
                ->execute([$pid, $app['name'], $key, $app['owner_id'], $app['id']]);
            $msg = '易支付收款商户已开通，商户ID与密钥见下方';
            // 刷新商户
            $st = $db->prepare('SELECT * FROM pay_merchants WHERE app_id = ? LIMIT 1');
            $st->execute([$app['id']]);
            $merchant = $st->fetch() ?: null;
        } catch (Throwable $e) {
            $err = '开通失败：' . $e->getMessage();
        }
    }
}

// 删除应用
if (($_POST['action'] ?? '') === 'delete') {
    $db->beginTransaction();
    $db->prepare('DELETE FROM app_scopes WHERE app_id = ?')->execute([$app['id']]);
    $db->prepare('DELETE FROM api_keys WHERE app_id = ?')->execute([$app['id']]);
    $db->prepare('DELETE FROM authorizations WHERE app_id = ?')->execute([$app['id']]);
    $db->prepare('DELETE FROM oauth_tokens WHERE app_id = ?')->execute([$app['id']]);
    $db->prepare('DELETE FROM apps WHERE id = ?')->execute([$app['id']]);
    $db->commit();
    header('Location: apps.php');
    exit;
}

$scopeDefs = scopeDefs();

pageHead($app['name'], '<link rel="stylesheet" href="/css/user.css?v=20260818a">');
pageNav($user);
echo '<div class="shell">';
devSidebar('devapps');
?>
<div class="content">
    <div class="back-btn" onclick="location.href='apps.php'"><mdui-icon name="arrow_back--outlined" style="font-size:18px;"></mdui-icon>返回我的应用</div>

    <?php if ($msg): ?><mdui-alert severity="success" icon="check_circle--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($msg) ?></mdui-alert><?php endif; ?>
    <?php if ($err): ?><mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($err) ?></mdui-alert><?php endif; ?>

    <!-- 应用信息头部 -->
    <mdui-card class="app-head" variant="elevated">
      <div style="position:relative;">
        <mdui-avatar id="h-avatar" style="--mdui-avatar-size:56px; border-radius:18px;"><?php if (!empty($app['icon'])): ?><img src="<?= htmlspecialchars($app['icon']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:18px;"><?php else: ?><div style="width:100%;height:100%;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#2dd4bf,#0ea5e9);border-radius:18px;"><mdui-icon name="apps--outlined" style="font-size:26px;"></mdui-icon></div><?php endif; ?></mdui-avatar>
        <input type="file" id="icon-input" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
        <mdui-icon name="edit--outlined" onclick="document.getElementById('icon-input').click()" style="position:absolute;bottom:-4px;right:-4px;background:var(--mdui-color-surface-container);border-radius:50%;padding:4px;font-size:16px;cursor:pointer;border:1px solid var(--ac-border,#26262e);"></mdui-icon>
      </div>
      <div class="info">
        <div class="nm"><?= htmlspecialchars($app['name']) ?> <?= appStatusBadge((int)$app['status']) ?></div>
        <div class="desc"><?= htmlspecialchars($app['description']) ?></div>
        <div class="cid">client_id: <?= htmlspecialchars($app['client_id']) ?></div>
      </div>
    </mdui-card>
    <div id="icon-status" style="font-size:12px;opacity:.7;margin-top:6px;"></div>

    <?php if ($regenedSecret): ?>
    <!-- 重置后的 client_secret（一次性展示） -->
    <mdui-card class="form-card" variant="elevated" style="border:1px solid rgba(var(--mdui-color-primary),.5);">
      <div class="sec-title" style="margin:0 0 12px; color:rgb(var(--mdui-color-primary));">已重置 client_secret</div>
      <div style="font-size:13px; opacity:.7; margin-bottom:10px;">旧密钥已失效。请立即复制保存，此密钥<b>仅本次显示</b>，刷新后不再出现。</div>
      <div style="display:flex; align-items:center; gap:10px;">
        <mdui-text-field readonly id="regen-secret" value="<?= htmlspecialchars($regenedSecret) ?>" full-width style="font-family:ui-monospace,monospace;"></mdui-text-field>
        <mdui-button variant="filled" icon="content_copy--outlined" onclick="copyText(this, document.getElementById('regen-secret').value)">复制</mdui-button>
      </div>
    </mdui-card>
    <?php endif; ?>

    <!-- 基本设置 + 权限范围（共用一个保存表单） -->
    <form method="POST">
      <input type="hidden" name="action" value="save">
      <mdui-card class="form-card" variant="elevated">
        <div class="sec-title" style="margin:0 0 16px;">基本设置</div>
        <div class="form-field">
          <mdui-text-field name="name" label="应用名称" icon="badge--outlined" value="<?= htmlspecialchars($app['name']) ?>" clearable full-width></mdui-text-field>
        </div>
        <div class="form-field">
          <mdui-text-field name="description" label="应用简介" placeholder="一句话描述这个应用是干什么的" icon="description--outlined" value="<?= htmlspecialchars($app['description']) ?>" clearable full-width></mdui-text-field>
        </div>
        <div class="form-field">
          <mdui-text-field name="callback" label="回调地址 (Callback URL)" icon="link--outlined" value="<?= htmlspecialchars($app['callback_url']) ?>" clearable full-width></mdui-text-field>
          <div class="callback-hint">用户授权后会跳转到这个地址，并附带授权码。生产环境必须使用 HTTPS。</div>
        </div>
        <div class="form-field">
          <mdui-text-field name="homepage" label="应用主页 (可选)" icon="language--outlined" value="<?= htmlspecialchars($app['homepage']) ?>" clearable full-width></mdui-text-field>
        </div>
        <div class="meta-row">
          <div class="m">创建时间<b><?= $app['created_at'] ?></b></div>
          <div class="m">最近更新<b><?= $app['updated_at'] ?></b></div>
        </div>
      </mdui-card>

      <mdui-card class="form-card" variant="elevated">
        <div class="sec-title" style="margin:0 0 12px;">权限范围</div>
        <?php foreach ($scopeDefs as $key => [$t, $d]): ?>
        <label class="scope-check">
          <mdui-checkbox name="scopes[]" value="<?= $key ?>" <?= in_array($key, $appScopes, true) ? 'checked' : '' ?>></mdui-checkbox>
          <div class="txt">
            <div class="t"><?= $t ?></div>
            <div class="d"><?= $d ?></div>
          </div>
        </label>
        <?php endforeach; ?>
      </mdui-card>

      <div class="actions">
        <mdui-button variant="text" onclick="location.href='apps.php'">取 消</mdui-button>
        <mdui-button variant="filled" icon="check--outlined" type="submit">保存修改</mdui-button>
      </div>
    </form>

    <!-- 客户端凭据（独立表单，重置密钥） -->
    <form method="POST" onsubmit="return confirm('确定重置 client_secret 吗？旧密钥将立即失效！');">
      <input type="hidden" name="action" value="reset_secret">
      <mdui-card class="form-card" variant="elevated">
        <div class="sec-title" style="margin:0 0 12px;">客户端凭据 (Client Credentials)</div>
        <div class="form-field">
          <mdui-text-field readonly label="client_id" icon="fingerprint--outlined" value="<?= htmlspecialchars($app['client_id']) ?>" full-width></mdui-text-field>
        </div>
        <div class="form-field">
          <mdui-text-field readonly label="client_secret" icon="key--outlined" placeholder="为安全起见，密钥仅创建或重置时显示" full-width></mdui-text-field>
        </div>
        <div style="font-size:12px; opacity:.6; margin-bottom:14px;">重置后旧 client_secret 立即失效，所有使用旧密钥的应用需更新为新密钥。</div>
        <mdui-button variant="tonal" color="error" icon="refresh--outlined" type="submit">重置 client_secret</mdui-button>
      </mdui-card>
    </form>

    <!-- 易支付收款（商户信息） -->
    <?php if ($merchant): ?>
    <mdui-card class="form-card" variant="elevated">
      <div class="sec-title" style="margin:0 0 16px;">易支付收款</div>
      <div style="font-size:12.5px; opacity:.65; margin-bottom:14px; line-height:1.7;">
        对接地址 <code style="user-select:all;">https://auth.sanhe.com.mp/</code>，填入下方商户ID和密钥即可，协议与彩虹易支付 V1 完全兼容。
      </div>
      <div class="form-field">
        <mdui-text-field readonly label="商户ID (pid)" icon="storefront--outlined" value="<?= htmlspecialchars($merchant['pid']) ?>" full-width></mdui-text-field>
      </div>
      <div class="form-field">
        <mdui-text-field readonly label="MD5 密钥 (key)" icon="key--outlined" value="<?= htmlspecialchars($merchant['key_plain']) ?>" full-width></mdui-text-field>
      </div>
      <div style="display:flex; align-items:center; justify-content:space-between; flex-wrap:wrap; gap:10px;">
        <div style="font-size:12px; opacity:.6;">收款进你的应用余额，D+1 后可提现。</div>
        <mdui-button variant="tonal" icon="open_in_new--outlined" onclick="location.href='/developer/app-balance.php'">查看应用余额</mdui-button>
      </div>
    </mdui-card>
    <?php else: ?>
    <mdui-card class="form-card" variant="elevated">
      <div class="sec-title" style="margin:0 0 8px;">易支付收款</div>
      <div style="font-size:13px; opacity:.7; line-height:1.7; margin-bottom:14px;">
        该应用还未开通易支付收款商户。开通后可获得商户ID(pid)和MD5密钥，任何支持易支付的系统都能零改动接入。
      </div>
      <form method="POST">
        <input type="hidden" name="action" value="open_merchant">
        <mdui-button variant="filled" icon="storefront--outlined" type="submit">开通收款商户</mdui-button>
      </form>
    </mdui-card>
    <?php endif; ?>

    <!-- 危险区 -->
    <div class="danger-zone">
      <div class="dz-t"><mdui-icon name="warning--outlined" style="font-size:18px;"></mdui-icon>危险区</div>
      <div class="dz-d">删除应用后，所有关联的密钥和授权将立即失效，此操作不可撤销。</div>
      <form method="POST" onsubmit="return confirm('确定要删除「<?= htmlspecialchars($app['name']) ?>」吗？此操作不可撤销！');">
        <input type="hidden" name="action" value="delete">
        <mdui-button variant="tonal" color="error" icon="delete--outlined" type="submit">删除应用</mdui-button>
      </form>
    </div>
</div>
<script>
(function(){
  const clientId = <?= json_encode($app['client_id']) ?>;
  const input = document.getElementById('icon-input');
  const status = document.getElementById('icon-status');
  const avatar = document.getElementById('h-avatar');
  const MAX_BYTES = 2 * 1024 * 1024; // 2MB
  const MAX_DIM = 256;

  // Canvas 压缩：等比缩放 + 循环降质量，直到达标
  function compressImage(file, maxBytes, maxDim) {
    return new Promise((resolve, reject) => {
      const img = new Image();
      const url = URL.createObjectURL(file);
      img.onload = () => {
        URL.revokeObjectURL(url);
        let { width, height } = img;
        const scale = Math.min(1, maxDim / Math.max(width, height));
        width = Math.round(width * scale);
        height = Math.round(height * scale);
        const canvas = document.createElement('canvas');
        canvas.width = width; canvas.height = height;
        const ctx = canvas.getContext('2d');
        ctx.fillStyle = '#ffffff'; ctx.fillRect(0, 0, width, height);
        ctx.drawImage(img, 0, 0, width, height);
        const qualities = [0.85, 0.7, 0.6, 0.5, 0.45];
        let i = 0;
        const tryNext = () => {
          if (i >= qualities.length) {
            canvas.toBlob(blob => blob ? resolve({ blob }) : reject(new Error('压缩失败')), 'image/jpeg', qualities[i - 1]);
            return;
          }
          canvas.toBlob(blob => {
            if (!blob) return reject(new Error('压缩失败'));
            if (blob.size <= maxBytes) resolve({ blob });
            else { i++; tryNext(); }
          }, 'image/jpeg', qualities[i]);
        };
        tryNext();
      };
      img.onerror = () => { URL.revokeObjectURL(url); reject(new Error('图片解析失败')); };
      img.src = url;
    });
  }

  input.addEventListener('change', async () => {
    const file = input.files[0];
    if (!file) return;
    const ok = /^image\/(jpeg|png|gif|webp)$/.test(file.type);
    if (!ok) { status.textContent = '格式不支持，仅 JPG/PNG/GIF/WebP'; return; }
    let selected = file;
    if (file.size > MAX_BYTES && file.type !== 'image/gif') {
      status.textContent = '图片超过 2MB，正在压缩…';
      try {
        const { blob } = await compressImage(file, MAX_BYTES, MAX_DIM);
        selected = new File([blob], 'icon.jpg', { type: blob.type });
      } catch (e) { status.textContent = '压缩失败：' + e.message; return; }
    } else if (file.size > MAX_BYTES) {
      status.textContent = 'GIF 超过 2MB，请换图';
      return;
    }
    status.textContent = '上传中…';
    const fd = new FormData();
    fd.append('client_id', clientId);
    fd.append('icon', selected);
    try {
      const r = await fetch('/api/apps/icon', { method: 'POST', body: fd, credentials: 'same-origin' });
      const d = await r.json();
      if (r.ok && d.icon) {
        avatar.innerHTML = '<img src="' + d.icon + '" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:18px;">';
        status.textContent = '✅ 头像已更新';
      } else {
        status.textContent = '上传失败：' + (d.error || d.message || '未知错误');
      }
    } catch (e) { status.textContent = '网络错误：' + e.message; }
  });
})();
</script>
<?php
echo '</div>';
pageFoot(); ?>