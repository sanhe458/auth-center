-- 签到系统：每天签到送积分，7 天循环奖励

CREATE TABLE IF NOT EXISTS `checkins` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_id` int(10) unsigned NOT NULL COMMENT '用户 id',
  `checkin_date` date NOT NULL COMMENT '签到日期',
  `streak` int(11) NOT NULL DEFAULT 1 COMMENT '本次签到时的连续天数',
  `points` int(11) NOT NULL DEFAULT 0 COMMENT '本次获得积分',
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_user_date` (`user_id`,`checkin_date`),
  KEY `idx_user` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci COMMENT='用户签到记录';