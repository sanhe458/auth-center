<?php
/**
 * 管理后台 · 用户管理
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$admin = requireAdminPage();
$db = db();

// 操作：禁用/启用/设管理员/撤销管理员/余额调整/积分调整
$action = $_POST['action'] ?? '';
$uid = (int)($_POST['uid'] ?? 0);
if ($action && $uid) {
    // 不能操作自己（禁用/管理权限变更；余额/积分调整允许）
    if ($uid === (int)$admin['id'] && !in_array($action, ['balance_adjust', 'points_adjust'], true)) {
        $msg = '不能修改自己的账号';
    } else {
        switch ($action) {
            case 'balance_adjust':
                require_once __DIR__ . '/../api/controllers/balance.php';
                $delta = (int)($_POST['delta_fen'] ?? 0);
                if ($delta === 0) {
                    $msg = '调整金额需非 0';
                } else {
                    try {
                        balanceChange($uid, 'admin_adjust', $delta, '', $_POST['remark'] ?? '管理员调整');
                        $msg = '已调整余额';
                    } catch (Throwable $e) {
                        $msg = '调整失败：' . $e->getMessage();
                    }
                }
                break;
            case 'points_adjust':
                require_once __DIR__ . '/../api/controllers/points.php';
                $delta = (int)($_POST['delta_points'] ?? 0);
                if ($delta === 0) {
                    $msg = '调整积分需非 0';
                } else {
                    try {
                        pointsChange($uid, 'admin_adjust', $delta, '', $_POST['remark'] ?? '管理员调整');
                        $msg = '已调整积分';
                    } catch (Throwable $e) {
                        $msg = '调整失败：' . $e->getMessage();
                    }
                }
                break;
            case 'disable':
                $db->prepare('UPDATE users SET status = 0 WHERE id = ?')->execute([$uid]);
                // 同时吊销该用户所有令牌
                $db->prepare('UPDATE oauth_tokens SET revoked = 1 WHERE user_id = ?')->execute([$uid]);
                $msg = '已禁用该用户';
                break;
            case 'enable':
                $db->prepare('UPDATE users SET status = 1 WHERE id = ?')->execute([$uid]);
                $msg = '已启用该用户';
                break;
            case 'setadmin':
                $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['admin', $uid]);
                $msg = '已设为管理员';
                break;
            case 'unsetadmin':
                $db->prepare('UPDATE users SET role = ? WHERE id = ?')->execute(['user', $uid]);
                $msg = '已取消管理员';
                break;
        }
    }
    header('Location: users.php' . ($msg ? '?msg=' . urlencode($msg) : ''));
    exit;
}
$msg = $_GET['msg'] ?? '';

// 搜索
$kw = trim($_GET['q'] ?? '');
$sql = 'SELECT id, uid, nickname, email, role, status, balance, points, created_at FROM users';
$args = [];
if ($kw) {
    $sql .= ' WHERE nickname LIKE ? OR email LIKE ?';
    $args = ["%$kw%", "%$kw%"];
}
$sql .= ' ORDER BY created_at DESC LIMIT 100';
$st = $db->prepare($sql);
$st->execute($args);
$users = $st->fetchAll();

// 每个用户的统计
$appCnt = $db->prepare('SELECT owner_id, COUNT(*) c FROM apps GROUP BY owner_id');
$appCnt->execute();
$appCntMap = array_column($appCnt->fetchAll(), 'c', 'owner_id');
$tokCnt = $db->prepare('SELECT user_id, COUNT(*) c FROM oauth_tokens WHERE revoked = 0 GROUP BY user_id');
$tokCnt->execute();
$tokCntMap = array_column($tokCnt->fetchAll(), 'c', 'user_id');

pageHead('用户管理', '<link rel="stylesheet" href="/css/user.css">');
pageNav($admin);
echo '<div class="shell">';
adminSidebar('users');
contentOpen('用户管理', '全部注册用户');
?>
    <?php if ($msg): ?><mdui-alert severity="success" icon="check_circle--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($msg) ?></mdui-alert><?php endif; ?>

    <form method="GET" style="margin-bottom:16px;">
      <div style="display:flex; gap:10px; max-width:420px;">
        <mdui-text-field name="q" label="搜索昵称或邮箱" value="<?= htmlspecialchars($kw) ?>" icon="search--outlined" clearable full-width></mdui-text-field>
        <mdui-button variant="filled" icon="search--outlined" type="submit">搜索</mdui-button>
      </div>
    </form>

    <mdui-list>
      <?php foreach ($users as $u): $isAdmin = $u['role'] === 'admin'; $active = (int)$u['status'] === 1; ?>
      <mdui-list-item nonclickable>
        <mdui-avatar slot="icon" style="--mdui-avatar-size:38px; border-radius:12px;"><img src="/avatar.php?n=<?= rawurlencode($u['nickname']) ?>&s=<?= rawurlencode($u['uid']) ?>&size=76" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:12px;"></mdui-avatar>
        <div>
          <?= htmlspecialchars($u['nickname']) ?>
          <?php if ($isAdmin): ?><mdui-badge color="primary">管理员</mdui-badge><?php endif; ?>
          <?php if (!$active): ?><mdui-badge color="error">已禁用</mdui-badge><?php endif; ?>
        </div>
        <span slot="description" style="font-size:12px;">
          <?= htmlspecialchars($u['email']) ?> · 注册于 <?= substr($u['created_at'], 0, 10) ?>
          · <?= (int)($appCntMap[$u['id']] ?? 0) ?> 应用 / <?= (int)($tokCntMap[$u['id']] ?? 0) ?> 令牌
          · 余额 ¥<?= number_format((int)$u['balance'] / 100, 2) ?> · 积分 <?= number_format((int)$u['points']) ?>
        </span>
        <?php if ((int)$u['id'] !== (int)$admin['id']): ?>
        <div slot="end-icon" style="display:flex; gap:4px; align-items:center;">
          <mdui-button variant="text" color="tertiary" style="font-size:12px;"
            onclick="var r=prompt('输入调整金额（元，负数扣减，0取消）'); if(r!==null&&r!==''){var f=document.createElement('form');f.method='POST';var h=[['action','balance_adjust'],['uid','<?= (int)$u['id'] ?>'],['delta_fen',Math.round(parseFloat(r)*100)],['remark','管理员调整']];for(var i=0;i<h.length;i++){var i2=document.createElement('input');i2.type='hidden';i2.name=h[i][0];i2.value=h[i][1];f.appendChild(i2);}document.body.appendChild(f);f.submit();}">调整余额</mdui-button>
          <mdui-button variant="text" color="tertiary" style="font-size:12px;"
            onclick="var r=prompt('输入调整积分（正数增加，负数扣减，0取消）'); if(r!==null&&r!==''){var f=document.createElement('form');f.method='POST';var h=[['action','points_adjust'],['uid','<?= (int)$u['id'] ?>'],['delta_points',parseInt(r)],['remark','管理员调整']];for(var i=0;i<h.length;i++){var i2=document.createElement('input');i2.type='hidden';i2.name=h[i][0];i2.value=h[i][1];f.appendChild(i2);}document.body.appendChild(f);f.submit();}">调整积分</mdui-button>
          <?php if ($isAdmin): ?>
          <form method="POST" onsubmit="return confirm('取消该用户的管理员权限？');">
            <input type="hidden" name="action" value="unsetadmin"><input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
            <mdui-button variant="text" color="primary" type="submit" style="font-size:12px;">取消管理员</mdui-button>
          </form>
          <?php else: ?>
          <form method="POST" onsubmit="return confirm('将该用户设为管理员？');">
            <input type="hidden" name="action" value="setadmin"><input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
            <mdui-button variant="text" type="submit" style="font-size:12px;">设为管理员</mdui-button>
          </form>
          <?php endif; ?>
          <?php if ($active): ?>
          <form method="POST" onsubmit="return confirm('禁用该用户？其所有令牌将立即失效。');">
            <input type="hidden" name="action" value="disable"><input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
            <mdui-button variant="text" color="error" type="submit" style="font-size:12px;">禁用</mdui-button>
          </form>
          <?php else: ?>
          <form method="POST">
            <input type="hidden" name="action" value="enable"><input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
            <mdui-button variant="text" color="tertiary" type="submit" style="font-size:12px;">启用</mdui-button>
          </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>
      </mdui-list-item>
      <?php endforeach; ?>
    </mdui-list>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>