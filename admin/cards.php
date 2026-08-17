<?php
/**
 * 管理后台 · 充值卡密管理
 * 批量生成卡密 + 查看未使用/已使用列表
 */
require_once __DIR__ . '/../api/lib/db.php';
require_once __DIR__ . '/../api/lib/helpers.php';
require_once __DIR__ . '/../api/lib/page.php';
require_once __DIR__ . '/../api/controllers/balance.php';

$admin = requireAdminPage();
$db = db();

// 批量生成
$genAction = $_POST['action'] ?? '';
$genMsg = '';
if ($genAction === 'generate') {
    $count = max(1, min(1000, (int)($_POST['count'] ?? 1)));
    $amountYuan = (float)($_POST['amount_yuan'] ?? 0);
    try {
        $res = adminCardsGenerate([
            'count' => $count,
            'amount_yuan' => $amountYuan,
            'admin_id' => (int)$admin['id'],
        ]);
        $genMsg = '成功生成 ' . count($res['codes']) . ' 张卡密，批次 ' . $res['batch'] . '，面值 ¥' . $res['amount_yuan'];
        $genCodes = $res['codes'];
    } catch (Throwable $e) {
        $genMsg = '生成失败：' . $e->getMessage();
    }
}

// 作废
if ($genAction === 'void') {
    $cid = (int)($_POST['card_id'] ?? 0);
    $db->prepare('UPDATE recharge_cards SET status = 2 WHERE id = ? AND status = 0')->execute([$cid]);
    $genMsg = '已作废该卡密';
    header('Location: cards.php?msg=' . urlencode($genMsg));
    exit;
}

// 列表筛选
$status = $_GET['status'] ?? 'unused'; // unused / used / void / all
$sql = 'SELECT c.*, u.nickname AS used_nickname FROM recharge_cards c LEFT JOIN users u ON u.id = c.used_by';
$conds = [];
$args = [];
switch ($status) {
    case 'used':  $conds[] = 'c.status = 1'; break;
    case 'void':  $conds[] = 'c.status = 2'; break;
    case 'all':   break;
    default: $status = 'unused'; $conds[] = 'c.status = 0';
}
if ($conds) $sql .= ' WHERE ' . implode(' AND ', $conds);
$sql .= ' ORDER BY c.id DESC LIMIT 200';
$st = $db->prepare($sql);
$st->execute($args);
$cards = $st->fetchAll();

pageHead('卡密管理', '<link rel="stylesheet" href="/css/user.css">');
pageNav($admin);
echo '<div class="shell">';
adminSidebar('cards');
contentOpen('卡密管理', '批量生成充值卡密，用户可兑换余额');
$msg = $_GET['msg'] ?? $genMsg;
?>
    <?php if ($msg): ?><mdui-alert severity="success" icon="check_circle--outlined" style="margin-bottom:14px;"><?= htmlspecialchars($msg) ?></mdui-alert><?php endif; ?>

    <!-- 批量生成 -->
    <mdui-card variant="elevated" style="border-radius:16px; padding:20px; margin-bottom:20px;">
      <div class="sec-title" style="margin:0 0 12px;">批量生成卡密</div>
      <form method="POST">
        <input type="hidden" name="action" value="generate">
        <div style="display:flex; gap:14px; flex-wrap:wrap; align-items:flex-end;">
          <mdui-text-field name="count" label="生成数量" type="number" min="1" max="1000" value="10" style="width:120px;" required></mdui-text-field>
          <mdui-text-field name="amount_yuan" label="单张面值（元）" type="number" min="0.01" step="0.01" value="10" style="width:160px;" required></mdui-text-field>
          <mdui-button variant="filled" icon="card_membership--outlined" type="submit">生成</mdui-button>
        </div>
      </form>
      <?php if (!empty($genCodes)): ?>
      <div style="margin-top:14px; font-size:13px;">
        <div style="margin-bottom:6px; opacity:.7;">生成的卡密：</div>
        <textarea readonly style="width:100%; height:120px; font-family:monospace; font-size:13px; padding:10px; border-radius:8px; border:1px solid #d7dee8; box-sizing:border-box; resize:vertical;"><?= htmlspecialchars(implode("\n", $genCodes)) ?></textarea>
      </div>
      <?php endif; ?>
    </mdui-card>

    <!-- 列表 -->
    <div style="display:flex; gap:8px; margin-bottom:14px; flex-wrap:wrap;">
      <?php foreach (['unused'=>'未使用','used'=>'已使用','void'=>'已作废','all'=>'全部'] as $k=>$lb): ?>
      <mdui-button variant="<?= $status === $k ? 'filled' : 'text' ?>" size="small" onclick="location.href='cards.php?status=<?= $k ?>'"><?= $lb ?></mdui-button>
      <?php endforeach; ?>
    </div>

    <mdui-list>
      <?php if (!$cards): ?>
      <mdui-list-item nonclickable>暂无<?= $status === 'unused' ? '未使用' : '' ?>卡密</mdui-list-item>
      <?php else: foreach ($cards as $c): ?>
      <?php $stMap = [0=>['未使用','tertiary'],1=>['已使用','primary'],2=>['已作废','error']]; [$lb,$co] = $stMap[(int)$c['status']]; ?>
      <mdui-list-item nonclickable>
        <mdui-icon slot="icon" name="card_membership--outlined"></mdui-icon>
        <div style="font-family:monospace; letter-spacing:.5px;"><?= htmlspecialchars($c['code']) ?></div>
        <span slot="description" style="font-size:12px;">
          ¥<?= number_format($c['amount_fen']/100,2) ?>
          <?= $c['used_nickname'] ? ' · 已被 ' . htmlspecialchars($c['used_nickname']) . ' 使用于 ' . substr($c['used_at'],0,16) : '' ?>
          · 批次 <?= htmlspecialchars($c['batch']) ?>
        </span>
        <mdui-badge slot="end-icon" color="<?= $co ?>"><?= $lb ?></mdui-badge>
        <?php if ((int)$c['status'] === 0): ?>
        <form slot="end-icon" method="POST" onsubmit="return confirm('确定作废该卡密？');" style="margin-left:8px;">
          <input type="hidden" name="action" value="void"><input type="hidden" name="card_id" value="<?= (int)$c['id'] ?>">
          <mdui-button variant="text" color="error" type="submit" style="font-size:12px;">作废</mdui-button>
        </form>
        <?php endif; ?>
      </mdui-list-item>
      <?php endforeach; endif; ?>
    </mdui-list>
<?php
contentClose();
echo '</div>';
pageFoot(); ?>