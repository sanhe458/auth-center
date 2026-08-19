<?php
/**
 * 个人设置（服务端渲染）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();
$db = db();

$msg = '';
$err = '';

// 修改昵称
if (($_POST['action'] ?? '') === 'nickname') {
    $nick = trim($_POST['nickname'] ?? '');
    if (mb_strlen($nick) < 2 || mb_strlen($nick) > 30) {
        $err = '昵称需 2-30 个字符';
    } else {
        $db->prepare('UPDATE users SET nickname = ? WHERE id = ?')->execute([$nick, $user['id']]);
        $_SESSION['nickname'] = $nick;
        $user['nickname'] = $nick;
        $msg = '昵称已更新';
    }
}

// 修改密码
if (($_POST['action'] ?? '') === 'password') {
    $old = $_POST['old_password'] ?? '';
    $new = $_POST['new_password'] ?? '';
    $st = $db->prepare('SELECT password_hash FROM users WHERE id = ?');
    $st->execute([$user['id']]);
    $hash = $st->fetch()['password_hash'];

    if (!password_verify($old, $hash)) {
        $err = '原密码不正确';
    } elseif (strlen($new) < 8 || strlen($new) > 72) {
        $err = '新密码需 8-72 位';
    } else {
        $db->prepare('UPDATE users SET password_hash = ? WHERE id = ?')
            ->execute([password_hash($new, PASSWORD_DEFAULT), $user['id']]);
        $msg = '密码已更新';
    }
}

pageHead('个人设置', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('profile');
?>
<div class="content">
    <div class="page-title">个人设置</div>
    <div class="page-sub">管理你的账户信息</div>

    <?php if ($msg): ?><mdui-alert severity="success" icon="check_circle--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($msg) ?></mdui-alert><?php endif; ?>
    <?php if ($err): ?><mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($err) ?></mdui-alert><?php endif; ?>

    <!-- 账户信息 -->
    <mdui-card class="form-card" variant="elevated">
      <div class="sec-title" style="margin:0 0 16px;">账户信息</div>
      <div class="form-field">
        <mdui-text-field label="用户 ID" value="<?= htmlspecialchars($user['uid']) ?>" readonly full-width></mdui-text-field>
      </div>
      <div class="form-field">
        <mdui-text-field label="邮箱" value="<?= htmlspecialchars($user['email']) ?>" readonly full-width></mdui-text-field>
      </div>
      <div class="form-field">
        <mdui-text-field label="注册时间" value="<?= htmlspecialchars($user['created_at']) ?>" readonly full-width></mdui-text-field>
      </div>
    </mdui-card>

    <!-- 头像设置 -->
    <mdui-card class="form-card" variant="elevated">
      <div class="sec-title" style="margin:0 0 16px;">头像</div>
      <div style="display:flex; align-items:center; gap:20px; flex-wrap:wrap;">
        <mdui-avatar id="avatar-preview" style="--mdui-avatar-size:72px; border-radius:22px; font-size:28px;"><?php if ($user['avatar']): ?><img src="<?= htmlspecialchars($user['avatar']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:22px;"><?php else: ?><img src="/avatar.php?n=<?= rawurlencode($user['nickname']) ?>&s=<?= rawurlencode($user['uid']) ?>&size=144" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:22px;"><?php endif; ?></mdui-avatar>
        <div style="flex:1; min-width:200px;">
          <div style="font-size:13px; opacity:.65; line-height:1.7; margin-bottom:12px;">支持 JPG / PNG / GIF / WebP。超过 2MB 会自动压缩（最长边 1280px，浏览器端处理），GIF 动图除外。上传后存储到 imgbb 图床。</div>
          <div style="display:flex; gap:10px; flex-wrap:wrap;">
            <input type="file" id="avatar-file" accept="image/jpeg,image/png,image/gif,image/webp" style="display:none;">
            <mdui-button variant="filled" icon="add_a_photo--outlined" onclick="document.getElementById('avatar-file').click()">选择图片</mdui-button>
            <mdui-button variant="tonal" icon="upload--outlined" id="avatar-upload-btn" disabled onclick="uploadAvatar()">上传头像</mdui-button>
            <?php if ($user['avatar']): ?>
            <mdui-button variant="text" color="error" icon="delete--outlined" onclick="removeAvatar()">移除头像</mdui-button>
            <?php endif; ?>
          </div>
          <div id="avatar-status" style="font-size:12px; margin-top:10px; opacity:.65;"></div>
        </div>
      </div>
    </mdui-card>

    <!-- 修改昵称 -->
    <form method="POST">
    <input type="hidden" name="action" value="nickname">
    <mdui-card class="form-card" variant="elevated">
      <div class="sec-title" style="margin:0 0 16px;">修改昵称</div>
      <div class="form-field">
        <mdui-text-field name="nickname" label="昵称" value="<?= htmlspecialchars($user['nickname']) ?>" icon="badge--outlined" clearable full-width></mdui-text-field>
      </div>
      <div class="actions">
        <mdui-button variant="filled" icon="check--outlined" type="submit">保存昵称</mdui-button>
      </div>
    </mdui-card>
    </form>

    <!-- 修改密码 -->
    <form method="POST">
    <input type="hidden" name="action" value="password">
    <mdui-card class="form-card" variant="elevated">
      <div class="sec-title" style="margin:0 0 16px;">修改密码</div>
      <div class="form-field">
        <mdui-text-field name="old_password" label="原密码" icon="lock--outlined" type="password" toggle-password clearable full-width></mdui-text-field>
      </div>
      <div class="form-field">
        <mdui-text-field name="new_password" label="新密码" placeholder="至少 8 位" icon="lock--outlined" type="password" toggle-password clearable full-width></mdui-text-field>
      </div>
      <div class="actions">
        <mdui-button variant="filled" icon="check--outlined" type="submit">更新密码</mdui-button>
      </div>
    </mdui-card>
    </form>

    <!-- 登出 -->
    <div style="margin-top:20px;">
      <form method="POST" action="/api/user/logout" onsubmit="event.preventDefault(); fetch('/api/user/logout',{method:'POST',credentials:'same-origin'}).then(()=>location.href='/login.php');">
        <mdui-button variant="tonal" icon="logout--outlined" type="submit">退出登录</mdui-button>
      </form>
    </div>
</div>
<script>
// 头像上传（带浏览器端压缩）
let selected = null;      // 当前选中的文件（可能已压缩），全局供 uploadAvatar 使用
let original = null;      // 原始文件（用于对比）

(function () {
  const fileInput = document.getElementById('avatar-file');
  const uploadBtn = document.getElementById('avatar-upload-btn');
  const status = document.getElementById('avatar-status');
  const preview = document.getElementById('avatar-preview');

  if (!fileInput || !uploadBtn) return;

  const MAX_BYTES = 2 * 1024 * 1024; // 2MB
  const MAX_DIM = 1280;              // 最长边像素

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

  fileInput.addEventListener('change', async function () {
    const file = this.files[0];
    if (!file) { uploadBtn.disabled = true; return; }
    original = file;

    const ok = /^image\/(jpeg|png|gif|webp)$/.test(file.type);
    if (!ok) { status.textContent = '格式不支持，仅 JPG/PNG/GIF/WebP'; uploadBtn.disabled = true; return; }

    // GIF 不压缩（保持动图），其他超 2MB 就压缩
    if (file.size > MAX_BYTES && file.type !== 'image/gif') {
      status.textContent = '图片超过 2MB，正在压缩…';
      uploadBtn.disabled = true;
      try {
        const { blob } = await compressImage(file, MAX_BYTES, MAX_DIM);
        selected = new File([blob], 'avatar_compressed.jpg', { type: blob.type });
        uploadBtn.disabled = false;
        status.textContent = '✅ 已压缩：' + Math.round(selected.size / 1024) + 'KB（原 ' + Math.round(file.size / 1024) + 'KB）';
      } catch (e) {
        status.textContent = '压缩失败：' + e.message;
        uploadBtn.disabled = true;
        return;
      }
    } else if (file.size > MAX_BYTES) {
      status.textContent = 'GIF 超过 2MB，不支持压缩动图';
      uploadBtn.disabled = true;
      return;
    } else {
      selected = file;
      uploadBtn.disabled = false;
      status.textContent = '已选择：' + file.name + '（' + Math.round(file.size / 1024) + 'KB）';
    }

    // 预览
    const reader = new FileReader();
    reader.onload = e => {
      preview.style.backgroundImage = 'url(' + e.target.result + ')';
      preview.style.backgroundSize = 'cover';
      preview.style.backgroundPosition = 'center';
      const img = preview.querySelector('img');
      if (img) img.style.display = 'none';
    };
    reader.readAsDataURL(selected);
  });
})();

function uploadAvatar() {
  const status = document.getElementById('avatar-status');
  const uploadBtn = document.getElementById('avatar-upload-btn');
  if (!selected) { status.textContent = '请先选择图片'; return; }
  const fd = new FormData();
  fd.append('avatar', selected);
  uploadBtn.disabled = true;
  status.textContent = '上传中…';
  fetch('/api/user/avatar', { method: 'POST', body: fd, credentials: 'same-origin' })
    .then(async r => ({ ok: r.ok, d: await r.json() }))
    .then(({ ok, d }) => {
      if (ok) {
        status.textContent = '✅ 头像上传成功';
        setTimeout(() => location.reload(), 800);
      } else {
        status.textContent = '上传失败：' + (d.error || d.message || '未知错误');
        uploadBtn.disabled = false;
      }
    })
    .catch(() => { status.textContent = '网络错误，请重试'; uploadBtn.disabled = false; });
}

function removeAvatar() {
  if (!confirm('确定移除头像？')) return;
  fetch('/api/user/avatar', { method: 'DELETE', credentials: 'same-origin' })
    .then(async r => ({ ok: r.ok, d: await r.json() }))
    .then(({ ok, d }) => { if (ok) location.reload(); else toast.error(d.error || d.message || '未知错误'); });
}
</script>

<?php
echo '</div>';
pageFoot(); ?>