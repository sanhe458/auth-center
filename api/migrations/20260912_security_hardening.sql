-- 2026-09-12 安全加固：昵称唯一性 + 会话表索引
-- 1) 昵称唯一（防冒充）
--    注意：加唯一索引前需确保无重复昵称，否则会失败
ALTER TABLE users ADD UNIQUE KEY uniq_nickname (nickname);

-- 2) oauth_tokens 按用户查询（退出登录级联吊销）加速
ALTER TABLE oauth_tokens ADD KEY idx_user_revoked (user_id, revoked);
