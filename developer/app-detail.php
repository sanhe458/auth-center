<?php
/**
 * 应用详情/设置（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/scopes.php';

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

$msg = '';
$err = '';

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

pageHead($app['name'], '<link rel="stylesheet" href="/css/user.css">');
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

    <!-- 基本设置 -->
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
        <div class="actions">
          <mdui-button variant="text" onclick="location.href='apps.php'">取 消</mdui-button>
          <mdui-button variant="filled" icon="check--outlined" type="submit">保存修改</mdui-button>
        </div>
      </mdui-card>

      <!-- 权限范围 -->
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
    </form>

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

  input.addEventListener('change', async () => {
    const file = input.files[0];
    if (!file) return;
    const ok = /^image\/(jpeg|png|gif|webp)$/.test(file.type);
    if (!ok) { status.textContent = '格式不支持，仅 JPG/PNG/GIF/WebP'; return; }
    status.textContent = '正在打开裁切…';
    let selected;
    try {
      selected = await window.AvatarCrop.open(file);
    } catch (err) {
      status.textContent = (err && err.message === 'cancelled') ? '已取消裁切' : '裁切失败：' + (err && err.message);
      input.value = '';
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
pageFoot('<script src="/js/avatar-crop.js?v=3"></script>'); ?>