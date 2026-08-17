<?php
/**
 * 统一权限（Scope）定义
 * ------------------------------------------------
 * 所有权限相关的集中配置都在这里。
 * 新增一个权限只需在 scopeDefs() 里加一行：
 *   'xxxx' => ['名称', '描述'],
 * 创建/编辑应用的表单、授权确认页、校验白名单会自动生效，
 * 无需再改其他文件。
 * 若还需要在授权列表(用户/后台)显示短名称，另在 scopeLabels() 加一行。
 */

/**
 * 完整权限定义：key => [标题, 描述]
 * 用于：创建/编辑应用时的权限勾选项、授权确认页的权限说明
 */
function scopeDefs(): array
{
    return [
        'basic'  => ['查看基本信息', '头像、昵称、用户 ID，用于展示登录状态（默认必选）'],
        'notify' => ['发送通知', '向用户设备推送登录与安全提醒'],
    ];
}

/**
 * 权限短标签：key => 短名称
 * 用于：用户/后台的授权列表展示（空间有限的地方用短名）
 */
function scopeLabels(): array
{
    return [
        'basic'  => '基本信息',
        'notify' => '通知',
    ];
}

/**
 * 合法权限白名单（自动取自 scopeDefs 的 key）
 */
function scopeAllowed(): array
{
    return array_keys(scopeDefs());
}

/**
 * 校验并规范权限数组：
 *  - 过滤掉白名单之外的非法权限
 *  - 保证始终包含 basic（默认必选）
 */
function sanitizeScopes(array $scopes): array
{
    $allowed = scopeAllowed();
    $out = array_values(array_unique(array_filter($scopes, fn($s) => in_array($s, $allowed, true))));
    if (!in_array('basic', $out, true)) {
        $out[] = 'basic';
    }
    return $out;
}
