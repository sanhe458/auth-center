-- 易支付兼容：商户表 + 收款单表
-- 商户 = 一个对接方（第三方系统 或 平台自用），分配 pid + MD5 key（明文，易支付风格）

CREATE TABLE IF NOT EXISTS `pay_merchants` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `pid` varchar(20) NOT NULL COMMENT '商户号（易支付 pid，数字字符串，唯一）',
  `name` varchar(80) NOT NULL COMMENT '商户名称',
  `key_plain` varchar(64) NOT NULL COMMENT 'MD5 密钥（明文，易支付可复制风格）',
  `balance` bigint(20) NOT NULL DEFAULT 0 COMMENT '商户收款余额（分）',
  `notify_url` varchar(500) DEFAULT '' COMMENT '默认异步回调地址（下单可覆盖）',
  `return_url` varchar(500) DEFAULT '' COMMENT '默认同步跳转地址',
  `status` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1启用 0停用',
  `remark` varchar(255) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_pid` (`pid`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='易支付商户';

CREATE TABLE IF NOT EXISTS `pay_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pid` varchar(20) NOT NULL COMMENT '商户号',
  `out_trade_no` varchar(64) NOT NULL COMMENT '商户订单号',
  `trade_no` varchar(40) NOT NULL COMMENT '平台订单号（唯一）',
  `type` varchar(16) DEFAULT '' COMMENT '支付方式（记录用，页面忽略渠道）',
  `name` varchar(120) DEFAULT '' COMMENT '商品名称',
  `amount_fen` bigint(20) NOT NULL COMMENT '金额（分）',
  `notify_url` varchar(500) DEFAULT '' COMMENT '异步回调地址',
  `return_url` varchar(500) DEFAULT '' COMMENT '同步跳转地址',
  `pay_user_id` int(10) unsigned DEFAULT NULL COMMENT '付款人（Auth Center 用户）',
  `status` tinyint(4) NOT NULL DEFAULT 0 COMMENT '0待支付 1已支付 2已关闭',
  `paid_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_trade` (`trade_no`),
  UNIQUE KEY `uk_out` (`pid`,`out_trade_no`),
  KEY `idx_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='易支付收款单';

CREATE TABLE IF NOT EXISTS `pay_merchant_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `pid` varchar(20) NOT NULL,
  `type` varchar(20) NOT NULL COMMENT 'income/consume/admin_adjust',
  `amount` bigint(20) NOT NULL COMMENT '变动（分，正入负出）',
  `balance_after` bigint(20) NOT NULL,
  `trade_no` varchar(40) DEFAULT '' COMMENT '关联订单',
  `pay_user_id` int(10) unsigned DEFAULT NULL,
  `remark` varchar(255) DEFAULT '',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_pid` (`pid`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='商户余额流水';
