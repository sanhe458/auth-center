-- 积分系统：users 加积分字段 + 积分流水表
-- 积分 = 平台内奖励点数（不可提现），与余额（钱）分离

ALTER TABLE `users` ADD COLUMN `points` int(11) NOT NULL DEFAULT 0 COMMENT '积分' AFTER `balance`;

CREATE TABLE IF NOT EXISTS `points_transactions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '用户 id',
  `type` varchar(32) NOT NULL COMMENT '类型: reward/consume/refund/admin_adjust',
  `amount` int(11) NOT NULL COMMENT '变动积分（正=增加，负=扣减）',
  `points_after` int(11) NOT NULL COMMENT '变动后积分',
  `reference` varchar(64) DEFAULT NULL COMMENT '关联单号/来源',
  `remark` varchar(255) DEFAULT NULL COMMENT '备注',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`,`created_at`),
  KEY `idx_ref` (`reference`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='积分变动流水';
