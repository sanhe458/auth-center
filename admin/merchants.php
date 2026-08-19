<?php
/**
 * 管理后台 · 商户管理（易支付兼容收款）
 * 分配商户ID(pid) + MD5密钥(key)，查看商户余额/流水，重置密钥，启停
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/pay.php';

$admin = requireAdminPage();
$db = db();

$msg = '';
$err = '';

// 新建商户
if (($_POST['action'] ?? '') === 'create') {
    $name = trim($_POST['name'] ?? '');
    if ($name === '') {
        $err = '商户名称不能为空';
    } else {
        try {
            $pid = genPid();
            $key = genPayKey();
            $db->prepare('INSERT INTO pay_merchants (pid, name, key_plain) VALUES (?,?,?)')
                ->execute([$pid, $name, $key]);
            $msg = "商户已创建 &nbsp;<b>{$name}</b>&nbsp;（pid: <code>{$pid}</code>）<br>MD5 密钥（请复制保存）：<br><code style=\"user-select:all;\">{$key}</code>";
        } catch (Throwable $e) {
            $err = '创建失败：' . $e->getMessage();
        }
    }
}

// 重置密钥
if (($_POST['action'] ?? '') === 'reset_key') {
    $pid = trim($_POST['pid'] ?? '');
    if ($pid) {
        $newKey = genPayKey();
        $db->prepare('UPDATE pay_merchants SET key_plain = ? WHERE pid = ?')->execute([$newKey, $pid]);
        $msg = "商户 <code>{$pid}</code> 密钥已重置，旧密钥立即失效：<br><code style=\"user-select:all;\">{$newKey}</code>";
    }
}

// 启停
if (($_POST['action'] ?? '') === 'toggle') {
    $pid = trim($_POST['pid'] ?? '');
    if ($pid) {
        $db->prepare('UPDATE pay_merchants SET status = 1 - status WHERE pid = ?')->execute([$pid]);
        header('Location: merchants.php?msg=' . urlencode('已切换商户状态'));
        exit;
    }
}

// 商户列表
$merchants = $db->query('SELECT * FROM pay_merchants ORDER BY id DESC')->fetchAll();

pageHead('商户管理', '<link rel="stylesheet" href="/css/user.css">');
pageNav($admin);
echo '<div class="shell">';
adminSidebar('merchants');
contentOpen('商户管理', '易支付兼容收款 · 分配商户ID与密钥');
?>

<?php if ($msg): ?><mdui-card variant="elevated" style="border-radius:12px;padding:16px;background:#e8f5e9;margin-bottom:16px;font-size:14px;"><?= $msg ?></mdui-card><?php endif; ?>
<?php if ($err): ?><mdui-card variant="elevated" style="border-radius:12px;padding:16px;background:#fff3f3;color:#c62828;margin-bottom:16px;font-size:14px;"><?= htmlspecialchars($err) ?></mdui-card><?php endif; ?>

<!-- 新建商户 -->
<div class="sec-title" style="margin:0 0 12px;">新建商户</div>
<mdui-card variant="elevated" style="border-radius:16px;padding:20px;margin-bottom:24px;">
  <form method="post" style="display:flex;gap:12px;align-items:flex-end;flex-wrap:wrap;">
    <input type="hidden" name="action" value="create">
    <mdui-text-field name="name" label="商户名称" variant="outlined" placeholder="如：测试商城" style="width:220px;" required></mdui-text-field>
    <mdui-button type="submit" variant="filled" icon="add--outlined">创建商户</mdui-button>
  </form>
  <div style="font-size:12px;opacity:.6;margin-top:8px;">创建后自动分配商户ID(pid)和32位MD5密钥，密钥明文只显示一次。</div>
</mdui-card>

<!-- 商户列表 -->
<div class="sec-title" style="margin:0 0 12px;">商户列表</div>
<?php if (!$merchants): ?>
  <mdui-card variant="elevated" style="border-radius:16px;padding:40px;text-align:center;color:#999;">还没有商户，先在上方创建一个。</mdui-card>
<?php else: foreach ($merchants as $m): ?>
  <mdui-card variant="elevated" style="border-radius:16px;padding:20px;margin-bottom:16px;">
    <div style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;">
      <div style="font-size:16px;font-weight:700;"><?= htmlspecialchars($m['name']) ?>
        <span class="mdui-chip" style="font-size:11px;background:<?= $m['status'] ? '#e8f5e9;color:#1b8a5a' : '#eee;color:#888' ?>;border-radius:20px;padding:2px 10px;margin-left:8px;"><?= $m['status'] ? '启用' : '停用' ?></span>
      </div>
      <div style="font-size:13px;color:#3700b3;font-weight:700;">余额 ¥ <?= number_format($m['balance'] / 100, 2) ?></div>
    </div>

    <div style="margin-top:12px;font-size:13px;line-height:2;">
      <div>商户ID(pid)：<code style="user-select:all;background:#f4f2ff;padding:2px 8px;border-radius:6px;"><?= htmlspecialchars($m['pid']) ?></code></div>
      <div>MD5密钥：<code style="user-select:all;background:#f4f2ff;padding:2px 8px;border-radius:6px;"><?= htmlspecialchars($m['key_plain']) ?></code>
        <button type="button" class="mdui-chip" onclick="copyText(this,'<?= htmlspecialchars($m['key_plain']) ?>')" style="font-size:11px;margin-left:6px;">复制</button>
      </div>
      <div style="opacity:.6;">创建于 <?= htmlspecialchars($m['created_at']) ?><?= $m['remark'] ? ' · ' . htmlspecialchars($m['remark']) : '' ?></div>
    </div>

    <div style="margin-top:12px;display:flex;gap:8px;flex-wrap:wrap;">
      <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="reset_key">
        <input type="hidden" name="pid" value="<?= htmlspecialchars($m['pid']) ?>">
        <mdui-button type="submit" variant="tonal" icon="key--outlined" onclick="return confirm('确认重置该商户密钥？旧密钥将立即失效。')">重置密钥</mdui-button>
      </form>
      <form method="post" style="display:inline;">
        <input type="hidden" name="action" value="toggle">
        <input type="hidden" name="pid" value="<?= htmlspecialchars($m['pid']) ?>">
        <mdui-button type="submit" variant="text" icon="<?= $m['status'] ? 'pause--outlined' : 'play_arrow--outlined' ?>"><?= $m['status'] ? '停用' : '启用' ?></mdui-button>
      </form>
    </div>
  </mdui-card>
<?php endforeach; endif; ?>

<div style="font-size:12px;opacity:.55;line-height:1.8;margin-top:8px;">
  对接说明：第三方系统（任何支持易支付的程序）将收款接口地址设为 <code>https://auth.sanhe.com.mp/mapi.php</code>（API下单）或 <code>submit.php</code>（页面跳转），
  商户ID填入上方 pid，密钥填入 MD5 密钥即可，协议与彩虹易支付 V1 完全兼容，无需修改代码。
</div>

<script>
// 复制走全局兼容函数（theme.js 提供：Clipboard API + WebView 回退）
</script>

<?php
contentClose();
echo '</div>';
pageFoot();
