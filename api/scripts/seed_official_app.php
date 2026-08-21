<?php
/**
 * 内置官方安卓客户端（seed 脚本，幂等可重复执行）
 * -------------------------------------------------
 * Auth Center 自带一个官方安卓应用，App 开箱即用无需注册：
 *   client_id      = authcenter_android
 *   callback_url   = authcenter://callback
 *   scopes         = basic + notify
 *   owner          = 管理员（sanhe458@qq.com）
 *
 * 用法：php api/scripts/seed_official_app.php
 * 注意：client_secret 明文只存在于本脚本注释/输出与打包的 App 内，
 *       数据库只存 HMAC hash（与普通应用一致）。
 */
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';

const OFFICIAL_CLIENT_ID  = 'authcenter_android';
// 打包进安卓 App 的明文 secret（App 内置 client_secret，自用/内部场景可接受）
const OFFICIAL_SECRET     = 'sk-gIByhNMcdkCETnJUs9ymbcHYyITgbUJq';
const OFFICIAL_CALLBACK   = 'authcenter://callback';
const OFFICIAL_SCOPES     = ['basic', 'notify'];

$db = db();

// 归属管理员（第一个 role=admin 的用户）
$owner = $db->prepare('SELECT id FROM users WHERE role = ? ORDER BY id ASC LIMIT 1');
$owner->execute(['admin']);
$ownerId = $owner->fetchColumn();
if (!$ownerId) {
    fwrite(STDERR, "未找到管理员账号，请先创建用户\n");
    exit(1);
}

// 幂等：已存在则更新，不存在则插入
$exist = $db->prepare('SELECT id FROM apps WHERE client_id = ? LIMIT 1');
$exist->execute([OFFICIAL_CLIENT_ID]);
$appId = $exist->fetchColumn();

if ($appId) {
    $upd = $db->prepare('UPDATE apps SET client_secret_hash = ?, owner_id = ?, name = ?, description = ?, callback_url = ?, status = 2, updated_at = NOW() WHERE id = ?');
    $upd->execute([hashSecret(OFFICIAL_SECRET), $ownerId, 'AuthCenter 安卓客户端', '官方安卓客户端（系统内置，无需注册）', OFFICIAL_CALLBACK, $appId]);
    echo "已更新内置应用 id={$appId}\n";
} else {
    $ins = $db->prepare('INSERT INTO apps (client_id, client_secret_hash, owner_id, name, description, callback_url, homepage, status) VALUES (?,?,?,?,?,?,?,2)');
    $ins->execute([OFFICIAL_CLIENT_ID, hashSecret(OFFICIAL_SECRET), $ownerId, 'AuthCenter 安卓客户端', '官方安卓客户端（系统内置，无需注册）', OFFICIAL_CALLBACK, '']);
    $appId = (int)$db->lastInsertId();
    echo "已创建内置应用 id={$appId}\n";
}

// 同步 scopes（先清后插，保持幂等）
$db->prepare('DELETE FROM app_scopes WHERE app_id = ?')->execute([$appId]);
$si = $db->prepare('INSERT INTO app_scopes (app_id, scope) VALUES (?,?)');
foreach (OFFICIAL_SCOPES as $s) $si->execute([$appId, $s]);
echo 'scopes: ' . implode(',', OFFICIAL_SCOPES) . "\n";

echo "client_id:   " . OFFICIAL_CLIENT_ID . "\n";
echo "callback:    " . OFFICIAL_CALLBACK . "\n";
echo "完成 ✅\n";
