-- 应用余额系统（D+1 结算）
-- 账户按用户维度，单用户所有应用收汇到同一个应用余额账户。
-- 应用余额 = 可提现(withdrawable) + 不可提现(pending)
--  收款 → pending → 满24h(D+1) → withdrawable → 提现 → users.balance(通用余额)

-- 应用余额账户（每用户一个）
CREATE TABLE IF NOT EXISTS `app_balances` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '归属用户',
  `withdrawable` bigint(20) NOT NULL DEFAULT 0 COMMENT '可提现（分）',
  `pending` bigint(20) NOT NULL DEFAULT 0 COMMENT '不可提现/待结算（分）',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='应用余额账户';

-- 待结算明细（每笔收款；满24h后结算进 withdrawable）
CREATE TABLE IF NOT EXISTS `app_balance_pending` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '归属用户',
  `amount` bigint(20) NOT NULL COMMENT '本笔金额（分）',
  `trade_no` varchar(40) DEFAULT '' COMMENT '关联平台订单号',
  `source` varchar(20) NOT NULL DEFAULT 'pay' COMMENT '来源: pay收款',
  `settled` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0待结算 1已结算',
  `settled_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_settle` (`user_id`,`settled`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='应用余额待结算明细';

-- 应用余额流水（含收款/结算/提现）
CREATE TABLE IF NOT EXISTS `app_balance_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `type` varchar(20) NOT NULL COMMENT 'income收款到pending / settle结算 pending→可提现 / withdraw提现',
  `amount` bigint(20) NOT NULL COMMENT '变动（分，正入负出）',
  `withdrawable_after` bigint(20) NOT NULL DEFAULT 0 COMMENT '变动后可提现余额',
  `pending_after` bigint(20) NOT NULL DEFAULT 0 COMMENT '变动后不可提现余额',
  `reference` varchar(64) DEFAULT '' COMMENT '关联单号',
  `remark` varchar(255) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='应用余额流水';
