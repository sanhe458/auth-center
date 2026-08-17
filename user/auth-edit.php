<?php
/**
 * 授权权限管理（服务端渲染）
 * 允许用户调整某个已授权应用的具体权限。
 *  - 只显示应用申请的权限（app_scopes）
 *  - basic(基本信息) 必选不可取消，且始终排在最前
 * 权限变更会同步到：authorizations.scopes、oauth_tokens.scopes、Redis 缓存
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/redis.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/lib/scopes.php';

$user = requireLoginPage();
$db = db();
$authId = (int)($_GET['id'] ?? ($_POST['authorization_id'] ?? 0));

// 取授权记录（必须属于当前用户且处于已授权状态）
$st = $db->prepare('SELECT z.id, z.app_id, z.scopes AS granted, a.name AS app_name,
                    a.client_id, a.description, a.icon FROM authorizations z
                    JOIN apps a ON a.id = z.app_id
                    WHERE z.id = ? AND z.user_id = ? LIMIT 1');
$st->execute([$authId, $user['id']]);
$auth = $st->fetch();
if (!$auth) {
    header('Location: auth.php');
    exit;
}

// 应用申请的所有权限（只显示这些）
$st = $db->prepare('SELECT scope FROM app_scopes WHERE app_id = ?');
$st->execute([$auth['app_id']]);
$appScopes = array_column($st->fetchAll(), 'scope');
// 保证 basic 一定在应用权限里（应用都要 basic）
if (!in_array('basic', $appScopes, true)) $appScopes[] = 'basic';

$defs = scopeDefs();

// 提交更新
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $picked = (array)($_POST['scopes'] ?? []);
    // 只保留应用申请过的权限；basic 必选且排最前
    $picked = array_values(array_unique(array_filter($picked, fn($s) => in_array($s, $appScopes, true))));
    if (!in_array('basic', $picked, true)) array_unshift($picked, 'basic');
    $newScopes = implode(',', $picked);

    $db->beginTransaction();
    try {
        // 更新授权关系
        $db->prepare('UPDATE authorizations SET scopes = ?, updated_at = NOW() WHERE id = ? AND user_id = ?')
           ->execute([$newScopes, $authId, $user['id']]);
        // 同步该应用下该用户的全部令牌 scope
        $db->prepare('UPDATE oauth_tokens SET scopes = ? WHERE user_id = ? AND app_id = ? AND revoked = 0')
           ->execute([$newScopes, $user['id'], $auth['app_id']]);
        // 清掉对应令牌的 Redis 缓存（key 直接用 token hash）
        $toks = $db->prepare('SELECT access_token_hash FROM oauth_tokens WHERE user_id = ? AND app_id = ?');
        $toks->execute([$user['id'], $auth['app_id']]);
        foreach ($toks->fetchAll() as $t) {
            redis()->del(rk('tok:' . $t['access_token_hash']));
        }
        $db->commit();
    } catch (Throwable $e) {
        $db->rollBack();
        throw $e;
    }
    header('Location: auth.php?updated=1');
    exit;
}

$granted = explode(',', $auth['granted']);

pageHead('权限管理', '<link rel="stylesheet" href="/css/user.css">');
pageNav($user);
echo '<div class="shell">';
pageSidebar('auth');
contentOpen('权限管理', '管理你对「' . htmlspecialchars($auth['app_name']) . '」的授权权限');
?>
    <mdui-card variant="elevated" style="border-radius:16px; padding:20px;">
      <div style="display:flex; align-items:center; gap:14px; margin-bottom:6px;">
        <?php if (!empty($auth['icon'])): ?>
        <mdui-avatar style="--mdui-avatar-size:44px; border-radius:14px;">
          <img src="<?= htmlspecialchars($auth['icon']) ?>" alt="" style="width:100%;height:100%;object-fit:cover;border-radius:14px;">
        </mdui-avatar>
        <?php else: ?>
        <mdui-avatar style="--mdui-avatar-size:44px; border-radius:14px; background:linear-gradient(135deg,#ffb74d,#ff7043); color:#3a1d00; font-weight:800;">
          <?= htmlspecialchars(mb_substr($auth['app_name'], 0, 1)) ?>
        </mdui-avatar>
        <?php endif; ?>
        <div>
          <div style="font-size:17px; font-weight:700;"><?= htmlspecialchars($auth['app_name']) ?></div>
          <div style="font-size:12px; opacity:.55;"><?= htmlspecialchars($auth['description']) ?></div>
        </div>
      </div>
      <div style="font-size:12px; opacity:.55; margin:8px 0 16px;">client_id: <code><?= htmlspecialchars($auth['client_id']) ?></code></div>

      <form method="POST">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="authorization_id" value="<?= (int)$authId ?>">
        <div class="sec-title" style="margin:0 0 10px;">授权权限</div>
        <div style="font-size:12px; opacity:.55; margin-bottom:12px;">以下为该应用申请的全部权限，可自行勾选授予或收回；「查看基本信息」为必选。</div>
        <?php foreach ($appScopes as $s): $label = $defs[$s] ?? [$s, '']; $isBasic = ($s === 'basic'); $checked = in_array($s, $granted, true); ?>
        <div class="scope-line" style="display:flex; align-items:center; gap:12px; padding:12px 14px; border:1px solid rgba(128,128,128,.15); border-radius:12px; margin-bottom:10px;">
          <input type="checkbox" name="scopes[]" value="<?= htmlspecialchars($s) ?>"
                 <?= $checked ? 'checked' : '' ?>
                 <?= $isBasic ? 'disabled' : '' ?>
                 style="width:17px;height:17px;accent-color:#ffa726; flex-shrink:0;">
          <div style="flex:1;">
            <div style="font-size:14px; font-weight:600;">
              <?= htmlspecialchars($label[0]) ?>
              <?php if ($isBasic): ?><mdui-badge color="tertiary" style="margin-left:4px;">必选</mdui-badge><?php endif; ?>
            </div>
            <div style="font-size:12px; opacity:.6; margin-top:2px;"><?= htmlspecialchars($label[1]) ?></div>
          </div>
          <?php if ($isBasic): ?><mdui-badge>已授予</mdui-badge><?php endif; ?>
        </div>
        <?php endforeach; ?>

        <div style="display:flex; gap:12px; margin-top:20px;">
          <mdui-button variant="filled" icon="save--outlined" type="submit">保存修改</mdui-button>
          <mdui-button variant="text" onclick="location.href='auth.php'">返回</mdui-button>
        </div>
      </form>
    </mdui-card>
<?php
contentClose();
echo '</div>';
pageFoot();
