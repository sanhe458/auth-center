<?php
/**
 * D+1 结算 CLI：把所有满 24 小时的待结算明细滚进可提现
 * 用法：php /var/www/auth.sanhe.com.mp/api/scripts/settle_d1.php
 * crontab 建议：每小时执行一次（满 24h 才结算）
 */
require_once __DIR__ . '/../lib/db.php';
require_once __DIR__ . '/../lib/helpers.php';
require_once __DIR__ . '/../lib/app_balance.php';

$n = appBalanceSettleD1();
echo '[' . date('Y-m-d H:i:s') . "] D+1 结算完成，共 {$n} 笔\n";
