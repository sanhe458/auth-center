-- 图床功能
-- 图片记录：复用 imgbb 上传，可选过期时间
-- 永久解锁：用户花 10 元解锁"永久"档位，一次付费永久有效

CREATE TABLE IF NOT EXISTS `images` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '上传用户',
  `app_id` int(10) unsigned DEFAULT NULL COMMENT '经哪个应用上传(开发者API)',
  `name` varchar(200) DEFAULT '' COMMENT '文件名',
  `url` varchar(500) NOT NULL COMMENT '图片直链',
  `page_url` varchar(500) DEFAULT '' COMMENT '查看页',
  `delete_hash` varchar(200) DEFAULT '' COMMENT 'imgbb delete token',
  `delete_url` varchar(500) DEFAULT '' COMMENT '删除地址',
  `size` int(10) unsigned DEFAULT 0 COMMENT '字节',
  `mime` varchar(50) DEFAULT '' COMMENT '类型',
  `expires_at` datetime DEFAULT NULL COMMENT '过期时间(null=永久)',
  `is_permanent` tinyint(4) NOT NULL DEFAULT 0 COMMENT '1=永久(解锁)',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `idx_user` (`user_id`),
  KEY `idx_app` (`app_id`),
  KEY `idx_expires` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='图床图片记录';

CREATE TABLE IF NOT EXISTS `image_permanent` (
  `id` int(10) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL,
  `paid` tinyint(4) NOT NULL DEFAULT 1 COMMENT '1=已付费解锁',
  `trade_no` varchar(40) DEFAULT '' COMMENT '解锁支付订单号',
  `unlocked_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci COMMENT='图床永久解锁记录';
