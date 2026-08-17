<?php
/**
 * 绑定渠道页：管理第三方登录绑定（GitHub 等）
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';

$user = requireLoginPage();

// 解绑 GitHub
if (($_POST['action'] ?? '') === 'unbind-github') {
    db()->prepare('UPDATE users SET github_id = NULL WHERE id = ?')->execute([$user['id']]);
    header('Location: bindings.php?msg=unbound');
    exit;
}

// 解绑彩虹渠道（qq/wx 等）
$unbindMap = ['unbind-qq' => 'qq', 'unbind-wx' => 'wx', 'unbind-gitee' => 'gitee'];
$act = $_POST['action'] ?? '';
if (isset($unbindMap[$act])) {
    db()->prepare('DELETE FROM social_bindings WHERE user_id = ? AND provider = ?')
        ->execute([$user['id'], $unbindMap[$act]]);
    header('Location: bindings.php?msg=unbound');
    exit;
}

$msg  = $_GET['msg'] ?? '';
$ghErr = $_GET['error'] ?? '';

// 用户的第三方绑定（彩虹聚合）
$st = db()->prepare('SELECT provider, social_uid, nickname FROM social_bindings WHERE user_id = ?');
$st->execute([$user['id']]);
$socials = [];
foreach ($st->fetchAll() as $row) {
    $socials[$row['provider']] = $row;
}

pageHead('绑定渠道', '<link rel="stylesheet" href="/css/user.css?v=20260817">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('bindings');
contentOpen('绑定渠道', '关联第三方账号，用它们快速登录');
?>
    <?php if ($msg === 'bound'): ?><mdui-alert severity="success" icon="check_circle--outlined" style="margin-bottom:14px;">✅ 已成功绑定 GitHub 账号</mdui-alert><?php endif; ?>
    <?php if ($msg === 'unbound'): ?><mdui-alert severity="success" icon="check_circle--outlined" style="margin-bottom:14px;">已解绑 GitHub</mdui-alert><?php endif; ?>
    <?php if ($ghErr === 'gh_used'): ?><mdui-alert severity="error" icon="error--outlined" style="margin-bottom:14px;">该 GitHub 账号已被其他用户绑定</mdui-alert><?php endif; ?>

    <div class="sec-title" style="margin:0 0 12px;">第三方登录</div>
    <mdui-card variant="elevated" style="border-radius:16px;">
      <mdui-list>
        <!-- GitHub -->
        <mdui-list-item nonclickable>
          <mdui-avatar slot="icon" style="--mdui-avatar-size:38px; border-radius:12px; background:#24292f;"><img src="/icons/github.png" style="width:20px;height:20px;border-radius:50%;" alt=""></mdui-avatar>
          GitHub
          <span slot="description" style="font-size:12px;">
            <?php if ($user['github_id']): ?>
            已绑定 · ID <?= htmlspecialchars($user['github_id']) ?>
            <?php else: ?>
            未绑定 · 绑定后可用 GitHub 一键登录
            <?php endif; ?>
          </span>
          <?php if ($user['github_id']): ?>
          <form slot="end-icon" method="POST" onsubmit="return confirm('确定解绑 GitHub 吗？之后需重新绑定才能用 GitHub 登录。');">
            <input type="hidden" name="action" value="unbind-github">
            <mdui-button variant="text" color="error" type="submit" style="font-size:12px;">解绑</mdui-button>
          </form>
          <?php else: ?>
          <mdui-button slot="end-icon" variant="text" color="tertiary" icon="link--outlined" onclick="location.href='/api/oauth/github'" style="font-size:12px;">绑定</mdui-button>
          <?php endif; ?>
        </mdui-list-item>

        <!-- QQ -->
        <mdui-list-item nonclickable>
          <mdui-avatar slot="icon" style="--mdui-avatar-size:38px; border-radius:12px; background:linear-gradient(135deg,#12b7f5,#0a87d4);"><img src="/icons/qq.png" style="width:20px;height:20px;border-radius:6px;" alt=""></mdui-avatar>
          QQ
          <span slot="description" style="font-size:12px;">
            <?php if (isset($socials['qq'])): ?>已绑定 · <?= htmlspecialchars($socials['qq']['nickname']) ?><?php else: ?>未绑定<?php endif; ?>
          </span>
          <?php if (isset($socials['qq'])): ?>
          <form slot="end-icon" method="POST" onsubmit="return confirm('确定解绑 QQ 吗？');">
            <input type="hidden" name="action" value="unbind-qq">
            <mdui-button variant="text" color="error" type="submit" style="font-size:12px;">解绑</mdui-button>
          </form>
          <?php else: ?>
          <mdui-button slot="end-icon" variant="text" color="tertiary" icon="link--outlined" onclick="location.href='/api/oauth/rainbow?type=qq'" style="font-size:12px;">绑定</mdui-button>
          <?php endif; ?>
        </mdui-list-item>

        <!-- 微信 -->
        <mdui-list-item nonclickable>
          <mdui-avatar slot="icon" style="--mdui-avatar-size:38px; border-radius:12px; background:linear-gradient(135deg,#07c160,#059a4c);"><img src="/icons/wechat.png" style="width:20px;height:20px;border-radius:50%;" alt=""></mdui-avatar>
          微信
          <span slot="description" style="font-size:12px;">
            <?php if (isset($socials['wx'])): ?>已绑定 · <?= htmlspecialchars($socials['wx']['nickname']) ?><?php else: ?>未绑定<?php endif; ?>
          </span>
          <?php if (isset($socials['wx'])): ?>
          <form slot="end-icon" method="POST" onsubmit="return confirm('确定解绑微信吗？');">
            <input type="hidden" name="action" value="unbind-wx">
            <mdui-button variant="text" color="error" type="submit" style="font-size:12px;">解绑</mdui-button>
          </form>
          <?php else: ?>
          <mdui-button slot="end-icon" variant="text" color="tertiary" icon="link--outlined" onclick="location.href='/api/oauth/rainbow?type=wx'" style="font-size:12px;">绑定</mdui-button>
          <?php endif; ?>
        </mdui-list-item>

        <!-- Gitee -->
        <mdui-list-item nonclickable>
          <mdui-avatar slot="icon" style="--mdui-avatar-size:38px; border-radius:12px; background:linear-gradient(135deg,#de6d2c,#c71d23);"><img src="/icons/gitee.png" style="width:20px;height:20px;border-radius:6px;" alt=""></mdui-avatar>
          Gitee
          <span slot="description" style="font-size:12px;">
            <?php if (isset($socials['gitee'])): ?>已绑定 · <?= htmlspecialchars($socials['gitee']['nickname']) ?><?php else: ?>未绑定<?php endif; ?>
          </span>
          <?php if (isset($socials['gitee'])): ?>
          <form slot="end-icon" method="POST" onsubmit="return confirm('确定解绑 Gitee 吗？');">
            <input type="hidden" name="action" value="unbind-gitee">
            <mdui-button variant="text" color="error" type="submit" style="font-size:12px;">解绑</mdui-button>
          </form>
          <?php else: ?>
          <mdui-button slot="end-icon" variant="text" color="tertiary" icon="link--outlined" onclick="location.href='/api/oauth/rainbow?type=gitee'" style="font-size:12px;">绑定</mdui-button>
          <?php endif; ?>
        </mdui-list-item>
      </mdui-list>
    </mdui-card>

    <div class="hint" style="margin-top:16px;">
      绑定后，登录页点击对应图标即可快速登录，无需再输密码。
    </div>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>